import { router } from '@inertiajs/react';
import { FileArchive } from 'lucide-react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';
import ZipUploadController from '@/actions/App/Http/Controllers/Sources/ZipUploadController';
import { formatBytes } from '@/components/import-status';
import InputError from '@/components/input-error';
import Progress from '@/components/progress';
import { Button } from '@/components/ui/button';
import {
    sourceImportToastId,
    trackSourceImport,
} from '@/lib/source-import-toast';
import { cn } from '@/lib/utils';

type Session = {
    id: string;
    status: string;
    totalBytes: number;
    receivedBytes: number;
    chunkBytes: number;
};

class UploadFailure extends Error {
    constructor(
        message: string,
        public readonly status: number,
        public readonly code?: string,
    ) {
        super(message);
    }
}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

type CallInit = Omit<RequestInit, 'headers'> & {
    headers?: Record<string, string>;
};

async function call<T>(url: string, init: CallInit = {}): Promise<T> {
    const response = await fetch(url, {
        credentials: 'same-origin',
        ...init,
        headers: {
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
            ...init.headers,
        },
    });
    const body = await response.json().catch(() => ({}));

    if (!response.ok) {
        const message =
            body?.error?.message ??
            body?.errors?.total_bytes?.[0] ??
            body?.errors?.file_name?.[0] ??
            body?.message ??
            'The upload failed.';
        throw new UploadFailure(message, response.status, body?.error?.code);
    }

    return body as T;
}

const wait = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

/** Remembers an unfinished upload per project + file, so a page reload resumes it. */
const resumeKey = (projectId: string, file: File) =>
    `zip-upload:${projectId}:${file.name}:${file.size}:${file.lastModified}`;

function rememberedSession(key: string): string | null {
    try {
        return localStorage.getItem(key);
    } catch {
        return null;
    }
}

function remember(key: string, id: string | null) {
    try {
        if (id) {
            localStorage.setItem(key, id);
        } else {
            localStorage.removeItem(key);
        }
    } catch {
        // storage unavailable: uploads still work, they just can't resume after a reload
    }
}

export default function ZipUpload({
    projectId,
    maxBytes,
    maxSourceBytes,
}: {
    projectId: string;
    maxBytes: number;
    maxSourceBytes: number;
}) {
    const [file, setFile] = useState<File | null>(null);
    const [dragging, setDragging] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [sent, setSent] = useState<number | null>(null);
    const [status, setStatus] = useState<string | null>(null);
    const stopped = useRef(false);

    const busy = sent !== null;

    const choose = (picked: File | undefined) => {
        setError(null);

        if (!picked) {
            return;
        }

        if (!picked.name.toLowerCase().endsWith('.zip')) {
            setError('Choose a .zip archive.');

            return;
        }

        if (picked.size > maxBytes) {
            setError(
                `That file is ${formatBytes(picked.size)}; the limit is ${formatBytes(maxBytes)}.`,
            );

            return;
        }

        setFile(picked);
    };

    const openSession = async (picked: File): Promise<Session> => {
        const key = resumeKey(projectId, picked);
        const previous = rememberedSession(key);

        if (previous) {
            try {
                const session = await call<Session>(
                    ZipUploadController.show.url({
                        project: projectId,
                        upload: previous,
                    }),
                );

                if (
                    session.status === 'uploading' &&
                    session.totalBytes === picked.size
                ) {
                    setStatus(
                        `Resuming from ${formatBytes(session.receivedBytes)}…`,
                    );

                    return session;
                }
            } catch {
                // expired or gone: start fresh
            }
        }

        const session = await call<Session>(
            ZipUploadController.store.url(projectId),
            {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    total_bytes: picked.size,
                    file_name: picked.name,
                }),
            },
        );
        remember(key, session.id);

        return session;
    };

    /** Sends one piece, retrying network errors with backoff. Returns the server's received bytes. */
    const sendPiece = async (
        session: Session,
        offset: number,
    ): Promise<number> => {
        const piece = file!.slice(
            offset,
            Math.min(offset + session.chunkBytes, file!.size),
        );
        const url = ZipUploadController.chunk.url(
            { project: projectId, upload: session.id },
            { query: { offset } },
        );

        for (let attempt = 1; ; attempt++) {
            try {
                const updated = await call<Session>(url, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/octet-stream' },
                    body: piece,
                });

                return updated.receivedBytes;
            } catch (e) {
                if (
                    e instanceof UploadFailure &&
                    e.code === 'upload.offset_mismatch'
                ) {
                    // The server knows where we really are: continue from there.
                    const current = await call<Session>(
                        ZipUploadController.show.url({
                            project: projectId,
                            upload: session.id,
                        }),
                    );

                    return current.receivedBytes;
                }

                const retryable =
                    !(e instanceof UploadFailure) ||
                    e.status >= 500 ||
                    e.status === 429;

                if (!retryable || attempt >= 5 || stopped.current) {
                    throw e;
                }

                setStatus(`Connection problem; retrying (${attempt}/4)…`);
                await wait(1000 * 2 ** attempt);
            }
        }
    };

    const upload = async () => {
        if (!file) {
            return;
        }

        stopped.current = false;
        setError(null);
        setSent(0);

        try {
            const session = await openSession(file);
            let received = session.receivedBytes;
            setSent(received);

            while (received < file.size) {
                if (stopped.current) {
                    setStatus('Paused. Choose the same file again to resume.');

                    return;
                }

                setStatus(null);
                received = await sendPiece(session, received);
                setSent(received);
            }

            setStatus('Finishing…');
            const result = await call<{
                runId: string;
                alreadyRunning: boolean;
            }>(
                ZipUploadController.complete.url({
                    project: projectId,
                    upload: session.id,
                }),
                { method: 'POST' },
            );
            remember(resumeKey(projectId, file), null);
            trackSourceImport(projectId, result.runId);
            // sonner shows no close button on loading toasts; its cancel button dismisses them.
            toast.loading(
                result.alreadyRunning
                    ? 'This source is already being imported…'
                    : 'Upload complete. Validating and unpacking the source…',
                {
                    id: sourceImportToastId(result.runId),
                    cancel: { label: 'Dismiss', onClick: () => {} },
                },
            );
            setFile(null);
            router.reload({ only: ['sources', 'project'] });
        } catch (e) {
            setError(e instanceof Error ? e.message : 'The upload failed.');
        } finally {
            setSent(null);
            setStatus(null);
        }
    };

    return (
        <div className="space-y-3 rounded-lg border bg-background/40 p-3">
            <label
                onDragOver={(e) => {
                    e.preventDefault();
                    setDragging(true);
                }}
                onDragLeave={() => setDragging(false)}
                onDrop={(e) => {
                    e.preventDefault();
                    setDragging(false);
                    choose(e.dataTransfer.files[0]);
                }}
                className={cn(
                    'flex cursor-pointer flex-col items-center gap-1.5 rounded-lg border border-dashed p-4 text-center text-sm has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring/50',
                    dragging && 'border-info bg-muted/50',
                    busy && 'pointer-events-none opacity-60',
                )}
            >
                <FileArchive
                    aria-hidden="true"
                    className="size-6 text-muted-foreground"
                />
                <span className="font-medium">
                    {file
                        ? `${file.name} (${formatBytes(file.size)})`
                        : 'Drop a .zip of your Laravel project, or click to choose'}
                </span>
                <span className="text-muted-foreground">
                    Up to {formatBytes(maxBytes)}. Large uploads resume if the
                    connection drops. public/, vendor/, node_modules/ and media
                    are skipped; analyzed source must be under{' '}
                    {formatBytes(maxSourceBytes)} after unpacking. Nothing you
                    upload is ever run.
                </span>
                <input
                    type="file"
                    accept=".zip,application/zip"
                    className="sr-only"
                    onChange={(e) => choose(e.currentTarget.files?.[0])}
                    disabled={busy}
                />
            </label>

            {busy && file && (
                <div className="space-y-1">
                    <Progress
                        label="Upload progress"
                        value={(sent / file.size) * 100}
                    />
                    <p className="text-xs text-muted-foreground">
                        {status ??
                            `${formatBytes(sent)} of ${formatBytes(file.size)}`}
                    </p>
                </div>
            )}
            <InputError message={error ?? undefined} />

            <div className="flex gap-2">
                <Button size="sm" onClick={upload} disabled={!file || busy}>
                    {busy ? 'Uploading…' : 'Upload and import'}
                </Button>
                {busy && (
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => (stopped.current = true)}
                    >
                        Pause
                    </Button>
                )}
            </div>
        </div>
    );
}
