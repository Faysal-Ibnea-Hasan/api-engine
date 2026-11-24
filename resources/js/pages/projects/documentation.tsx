import { Head, Link, router } from '@inertiajs/react';
import { FileText, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import DocumentationController from '@/actions/App/Http/Controllers/Docs/DocumentationController';
import EndpointController from '@/actions/App/Http/Controllers/Discovery/EndpointController';
import ProjectController from '@/actions/App/Http/Controllers/Projects/ProjectController';
import {
    CurlBlock,
    PayloadView,
    ResponseList,
} from '@/components/contract-views';
import { AuthLabel } from '@/components/endpoint-analysis';
import { useClipboard } from '@/hooks/use-clipboard';
import EmptyState from '@/components/empty-state';
import Heading from '@/components/heading';
import MethodBadge from '@/components/method-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type {
    DocField,
    DocNotice,
    DocumentationDraft,
    DocumentationDraftRow,
    StaticDocument,
} from '@/types/docs';

type Props = {
    project: { id: string; name: string };
    endpointId: string | null;
    documentation: DocumentationDraft | null;
    drafts: DocumentationDraftRow[];
    canGenerate: boolean;
};

const SECTIONS = [
    ['overview', 'Overview'],
    ['request', 'Request'],
    ['headers', 'Headers'],
    ['validation', 'Validation'],
    ['curl', 'cURL'],
    ['responses', 'Responses'],
    ['errors', 'Errors'],
    ['unknowns', 'Unknowns'],
] as const;

const NOTICE: Record<DocNotice, string> = {
    partial:
        'Partial: some parts of this endpoint could not be read statically. They are listed under Unknowns and never guessed.',
    legacy_result:
        'This draft comes from an older analysis without full response bodies. Analyze again for full response bodies, then generate a new draft.',
    budget_reached:
        'The analysis hit its work budget, so some responses may be missing.',
    auth_unknown:
        "Authentication: can't tell from the code. Check the endpoint's middleware.",
};

const REQUIRED: Record<DocField['required'], string> = {
    required: 'Required',
    conditional: 'Required in some cases',
    optional: 'Optional',
    unknown: 'Not stated',
};

const when = (iso: string | null) =>
    iso ? new Date(iso).toLocaleString() : '—';

function Section({
    id,
    title,
    children,
}: {
    id: string;
    title: string;
    children: React.ReactNode;
}) {
    return (
        <section id={id} className="scroll-mt-4 space-y-3 text-sm">
            <Heading variant="small" title={title} />
            {children}
        </section>
    );
}

function FieldTable({ title, fields }: { title: string; fields: DocField[] }) {
    if (fields.length === 0) {
        return null;
    }

    return (
        <div className="space-y-1">
            <h4 className="text-xs font-medium text-muted-foreground">
                {title}
            </h4>
            <div className="overflow-x-auto">
                <table className="w-full text-left">
                    <thead className="text-xs text-muted-foreground">
                        <tr>
                            <th className="py-1 pr-3">Name</th>
                            <th className="py-1 pr-3">Required</th>
                            <th className="py-1 pr-3">Type</th>
                            <th className="py-1">Confidence</th>
                        </tr>
                    </thead>
                    <tbody>
                        {fields.map((f) => (
                            <tr key={f.name} className="border-t align-top">
                                <td className="py-1.5 pr-3">
                                    <code>{f.name}</code>
                                    {f.binds && (
                                        <span className="block text-xs text-muted-foreground">
                                            binds {f.binds}
                                        </span>
                                    )}
                                </td>
                                <td className="py-1.5 pr-3">
                                    {REQUIRED[f.required]}
                                    {f.nullable && ', nullable'}
                                </td>
                                <td className="py-1.5 pr-3">
                                    {[...f.types, ...f.formats].join(', ') ||
                                        'unknown'}
                                </td>
                                <td className="py-1.5">{f.status}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

function LegacyResponses({
    responses,
    empty,
}: {
    responses: StaticDocument['legacy_responses'];
    empty: string;
}) {
    if (responses.length === 0) {
        return <p className="text-muted-foreground">{empty}</p>;
    }

    return (
        <ul className="space-y-3">
            {responses.map((r, i) => (
                <li key={i} className="rounded-lg border p-3">
                    <div className="flex flex-wrap items-baseline gap-2">
                        <code className="font-semibold">
                            {r.status ?? 'status computed'}
                        </code>
                        <span className="text-xs text-muted-foreground">
                            {r.status_confidence} · {r.kind}
                            {r.resource && ` · ${r.resource}`}
                        </span>
                    </div>
                    {r.reason && (
                        <p className="mt-1 text-muted-foreground">{r.reason}</p>
                    )}
                    {r.body && r.body.fields.length > 0 && (
                        <ul className="mt-2 space-y-0.5">
                            {r.body.wrapped_in && (
                                <li className="text-xs text-muted-foreground">
                                    Wrapped in {r.body.wrapped_in}
                                </li>
                            )}
                            {r.body.fields.map((f) => (
                                <li key={f.name}>
                                    <code>{f.name}</code>{' '}
                                    <span className="text-muted-foreground">
                                        {f.type}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </li>
            ))}
        </ul>
    );
}

function CopyMarkdown({ markdown }: { markdown: string }) {
    const [copiedText, copy] = useClipboard();

    return (
        <Button
            variant="outline"
            size="sm"
            onClick={() => void copy(markdown, 'Markdown')}
        >
            {copiedText === markdown ? 'Copied' : 'Copy as Markdown'}
        </Button>
    );
}

function DraftList({
    projectId,
    drafts,
    currentId,
}: {
    projectId: string;
    drafts: DocumentationDraftRow[];
    currentId: string | null;
}) {
    return (
        <nav aria-label="Drafts" className="space-y-1 text-sm">
            <h3 className="text-xs font-medium text-muted-foreground">
                Drafts (newest first)
            </h3>
            <ul className="space-y-1">
                {drafts.map((d) => (
                    <li key={d.id}>
                        <Link
                            href={DocumentationController.show.url({
                                project: projectId,
                                documentation: d.id,
                            })}
                            aria-current={
                                d.id === currentId ? 'page' : undefined
                            }
                            className={cn(
                                'block rounded-md px-2 py-1 outline-none hover:bg-muted focus-visible:ring-[3px] focus-visible:ring-ring/50',
                                d.id === currentId && 'bg-muted font-medium',
                            )}
                        >
                            {when(d.generatedAt)}
                            <span className="block text-xs text-muted-foreground">
                                {d.analyzerVersion} · {d.builderVersion}
                            </span>
                        </Link>
                    </li>
                ))}
            </ul>
        </nav>
    );
}

function Document({
    projectId,
    draft,
}: {
    projectId: string;
    draft: DocumentationDraft;
}) {
    const doc = draft.document;
    const o = doc.overview;
    const legacySuccess = doc.legacy_responses.filter(
        (r) => r.status === null || r.status < 400,
    );
    const legacyErrors = doc.legacy_responses.filter(
        (r) => r.status !== null && r.status >= 400,
    );

    return (
        <div className="min-w-0 flex-1 space-y-8">
            {doc.notices.length > 0 && (
                <ul className="space-y-2" aria-label="Notices">
                    {doc.notices.map((n) => (
                        <li key={n}>
                            <Alert variant="warning" role="status">
                                <TriangleAlert aria-hidden="true" />
                                <AlertDescription>{NOTICE[n]}</AlertDescription>
                            </Alert>
                        </li>
                    ))}
                </ul>
            )}

            <Section id="overview" title="Overview">
                <div className="flex flex-wrap items-center gap-2">
                    {o.methods.map((m) => (
                        <MethodBadge key={m} method={m} />
                    ))}
                    <code className="text-base font-medium break-all">
                        {o.domain ?? ''}
                        {o.uri}
                    </code>
                </div>
                <dl className="grid grid-cols-[max-content_1fr] gap-x-6 gap-y-1.5 rounded-lg border p-3 text-sm lg:grid-cols-[max-content_1fr_max-content_1fr]">
                    <dt className="text-muted-foreground">Route name</dt>
                    <dd>{o.name ?? 'Unnamed route'}</dd>
                    <dt className="text-muted-foreground">Handler</dt>
                    <dd>
                        <code className="break-all">{o.handler ?? '—'}</code>
                    </dd>
                    <dt className="text-muted-foreground">Authentication</dt>
                    <dd>
                        <AuthLabel status={o.auth.status} />
                        {o.auth.reason && (
                            <span className="block text-muted-foreground">
                                {o.auth.reason}
                            </span>
                        )}
                    </dd>
                    <dt className="text-muted-foreground">Middleware</dt>
                    <dd>
                        {o.middleware.length
                            ? o.middleware.map((m) => (
                                  <code key={m} className="mr-2">
                                      {m}
                                  </code>
                              ))
                            : 'none found'}
                    </dd>
                    <dt className="text-muted-foreground">Group</dt>
                    <dd>{o.group}</dd>
                </dl>
            </Section>

            <Section id="request" title="Request">
                <FieldTable title="Path parameters" fields={doc.request.path} />
                <FieldTable title="Query string" fields={doc.request.query} />
                {doc.request.payload ? (
                    <PayloadView payload={doc.request.payload} />
                ) : (
                    <>
                        <FieldTable title="Body" fields={doc.request.body} />
                        <FieldTable
                            title="Query string or body"
                            fields={doc.request.query_or_body}
                        />
                        {doc.request.body.length +
                            doc.request.query.length +
                            doc.request.query_or_body.length ===
                            0 && (
                            <p className="text-muted-foreground">
                                No input fields were found besides the URL.
                            </p>
                        )}
                    </>
                )}
            </Section>

            <Section id="headers" title="Headers">
                {doc.headers.length === 0 ? (
                    <p className="text-muted-foreground">
                        No request headers were found in the code.
                    </p>
                ) : (
                    <ul className="space-y-1">
                        {doc.headers.map((h) => (
                            <li key={h.name}>
                                <code>
                                    {h.name}: {h.value}
                                </code>{' '}
                                <span className="text-muted-foreground">
                                    {h.required} · {h.status}
                                    {h.reason && ` · ${h.reason}`}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </Section>

            <Section id="validation" title="Validation">
                {doc.validation.length === 0 ? (
                    <p className="text-muted-foreground">
                        No validation rules were found.
                    </p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-left">
                            <thead className="text-xs text-muted-foreground">
                                <tr>
                                    <th className="py-1 pr-3">Field</th>
                                    <th className="py-1 pr-3">Required</th>
                                    <th className="py-1">Rules</th>
                                </tr>
                            </thead>
                            <tbody>
                                {doc.validation.map((v) => (
                                    <tr
                                        key={`${v.location}:${v.name}`}
                                        className="border-t align-top"
                                    >
                                        <td className="py-1.5 pr-3">
                                            <code>{v.name}</code>
                                        </td>
                                        <td className="py-1.5 pr-3">
                                            {REQUIRED[v.required]}
                                        </td>
                                        <td className="py-1.5">
                                            {v.rules.map((r) => (
                                                <code
                                                    key={r.rule}
                                                    title={r.note}
                                                    className={cn(
                                                        'mr-1 inline-block rounded border px-1 text-xs',
                                                        r.status !==
                                                            'verified' &&
                                                            'border-dashed text-muted-foreground',
                                                    )}
                                                >
                                                    {r.rule}
                                                    {r.status !== 'verified' &&
                                                        ` (${r.status})`}
                                                </code>
                                            ))}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Section>

            <Section id="curl" title="cURL">
                {doc.curl.source === 'built' && (
                    <p className="text-muted-foreground">
                        Built from the older analysis: values in angle brackets
                        are placeholders, not real data.
                    </p>
                )}
                <CurlBlock projectId={projectId} curl={doc.curl.command} />
            </Section>

            <Section id="responses" title="Responses">
                {doc.responses ? (
                    <ResponseList
                        responses={doc.responses.success}
                        empty="No success response was read."
                    />
                ) : (
                    <LegacyResponses
                        responses={legacySuccess}
                        empty="No success response was read."
                    />
                )}
            </Section>

            <Section id="errors" title="Errors">
                {doc.responses ? (
                    <ResponseList
                        responses={doc.responses.errors}
                        empty="No error response was read."
                    />
                ) : (
                    <LegacyResponses
                        responses={legacyErrors}
                        empty="No error response was read."
                    />
                )}
            </Section>

            <Section id="unknowns" title="Unknowns">
                {doc.unknowns.length === 0 ? (
                    <p className="text-muted-foreground">
                        Nothing was left unknown.
                    </p>
                ) : (
                    <ul className="list-inside list-disc space-y-1 text-muted-foreground">
                        {doc.unknowns.map((u) => (
                            <li key={u}>{u}</li>
                        ))}
                    </ul>
                )}
            </Section>
        </div>
    );
}

export default function ShowDocumentation({
    project,
    endpointId,
    documentation,
    drafts,
    canGenerate,
}: Props) {
    const o = documentation?.document.overview;
    const [regenerating, setRegenerating] = useState(false);
    // Rebuilds from the analysis as it is now: a new draft if it changed, otherwise this one.
    const regenerate = () =>
        documentation &&
        router.post(
            DocumentationController.store.url({
                project: project.id,
                analysis: documentation.analysisId,
            }),
            {},
            {
                onStart: () => setRegenerating(true),
                onFinish: () => setRegenerating(false),
            },
        );

    return (
        <>
            <Head
                title={
                    o
                        ? `Docs: ${o.methods.join(' ')} ${o.uri}`
                        : 'Documentation'
                }
            />
            <div className="flex w-full flex-col gap-4 p-4 md:p-6">
                <Heading
                    title="Documentation"
                    description={`Static draft for ${project.name}, built from the analysis only (no AI).`}
                    icon={FileText}
                >
                    {documentation && canGenerate && (
                        <Button
                            variant="secondary"
                            disabled={regenerating}
                            onClick={regenerate}
                            title="Build the documentation again from the endpoint's current analysis. Older drafts stay in the list."
                        >
                            Regenerate
                        </Button>
                    )}
                </Heading>

                {documentation === null ? (
                    <EmptyState
                        icon={FileText}
                        title="No documentation yet"
                        description="Analyze this endpoint, then choose Generate documentation on the endpoint page."
                    />
                ) : (
                    <div className="flex flex-col gap-6 lg:flex-row lg:items-start">
                        <aside className="space-y-6 lg:sticky lg:top-4 lg:w-56 lg:shrink-0">
                            <nav aria-label="Sections">
                                <ul className="flex flex-wrap gap-x-3 gap-y-1 text-sm lg:flex-col">
                                    {SECTIONS.map(([id, label]) => (
                                        <li key={id}>
                                            <a
                                                href={`#${id}`}
                                                className="rounded-sm text-muted-foreground outline-none hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                            >
                                                {label}
                                            </a>
                                        </li>
                                    ))}
                                </ul>
                            </nav>
                            <dl
                                aria-label="Provenance"
                                className="space-y-1 rounded-lg border p-3 text-xs"
                            >
                                <dt className="text-muted-foreground">
                                    Generated
                                </dt>
                                <dd>{when(documentation.generatedAt)}</dd>
                                <dt className="text-muted-foreground">
                                    Snapshot
                                </dt>
                                <dd>
                                    <code className="break-all">
                                        {documentation.snapshotId}
                                    </code>
                                </dd>
                                {documentation.snapshotHash && (
                                    <>
                                        <dt className="text-muted-foreground">
                                            Snapshot hash
                                        </dt>
                                        <dd>
                                            <code className="break-all">
                                                {documentation.snapshotHash}
                                            </code>
                                        </dd>
                                    </>
                                )}
                                <dt className="text-muted-foreground">
                                    Analysis
                                </dt>
                                <dd>
                                    <code className="break-all">
                                        {documentation.analysisId}
                                    </code>{' '}
                                    ({documentation.document.analysis_status})
                                </dd>
                                <dt className="text-muted-foreground">
                                    Versions
                                </dt>
                                <dd>
                                    {documentation.analyzerVersion} ·{' '}
                                    {documentation.builderVersion}
                                </dd>
                                {documentation.checksum && (
                                    <>
                                        <dt className="text-muted-foreground">
                                            Contract checksum
                                        </dt>
                                        <dd>
                                            <code className="break-all">
                                                {documentation.checksum}
                                            </code>
                                        </dd>
                                    </>
                                )}
                            </dl>
                            <CopyMarkdown markdown={documentation.markdown} />
                            <DraftList
                                projectId={project.id}
                                drafts={drafts}
                                currentId={documentation.id}
                            />
                        </aside>
                        <Document
                            projectId={project.id}
                            draft={documentation}
                        />
                    </div>
                )}

                {endpointId && (
                    <Button variant="ghost" asChild className="w-fit">
                        <Link
                            href={EndpointController.show.url({
                                project: project.id,
                                endpoint: endpointId,
                            })}
                        >
                            Back to the endpoint
                        </Link>
                    </Button>
                )}
            </div>
        </>
    );
}

ShowDocumentation.layout = {
    breadcrumbs: [{ title: 'Projects', href: ProjectController.index() }],
};
