/** verified = read directly from the code; inferred = Laravel's usual behavior; unknown = decided at runtime */
export type Confidence = 'verified' | 'inferred' | 'unknown';

export type AnalysisRule = {
    rule: string;
    params: string[];
    status: 'verified' | 'partial' | 'unknown';
    note?: string;
};

export type AnalysisField = {
    name: string;
    location: 'path' | 'query' | 'body' | 'query_or_body';
    location_status?: 'verified' | 'inferred';
    required: 'required' | 'conditional' | 'optional' | 'unknown';
    nullable: boolean;
    sometimes: boolean;
    conditional: boolean;
    types: string[];
    formats: string[];
    constraints: Record<string, string[]>;
    rules: AnalysisRule[];
    status: 'verified' | 'partial' | 'unknown';
    source: string;
    binds?: string;
    read_via?: string;
    /** absent when the viewer may not see source */
    evidence?: string[];
};

export type AnalysisHeader = {
    name: string;
    value: string | null;
    required: string;
    status: Confidence;
    reason: string | null;
    evidence?: string[];
};

export type AnalysisResponseField = {
    name: string;
    type: string;
    value: unknown;
    value_known?: boolean;
    description?: string | null;
    items?: AnalysisResponseField[];
};

export type AnalysisResponse = {
    status: number | null;
    status_confidence: Confidence;
    kind: string;
    resource?: string;
    body: {
        fields: AnalysisResponseField[];
        wrapped_in?: string;
        collection?: boolean;
    } | null;
    reason: string | null;
    evidence?: string[];
};

export type AuthVerdict =
    | 'authenticates'
    | 'presence_only'
    | 'guest_or_user'
    | 'optional'
    | 'conditional'
    | 'none'
    | 'unknown';

export type AuthStatus =
    | 'required'
    | 'required_unverified'
    | 'guest_or_user'
    | 'not_required'
    | 'unknown'
    /** written by analyzer laravel-ast-v1 */
    | 'not_detected';

export type AuthSignal = {
    kind: string;
    /** absent in results from analyzer laravel-ast-v1 */
    verdict?: AuthVerdict;
    middleware: string | null;
    class?: string | null;
    /** global | group:api | route | controller | form_request */
    from: string;
    status: Confidence;
    conditional: boolean;
    credentials?: string[];
    rejects?: number[];
    note?: string | null;
    evidence?: string[];
};

export type EvidenceRef = {
    id: string;
    path: string;
    sha256: string | null;
    symbol: string | null;
    lines: [number, number];
    kind: string;
};

export type AnalysisResult = {
    schema_version: string;
    snapshot_id: string;
    endpoint_id: string;
    analyzer_version: string;
    request: { headers: AnalysisHeader[]; fields: AnalysisField[] };
    responses: AnalysisResponse[];
    auth: {
        status: AuthStatus;
        /** why, in one sentence (laravel-ast-v2+) */
        reason?: string;
        signals: AuthSignal[];
    };
    dependencies: { kind: string; class: string }[];
    evidence?: EvidenceRef[];
    unresolved: string[];
    /** laravel-ast-v6+: the request followed through the app's middleware, controller and renderers */
    contract?: AnalysisContract;
};

/** exact = stated by the code; example = typed by the code, value is runtime data; unread = structure not read */
export type ValueSource = 'exact' | 'example' | 'unread' | 'optional';

export type ExampleNote = {
    path: string;
    source: ValueSource;
    types: string[];
    note?: string;
};

export type ContractResponse = {
    status: number | null;
    status_source: 'exact' | 'unread';
    kind: string;
    triggers: string[];
    example: unknown;
    notes: ExampleNote[];
    holes: string[];
};

export type AnalysisContract = {
    curl: string;
    payload: {
        location: 'query' | 'json' | 'multipart';
        example: unknown;
        notes: {
            path: string;
            required: string;
            types: string[];
            source: 'rules' | 'read';
            rules: string[];
        }[];
    };
    success: ContractResponse[];
    errors: ContractResponse[];
    budget_reached: boolean;
};

export type EndpointAnalysisSummary = {
    id: string;
    status:
        | 'queued'
        | 'running'
        | 'completed'
        | 'partial'
        | 'failed'
        | 'cancelled';
    active: boolean;
    analyzerVersion: string;
    schemaVersion: string;
    snapshotId: string;
    fieldCount: number;
    responseCount: number;
    unresolvedCount: number;
    failureCode: string | null;
    /** the stored result came from an older analyzer version: re-analyze */
    stale?: boolean;
    finishedAt: string | null;
    result: AnalysisResult | null;
};

export type EvidenceExcerpt = {
    id: string;
    path: string;
    sha256: string | null;
    symbol: string | null;
    kind: string;
    lines: [number, number];
    truncated: boolean;
    excerpt: { n: number; text: string; hit: boolean }[];
};
