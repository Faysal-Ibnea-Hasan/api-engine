import { router, usePoll } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import AnalysisController from '@/actions/App/Http/Controllers/Analysis/AnalysisController';
import {
    CurlBlock,
    PayloadView,
    ResponseList,
} from '@/components/contract-views';
import Heading from '@/components/heading';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import type {
    AnalysisField,
    AuthStatus,
    AnalysisResult,
    Confidence,
    EndpointAnalysisSummary,
    EvidenceExcerpt,
} from '@/types';

type Props = {
    projectId: string;
    endpointId: string;
    analysis: EndpointAnalysisSummary | null;
    canAnalyze: boolean;
    canViewSource: boolean;
};

const TABS = [
    'Summary',
    'Payload',
    'Response',
    'Errors',
    'Contract',
    'Validation',
    'Evidence',
    'Unknowns',
] as const;
type Tab = (typeof TABS)[number];

const LOCATION: Record<AnalysisField['location'], string> = {
    path: 'Path',
    query: 'Query',
    body: 'Body',
    query_or_body: 'Query or body',
};

function ConfidenceBadge({ value }: { value: Confidence | 'partial' }) {
    const label = {
        verified: 'Verified',
        inferred: 'Inferred',
        partial: 'Partly verified',
        unknown: 'Unknown',
    }[value];

    return (
        <Badge
            variant={value === 'verified' ? 'secondary' : 'outline'}
            className={cn(value === 'unknown' && 'border-dashed')}
            title={
                value === 'verified'
                    ? 'Read directly from the code'
                    : value === 'inferred'
                      ? "Laravel's usual behavior, not read from this code"
                      : 'Decided at runtime; not guessed'
            }
        >
            {label}
        </Badge>
    );
}

function EvidenceLinks({
    ids,
    onOpen,
}: {
    ids?: string[];
    onOpen: (id: string) => void;
}) {
    if (!ids?.length) {
        return null;
    }

    return (
        <span className="ml-1 inline-flex flex-wrap gap-1">
            {ids.map((id) => (
                <button
                    key={id}
                    type="button"
                    onClick={() => onOpen(id)}
                    className="rounded border px-1 font-mono text-[10px] text-muted-foreground outline-none hover:bg-muted focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    aria-label={`Show source evidence ${id}`}
                >
                    {id}
                </button>
            ))}
        </span>
    );
}

const AUTH_LABEL: Record<AuthStatus, string> = {
    required: 'Required',
    required_unverified:
        'Required, but the token is not verified on every path',
    guest_or_user: 'Guest or signed-in user',
    not_required: 'Not required',
    unknown: "Can't tell",
    not_detected:
        "Can't tell (analyzed before middleware were read; analyze again)",
};

export function AuthLabel({ status }: { status: AuthStatus }) {
    return (
        <span
            className={cn(
                status === 'required_unverified' && 'font-medium text-warning',
                (status === 'unknown' || status === 'not_detected') &&
                    'text-muted-foreground',
            )}
        >
            {AUTH_LABEL[status]}
        </span>
    );
}

function requiredLabel(f: AnalysisField) {
    return {
        required: 'Required',
        conditional: 'Sometimes required',
        optional: 'Optional',
        unknown: 'Not stated',
    }[f.required];
}

export default function EndpointAnalysis({
    projectId,
    endpointId,
    analysis,
    canAnalyze,
    canViewSource,
}: Props) {
    const [tab, setTab] = useState<Tab>('Summary');
    const [excerptState, setExcerptState] = useState<{
        analysisId: string;
        data: EvidenceExcerpt;
    } | null>(null);
    const [excerptError, setExcerptError] = useState<string | null>(null);
    const [excerptLoading, setExcerptLoading] = useState(false);
    const evidenceCache = useRef(new Map<string, EvidenceExcerpt>());
    const pendingEvidence = useRef(new Map<string, Promise<EvidenceExcerpt>>());
    const evidenceRequest = useRef(0);
    const active = analysis?.active ?? false;
    const excerpt =
        excerptState !== null && excerptState.analysisId === analysis?.id
            ? excerptState.data
            : null;
    const { start, stop } = usePoll(
        1500,
        { only: ['analysis'] },
        { autoStart: false },
    );

    useEffect(() => {
        if (active) {
            start();
        } else {
            stop();
        }
    }, [active, start, stop]);

    const reanalyze = () =>
        router.post(
            AnalysisController.store.url({
                project: projectId,
                endpoint: endpointId,
            }),
            { reanalyze: true },
            { preserveScroll: true },
        );

    const analyze = () =>
        router.post(
            AnalysisController.store.url({
                project: projectId,
                endpoint: endpointId,
            }),
            {},
            { preserveScroll: true },
        );

    const openEvidence = (id: string) => {
        if (!analysis || !canViewSource) {
            return;
        }

        setTab('Evidence');
        setExcerptError(null);
        const cacheKey = `${analysis.id}:${id}`;
        const cached = evidenceCache.current.get(cacheKey);

        if (cached) {
            evidenceRequest.current++;
            setExcerptState({ analysisId: analysis.id, data: cached });
            setExcerptLoading(false);

            return;
        }

        const request = ++evidenceRequest.current;
        setExcerptLoading(true);
        const pending =
            pendingEvidence.current.get(cacheKey) ??
            fetch(
                AnalysisController.evidence.url({
                    project: projectId,
                    analysis: analysis.id,
                    evidence: id,
                }),
                { headers: { Accept: 'application/json' } },
            )
                .then((r) => (r.ok ? r.json() : Promise.reject(r.status)))
                .then((data: EvidenceExcerpt) => {
                    evidenceCache.current.set(cacheKey, data);

                    return data;
                })
                .finally(() => pendingEvidence.current.delete(cacheKey));

        pendingEvidence.current.set(cacheKey, pending);
        pending
            .then((data: EvidenceExcerpt) => {
                if (evidenceRequest.current === request) {
                    setExcerptState({ analysisId: analysis.id, data });
                }
            })
            .catch(() => {
                if (evidenceRequest.current === request) {
                    setExcerptError('This source excerpt could not be loaded.');
                }
            })
            .finally(() => {
                if (evidenceRequest.current === request) {
                    setExcerptLoading(false);
                }
            });
    };

    const result = analysis?.result ?? null;
    const canRun =
        canAnalyze &&
        !active &&
        (analysis === null ||
            analysis.stale ||
            analysis.status === 'failed' ||
            analysis.status === 'cancelled');

    return (
        <section className="space-y-4 rounded-lg border bg-card p-4">
            <div className="flex flex-wrap items-start gap-3">
                <Heading
                    variant="small"
                    title="Request and response contract"
                    description="Read from the code without running it. Responses are what the code returns, not captured traffic."
                />
                {canRun && (
                    <Button className="ml-auto" onClick={analyze}>
                        {analysis ? 'Analyze again' : 'Analyze'}
                    </Button>
                )}
                {!canRun &&
                    canAnalyze &&
                    !active &&
                    (analysis?.status === 'completed' ||
                        analysis?.status === 'partial') && (
                        <Button
                            variant="outline"
                            className="ml-auto"
                            onClick={reanalyze}
                            title="Read the code again and replace this result. Existing documentation drafts keep their own copy."
                        >
                            Reanalyze
                        </Button>
                    )}
            </div>

            {analysis === null && (
                <p className="text-sm text-muted-foreground">
                    {canAnalyze
                        ? 'Not analyzed yet.'
                        : 'Not analyzed yet. A Developer, Maintainer or Owner can run the analysis.'}
                </p>
            )}

            {active && (
                <p className="flex items-center gap-2 text-sm text-muted-foreground">
                    <Spinner /> Analyzing… ({analysis?.status})
                </p>
            )}

            {analysis?.status === 'failed' && (
                <Alert variant="destructive">
                    <AlertDescription>
                        <p>
                            {analysis.failureCode === 'analyzer_mismatch'
                                ? 'The queue worker is running an older version of the analyzer, so its result was not saved. Restart the queue worker, then analyze again.'
                                : `The analysis failed (${analysis.failureCode ?? 'error'}). No partial result was saved.`}{' '}
                            {canAnalyze &&
                                analysis.failureCode !== 'analyzer_mismatch' &&
                                'You can run it again.'}
                        </p>
                    </AlertDescription>
                </Alert>
            )}

            {analysis?.stale && !active && (
                <Alert variant="warning" role="note">
                    <TriangleAlert aria-hidden="true" />
                    <AlertDescription>
                        This result was made by an older analyzer version and
                        has no payload, response or error bodies. Analyze again
                        to get them (restart the queue worker first if it has
                        been running since before the update).
                    </AlertDescription>
                </Alert>
            )}

            {result && analysis && (
                <AnalysisTabs
                    tab={tab}
                    setTab={setTab}
                    analysis={analysis}
                    result={result}
                    canViewSource={canViewSource}
                    openEvidence={openEvidence}
                    excerpt={excerpt}
                    excerptError={excerptError}
                    excerptLoading={excerptLoading}
                    projectId={projectId}
                />
            )}
        </section>
    );
}

function AnalysisTabs({
    tab,
    setTab,
    analysis,
    result,
    canViewSource,
    openEvidence,
    excerpt,
    excerptError,
    excerptLoading,
    projectId,
}: {
    projectId: string;
    tab: Tab;
    setTab: (t: Tab) => void;
    analysis: EndpointAnalysisSummary;
    result: AnalysisResult;
    canViewSource: boolean;
    openEvidence: (id: string) => void;
    excerpt: EvidenceExcerpt | null;
    excerptError: string | null;
    excerptLoading: boolean;
}) {
    const contract = result.contract;
    // Payload/Response/Errors come from the followed contract (analyzer v6+); older results don't have it.
    const tabs = TABS.filter(
        (t) =>
            (t !== 'Evidence' || canViewSource) &&
            (contract !== undefined ||
                !['Payload', 'Response', 'Errors'].includes(t)),
    );
    const validated = result.request.fields.filter((f) => f.rules.length > 0);
    const ev = (ids?: string[]) => (
        <EvidenceLinks ids={ids} onOpen={openEvidence} />
    );

    return (
        <div className="space-y-4">
            <div
                role="tablist"
                aria-label="Analysis sections"
                className="flex flex-wrap gap-1 border-b"
            >
                {tabs.map((t) => (
                    <button
                        key={t}
                        role="tab"
                        type="button"
                        aria-selected={tab === t}
                        onClick={() => setTab(t)}
                        className={cn(
                            '-mb-px border-b-2 px-3 py-1.5 text-sm outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                            tab === t
                                ? 'border-primary font-medium'
                                : 'border-transparent text-muted-foreground hover:text-foreground',
                        )}
                    >
                        {t}
                        {t === 'Unknowns' && result.unresolved.length > 0
                            ? ` (${result.unresolved.length})`
                            : ''}
                    </button>
                ))}
            </div>

            <div role="tabpanel" className="text-sm">
                {tab === 'Summary' && (
                    <div className="space-y-4">
                        {contract ? (
                            <CurlBlock
                                projectId={projectId}
                                curl={contract.curl}
                            />
                        ) : (
                            <p className="text-muted-foreground">
                                Analyze again to get the curl command, payload
                                and every response body.
                            </p>
                        )}
                        <dl className="grid grid-cols-[max-content_1fr] gap-x-6 gap-y-2">
                            <dt className="text-muted-foreground">
                                Request fields
                            </dt>
                            <dd>{analysis.fieldCount}</dd>
                            <dt className="text-muted-foreground">Responses</dt>
                            <dd>
                                {contract
                                    ? [
                                          ...new Set(
                                              [
                                                  ...contract.success,
                                                  ...contract.errors,
                                              ]
                                                  .map((r) => r.status)
                                                  .filter(
                                                      (s): s is number =>
                                                          s !== null,
                                                  ),
                                          ),
                                      ]
                                          .sort((a, b) => a - b)
                                          .join(', ')
                                    : [
                                          ...new Set(
                                              result.responses
                                                  .map((r) => r.status)
                                                  .filter((s) => s !== null),
                                          ),
                                      ].join(', ') || 'none found'}
                            </dd>
                            <dt className="text-muted-foreground">
                                Authentication
                            </dt>
                            <dd title={result.auth.reason}>
                                <AuthLabel status={result.auth.status} />
                            </dd>
                        </dl>
                    </div>
                )}

                {tab === 'Payload' && contract && (
                    <PayloadView payload={contract.payload} />
                )}

                {tab === 'Response' && contract && (
                    <ResponseList
                        responses={contract.success}
                        empty="No successful response could be followed."
                    />
                )}

                {tab === 'Errors' && contract && (
                    <ResponseList
                        responses={contract.errors}
                        empty="No error responses were found."
                    />
                )}

                {tab === 'Contract' && (
                    <div className="space-y-5">
                        <div className="space-y-2">
                            <h3 className="font-medium">Headers</h3>
                            {result.request.headers.length === 0 ? (
                                <p className="text-muted-foreground">
                                    None found in the code.
                                </p>
                            ) : (
                                <ul className="space-y-1">
                                    {result.request.headers.map((h) => (
                                        <li
                                            key={h.name}
                                            className="flex flex-wrap items-center gap-2"
                                        >
                                            <code>{h.name}</code>
                                            {h.value && (
                                                <code className="text-muted-foreground">
                                                    {h.value}
                                                </code>
                                            )}
                                            <ConfidenceBadge value={h.status} />
                                            {h.reason && (
                                                <span className="text-muted-foreground">
                                                    {h.reason}
                                                </span>
                                            )}
                                            {ev(h.evidence)}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>

                        <div className="space-y-2">
                            <h3 className="font-medium">Request fields</h3>
                            {result.request.fields.length === 0 ? (
                                <p className="text-muted-foreground">
                                    None found in the code.
                                </p>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-left">
                                        <thead className="text-xs text-muted-foreground">
                                            <tr>
                                                <th className="py-1 pr-3">
                                                    Field
                                                </th>
                                                <th className="py-1 pr-3">
                                                    In
                                                </th>
                                                <th className="py-1 pr-3">
                                                    Type
                                                </th>
                                                <th className="py-1 pr-3">
                                                    Required
                                                </th>
                                                <th className="py-1">
                                                    Confidence
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {result.request.fields.map((f) => (
                                                <tr
                                                    key={f.location + f.name}
                                                    className="border-t align-top"
                                                >
                                                    <td className="py-1.5 pr-3">
                                                        <code>{f.name}</code>
                                                        {f.binds && (
                                                            <div className="text-xs text-muted-foreground">
                                                                binds{' '}
                                                                {f.binds
                                                                    .split('\\')
                                                                    .pop()}
                                                            </div>
                                                        )}
                                                    </td>
                                                    <td className="py-1.5 pr-3">
                                                        {LOCATION[f.location]}
                                                    </td>
                                                    <td className="py-1.5 pr-3">
                                                        {[
                                                            ...f.types,
                                                            ...f.formats,
                                                        ].join(', ') || '—'}
                                                        {f.nullable &&
                                                            ', nullable'}
                                                    </td>
                                                    <td className="py-1.5 pr-3">
                                                        {requiredLabel(f)}
                                                    </td>
                                                    <td className="py-1.5">
                                                        <ConfidenceBadge
                                                            value={
                                                                f.source ===
                                                                'input_read'
                                                                    ? 'inferred'
                                                                    : f.status
                                                            }
                                                        />
                                                        {ev(f.evidence)}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>
                    </div>
                )}

                {tab === 'Validation' &&
                    (validated.length === 0 ? (
                        <p className="text-muted-foreground">
                            No validation rules found for this action.
                        </p>
                    ) : (
                        <ul className="space-y-3">
                            {validated.map((f) => (
                                <li
                                    key={f.location + f.name}
                                    className="space-y-1"
                                >
                                    <div className="flex flex-wrap items-center gap-2">
                                        <code className="font-semibold">
                                            {f.name}
                                        </code>
                                        <span className="text-muted-foreground">
                                            {requiredLabel(f)}
                                            {f.conditional &&
                                                ' · set in a branch'}
                                            {f.sometimes &&
                                                ' · only checked when present'}
                                        </span>
                                        <ConfidenceBadge value={f.status} />
                                        {ev(f.evidence)}
                                    </div>
                                    <div className="flex flex-wrap gap-1">
                                        {f.rules.map((r, i) => (
                                            <code
                                                key={i}
                                                title={r.note}
                                                className={cn(
                                                    'rounded border px-1 text-xs',
                                                    r.status !== 'verified' &&
                                                        'border-dashed text-muted-foreground',
                                                )}
                                            >
                                                {r.rule}
                                                {r.params.length > 0 &&
                                                    `:${r.params.join(',')}`}
                                                {r.status !== 'verified' &&
                                                    ' ?'}
                                            </code>
                                        ))}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    ))}

                {tab === 'Evidence' && canViewSource && (
                    <div className="grid items-start gap-4 md:grid-cols-[minmax(16rem,0.9fr)_minmax(0,1.35fr)]">
                        <ul
                            data-testid="evidence-list"
                            className="max-h-[34rem] space-y-1 overflow-y-auto pr-1"
                        >
                            {(result.evidence ?? []).map((e) => (
                                <li key={e.id}>
                                    <button
                                        type="button"
                                        data-evidence-id={e.id}
                                        onMouseEnter={() => openEvidence(e.id)}
                                        onFocus={() => openEvidence(e.id)}
                                        onClick={() => openEvidence(e.id)}
                                        aria-pressed={excerpt?.id === e.id}
                                        className={cn(
                                            'block w-full min-w-0 rounded-md border border-transparent px-2.5 py-2 text-left transition-colors outline-none hover:border-border hover:bg-muted/60 focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50',
                                            excerpt?.id === e.id &&
                                                'border-border bg-muted',
                                        )}
                                    >
                                        <span className="flex items-center gap-2">
                                            <code className="text-xs text-muted-foreground">
                                                {e.id}
                                            </code>
                                            <span className="text-xs text-muted-foreground">
                                                {e.kind.replace('_', ' ')}
                                            </span>
                                        </span>
                                        <code
                                            className="mt-0.5 block truncate text-xs"
                                            title={`${e.path}:${e.lines[0]}${e.lines[1] !== e.lines[0] ? `–${e.lines[1]}` : ''}`}
                                        >
                                            {e.path}:{e.lines[0]}
                                            {e.lines[1] !== e.lines[0] &&
                                                `–${e.lines[1]}`}
                                        </code>
                                    </button>
                                </li>
                            ))}
                        </ul>

                        <div
                            data-testid="evidence-preview"
                            className="min-w-0 md:sticky md:top-4 md:self-start"
                        >
                            {excerptLoading && (
                                <p className="mb-2 flex items-center gap-2 text-xs text-muted-foreground">
                                    <Spinner className="size-3" /> Loading
                                    source preview…
                                </p>
                            )}
                            {excerptError && (
                                <Alert variant="destructive">
                                    <AlertDescription>
                                        {excerptError}
                                    </AlertDescription>
                                </Alert>
                            )}
                            {excerpt ? (
                                <EvidencePreview excerpt={excerpt} />
                            ) : !excerptLoading && !excerptError ? (
                                <div className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                                    Hover or focus an evidence item to preview
                                    its source here.
                                </div>
                            ) : null}
                        </div>
                    </div>
                )}

                {tab === 'Unknowns' &&
                    (result.unresolved.length === 0 ? (
                        <p className="text-muted-foreground">
                            Nothing in this action was left unresolved.
                        </p>
                    ) : (
                        <ul className="list-inside list-disc space-y-1 text-muted-foreground">
                            {result.unresolved.map((u) => (
                                <li key={u}>{u}</li>
                            ))}
                        </ul>
                    ))}
            </div>
        </div>
    );
}

function EvidencePreview({ excerpt }: { excerpt: EvidenceExcerpt }) {
    return (
        <figure className="overflow-hidden rounded-lg border bg-muted/20">
            <figcaption className="space-y-1 border-b bg-background px-3 py-2 text-xs">
                <code
                    className="block truncate font-medium"
                    title={excerpt.path}
                >
                    {excerpt.path}
                </code>
                <span className="block text-muted-foreground">
                    {excerpt.symbol ?? 'route file'} · lines {excerpt.lines[0]}
                    {excerpt.lines[1] !== excerpt.lines[0] &&
                        `–${excerpt.lines[1]}`}{' '}
                    · sha256 {excerpt.sha256?.slice(0, 12) ?? 'n/a'}
                    {excerpt.truncated && ' · excerpt shortened'}
                </span>
            </figcaption>
            <pre className="max-h-[30rem] overflow-auto py-2 text-xs">
                <code>
                    {excerpt.excerpt.map((line) => (
                        <span
                            key={line.n}
                            className={cn(
                                'block min-w-max px-3',
                                line.hit && 'bg-warning/15',
                            )}
                        >
                            <span className="mr-3 inline-block w-8 text-right text-muted-foreground select-none">
                                {line.n}
                            </span>
                            {line.text || ' '}
                        </span>
                    ))}
                </code>
            </pre>
        </figure>
    );
}
