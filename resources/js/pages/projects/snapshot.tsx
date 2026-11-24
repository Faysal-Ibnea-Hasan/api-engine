import { Head, Link } from '@inertiajs/react';
import ProjectController from '@/actions/App/Http/Controllers/Projects/ProjectController';
import ErrorPanel from '@/components/error-panel';
import FrameworkDetectionCard from '@/components/framework-detection-card';
import Heading from '@/components/heading';
import ImportStatus, { formatBytes } from '@/components/import-status';
import { Button } from '@/components/ui/button';
import type { FrameworkDetectionData, SnapshotSummary } from '@/types';

type FileRow = {
    path: string;
    bytes: number;
    language: string | null;
    excludedReason: string | null;
};

type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

type Props = {
    project: { id: string; name: string };
    snapshot: SnapshotSummary;
    files: Paginated<FileRow> | null;
    detection: {
        result: FrameworkDetectionData | null;
        canDetect: boolean;
        canSelectRoot: boolean;
    } | null;
};

const exclusionLabels: Record<string, string> = {
    secret: 'Excluded: may contain secrets',
    dependency_or_vcs: 'Excluded: dependency or version-control folder',
    ignored_directory: 'Excluded: configured ignored folder',
    log: 'Excluded: log file',
    too_large: 'Excluded: file too large',
    binary: 'Excluded: binary file',
    nested_archive: 'Excluded: archive inside the archive (never opened)',
    runtime_data: 'Excluded: cached or session data',
    database: 'Excluded: database file',
    symlink: 'Excluded: symbolic link (not followed)',
    special_file: 'Excluded: device or pipe, not a regular file',
};

export default function ShowSnapshot({
    project,
    snapshot,
    files,
    detection,
}: Props) {
    return (
        <>
            <Head title={`Snapshot of ${project.name}`} />
            <div className="flex w-full flex-col gap-4 p-4 md:p-6">
                <Heading
                    title="Source snapshot"
                    description={`An immutable copy of ${project.name}'s source. It never changes once ready.`}
                />

                <dl className="grid grid-cols-[max-content_1fr] gap-x-6 gap-y-1.5 rounded-lg border p-3 text-sm lg:grid-cols-[max-content_1fr_max-content_1fr]">
                    <dt className="text-muted-foreground">Status</dt>
                    <dd>
                        <ImportStatus status={snapshot.status} />
                    </dd>
                    <dt className="text-muted-foreground">Source</dt>
                    <dd className="capitalize">{snapshot.sourceType}</dd>
                    <dt className="text-muted-foreground">Content hash</dt>
                    <dd>
                        <code className="font-mono text-code break-all">
                            {snapshot.sha256 ?? '—'}
                        </code>
                    </dd>
                    <dt className="text-muted-foreground">Files</dt>
                    <dd>
                        {snapshot.fileCount} stored, {snapshot.excludedCount}{' '}
                        excluded · {formatBytes(snapshot.totalBytes)}
                    </dd>
                    <dt className="text-muted-foreground">Ready</dt>
                    <dd>
                        {snapshot.readyAt
                            ? new Date(snapshot.readyAt).toLocaleString()
                            : '—'}
                    </dd>
                    <dt className="text-muted-foreground">Manifest version</dt>
                    <dd>{snapshot.manifestVersion}</dd>
                </dl>

                {snapshot.failureMessage && (
                    <ErrorPanel
                        title="This snapshot was not stored"
                        message={snapshot.failureMessage}
                    />
                )}

                {detection && (
                    <FrameworkDetectionCard
                        projectId={project.id}
                        snapshotId={snapshot.id}
                        detection={detection.result}
                        canDetect={detection.canDetect}
                        canSelectRoot={detection.canSelectRoot}
                    />
                )}

                {snapshot.status === 'ready' && files === null && (
                    <p className="rounded-lg border p-4 text-sm text-muted-foreground">
                        Your role can see the import status but not the source
                        files.
                    </p>
                )}

                {files && (
                    <section className="flex flex-col gap-3">
                        <Heading
                            variant="small"
                            title={`Files (${files.total})`}
                        />
                        <ul className="divide-y rounded-lg border bg-card text-sm">
                            {files.data.map((file) => (
                                <li
                                    key={file.path}
                                    className="flex flex-wrap items-center gap-x-4 gap-y-1 px-3 py-2"
                                >
                                    <code
                                        className={
                                            file.excludedReason
                                                ? 'min-w-0 font-mono text-code break-all text-muted-foreground line-through'
                                                : 'min-w-0 font-mono text-code break-all'
                                        }
                                    >
                                        {file.path}
                                    </code>
                                    {file.language && (
                                        <span className="text-xs text-muted-foreground">
                                            {file.language}
                                        </span>
                                    )}
                                    <span className="ml-auto text-xs text-muted-foreground">
                                        {file.excludedReason
                                            ? (exclusionLabels[
                                                  file.excludedReason
                                              ] ?? 'Excluded')
                                            : formatBytes(file.bytes)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                        {files.last_page > 1 && (
                            <div className="flex items-center gap-2 text-sm">
                                {files.prev_page_url && (
                                    <Button variant="outline" size="sm" asChild>
                                        <Link
                                            href={files.prev_page_url}
                                            preserveScroll
                                        >
                                            Previous
                                        </Link>
                                    </Button>
                                )}
                                <span className="text-muted-foreground">
                                    Page {files.current_page} of{' '}
                                    {files.last_page}
                                </span>
                                {files.next_page_url && (
                                    <Button variant="outline" size="sm" asChild>
                                        <Link
                                            href={files.next_page_url}
                                            preserveScroll
                                        >
                                            Next
                                        </Link>
                                    </Button>
                                )}
                            </div>
                        )}
                    </section>
                )}

                <Button variant="ghost" asChild className="w-fit">
                    <Link href={ProjectController.show(project.id)}>
                        Back to {project.name}
                    </Link>
                </Button>
            </div>
        </>
    );
}

ShowSnapshot.layout = {
    breadcrumbs: [{ title: 'Projects', href: ProjectController.index() }],
};
