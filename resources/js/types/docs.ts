import type {
    AnalysisContract,
    AnalysisResponse,
    AuthStatus,
    Confidence,
    ContractResponse,
} from '@/types';

/** Stable codes the builder sets; the page maps them to banners. */
export type DocNotice =
    | 'partial'
    | 'legacy_result'
    | 'budget_reached'
    | 'auth_unknown';

export type DocField = {
    name: string;
    required: 'required' | 'conditional' | 'optional' | 'unknown';
    nullable: boolean;
    types: string[];
    formats: string[];
    status: 'verified' | 'partial' | 'unknown';
    binds: string | null;
    /** absent when the viewer may not see source */
};

export type DocValidation = {
    name: string;
    location: 'path' | 'query' | 'body' | 'query_or_body';
    required: DocField['required'];
    nullable: boolean;
    rules: {
        rule: string;
        status: 'verified' | 'partial' | 'unknown';
        note?: string;
    }[];
    status: DocField['status'];
};

export type DocHeader = {
    name: string;
    value: string;
    required: string;
    status: Confidence;
    reason: string | null;
};

export type StaticDocument = {
    schema_version: string;
    builder_version: string;
    analyzer_version: string;
    analysis_status: 'completed' | 'partial';
    notices: DocNotice[];
    overview: {
        methods: string[];
        uri: string;
        domain: string | null;
        name: string | null;
        handler: string | null;
        group: string;
        middleware: string[];
        auth: { status: AuthStatus; reason: string | null };
        dependencies: { kind: string; class: string }[];
    };
    request: {
        path: DocField[];
        query: DocField[];
        body: DocField[];
        query_or_body: DocField[];
        payload: AnalysisContract['payload'] | null;
    };
    headers: DocHeader[];
    validation: DocValidation[];
    curl: { command: string; source: 'contract' | 'built' };
    /** null for results analyzed before laravel-ast-v6 */
    responses: {
        success: ContractResponse[];
        errors: ContractResponse[];
    } | null;
    legacy_responses: Omit<AnalysisResponse, 'evidence'>[];
    /** absent when the viewer may not see source */
    unknowns: string[];
};

export type DocumentationDraft = {
    id: string;
    status: string;
    builderVersion: string;
    analyzerVersion: string;
    snapshotId: string;
    analysisId: string;
    generatedAt: string | null;
    /** null without source access */
    checksum: string | null;
    snapshotHash: string | null;
    document: StaticDocument;
    /** the same document as Markdown, already stripped like `document` */
    markdown: string;
};

export type DocumentationDraftRow = {
    id: string;
    generatedAt: string | null;
    analyzerVersion: string;
    builderVersion: string;
};
