import { router } from '@inertiajs/react';
import { Search, TriangleAlert, Workflow } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import WorkflowController from '@/actions/App/Http/Controllers/Analysis/WorkflowController';
import Heading from '@/components/heading';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { WorkflowExplanationView } from '@/components/workflow-explanation';
import { cn } from '@/lib/utils';
import type {
    AnalysisResult,
    WorkflowData,
    WorkflowEdge,
    WorkflowEdgeKind,
    WorkflowGraph,
    WorkflowNode,
    WorkflowNodeType,
} from '@/types';

const NODE_TYPE: Record<WorkflowNodeType, string> = {
    route: 'Route',
    controller: 'Controller',
    service: 'Service',
    model: 'Model',
    job: 'Job',
    event: 'Event',
    mail: 'Mail',
    notification: 'Notification',
    resource: 'Resource',
    form_request: 'Form request',
    exception: 'Exception',
    external: 'Library',
    unresolved: 'Decided at runtime',
};

const EDGE_KIND: Record<WorkflowEdgeKind, string> = {
    handled_by: 'handled by',
    validates_with: 'validates with',
    calls: 'calls',
    reads_from: 'reads',
    writes_to: 'writes',
    dispatches: 'dispatches',
    responds_with: 'responds with',
    throws: 'throws',
};

/** Why an edge was not followed, in a few words; the node label explains it in full. */
const REASON: Record<string, string> = {
    container_binding: 'Runtime binding',
    container_name: 'Runtime service name',
    dynamic_method: 'Dynamic method',
    dynamic_class: 'Dynamic class',
    dynamic_function: 'Dynamic function',
    dynamic_dispatch: 'Dynamic dispatch',
    abstract_method: 'Polymorphic call',
    outside_snapshot: 'Outside the source',
    class_limit: 'Class limit reached',
    missing_class: 'Not in the source',
    no_action: 'No controller action',
    cycle: 'Recursive call',
    bound_depth: 'Depth bound reached',
};

const reasonLabel = (code: string) => REASON[code] ?? code.replace(/_/g, ' ');

type TreeRow = {
    key: string;
    depth: number;
    node: WorkflowNode;
    edge: WorkflowEdge | null;
    /** already shown above (a second path or a recursion): not expanded again */
    repeat: boolean;
};

/** Depth-first from the route; each node expands once. */
export function workflowRows(graph: WorkflowGraph): TreeRow[] {
    const nodes = new Map(graph.nodes.map((n) => [n.id, n]));
    const out = new Map<string, WorkflowEdge[]>();

    for (const e of graph.edges) {
        out.set(e.from, [...(out.get(e.from) ?? []), e]);
    }

    const rows: TreeRow[] = [];
    const expanded = new Set<string>();
    const visit = (id: string, edge: WorkflowEdge | null, depth: number) => {
        const node = nodes.get(id);

        if (!node) {
            return;
        }

        const repeat = expanded.has(id);
        rows.push({ key: `${rows.length}`, depth, node, edge, repeat });

        if (repeat) {
            return;
        }

        expanded.add(id);

        for (const e of out.get(id) ?? []) {
            visit(e.to, e, depth + 1);
        }
    };

    if (graph.root) {
        visit(graph.root, null, 0);
    }

    // Anything not reachable from the route (should not happen) is still listed.
    for (const n of graph.nodes) {
        if (!expanded.has(n.id)) {
            visit(n.id, null, 0);
        }
    }

    return rows;
}

/** Rows matching the query, with the path that leads to each. */
export function filterRows(rows: TreeRow[], query: string): TreeRow[] {
    const q = query.trim().toLowerCase();

    if (q === '') {
        return rows;
    }

    const keep = new Set<number>();
    const path: number[] = [];

    rows.forEach((row, i) => {
        path.length = row.depth;
        path[row.depth] = i;
        const text = [
            row.node.label,
            row.node.class ?? '',
            NODE_TYPE[row.node.type],
            row.edge ? EDGE_KIND[row.edge.kind] : '',
            row.edge?.reason ? reasonLabel(row.edge.reason) : '',
            ...(row.edge?.refs ?? []).map((r) => r.path),
        ]
            .join(' ')
            .toLowerCase();

        if (text.includes(q)) {
            path.forEach((p) => keep.add(p));
        }
    });

    return rows.filter((_, i) => keep.has(i));
}

function NodeBadge({ type }: { type: WorkflowNodeType }) {
    return (
        <Badge
            variant={
                type === 'unresolved'
                    ? 'warning'
                    : type === 'exception'
                      ? 'destructive'
                      : type === 'job' ||
                          type === 'event' ||
                          type === 'mail' ||
                          type === 'notification'
                        ? 'info'
                        : 'secondary'
            }
        >
            {NODE_TYPE[type]}
        </Badge>
    );
}

function Row({ row, canViewSource }: { row: TreeRow; canViewSource: boolean }) {
    const { node, edge } = row;

    return (
        <li
            className="space-y-1 border-l py-1.5 pl-3"
            style={{ marginLeft: `${row.depth * 1.25}rem` }}
            data-node-type={node.type}
        >
            <div className="flex flex-wrap items-center gap-2">
                {edge && (
                    <span className="text-xs text-muted-foreground">
                        {EDGE_KIND[edge.kind]}
                    </span>
                )}
                <NodeBadge type={node.type} />
                <span
                    className={cn(
                        'min-w-0 break-all',
                        node.type !== 'unresolved' && 'font-mono text-xs',
                    )}
                    title={node.class ?? undefined}
                >
                    {node.label}
                </span>
                {edge?.conditional && (
                    <Badge
                        variant="outline"
                        title="Runs only on some paths: inside a branch, loop or catch"
                    >
                        Conditional
                    </Badge>
                )}
                {edge?.async && (
                    <Badge
                        variant="info"
                        title="Queued: runs later on a worker"
                    >
                        Async
                    </Badge>
                )}
                {edge?.reason && (
                    <Badge variant="warning" className="border-dashed">
                        {reasonLabel(edge.reason)}
                    </Badge>
                )}
                {row.repeat && (
                    <span className="text-xs text-muted-foreground">
                        shown above
                    </span>
                )}
            </div>
            {edge?.note && (
                <p className="text-xs text-muted-foreground">{edge.note}</p>
            )}
            {canViewSource && edge?.expression && (
                <code className="block text-xs break-all text-muted-foreground">
                    {edge.expression}
                </code>
            )}
            {canViewSource && (edge?.refs ?? []).length > 0 && (
                <div className="flex flex-wrap gap-2">
                    {edge?.refs?.map((r) => (
                        <code
                            key={r.id}
                            className="rounded border px-1 text-[10px] text-muted-foreground"
                        >
                            {r.path}:{r.lines[0]}
                        </code>
                    ))}
                </div>
            )}
            {!row.repeat && node.fields && node.fields.length > 0 && (
                <ul className="flex flex-wrap gap-1 pt-0.5">
                    {node.fields.map((f) => (
                        <li key={f.name}>
                            <code
                                title={
                                    f.presence === 'always'
                                        ? 'Always present'
                                        : (f.condition ??
                                          'Present on some paths')
                                }
                                className={cn(
                                    'rounded border px-1 text-xs',
                                    f.presence === 'conditional' &&
                                        'border-dashed text-muted-foreground',
                                )}
                            >
                                {f.name}
                                {f.presence === 'conditional' &&
                                    ` · ${f.condition ?? 'some paths'}`}
                            </code>
                        </li>
                    ))}
                </ul>
            )}
        </li>
    );
}

/** What the trace adds over the basic analysis: counts only, no invented confidence. */
function CoverageComparison({
    graph,
    basic,
}: {
    graph: WorkflowGraph;
    basic: AnalysisResult;
}) {
    const count = (fn: (e: WorkflowEdge) => boolean) =>
        graph.edges.filter(fn).length;
    const classes = graph.nodes.filter(
        (n) => n.type !== 'route' && n.type !== 'unresolved',
    ).length;
    const rows: [string, string, string][] = [
        ['Classes identified', `${basic.dependencies.length}`, `${classes}`],
        [
            'Method calls followed',
            'not traced',
            `${graph.coverage.followed_calls}`,
        ],
        [
            'Model reads / writes',
            'not traced',
            `${count((e) => e.kind === 'reads_from')} / ${count((e) => e.kind === 'writes_to')}`,
        ],
        [
            'Jobs, events, mail, notifications',
            'not traced',
            `${count((e) => e.kind === 'dispatches')} (${graph.coverage.async} queued)`,
        ],
        [
            'Conditional relationships',
            'not traced',
            `${graph.coverage.conditional}`,
        ],
        [
            'Calls decided at runtime',
            'not traced',
            `${graph.coverage.unresolved}`,
        ],
    ];

    return (
        <div className="overflow-x-auto">
            <table className="w-full max-w-xl text-left text-sm">
                <thead className="text-xs text-muted-foreground">
                    <tr>
                        <th className="py-1 pr-3 font-normal">Coverage</th>
                        <th className="py-1 pr-3 font-normal">
                            Basic analysis
                        </th>
                        <th className="py-1 font-normal">Workflow trace</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map(([label, b, a]) => (
                        <tr key={label} className="border-t">
                            <td className="py-1 pr-3 text-muted-foreground">
                                {label}
                            </td>
                            <td className="py-1 pr-3">{b}</td>
                            <td className="py-1">{a}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/** The traced graph: coverage, bounds, and the searchable dependency tree. */
export function WorkflowView({
    graph,
    basic,
    canViewSource,
    query,
    onQuery,
}: {
    graph: WorkflowGraph;
    basic: AnalysisResult;
    canViewSource: boolean;
    query: string;
    onQuery: (q: string) => void;
}) {
    const rows = useMemo(() => workflowRows(graph), [graph]);
    const shown = filterRows(rows, query);

    return (
        <div className="space-y-4">
            <CoverageComparison graph={graph} basic={basic} />

            {!graph.coverage.complete && (
                <Alert variant="warning" role="note">
                    <TriangleAlert aria-hidden="true" />
                    <AlertDescription>
                        <p>
                            Partial trace.
                            {graph.coverage.unresolved > 0 &&
                                ` ${graph.coverage.unresolved} call${graph.coverage.unresolved === 1 ? ' is' : 's are'} decided at runtime and shown below with the reason; no target was guessed.`}
                        </p>
                        {graph.coverage.bounds.length > 0 && (
                            <ul className="list-inside list-disc">
                                {graph.coverage.bounds.map((b) => (
                                    <li key={b.code}>{b.detail}</li>
                                ))}
                            </ul>
                        )}
                    </AlertDescription>
                </Alert>
            )}

            <label className="relative block max-w-sm">
                <span className="sr-only">Search the workflow</span>
                <Search
                    aria-hidden="true"
                    className="pointer-events-none absolute top-2 left-2.5 size-4 text-muted-foreground"
                />
                <Input
                    type="search"
                    value={query}
                    onChange={(e) => onQuery(e.target.value)}
                    placeholder="Search classes, types, files"
                    className="pl-8"
                />
            </label>

            {shown.length === 0 ? (
                <p className="text-muted-foreground">
                    Nothing in this workflow matches “{query}”.
                </p>
            ) : (
                <ul data-testid="workflow-tree" className="space-y-0.5">
                    {shown.map((row) => (
                        <Row
                            key={row.key}
                            row={row}
                            canViewSource={canViewSource}
                        />
                    ))}
                </ul>
            )}
        </div>
    );
}

/**
 * The explanation as the tab treats it: a 'tracing' row counts as running only while its trace does. One whose
 * finish event was missed stops the spinner and polling and shows the button, whose request hands the trace over.
 */
export function liveExplanation(
    data: WorkflowData,
): WorkflowData['explanation'] {
    const explanation = data.explanation;

    return explanation?.stage === 'tracing' && !data.advanced?.active
        ? { ...explanation, stage: null }
        : explanation;
}

/** How often the tab re-reads while a run is in progress (ms), or null once it is final. The trace takes seconds, the AI step minutes. */
export function pollDelay(data: WorkflowData): number | null {
    const stage = liveExplanation(data)?.stage ?? null;

    if (data.advanced?.active === true || stage === 'tracing') {
        return 1500;
    }

    return stage === 'writing' ? 5000 : null;
}

/** Everything the tab shows once its data has loaded: the AI explanation first, the trace folded below it. */
export function WorkflowContent({
    data,
    basic,
    canViewSource,
    query,
    onQuery,
    onExplain,
}: {
    data: WorkflowData;
    basic: AnalysisResult;
    canViewSource: boolean;
    query: string;
    onQuery: (q: string) => void;
    onExplain: (again: boolean) => void;
}) {
    const advanced = data.advanced;
    const final =
        advanced?.status === 'completed' || advanced?.status === 'partial';

    return (
        <div className="space-y-4">
            <WorkflowExplanationView
                explanation={liveExplanation(data)}
                ai={data.ai}
                canExplain={data.canExplain}
                onExplain={onExplain}
            />

            {advanced !== null && (
                <details className="space-y-4">
                    <summary className="cursor-pointer rounded-sm text-sm text-muted-foreground outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50">
                        Show trace details
                    </summary>

                    <div className="mt-4 space-y-4">
                        {advanced.active && (
                            <p className="flex items-center gap-2 text-muted-foreground">
                                <Spinner /> Tracing the code…
                            </p>
                        )}

                        {advanced.status === 'failed' && (
                            <Alert variant="destructive">
                                <AlertDescription>
                                    <p>
                                        {advanced.failureCode ===
                                        'analyzer_mismatch'
                                            ? 'The queue worker is running an older analyzer that cannot trace workflows, so its result was not saved. Restart the queue worker, then explain again.'
                                            : `Tracing failed (${advanced.failureCode ?? 'error'}). No partial result was saved.`}
                                    </p>
                                </AlertDescription>
                            </Alert>
                        )}

                        {advanced.stale && !advanced.active && (
                            <Alert variant="warning" role="note">
                                <TriangleAlert aria-hidden="true" />
                                <AlertDescription>
                                    This trace was made by an older analyzer
                                    version. Explain again for the current one.
                                </AlertDescription>
                            </Alert>
                        )}

                        {data.graph && final && (
                            <WorkflowView
                                graph={data.graph}
                                basic={basic}
                                canViewSource={canViewSource}
                                query={query}
                                onQuery={onQuery}
                            />
                        )}
                    </div>
                </details>
            )}
        </div>
    );
}

/** The Workflow tab: loads, starts and polls the advanced analysis and its AI explanation. */
export default function WorkflowPanel({
    projectId,
    analysisId,
    basic,
    canViewSource,
}: {
    projectId: string;
    analysisId: string;
    basic: AnalysisResult;
    canViewSource: boolean;
}) {
    const [data, setData] = useState<WorkflowData | null>(null);
    const [failed, setFailed] = useState(false);
    const [query, setQuery] = useState('');
    const url = WorkflowController.show.url({
        project: projectId,
        analysis: analysisId,
    });

    const load = useCallback(() => {
        fetch(url, { headers: { Accept: 'application/json' } })
            .then((r) => (r.ok ? r.json() : Promise.reject(r.status)))
            .then((d: WorkflowData) => {
                setData(d);
                setFailed(false);
            })
            .catch(() => setFailed(true));
    }, [url]);

    useEffect(load, [load]);

    // Every role sees the stage of a run in progress; poll until the trace and the explanation are final.
    const delay = data === null ? null : pollDelay(data);

    useEffect(() => {
        if (delay === null) {
            return;
        }

        const timer = setInterval(load, delay);

        return () => clearInterval(timer);
    }, [delay, load]);

    const explain = (again: boolean) =>
        router.post(
            WorkflowController.store.url({
                project: projectId,
                analysis: analysisId,
            }),
            { again },
            { preserveScroll: true, preserveState: true, onSuccess: load },
        );

    return (
        <div className="space-y-4">
            <Heading
                variant="small"
                title="Workflow"
                icon={Workflow}
                description="Services, models, jobs, events and resources this action reaches, followed in the code without running it."
            />

            {data === null && !failed && (
                <p className="flex items-center gap-2 text-muted-foreground">
                    <Spinner /> Loading the workflow…
                </p>
            )}

            {failed && (
                <Alert variant="destructive">
                    <AlertDescription>
                        <p>
                            The workflow could not be loaded.{' '}
                            <button
                                type="button"
                                className="underline"
                                onClick={load}
                            >
                                Try again
                            </button>
                        </p>
                    </AlertDescription>
                </Alert>
            )}

            {data && (
                <WorkflowContent
                    data={data}
                    basic={basic}
                    canViewSource={canViewSource}
                    query={query}
                    onQuery={setQuery}
                    onExplain={explain}
                />
            )}
        </div>
    );
}
