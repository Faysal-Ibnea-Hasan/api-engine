import { Check, Copy } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useClipboard } from '@/hooks/use-clipboard';
import { cn } from '@/lib/utils';
import type { AnalysisContract, ContractResponse, ExampleNote } from '@/types';

const BASE_URL_KEY = (projectId: string) => `api-base-url:${projectId}`;

function readBaseUrl(projectId: string): string {
    try {
        return window.localStorage.getItem(BASE_URL_KEY(projectId)) ?? '';
    } catch {
        return '';
    }
}

function CopyButton({
    text,
    label,
    what,
}: {
    text: string;
    label: string;
    what: string;
}) {
    const [copiedText, copy] = useClipboard();
    const copied = copiedText === text;

    return (
        <Button
            type="button"
            variant="outline"
            size="sm"
            aria-label={label}
            onClick={() => void copy(text, what)}
        >
            {copied ? (
                <Check className="size-3" />
            ) : (
                <Copy className="size-3" />
            )}
            {copied ? 'Copied' : 'Copy'}
        </Button>
    );
}

/** The curl command; {{base_url}} is replaced by the base URL this browser remembers for the project. */
export function CurlBlock({
    projectId,
    curl,
}: {
    projectId: string;
    curl: string;
}) {
    const [baseUrl, setBaseUrl] = useState('');
    useEffect(() => setBaseUrl(readBaseUrl(projectId)), [projectId]);

    const command = baseUrl.trim()
        ? curl.replaceAll('{{base_url}}', baseUrl.trim().replace(/\/+$/, ''))
        : curl;

    return (
        <div className="space-y-2">
            <div className="flex flex-wrap items-center gap-2">
                <label
                    htmlFor="base-url"
                    className="text-xs text-muted-foreground"
                >
                    Base URL
                </label>
                <Input
                    id="base-url"
                    value={baseUrl}
                    placeholder="https://api.your-app.com"
                    onChange={(e) => {
                        setBaseUrl(e.target.value);
                        try {
                            window.localStorage.setItem(
                                BASE_URL_KEY(projectId),
                                e.target.value,
                            );
                        } catch {
                            // storage unavailable: the field still works for this view
                        }
                    }}
                    className="h-8 max-w-xs text-xs"
                />
                <span className="ml-auto">
                    <CopyButton
                        text={command}
                        label="Copy curl command"
                        what="curl command"
                    />
                </span>
            </div>
            <pre className="overflow-x-auto rounded-lg border bg-muted/40 p-3 text-xs leading-relaxed">
                <code>{command}</code>
            </pre>
        </div>
    );
}

const SOURCE_TITLE: Record<string, (n: ExampleNote) => string> = {
    exact: () => 'Exact: stated in the code',
    example: (n) =>
        `Example value: the code makes this ${n.types.join(' or ')}; the real value is data`,
    unread: (n) =>
        `Typed placeholder: this part is built in a way that couldn't be read${n.note ? ` (${n.note})` : ''}`,
};

/** Example JSON where every value shows whether it is exact, an example, or a placeholder. */
export function JsonView({
    value,
    notes,
}: {
    value: unknown;
    notes: ExampleNote[];
}) {
    const byPath = useMemo(() => {
        const map = new Map<string, ExampleNote>();
        const optional = new Set<string>();
        notes.forEach((n) =>
            n.source === 'optional' ? optional.add(n.path) : map.set(n.path, n),
        );

        return { map, optional };
    }, [notes]);

    const lines: React.ReactNode[] = [];
    const pad = (depth: number) => '  '.repeat(depth);

    const leaf = (v: unknown, path: string) => {
        const note =
            byPath.map.get(path) ??
            byPath.map.get(path.replace(/\[\d+\]$/, '[]'));
        const source = note?.source ?? 'exact';

        return (
            <span
                title={note ? SOURCE_TITLE[source]?.(note) : undefined}
                className={cn(
                    source === 'example' &&
                        'text-info italic decoration-dotted underline-offset-2 hover:underline',
                    source === 'unread' && 'text-warning italic',
                )}
            >
                {JSON.stringify(v)}
                {note && note.types.length > 1 && (
                    <span className="ml-2 text-[10px] text-muted-foreground not-italic">
                        {note.types.join(' | ')}
                    </span>
                )}
            </span>
        );
    };

    const walk = (
        v: unknown,
        path: string,
        depth: number,
        prefix: React.ReactNode,
        comma: boolean,
    ) => {
        const tail = comma ? ',' : '';
        if (Array.isArray(v)) {
            if (v.length === 0) {
                lines.push(
                    <div key={path + 'e'}>
                        {pad(depth)}
                        {prefix}[]{tail}
                    </div>,
                );

                return;
            }
            lines.push(
                <div key={path + 'o'}>
                    {pad(depth)}
                    {prefix}[
                </div>,
            );
            v.forEach((item, i) =>
                walk(item, `${path}[${i}]`, depth + 1, null, i < v.length - 1),
            );
            lines.push(
                <div key={path + 'c'}>
                    {pad(depth)}]{tail}
                </div>,
            );

            return;
        }
        if (v !== null && typeof v === 'object') {
            const entries = Object.entries(v as Record<string, unknown>);
            if (entries.length === 0) {
                lines.push(
                    <div key={path + 'e'}>
                        {pad(depth)}
                        {prefix}
                        {'{}'}
                        {tail}
                    </div>,
                );

                return;
            }
            lines.push(
                <div key={path + 'o'}>
                    {pad(depth)}
                    {prefix}
                    {'{'}
                </div>,
            );
            entries.forEach(([k, child], i) => {
                const childPath = `${path}.${k}`;
                walk(
                    child,
                    childPath,
                    depth + 1,
                    <>
                        <span className="text-foreground">"{k}"</span>
                        {byPath.optional.has(childPath) && (
                            <span
                                className="ml-1 text-[10px] text-muted-foreground"
                                title="Only present in some responses"
                            >
                                optional
                            </span>
                        )}
                        :{' '}
                    </>,
                    i < entries.length - 1,
                );
            });
            lines.push(
                <div key={path + 'c'}>
                    {pad(depth)}
                    {'}'}
                    {tail}
                </div>,
            );

            return;
        }
        lines.push(
            <div key={path}>
                {pad(depth)}
                {prefix}
                {leaf(v, path)}
                {tail}
            </div>,
        );
    };

    walk(value, '$', 0, null, false);

    return (
        <div className="relative">
            <span className="absolute top-2 right-2">
                <CopyButton
                    text={JSON.stringify(value, null, 2)}
                    label="Copy JSON"
                    what="JSON"
                />
            </span>
            <pre className="overflow-x-auto rounded-lg border bg-muted/40 p-3 pr-20 font-mono text-xs leading-relaxed whitespace-pre">
                {lines}
            </pre>
        </div>
    );
}

export function SourceLegend() {
    return (
        <p className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
            <span>
                <span className="text-foreground">value</span> exact, from the
                code
            </span>
            <span>
                <span className="text-info italic">value</span> example of the
                type the code returns (real value is data)
            </span>
            <span>
                <span className="text-warning italic">value</span> typed
                placeholder: structure not read (see tooltip)
            </span>
        </p>
    );
}

function StatusBadge({ status }: { status: number | null }) {
    const tone =
        status === null
            ? 'outline'
            : status < 300
              ? 'success'
              : status < 400
                ? 'info'
                : status < 500
                  ? 'warning'
                  : 'destructive';

    return (
        <Badge
            variant={tone}
            className={cn(
                'font-mono font-semibold',
                status === null && 'border-dashed',
            )}
        >
            {status ?? 'status computed'}
        </Badge>
    );
}

export function ResponseList({
    responses,
    empty,
}: {
    responses: ContractResponse[];
    empty: string;
}) {
    if (responses.length === 0) {
        return <p className="text-muted-foreground">{empty}</p>;
    }

    return (
        <div className="space-y-5">
            <SourceLegend />
            {responses.map((r, i) => (
                <section key={i} className="space-y-2">
                    <div className="flex flex-wrap items-baseline gap-2">
                        <StatusBadge status={r.status} />
                        <span className="text-muted-foreground">
                            {r.triggers.join('; ')}
                        </span>
                    </div>
                    {r.kind === 'empty' ? (
                        <p className="text-muted-foreground">Empty body.</p>
                    ) : (
                        <JsonView value={r.example} notes={r.notes} />
                    )}
                </section>
            ))}
        </div>
    );
}

export function PayloadView({
    payload,
}: {
    payload: AnalysisContract['payload'];
}) {
    const where = {
        query: 'Sent as query-string parameters',
        json: 'Sent as a JSON body (Content-Type: application/json)',
        multipart: 'Sent as multipart/form-data (it includes files)',
    }[payload.location];
    const isEmpty =
        payload.example === null ||
        (typeof payload.example === 'object' &&
            Object.keys(payload.example as object).length === 0);

    return (
        <div className="space-y-4">
            <p className="text-muted-foreground">{where}.</p>
            {isEmpty ? (
                <p className="text-muted-foreground">
                    This endpoint takes no input besides the URL.
                </p>
            ) : (
                <JsonView
                    value={payload.example}
                    notes={payload.notes.map((n) => ({
                        path: n.path,
                        source: 'example',
                        types: n.types,
                    }))}
                />
            )}
            {payload.notes.length > 0 && (
                <div className="overflow-x-auto">
                    <table className="w-full text-left">
                        <thead className="text-xs text-muted-foreground">
                            <tr>
                                <th className="py-1 pr-3">Field</th>
                                <th className="py-1 pr-3">Required</th>
                                <th className="py-1 pr-3">Type</th>
                                <th className="py-1">Rules</th>
                            </tr>
                        </thead>
                        <tbody>
                            {payload.notes.map((n) => (
                                <tr key={n.path} className="border-t align-top">
                                    <td className="py-1.5 pr-3">
                                        <code>{n.path.slice(2)}</code>
                                    </td>
                                    <td className="py-1.5 pr-3">
                                        {n.required === 'required'
                                            ? 'Required'
                                            : n.required === 'conditional'
                                              ? 'Required in some cases'
                                              : n.source === 'read'
                                                ? 'Optional (read, no rule)'
                                                : 'Optional'}
                                    </td>
                                    <td className="py-1.5 pr-3">
                                        {n.types.join(', ') || 'string'}
                                    </td>
                                    <td className="py-1.5">
                                        <code className="text-xs break-all">
                                            {n.rules.join('|') || '—'}
                                        </code>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}
