import { Link, router, usePoll } from '@inertiajs/react';
import { Upload } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import ProcessingRunController from '@/actions/App/Http/Controllers/Sources/ProcessingRunController';
import SnapshotController from '@/actions/App/Http/Controllers/Sources/SnapshotController';
import EmptyState from '@/components/empty-state';
import ErrorPanel from '@/components/error-panel';
import Heading from '@/components/heading';
import ImportStatus, { formatBytes } from '@/components/import-status';
import Progress from '@/components/progress';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import GitHubSource from '@/components/github-source';
import ZipUpload from '@/components/zip-upload';
import {
    clearTrackedSourceImport,
    sourceImportToastId,
    trackedSourceImport,
} from '@/lib/source-import-toast';
import { cn } from '@/lib/utils';
import type { GitHubPanelData, SourcePanelData } from '@/types';

type Props = {
    projectId: string;
    sourcePreference: string | null;
    canManage: boolean;
    sources: SourcePanelData;
    /** P09: present only while Git import is enabled; ZIP works the same without it. */
    github?: GitHubPanelData | null;
};

export default function SourcePanel({
    projectId,
    sourcePreference,
    canManage,
    sources,
    github = null,
}: Props) {
    const [method, setMethod] = useState<'zip' | 'github'>(
        github && sourcePreference === 'git' ? 'github' : 'zip',
    );
    const run = sources.latestRun;
    const active = run?.active ?? false;
    const runId = run?.id ?? null;
    const runStatus = run?.status ?? null;
    const failureMessage = run?.failureMessage ?? null;
    // Refresh only this panel while an import runs.
    const { start, stop } = usePoll(
        2000,
        { only: ['sources', 'project', 'github'] },
        { autoStart: false },
    );

    useEffect(() => {
        if (active) {
            start();
        } else {
            stop();
        }
    }, [active, start, stop]);

    // Receiving every upload chunk is not the same as importing the source.
    // Resolve the loading toast only when the queued import actually finishes.
    useEffect(() => {
        if (runId === null || trackedSourceImport(projectId) !== runId) {
            return;
        }

        const id = sourceImportToastId(runId);

        if (active) {
            // sonner shows no close button on loading toasts; its cancel button dismisses them.
            toast.loading('Validating and unpacking the imported source…', {
                id,
                cancel: { label: 'Dismiss', onClick: () => {} },
            });

            return;
        }

        clearTrackedSourceImport(projectId);

        if (runStatus === 'completed') {
            toast.success('Source imported successfully.', {
                id,
                cancel: undefined,
            });
        } else if (runStatus === 'cancelled') {
            toast.info('Source import cancelled.', { id, cancel: undefined });
        } else {
            toast.error(failureMessage ?? 'The source import failed.', {
                id,
                cancel: undefined,
            });
        }
    }, [active, failureMessage, projectId, runId, runStatus]);

    return (
        <section className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center gap-2">
                <Heading variant="small" title="Source" />
                {sources.canImportFixture && !active && (
                    <Button
                        variant="outline"
                        size="sm"
                        className="ml-auto"
                        onClick={() =>
                            router.post(
                                ProcessingRunController.importFixture.url(
                                    projectId,
                                ),
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        Import demo fixture
                    </Button>
                )}
            </div>

            {github && (
                <div
                    role="tablist"
                    aria-label="Source method"
                    className="inline-flex w-fit gap-1 rounded-lg bg-muted p-1"
                >
                    {(
                        [
                            ['zip', 'ZIP upload'],
                            ['github', 'GitHub repository'],
                        ] as const
                    ).map(([value, label]) => (
                        <button
                            key={value}
                            type="button"
                            role="tab"
                            aria-selected={method === value}
                            onClick={() => setMethod(value)}
                            className={cn(
                                'rounded-md px-3 py-1.5 text-sm transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                                method === value
                                    ? 'bg-background shadow-xs'
                                    : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {label}
                        </button>
                    ))}
                </div>
            )}

            {github && method === 'github' && (
                <GitHubSource
                    projectId={projectId}
                    github={github}
                    busy={active}
                />
            )}

            {(!github || method === 'zip') && sources.canUpload && !active && (
                <ZipUpload
                    projectId={projectId}
                    maxBytes={sources.maxUploadBytes}
                    maxSourceBytes={sources.maxSourceBytes}
                />
            )}

            {run && active && (
                <div className="space-y-2 rounded-lg border bg-card p-4">
                    <div className="flex items-center gap-2 text-sm">
                        <ImportStatus status={run.status} />
                        <span className="text-muted-foreground">
                            {run.cancelRequested
                                ? 'Stopping…'
                                : 'Validating, hashing and storing files…'}
                        </span>
                        {canManage && !run.cancelRequested && (
                            <Dialog>
                                <DialogTrigger asChild>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="ml-auto"
                                    >
                                        Cancel
                                    </Button>
                                </DialogTrigger>
                                <DialogContent>
                                    <DialogTitle>Stop this import?</DialogTitle>
                                    <DialogDescription>
                                        The import stops at its next checkpoint.
                                        You can start it again later.
                                    </DialogDescription>
                                    <DialogFooter className="gap-2">
                                        <DialogClose asChild>
                                            <Button variant="secondary">
                                                Keep importing
                                            </Button>
                                        </DialogClose>
                                        <DialogClose asChild>
                                            <Button
                                                variant="destructive"
                                                onClick={() =>
                                                    router.post(
                                                        ProcessingRunController.cancel.url(
                                                            run.id,
                                                        ),
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Stop import
                                            </Button>
                                        </DialogClose>
                                    </DialogFooter>
                                </DialogContent>
                            </Dialog>
                        )}
                    </div>
                    <Progress label="Import progress" />
                </div>
            )}

            {run && !active && run.failureMessage && (
                <ErrorPanel
                    title="The last import did not complete"
                    message={run.failureMessage}
                    action={
                        sources.canUpload &&
                        !run.failureCode?.startsWith('github.') ? (
                            <span className="text-sm">
                                {run.failureCode === 'too_large'
                                    ? `Keep analyzed source under ${formatBytes(sources.maxSourceBytes)}. Remove build output, uploads, backups, or other generated files, then upload a new ZIP.`
                                    : 'Fix the archive and upload it again.'}
                            </span>
                        ) : undefined
                    }
                />
            )}

            {sources.rootCandidates.length > 0 && (
                <p className="text-sm text-muted-foreground">
                    Laravel app found in{' '}
                    {sources.rootCandidates.map((dir, i) => (
                        <span key={dir}>
                            {i > 0 && ', '}
                            <code>
                                {dir === '.' ? '(archive root)' : `${dir}/`}
                            </code>
                        </span>
                    ))}
                    .
                </p>
            )}

            {sources.snapshots.length === 0 ? (
                <EmptyState
                    icon={Upload}
                    title="No source imported yet"
                    description={
                        github
                            ? 'Upload a ZIP or import a GitHub repository above to create the first snapshot.'
                            : sourcePreference === 'git'
                              ? 'Git import arrives in a later release. Your preference is saved; no credentials are stored.'
                              : sources.canUpload
                                ? 'Upload a ZIP above to create the first snapshot.'
                                : 'Ask someone who can manage this project to upload a ZIP.'
                    }
                />
            ) : (
                <ul className="divide-y rounded-lg border bg-card">
                    {sources.snapshots.map((snapshot) => (
                        <li key={snapshot.id}>
                            <Link
                                href={SnapshotController.show({
                                    project: projectId,
                                    snapshot: snapshot.id,
                                })}
                                className="flex flex-wrap items-center gap-x-4 gap-y-1 px-3 py-2 text-sm transition-[color,background-color,box-shadow] outline-none hover:bg-muted/50 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:ring-inset"
                            >
                                <ImportStatus status={snapshot.status} />
                                <span className="capitalize">
                                    {snapshot.sourceType}
                                </span>
                                {snapshot.sha256 && (
                                    <code
                                        className="font-mono text-code text-muted-foreground"
                                        title={snapshot.sha256}
                                    >
                                        {snapshot.sha256.slice(0, 12)}
                                    </code>
                                )}
                                {snapshot.status === 'ready' && (
                                    <span className="text-muted-foreground">
                                        {snapshot.fileCount} files ·{' '}
                                        {formatBytes(snapshot.totalBytes)}
                                    </span>
                                )}
                                <span className="ml-auto text-muted-foreground">
                                    {new Date(
                                        snapshot.readyAt ??
                                            snapshot.createdAt ??
                                            '',
                                    ).toLocaleString()}
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
