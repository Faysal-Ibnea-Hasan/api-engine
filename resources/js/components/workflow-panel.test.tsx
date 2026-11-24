import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vite-plus/test';
import WorkflowPanel, {
    filterRows,
    workflowRows,
    WorkflowView,
} from '@/components/workflow-panel';
import type { AnalysisResult, WorkflowGraph } from '@/types';

vi.mock('@inertiajs/react', async (orig) => ({
    ...(await orig<typeof import('@inertiajs/react')>()),
    usePage: () => ({ props: { features: { advanced_analysis: true } } }),
}));

const C = 'App\\Http\\Controllers\\OrderController::store';
const S = 'App\\Services\\OrderService::place';

const edge = (
    from: string,
    to: string,
    kind: WorkflowGraph['edges'][number]['kind'],
    extra: Partial<WorkflowGraph['edges'][number]> = {},
): WorkflowGraph['edges'][number] => ({
    from,
    to,
    kind,
    conditional: false,
    resolution: 'resolved',
    reason: null,
    note: null,
    async: null,
    expression: '$this->orders->place($data)',
    refs: [
        {
            id: 'ev-9',
            path: 'app/Http/Controllers/OrderController.php',
            lines: [21, 21],
        },
    ],
    ...extra,
});

const graph: WorkflowGraph = {
    version: 'workflow-graph-v1',
    root: 'route:1',
    nodes: [
        {
            id: 'route:1',
            type: 'route',
            label: 'POST /api/orders',
            class: null,
            method: null,
        },
        {
            id: C,
            type: 'controller',
            label: 'OrderController::store',
            class: 'App\\Http\\Controllers\\OrderController',
            method: 'store',
        },
        {
            id: S,
            type: 'service',
            label: 'OrderService::place',
            class: 'App\\Services\\OrderService',
            method: 'place',
        },
        {
            id: 'App\\Models\\Order',
            type: 'model',
            label: 'Order',
            class: 'App\\Models\\Order',
            method: null,
        },
        {
            id: 'App\\Jobs\\ProcessOrder',
            type: 'job',
            label: 'ProcessOrder',
            class: 'App\\Jobs\\ProcessOrder',
            method: null,
        },
        {
            id: 'App\\Events\\OrderPlaced',
            type: 'event',
            label: 'OrderPlaced',
            class: 'App\\Events\\OrderPlaced',
            method: null,
        },
        {
            id: 'http:404',
            type: 'exception',
            label: 'HTTP 404 (abort)',
            class: 'http:404',
            method: null,
        },
        {
            id: 'R::toArray',
            type: 'resource',
            label: 'OrderResource',
            class: 'App\\Http\\Resources\\OrderResource',
            method: 'toArray',
            fields: [
                {
                    name: 'id',
                    presence: 'always',
                    condition: null,
                    expression: null,
                },
                {
                    name: 'items',
                    presence: 'conditional',
                    condition: 'only when the items relation is loaded',
                    expression: "$this->whenLoaded('items')",
                },
            ],
        },
        {
            id: 'unresolved:container_binding:a',
            type: 'unresolved',
            label: 'Container binding resolved at runtime: App\\Contracts\\PaymentGateway::charge() (no static binding found)',
            class: null,
            method: null,
        },
        {
            id: 'unresolved:dynamic_method:b',
            type: 'unresolved',
            label: 'Dynamic method name: the method is chosen at runtime',
            class: null,
            method: null,
        },
        {
            id: 'App\\Services\\Deep::d14',
            type: 'service',
            label: 'Deep::d14',
            class: 'App\\Services\\Deep',
            method: 'd14',
        },
    ],
    edges: [
        edge('route:1', C, 'handled_by'),
        edge(C, S, 'calls'),
        edge(S, 'App\\Models\\Order', 'writes_to'),
        edge(S, 'App\\Jobs\\ProcessOrder', 'dispatches', {
            conditional: true,
            async: true,
            note: 'Queued: runs later on a worker; its order relative to other work is not known statically',
        }),
        edge(S, 'App\\Events\\OrderPlaced', 'dispatches', { async: false }),
        edge(S, S, 'calls', {
            reason: 'cycle',
            note: 'Recursive call: already on the call path, so it is not followed again',
        }),
        edge(C, 'http:404', 'throws', { conditional: true }),
        edge(C, 'unresolved:container_binding:a', 'calls', {
            resolution: 'unresolved',
            reason: 'container_binding',
        }),
        edge(C, 'unresolved:dynamic_method:b', 'calls', {
            resolution: 'unresolved',
            reason: 'dynamic_method',
            expression: '$this->{$action}()',
        }),
        edge(C, 'App\\Services\\Deep::d14', 'calls', {
            reason: 'bound_depth',
            note: 'Bound reached: call depth 13; not followed further',
        }),
        edge(C, 'R::toArray', 'responds_with'),
    ],
    coverage: {
        complete: false,
        nodes: 11,
        edges: 11,
        followed_calls: 1,
        unresolved: 2,
        conditional: 2,
        async: 1,
        bounds: [
            {
                code: 'depth',
                detail: 'Bound reached: call depth 13; deeper calls were not followed',
            },
        ],
        limits: { depth: 13 },
    },
};

const basic = {
    dependencies: [
        {
            kind: 'controller',
            class: 'App\\Http\\Controllers\\OrderController',
        },
    ],
} as unknown as AnalysisResult;

const render = (canViewSource: boolean, query = '') =>
    renderToStaticMarkup(
        <WorkflowView
            graph={graph}
            basic={basic}
            canViewSource={canViewSource}
            query={query}
            onQuery={() => {}}
        />,
    );

const FORBIDDEN = [/unknown/i, /not stated/i, /can.?t tell/i, /\?/];

describe('Workflow tab', () => {
    it('shows node types, conditional and async badges, and typed reasons for what was not followed', () => {
        const html = render(true);

        for (const text of [
            'Route',
            'Controller',
            'Service',
            'Model',
            'Job',
            'Event',
            'Resource',
            'Exception',
            'Decided at runtime',
            'Conditional',
            'Async',
            'Runtime binding',
            'Dynamic method',
            'Recursive call',
            'Depth bound reached',
        ]) {
            expect(html).toContain(text);
        }
        expect(html).toContain(
            'Container binding resolved at runtime: App\\Contracts\\PaymentGateway::charge() (no static binding found)',
        );
        expect(html).toContain('Bound reached: call depth 13');
        expect(html).toContain('only when the items relation is loaded');
        expect(html).toContain('Partial trace.');
    });

    it('never words an unresolved edge as a bare unknown', () => {
        for (const canViewSource of [true, false]) {
            const text = render(canViewSource)
                .replace(/<[^>]+>/g, ' ')
                .replace(/\$this->\{\$action\}\(\)/g, ''); // source text, shown to source readers

            for (const pattern of FORBIDDEN) {
                expect(text).not.toMatch(pattern);
            }
        }

        const source = fs.readFileSync(
            path.join(
                fileURLToPath(new URL('.', import.meta.url)),
                'workflow-panel.tsx',
            ),
            'utf8',
        );
        expect(source).not.toMatch(
            /['"`][^'"`]*\b(unknown|not stated|can't tell)\b[^'"`]*['"`]/i,
        );
    });

    it('shows source references and expressions only to roles that may read source', () => {
        const withSource = render(true);
        expect(withSource).toContain(
            'app/Http/Controllers/OrderController.php:21',
        );
        expect(withSource).toContain('$this-&gt;{$action}()');

        const without = render(false);
        expect(without).not.toContain('.php');
        expect(without).not.toContain('$this-&gt;');
        expect(without).toContain('OrderController::store');
    });

    it('compares coverage with counts, never a confidence percentage', () => {
        const html = render(false);
        expect(html).toContain('Basic analysis');
        expect(html).toContain('Workflow trace');
        expect(html).toContain('not traced');
        expect(html).not.toMatch(/\d+\s*%/);
    });

    it('builds a tree from the route that expands each node once, and searches it keeping the path', () => {
        const rows = workflowRows(graph);
        expect(rows[0].node.type).toBe('route');
        expect(rows[1].node.label).toBe('OrderController::store');
        const service = rows.filter((r) => r.node.id === S);
        expect(service.map((r) => r.repeat)).toEqual([false, true]); // the recursion is shown, not followed again

        const jobs = filterRows(rows, 'job');
        expect(jobs.map((r) => r.node.label)).toEqual([
            'POST /api/orders',
            'OrderController::store',
            'OrderService::place',
            'ProcessOrder',
        ]);
        expect(render(false, 'no-such-class')).toContain(
            'Nothing in this workflow matches',
        );
    });

    it('starts in a loading state before the trace is fetched', () => {
        const html = renderToStaticMarkup(
            <WorkflowPanel
                projectId="p"
                analysisId="a"
                basic={basic}
                canViewSource={false}
            />,
        );
        expect(html).toContain('Loading the workflow');
        for (const pattern of FORBIDDEN) {
            expect(html.replace(/<[^>]+>/g, ' ')).not.toMatch(pattern);
        }
    });
});
