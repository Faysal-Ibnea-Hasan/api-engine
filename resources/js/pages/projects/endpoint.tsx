import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import DocumentationController from '@/actions/App/Http/Controllers/Docs/DocumentationController';
import EndpointController from '@/actions/App/Http/Controllers/Discovery/EndpointController';
import ProjectController from '@/actions/App/Http/Controllers/Projects/ProjectController';
import EndpointAnalysis, { AuthLabel } from '@/components/endpoint-analysis';
import Heading from '@/components/heading';
import MethodBadge from '@/components/method-badge';
import ResolutionBadge from '@/components/resolution-badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import type {
    EndpointAnalysisSummary,
    EndpointRow,
    RouteScanSummary,
} from '@/types';

type Props = {
    project: { id: string; name: string };
    endpoint: EndpointRow;
    scan: RouteScanSummary;
    isCurrent: boolean;
    analysisEnabled: boolean;
    analysis: EndpointAnalysisSummary | null;
    canAnalyze: boolean;
    canViewSource: boolean;
    /** none: no draft yet; current: a draft matches this analysis; outdated: the analysis changed since */
    docsState: 'none' | 'current' | 'outdated';
};

export default function ShowEndpoint({
    project,
    endpoint,
    scan,
    isCurrent,
    analysisEnabled,
    analysis,
    canAnalyze,
    canViewSource,
    docsState,
}: Props) {
    const { features, currentWorkspace } = usePage().props;
    const [generating, setGenerating] = useState(false);
    const analysisFinal =
        analysis?.status === 'completed' || analysis?.status === 'partial';
    // The server re-checks all of this (403 / 409); these only decide whether the button is enabled.
    const docsEnabled =
        analysisEnabled &&
        features.static_docs === true &&
        endpoint.handlerType !== 'closure';
    const canGenerateDocs =
        analysisFinal &&
        canAnalyze &&
        currentWorkspace?.can.submit_docs === true;
    const handler =
        endpoint.handlerType === 'closure'
            ? 'Closure (inline function)'
            : endpoint.controller
              ? `${endpoint.controller}@${endpoint.action}`
              : endpoint.handlerType;

    return (
        <>
            <Head title={`${endpoint.methods.join(' ')} ${endpoint.uri}`} />
            <div className="flex w-full flex-col gap-4 p-4 md:p-6">
                <div className="space-y-2">
                    <div className="flex flex-wrap items-center gap-2">
                        {endpoint.methods.map((m) => (
                            <MethodBadge key={m} method={m} />
                        ))}
                        <ResolutionBadge resolution={endpoint.resolution} />
                    </div>
                    <Heading
                        title={`${endpoint.domain ?? ''}${endpoint.uri}`}
                        description={endpoint.name ?? 'Unnamed route'}
                    />
                </div>

                {!isCurrent && (
                    <p className="rounded-lg border p-3 text-sm text-muted-foreground">
                        This endpoint is from an older scan, not the project's
                        current one.
                    </p>
                )}

                <dl className="grid grid-cols-[max-content_1fr] gap-x-6 gap-y-1.5 rounded-lg border p-3 text-sm lg:grid-cols-[max-content_1fr_max-content_1fr]">
                    <dt className="text-muted-foreground">Handler</dt>
                    <dd>
                        <code className="break-all">{handler}</code>
                    </dd>
                    <dt className="text-muted-foreground">Middleware</dt>
                    <dd>
                        {endpoint.middleware.length
                            ? endpoint.middleware.map((m) => (
                                  <code key={m} className="mr-2">
                                      {m}
                                  </code>
                              ))
                            : 'none found'}
                    </dd>
                    <dt className="text-muted-foreground">Authentication</dt>
                    <dd>
                        {analysis?.result ? (
                            <span title={analysis.result.auth.reason}>
                                <AuthLabel
                                    status={analysis.result.auth.status}
                                />
                            </span>
                        ) : analysisEnabled ? (
                            'Not analyzed yet: analyze this endpoint to read its middleware'
                        ) : endpoint.auth === 'required' ? (
                            'Required (auth middleware)'
                        ) : (
                            'Not detected — custom middleware may still require it'
                        )}
                    </dd>
                    <dt className="text-muted-foreground">Group</dt>
                    <dd>
                        {endpoint.group}
                        {endpoint.module && ` · module ${endpoint.module}`}
                    </dd>
                    {endpoint.source && (
                        <>
                            <dt className="text-muted-foreground">
                                Defined in
                            </dt>
                            <dd>
                                <code>
                                    {endpoint.source.path}:
                                    {endpoint.source.line}
                                </code>
                            </dd>
                        </>
                    )}
                    <dt className="text-muted-foreground">Route identity</dt>
                    <dd>
                        <code className="text-xs break-all">
                            {endpoint.routeIdentity}
                        </code>
                    </dd>
                </dl>

                {endpoint.reasons.length > 0 && (
                    <section className="space-y-1 text-sm">
                        <Heading
                            variant="small"
                            title={
                                endpoint.resolution === 'resolved'
                                    ? 'Notes'
                                    : "Why it isn't fully resolved"
                            }
                        />
                        <ul className="list-inside list-disc text-muted-foreground">
                            {endpoint.reasons.map((r) => (
                                <li key={r}>{r}</li>
                            ))}
                        </ul>
                    </section>
                )}

                {endpoint.source?.expression && (
                    <section className="space-y-1">
                        <Heading variant="small" title="Route definition" />
                        <pre className="overflow-x-auto rounded-lg border bg-muted/40 p-3 text-xs">
                            <code>{endpoint.source.expression}</code>
                        </pre>
                    </section>
                )}

                {analysisEnabled && endpoint.handlerType !== 'closure' && (
                    <EndpointAnalysis
                        projectId={project.id}
                        endpointId={endpoint.id}
                        analysis={analysis}
                        canAnalyze={canAnalyze}
                        canViewSource={canViewSource}
                    />
                )}

                {docsEnabled && (
                    <div className="flex flex-wrap items-center gap-3">
                        <Button
                            disabled={!canGenerateDocs || generating}
                            onClick={() =>
                                analysis &&
                                router.post(
                                    DocumentationController.store.url({
                                        project: project.id,
                                        analysis: analysis.id,
                                    }),
                                    {},
                                    {
                                        onStart: () => setGenerating(true),
                                        onFinish: () => setGenerating(false),
                                    },
                                )
                            }
                        >
                            {generating && <Spinner />}
                            {docsState === 'none'
                                ? 'Generate documentation'
                                : 'Regenerate documentation'}
                        </Button>
                        <Button variant="outline" asChild>
                            <Link
                                href={DocumentationController.index.url({
                                    project: project.id,
                                    endpoint: endpoint.id,
                                })}
                            >
                                View documentation
                            </Link>
                        </Button>
                        {!analysisFinal && (
                            <span className="text-sm text-muted-foreground">
                                Analyze this endpoint first: documentation is
                                built from the analysis.
                            </span>
                        )}
                        {analysisFinal && docsState === 'outdated' && (
                            <span className="text-sm text-warning">
                                The analysis changed since the last draft.
                                Regenerate to update it.
                            </span>
                        )}
                        {analysisFinal && docsState === 'current' && (
                            <span className="text-sm text-muted-foreground">
                                The latest draft matches this analysis.
                            </span>
                        )}
                    </div>
                )}

                <Button variant="ghost" asChild className="w-fit">
                    <Link href={EndpointController.index(project.id)}>
                        Back to all endpoints
                    </Link>
                </Button>
                <p className="text-xs text-muted-foreground">
                    Scan {scan.analyzerVersion} · root {scan.applicationRoot}
                </p>
            </div>
        </>
    );
}

ShowEndpoint.layout = {
    breadcrumbs: [{ title: 'Projects', href: ProjectController.index() }],
};
