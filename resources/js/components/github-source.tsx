import { Link, router } from '@inertiajs/react';
import { GitBranch } from 'lucide-react';
import { useEffect, useState } from 'react';
import GitHubConnectionController from '@/actions/App/Http/Controllers/GitHub/GitHubConnectionController';
import GitHubRefController from '@/actions/App/Http/Controllers/GitHub/GitHubRefController';
import GitHubRepositoryController from '@/actions/App/Http/Controllers/GitHub/GitHubRepositoryController';
import GitHubImportController from '@/actions/App/Http/Controllers/Sources/GitHubImportController';
import SnapshotController from '@/actions/App/Http/Controllers/Sources/SnapshotController';
import EmptyState from '@/components/empty-state';
import ErrorPanel from '@/components/error-panel';
import { GitHubImportHelp } from '@/components/github-guides';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import type {
    GitHubImportSummary,
    GitHubInstallationSummary,
    GitHubPanelData,
    GitHubRepositorySummary,
} from '@/types';

type RefType = 'default' | 'branch' | 'tag' | 'commit';
type Refs = { defaultBranch: string; branches: string[]; tags: string[] };

/** GET a picker endpoint. Errors carry the server's stable, user-safe message. */
async function getJson<T>(url: string): Promise<T> {
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
    });
    const body = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw new Error(
            body?.error?.message ?? 'GitHub could not be reached. Try again.',
        );
    }

    return body as T;
}

const statusTone: Record<
    string,
    'success' | 'destructive' | 'outline' | 'secondary'
> = {
    completed: 'success',
    failed: 'destructive',
    cancelled: 'outline',
};

const statusLabel: Record<string, string> = {
    queued: 'Queued',
    downloading: 'Downloading',
    importing: 'Importing',
    completed: 'Snapshot ready',
    failed: 'Failed',
    cancelled: 'Cancelled',
};

/** "main @ 4f7c18a": the friendly ref and the immutable commit. */
export function refLabel(item: GitHubImportSummary): string {
    const ref =
        item.refType === 'commit' ? 'commit' : item.requestedRef || 'default';

    return `${ref} @ ${item.commitSha.slice(0, 7)}`;
}

export default function GitHubSource({
    projectId,
    github,
    busy,
}: {
    projectId: string;
    github: GitHubPanelData;
    busy: boolean;
}) {
    const integrationsUrl = GitHubConnectionController.show.url(
        github.workspaceId,
    );
    const [installations, setInstallations] = useState<
        GitHubInstallationSummary[] | null
    >(null);
    const [installationId, setInstallationId] = useState(
        github.source?.installationId ?? '',
    );
    const [repositories, setRepositories] = useState<
        GitHubRepositorySummary[] | null
    >(null);
    const [repositoryId, setRepositoryId] = useState('');
    const [picking, setPicking] = useState(github.source === null);
    const [refs, setRefs] = useState<Refs | null>(null);
    const [refType, setRefType] = useState<RefType>('default');
    const [ref, setRef] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const sourceRepositoryId = github.source?.repositoryId ?? null;
    const canBrowse =
        github.configured && github.connected && github.canSelect && picking;

    useEffect(() => {
        if (!canBrowse || installations !== null) {
            return;
        }

        getJson<GitHubInstallationSummary[]>(
            GitHubRepositoryController.installations.url(projectId),
        )
            .then((list) => {
                setInstallations(list);
                setInstallationId((id) => id || (list[0]?.id ?? ''));
            })
            .catch((e: Error) => setError(e.message));
    }, [canBrowse, installations, projectId]);

    useEffect(() => {
        if (!canBrowse || installationId === '') {
            return;
        }

        setRepositories(null);
        setLoading(true);
        getJson<GitHubRepositorySummary[]>(
            GitHubRepositoryController.repositories.url({
                project: projectId,
                installation: installationId,
            }),
        )
            .then(setRepositories)
            .catch((e: Error) => setError(e.message))
            .finally(() => setLoading(false));
    }, [canBrowse, installationId, projectId]);

    useEffect(() => {
        if (sourceRepositoryId === null || !github.canImport) {
            return;
        }

        getJson<Refs>(GitHubRefController.url(projectId))
            .then(setRefs)
            .catch((e: Error) => setError(e.message));
    }, [sourceRepositoryId, github.canImport, projectId]);

    if (!github.configured) {
        return (
            <EmptyState
                icon={GitBranch}
                title="GitHub isn't set up yet"
                description="A platform admin must add the GitHub App in Settings → GitHub App. ZIP upload keeps working."
            />
        );
    }

    if (!github.connected && github.source === null) {
        return (
            <div className="space-y-3">
                <EmptyState
                    icon={GitBranch}
                    title="GitHub is not connected"
                    description={
                        github.canConnect
                            ? 'Connect a GitHub account or organization, then pick a repository here.'
                            : 'Ask a workspace Owner or Maintainer to connect GitHub.'
                    }
                    action={
                        github.canConnect ? (
                            <Button asChild size="sm">
                                <Link href={integrationsUrl}>
                                    Connect GitHub
                                </Link>
                            </Button>
                        ) : undefined
                    }
                />
                <GitHubImportHelp
                    integrationsUrl={integrationsUrl}
                    manageUrl={null}
                />
            </div>
        );
    }

    const manageUrl =
        installations?.find((i) => i.id === installationId)?.manageUrl ?? null;
    const refOptions =
        refType === 'branch'
            ? (refs?.branches ?? [])
            : refType === 'tag'
              ? (refs?.tags ?? [])
              : [];

    return (
        <div className="space-y-4">
            {error && (
                <ErrorPanel title="GitHub request failed" message={error} />
            )}

            {github.source && !picking && (
                <div className="flex flex-wrap items-center gap-2 rounded-lg border bg-card p-3 text-sm">
                    <GitBranch className="size-4 text-muted-foreground" />
                    <span className="font-medium">
                        {github.source.fullName}
                    </span>
                    <Badge variant="secondary">
                        {github.source.visibility}
                    </Badge>
                    {github.source.lastImportedSha && (
                        <span className="text-muted-foreground">
                            last import{' '}
                            <code className="font-mono text-code">
                                {github.source.lastImportedSha.slice(0, 7)}
                            </code>
                        </span>
                    )}
                    {github.canSelect && (
                        <Button
                            variant="link"
                            size="sm"
                            className="ml-auto"
                            onClick={() => setPicking(true)}
                        >
                            Change repository
                        </Button>
                    )}
                </div>
            )}

            {github.source && !github.source.installationLinked && (
                <ErrorPanel
                    title="GitHub access was disconnected"
                    message="New imports are blocked until GitHub is connected again. Earlier snapshots and documentation are kept."
                />
            )}

            {canBrowse && (
                <div className="grid gap-3 rounded-lg border bg-card p-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="github-installation">
                            Account or organization
                        </Label>
                        <NativeSelect
                            id="github-installation"
                            value={installationId}
                            onChange={(e) => setInstallationId(e.target.value)}
                            disabled={installations === null}
                        >
                            {(installations ?? []).map((i) => (
                                <option key={i.id} value={i.id}>
                                    {i.accountLogin}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="github-repository">Repository</Label>
                        <NativeSelect
                            id="github-repository"
                            value={repositoryId}
                            onChange={(e) => setRepositoryId(e.target.value)}
                            disabled={repositories === null}
                        >
                            <option value="">
                                {loading
                                    ? 'Loading repositories…'
                                    : 'Choose a repository'}
                            </option>
                            {(repositories ?? []).map((r) => (
                                <option key={r.id} value={r.id}>
                                    {r.fullName}
                                    {r.archived ? ' (archived)' : ''}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    <div className="flex flex-wrap items-center gap-2 sm:col-span-2">
                        <Button
                            size="sm"
                            disabled={repositoryId === ''}
                            onClick={() =>
                                router.put(
                                    GitHubRepositoryController.update.url(
                                        projectId,
                                    ),
                                    {
                                        installation_id: installationId,
                                        repository_id: Number(repositoryId),
                                    },
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => {
                                            setPicking(false);
                                            setRefs(null);
                                        },
                                    },
                                )
                            }
                        >
                            Use this repository
                        </Button>
                        {github.source && (
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => setPicking(false)}
                            >
                                Cancel
                            </Button>
                        )}
                        {loading && <Spinner />}
                        <span className="text-sm text-muted-foreground">
                            Missing a repository? Grant access on GitHub (
                            {manageUrl ? (
                                <a
                                    className="underline underline-offset-4"
                                    href={manageUrl}
                                >
                                    Configure
                                </a>
                            ) : (
                                'Configure'
                            )}
                            ), then reload.
                        </span>
                    </div>
                </div>
            )}

            {github.source &&
                !picking &&
                github.canImport &&
                github.source.installationLinked && (
                    <form
                        className="grid gap-3 rounded-lg border bg-card p-4 sm:grid-cols-[auto_1fr_auto] sm:items-end"
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.post(
                                GitHubImportController.store.url(projectId),
                                {
                                    ref_type: refType,
                                    ref: refType === 'default' ? null : ref,
                                },
                                { preserveScroll: true },
                            );
                        }}
                    >
                        <div className="grid gap-2">
                            <Label htmlFor="github-ref-type">Import</Label>
                            <NativeSelect
                                id="github-ref-type"
                                value={refType}
                                onChange={(e) => {
                                    setRefType(e.target.value as RefType);
                                    setRef('');
                                }}
                            >
                                <option value="default">
                                    Default branch (
                                    {refs?.defaultBranch ??
                                        github.source.defaultBranch}
                                    )
                                </option>
                                <option value="branch">Branch</option>
                                <option value="tag">Tag</option>
                                <option value="commit">Commit SHA</option>
                            </NativeSelect>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="github-ref">
                                {refType === 'commit'
                                    ? 'Full 40-character commit SHA'
                                    : refType === 'default'
                                      ? 'Latest commit of the default branch'
                                      : `${refType === 'branch' ? 'Branch' : 'Tag'} name`}
                            </Label>
                            <Input
                                id="github-ref"
                                value={ref}
                                onChange={(e) => setRef(e.target.value)}
                                disabled={refType === 'default'}
                                list="github-ref-options"
                                maxLength={255}
                                autoComplete="off"
                                spellCheck={false}
                                className="font-mono text-code"
                            />
                            <datalist id="github-ref-options">
                                {refOptions.map((name) => (
                                    <option key={name} value={name} />
                                ))}
                            </datalist>
                        </div>
                        <Button
                            disabled={
                                busy || (refType !== 'default' && ref === '')
                            }
                        >
                            Import
                        </Button>
                        <p className="text-sm text-muted-foreground sm:col-span-3">
                            The commit is fixed when you click Import. If the
                            branch moves later, import again to get a new
                            snapshot.
                        </p>
                    </form>
                )}

            {github.imports.length > 0 && (
                <ul className="divide-y rounded-lg border bg-card">
                    {github.imports.map((item) => (
                        <li
                            key={item.id}
                            className="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2 text-sm"
                        >
                            <Badge
                                variant={statusTone[item.status] ?? 'secondary'}
                            >
                                {statusLabel[item.status] ?? item.status}
                            </Badge>
                            <code
                                className="font-mono text-code"
                                title={item.commitSha}
                            >
                                {refLabel(item)}
                            </code>
                            <span className="text-muted-foreground">
                                {item.repository}
                            </span>
                            {item.commitMessage && (
                                <span className="truncate text-muted-foreground">
                                    {item.commitMessage}
                                </span>
                            )}
                            {item.failureMessage && (
                                <span className="w-full text-destructive">
                                    {item.failureMessage}
                                </span>
                            )}
                            {item.snapshotId && (
                                <Link
                                    className="ml-auto underline underline-offset-4"
                                    href={SnapshotController.show({
                                        project: projectId,
                                        snapshot: item.snapshotId,
                                    })}
                                >
                                    Snapshot
                                </Link>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            <GitHubImportHelp
                integrationsUrl={integrationsUrl}
                manageUrl={manageUrl}
            />
        </div>
    );
}
