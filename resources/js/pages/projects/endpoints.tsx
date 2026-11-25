import { Form, Head, Link, router, usePoll } from '@inertiajs/react';
import {
    CircleCheck,
    CircleDashed,
    CircleHelp,
    FileCode,
    Info,
    ListChecks,
    Route as RouteIcon,
    TriangleAlert,
} from 'lucide-react';
import { useEffect } from 'react';
import EndpointController from '@/actions/App/Http/Controllers/Discovery/EndpointController';
import ProjectController from '@/actions/App/Http/Controllers/Projects/ProjectController';
import SnapshotController from '@/actions/App/Http/Controllers/Sources/SnapshotController';
import EmptyState from '@/components/empty-state';
import ErrorPanel from '@/components/error-panel';
import Heading from '@/components/heading';
import MethodBadge from '@/components/method-badge';
import Progress from '@/components/progress';
import ResolutionBadge from '@/components/resolution-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { NativeSelect } from '@/components/ui/native-select';
import { cn } from '@/lib/utils';
import type {
    AuthStatus,
    EndpointRow,
    RouteScanSummary,
    ScanFindingRow,
} from '@/types';

const AUTH_BADGE: Record<AuthStatus, [string, string]> = {
    required: ['Auth required', 'border-transparent bg-secondary'],
    required_unverified: [
        'Token not always verified',
        'border-warning/50 text-warning',
    ],
    guest_or_user: ['Guest or user', ''],
    not_required: ['No auth', ''],
    unknown: ["Auth: can't tell", 'border-dashed text-muted-foreground'],
    not_detected: ["Auth: can't tell", 'border-dashed text-muted-foreground'],
};

/** Authentication as found by the endpoint's analysis; "Not analyzed" until someone runs it. */
function AuthBadge({ status }: { status: AuthStatus | null }) {
    if (status === null) {
        return (
            <span className="text-xs text-muted-foreground">Not analyzed</span>
        );
    }

    const [label, style] = AUTH_BADGE[status];

    return (
        <span
            className={cn(
                'rounded-md border px-1.5 py-0.5 text-xs whitespace-nowrap',
                style,
            )}
        >
            {label}
        </span>
    );
}

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
    snapshotId: string | null;
    needsRoot: boolean;
    scan: RouteScanSummary | null;
    canScan: boolean;
    analysisEnabled: boolean;
    endpoints: Paginated<EndpointRow> | null;
    filters: {
        method: string;
    };
    findings: ScanFindingRow[];
};

export default function Endpoints({
    project,
    snapshotId,
    needsRoot,
    scan,
    canScan,
    analysisEnabled,
    endpoints,
    filters,
    findings,
}: Props) {
    const active = scan?.active ?? false;
    const { start, stop } = usePoll(
        2000,
        { only: ['scan', 'endpoints', 'findings'] },
        { autoStart: false },
    );

    useEffect(() => {
        if (active) {
            start();
        } else {
            stop();
        }
    }, [active, start, stop]);

    const startScan = () =>
        snapshotId &&
        router.post(
            EndpointController.start.url({
                project: project.id,
                snapshot: snapshotId,
            }),
            {},
            { preserveScroll: true },
        );

    return (
        <>
            <Head title={`${project.name} API endpoints`} />
            <div className="flex w-full flex-col gap-4 p-4 md:p-6">
                <Heading
                    title="API endpoints"
                    description={`Routes found in ${project.name}'s code, without running it.`}
                    icon={RouteIcon}
                >
                    {canScan &&
                        scan &&
                        !active &&
                        scan.status !== 'completed' &&
                        scan.status !== 'partial' && (
                            <Button variant="secondary" onClick={startScan}>
                                Retry discovery
                            </Button>
                        )}
                </Heading>

                <Alert variant="info" role="note">
                    <Info aria-hidden="true" />
                    <AlertDescription>
                        Static discovery reads route files as code. Routes added
                        at runtime (in loops, packages, or callbacks it can't
                        follow) may be missing; anything it couldn't pin down is
                        listed below as a finding, not guessed.
                    </AlertDescription>
                </Alert>

                {!snapshotId && (
                    <EmptyState
                        icon={RouteIcon}
                        title="No source imported yet"
                        description="Import a ZIP on the project page first."
                        action={
                            <Button asChild variant="outline">
                                <Link href={ProjectController.show(project.id)}>
                                    Go to project
                                </Link>
                            </Button>
                        }
                    />
                )}

                {snapshotId && needsRoot && (
                    <EmptyState
                        icon={RouteIcon}
                        title="Choose the application root first"
                        description="Several Laravel apps were found in the latest snapshot."
                        action={
                            <Button asChild>
                                <Link
                                    href={SnapshotController.show({
                                        project: project.id,
                                        snapshot: snapshotId,
                                    })}
                                >
                                    Choose root
                                </Link>
                            </Button>
                        }
                    />
                )}

                {snapshotId && !needsRoot && !scan && (
                    <EmptyState
                        icon={RouteIcon}
                        title="Routes haven't been discovered yet"
                        description="Discovery runs automatically once the framework and root are known."
                        action={
                            canScan ? (
                                <Button onClick={startScan}>
                                    Discover routes
                                </Button>
                            ) : undefined
                        }
                    />
                )}

                {scan && active && (
                    <div className="space-y-2 rounded-lg border bg-card p-4 text-sm">
                        <p>
                            Reading route files in{' '}
                            <code>
                                {scan.applicationRoot === '.'
                                    ? 'the archive root'
                                    : `${scan.applicationRoot}/`}
                            </code>
                            …
                        </p>
                        <Progress label="Route discovery" />
                    </div>
                )}

                {scan?.status === 'failed' && (
                    <ErrorPanel
                        title="Route discovery failed"
                        message="The scan stopped before finishing. Try again; if it keeps failing, contact an administrator."
                    />
                )}

                {scan &&
                    (scan.status === 'completed' ||
                        scan.status === 'partial') &&
                    endpoints && (
                        <>
                            <dl className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-3 lg:grid-cols-5">
                                <div className="rounded-lg border brand-gradient-border bg-card p-3">
                                    <dt className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                        <RouteIcon
                                            aria-hidden="true"
                                            className="size-3.5"
                                        />
                                        Endpoints
                                    </dt>
                                    <dd className="brand-gradient-text text-xl font-semibold tabular-nums">
                                        {scan.endpointCount}
                                    </dd>
                                </div>
                                <div className="rounded-lg border brand-gradient-border bg-card p-3">
                                    <dt className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                        <CircleCheck
                                            aria-hidden="true"
                                            className="size-3.5"
                                        />
                                        Resolved
                                    </dt>
                                    <dd className="brand-gradient-text text-xl font-semibold tabular-nums">
                                        {scan.resolvedCount}
                                        {scan.coverage !== null && (
                                            <span className="text-sm text-muted-foreground">
                                                {' '}
                                                ({scan.coverage}%)
                                            </span>
                                        )}
                                    </dd>
                                </div>
                                <div className="rounded-lg border brand-gradient-border bg-card p-3">
                                    <dt className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                        <CircleDashed
                                            aria-hidden="true"
                                            className="size-3.5"
                                        />
                                        Partial
                                    </dt>
                                    <dd className="brand-gradient-text text-xl font-semibold tabular-nums">
                                        {scan.partialCount}
                                    </dd>
                                </div>
                                <div className="rounded-lg border brand-gradient-border bg-card p-3">
                                    <dt className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                        <CircleHelp
                                            aria-hidden="true"
                                            className="size-3.5"
                                        />
                                        Unresolved
                                    </dt>
                                    <dd className="brand-gradient-text text-xl font-semibold tabular-nums">
                                        {scan.unresolvedCount}
                                    </dd>
                                </div>
                                <div className="rounded-lg border brand-gradient-border bg-card p-3">
                                    <dt className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                        <FileCode
                                            aria-hidden="true"
                                            className="size-3.5"
                                        />
                                        Route files
                                    </dt>
                                    <dd className="brand-gradient-text text-xl font-semibold tabular-nums">
                                        {scan.fileCount}
                                    </dd>
                                </div>
                            </dl>
                            {scan.status === 'partial' && (
                                <Alert variant="warning" role="note">
                                    <TriangleAlert aria-hidden="true" />
                                    <AlertDescription>
                                        Some route files could not be parsed;
                                        the list may be incomplete. See the
                                        findings below.
                                    </AlertDescription>
                                </Alert>
                            )}

                            <Form
                                action={EndpointController.index.url(
                                    project.id,
                                )}
                                method="get"
                                options={{
                                    preserveState: true,
                                    preserveScroll: true,
                                }}
                                className="flex items-center text-sm"
                            >
                                <NativeSelect
                                    name="method"
                                    defaultValue={filters.method}
                                    aria-label="HTTP method"
                                    className="w-auto"
                                    onChange={(event) =>
                                        event.currentTarget.form?.requestSubmit()
                                    }
                                >
                                    <option value="">Any method</option>
                                    {[
                                        'GET',
                                        'POST',
                                        'PUT',
                                        'PATCH',
                                        'DELETE',
                                        'OPTIONS',
                                    ].map((m) => (
                                        <option key={m} value={m}>
                                            {m}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </Form>

                            {endpoints.data.length === 0 ? (
                                <EmptyState
                                    icon={RouteIcon}
                                    title="No endpoints match"
                                    description="Try another method."
                                />
                            ) : (
                                <ul className="divide-y rounded-lg border bg-card">
                                    {endpoints.data.map((e) => (
                                        <li key={e.id}>
                                            <Link
                                                href={EndpointController.show({
                                                    project: project.id,
                                                    endpoint: e.id,
                                                })}
                                                className="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2 text-sm transition-[color,background-color,box-shadow] outline-none hover:bg-muted/50 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:ring-inset"
                                            >
                                                <span className="flex gap-1">
                                                    {e.methods.map((m) => (
                                                        <MethodBadge
                                                            key={m}
                                                            method={m}
                                                        />
                                                    ))}
                                                </span>
                                                <code
                                                    title={e.uri}
                                                    className="min-w-0 truncate font-mono text-code font-medium"
                                                >
                                                    {e.domain
                                                        ? `${e.domain}`
                                                        : ''}
                                                    {e.uri}
                                                </code>
                                                {e.name && (
                                                    <span className="text-xs text-muted-foreground">
                                                        {e.name}
                                                    </span>
                                                )}
                                                <span className="ml-auto flex items-center gap-2">
                                                    {analysisEnabled ? (
                                                        <AuthBadge
                                                            status={
                                                                e.analysisAuth
                                                            }
                                                        />
                                                    ) : (
                                                        e.auth ===
                                                            'required' && (
                                                            <span className="text-xs text-muted-foreground">
                                                                auth
                                                            </span>
                                                        )
                                                    )}
                                                    <span className="hidden text-xs text-muted-foreground md:inline">
                                                        {e.handlerType ===
                                                        'closure'
                                                            ? 'closure'
                                                            : e.controller
                                                              ? `${e.controller.split('\\').pop()}@${e.action}`
                                                              : e.handlerType}
                                                    </span>
                                                    <ResolutionBadge
                                                        resolution={
                                                            e.resolution
                                                        }
                                                    />
                                                </span>
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            )}

                            {endpoints.last_page > 1 && (
                                <div className="flex items-center gap-2 text-sm">
                                    {endpoints.prev_page_url && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            asChild
                                        >
                                            <Link
                                                href={endpoints.prev_page_url}
                                                preserveScroll
                                            >
                                                Previous
                                            </Link>
                                        </Button>
                                    )}
                                    <span className="text-muted-foreground">
                                        Page {endpoints.current_page} of{' '}
                                        {endpoints.last_page} ·{' '}
                                        {endpoints.total} endpoints
                                    </span>
                                    {endpoints.next_page_url && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            asChild
                                        >
                                            <Link
                                                href={endpoints.next_page_url}
                                                preserveScroll
                                            >
                                                Next
                                            </Link>
                                        </Button>
                                    )}
                                </div>
                            )}

                            {findings.length > 0 && (
                                <section className="flex flex-col gap-3">
                                    <Heading
                                        variant="small"
                                        title={`Findings (${scan.findingCount})`}
                                        icon={ListChecks}
                                        description="Things the analyzer could not resolve or had to assume."
                                    />
                                    <ul className="divide-y rounded-lg border bg-card text-sm">
                                        {findings.map((f, i) => (
                                            <li
                                                key={i}
                                                className="space-y-1 px-3 py-2"
                                            >
                                                <p>
                                                    <span
                                                        className={
                                                            f.severity ===
                                                            'error'
                                                                ? 'font-medium text-destructive'
                                                                : f.severity ===
                                                                    'warning'
                                                                  ? 'font-medium text-warning'
                                                                  : 'font-medium'
                                                        }
                                                    >
                                                        {f.severity}
                                                    </span>{' '}
                                                    {f.message}
                                                </p>
                                                {f.source && (
                                                    <p className="text-xs text-muted-foreground">
                                                        <code>
                                                            {f.source.path}
                                                            {f.source.line
                                                                ? `:${f.source.line}`
                                                                : ''}
                                                        </code>
                                                        {f.source
                                                            .expression && (
                                                            <code className="mt-1 block truncate">
                                                                {
                                                                    f.source
                                                                        .expression
                                                                }
                                                            </code>
                                                        )}
                                                    </p>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                </section>
                            )}
                        </>
                    )}
            </div>
        </>
    );
}

Endpoints.layout = {
    breadcrumbs: [{ title: 'Projects', href: ProjectController.index() }],
};
