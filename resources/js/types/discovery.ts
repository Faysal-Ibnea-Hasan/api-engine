import type { AuthStatus } from './analysis';

export type EndpointResolution = 'resolved' | 'partial' | 'unresolved';

export type EndpointRow = {
    id: string;
    methods: string[];
    uri: string;
    domain: string | null;
    name: string | null;
    handlerType:
        | 'controller'
        | 'invokable'
        | 'closure'
        | 'view'
        | 'redirect'
        | 'unknown';
    controller: string | null;
    action: string | null;
    middleware: string[];
    /** discovery's name-based guess; used only when endpoint analysis is off */
    auth: 'required' | 'unknown';
    /** from the endpoint's analysis; null = not analyzed yet */
    analysisAuth: AuthStatus | null;
    group: string;
    module: string | null;
    resolution: EndpointResolution;
    reasons: string[];
    routeIdentity: string;
    /** null when the viewer may not see source */
    source: { path: string; line: number; expression: string | null } | null;
};

export type RouteScanSummary = {
    id: string;
    status:
        | 'queued'
        | 'running'
        | 'completed'
        | 'partial'
        | 'failed'
        | 'cancelled';
    active: boolean;
    applicationRoot: string;
    analyzerVersion: string;
    endpointCount: number;
    resolvedCount: number;
    partialCount: number;
    unresolvedCount: number;
    findingCount: number;
    fileCount: number;
    coverage: number | null;
    failureCode: string | null;
    finishedAt: string | null;
};

export type ScanFindingRow = {
    severity: 'info' | 'warning' | 'error';
    code: string;
    message: string;
    source: {
        path: string;
        line: number | null;
        expression: string | null;
    } | null;
};

export type EndpointsSummary = {
    scan: RouteScanSummary | null;
    snapshotId: string | null;
    needsRoot: boolean;
    canScan: boolean;
};
