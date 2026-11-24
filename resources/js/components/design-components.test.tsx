import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { FolderKanban, Wrench } from 'lucide-react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vite-plus/test';
import { ResponseList } from '@/components/contract-views';
import EmptyState from '@/components/empty-state';
import Heading from '@/components/heading';
import ImportStatus from '@/components/import-status';
import MethodBadge from '@/components/method-badge';
import Progress from '@/components/progress';
import ProjectStatusBadge from '@/components/project-status-badge';
import ResolutionBadge from '@/components/resolution-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge, badgeVariants } from '@/components/ui/badge';
import { BreadcrumbLink } from '@/components/ui/breadcrumb';
import { buttonVariants } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Skeleton } from '@/components/ui/skeleton';
import { Textarea } from '@/components/ui/textarea';

// Chunk 2 kit tests: AC6, AC7, AC8, AC10, AC22 (Vitest part), AC27, AC30, AC32, AC37.
// AC9 lives in platform-components.test.tsx, which is not edited.
// Chunk 3 adds AC40 (contract-view status colors); chunk 3b adds AC44 (gradient and icon classes).

const UI_DIR = path.join(fileURLToPath(new URL('.', import.meta.url)), 'ui');

/** Token-wise split used throughout the spec: class attribute or file text. */
const tokenize = (s: string) => s.split(/[\s"'`]+/).filter(Boolean);

const unescape = (s: string) =>
    s
        .replace(/&gt;/g, '>')
        .replace(/&lt;/g, '<')
        .replace(/&quot;/g, '"')
        .replace(/&#x27;/g, "'")
        .replace(/&amp;/g, '&');

/** The opening tag of the first element carrying `data-slot="<slot>"`. */
function tagFor(html: string, slot: string) {
    const m = html.match(
        new RegExp(`<[a-zA-Z0-9]+\\b[^>]*data-slot="${slot}"[^>]*>`),
    );

    expect(m, `no element with data-slot="${slot}" in: ${html}`).not.toBeNull();

    return m![0];
}

const classTokens = (tag: string) => {
    const m = tag.match(/\bclass="([^"]*)"/);

    return m ? tokenize(unescape(m[1])) : [];
};

const slotTokens = (html: string, slot: string) =>
    classTokens(tagFor(html, slot));

/** Tokens of the root (first) element. */
const rootTokens = (html: string) => {
    const m = html.match(/^<[a-zA-Z0-9]+\b[^>]*>/);

    expect(m, `no root element in: ${html}`).not.toBeNull();

    return classTokens(m![0]);
};

const tagTokens = (html: string, tagName: string) => {
    const m = html.match(new RegExp(`<${tagName}\\b[^>]*>`));

    expect(m, `no <${tagName}> in: ${html}`).not.toBeNull();

    return classTokens(m![0]);
};

const text = (html: string) => unescape(html.replace(/<[^>]*>/g, ''));

const TONE = /^text-(?:success|info|warning|destructive|muted-foreground)$/;

// ---------------------------------------------------------------------------
// AC6: MethodBadge
// ---------------------------------------------------------------------------

describe('AC6: MethodBadge', () => {
    it.each([
        ['GET', 'text-success'],
        ['POST', 'text-info'],
        ['PUT', 'text-warning'],
        ['PATCH', 'text-warning'],
        ['DELETE', 'text-destructive'],
        ['OPTIONS', 'text-muted-foreground'],
        ['XYZ', 'text-muted-foreground'],
        ['toString', 'text-muted-foreground'],
        ['constructor', 'text-muted-foreground'],
        ['__proto__', 'text-muted-foreground'],
    ])('%s renders one tone token: %s', (method, tone) => {
        const html = renderToStaticMarkup(<MethodBadge method={method} />);

        expect(html.startsWith('<span ')).toBe(true);
        expect(html).toContain('data-slot="method-badge"');
        expect(text(html)).toBe(method);

        const tokens = slotTokens(html, 'method-badge');

        expect(tokens).toContain('font-mono');
        expect(tokens).toContain('min-w-14');
        expect(tokens).toContain('max-w-24');
        expect(tokens).toContain('truncate');
        expect(tokens.filter((t) => TONE.test(t))).toEqual([tone]);
        expect(tagFor(html, 'method-badge')).toContain(`title="${method}"`);
    });

    it('keeps a 240-character method in full and carries it as the title', () => {
        const method = 'M'.repeat(240);
        const html = renderToStaticMarkup(<MethodBadge method={method} />);

        expect(text(html)).toBe(method);
        expect(tagFor(html, 'method-badge')).toContain(`title="${method}"`);
        expect(slotTokens(html, 'method-badge')).toEqual(
            expect.arrayContaining(['max-w-24', 'truncate']),
        );
    });
});

// ---------------------------------------------------------------------------
// AC7: Badge variants and the status badges
// ---------------------------------------------------------------------------

type Variant =
    | 'success'
    | 'warning'
    | 'info'
    | 'destructive'
    | 'secondary'
    | 'outline'
    | 'default';

const badgeTokens = (variant: Variant) =>
    slotTokens(
        renderToStaticMarkup(<Badge variant={variant}>x</Badge>),
        'badge',
    );

/** The first badge rendered by a status component must carry the variant's tokens. */
function expectVariant(html: string, variant: Variant) {
    const tokens = slotTokens(html, 'badge');

    expect(tokens).toEqual(expect.arrayContaining(badgeTokens(variant)));

    if (variant === 'secondary' || variant === 'outline') {
        expect(
            tokens.filter((t) =>
                /^text-(?:success|warning|info|destructive)$/.test(t),
            ),
        ).toEqual([]);
    }
}

describe('AC7: Badge variants', () => {
    it.each(['success', 'warning', 'info', 'destructive'] as const)(
        '%s has its tone text and no text-white',
        (variant) => {
            const tokens = badgeTokens(variant);

            expect(tokens).toContain(`text-${variant}`);
            expect(tokens).not.toContain('text-white');
        },
    );
});

const PROTO_KEYS = [
    'toString',
    'constructor',
    'hasOwnProperty',
    'valueOf',
    '__proto__',
];

describe('AC7: ResolutionBadge', () => {
    it.each([
        ['resolved', 'Resolved', 'success'],
        ['partial', 'Partial', 'warning'],
        ['unresolved', 'Unresolved', 'destructive'],
    ] as const)(
        '%s is %s with the %s variant',
        (resolution, label, variant) => {
            const html = renderToStaticMarkup(
                <ResolutionBadge resolution={resolution} />,
            );

            expect(text(html)).toBe(label);
            expect(slotTokens(html, 'badge')).toContain(`text-${variant}`);
            expectVariant(html, variant);
        },
    );
});

describe('AC7: ImportStatus (Gate 1 question 3 mapping)', () => {
    it.each([
        ['completed', 'success', 'Completed'],
        ['ready', 'success', 'Ready'],
        ['partial', 'warning', 'Partly completed'],
        ['failed', 'destructive', 'Failed'],
        ['rejected', 'destructive', 'Rejected'],
        ['cancelled', 'outline', 'Cancelled'],
        ['queued', 'secondary', 'Queued'],
        ['running', 'secondary', 'Importing'],
        ['pending', 'secondary', 'Pending'],
        ['validating', 'secondary', 'Validating'],
    ] as const)(
        '%s maps to %s and is labelled %s',
        (status, variant, label) => {
            const html = renderToStaticMarkup(<ImportStatus status={status} />);

            expect(text(html).trim()).not.toBe('');
            // C2-F13: AC7 keeps the pre-chunk labels (chunk 1 import-status.tsx).
            expect(text(html)).toBe(label);
            expectVariant(html, variant);
        },
    );

    it.each([...PROTO_KEYS, 'superseded'])(
        'unknown or prototype key %s renders with the secondary variant',
        (status) => {
            const html = renderToStaticMarkup(
                <ImportStatus status={status as never} />,
            );

            expectVariant(html, 'secondary');
        },
    );

    it('shows a distinct label per status', () => {
        const labels = (
            [
                'completed',
                'partial',
                'failed',
                'cancelled',
                'queued',
                'running',
            ] as const
        ).map((s) => text(renderToStaticMarkup(<ImportStatus status={s} />)));

        expect(new Set(labels).size).toBe(labels.length);
    });
});

describe('AC7: prototype keys render with an empty label', () => {
    it.each(PROTO_KEYS)('%s', (key) => {
        for (const html of [
            renderToStaticMarkup(<ImportStatus status={key as never} />),
            renderToStaticMarkup(<ResolutionBadge resolution={key as never} />),
            renderToStaticMarkup(
                <ProjectStatusBadge
                    status={key as never}
                    visibility="workspace"
                />,
            ),
        ]) {
            expect(text(html).trim()).toBe('');
        }
    });
});

describe('AC7: ProjectStatusBadge (Gate 1 question 3 mapping)', () => {
    it.each([
        ['active', 'success', 'Active'],
        ['draft', 'secondary', 'Draft'],
        ['archived', 'outline', 'Archived'],
    ] as const)(
        '%s maps to %s and is labelled %s',
        (status, variant, label) => {
            const html = renderToStaticMarkup(
                <ProjectStatusBadge status={status} visibility="workspace" />,
            );

            expect(text(html).trim()).not.toBe('');
            // C2-F13: AC7 keeps the pre-chunk labels (chunk 1 project-status-badge.tsx).
            expect(text(html)).toBe(label);
            expectVariant(html, variant);
        },
    );

    it('keeps the Restricted visibility badge as outline', () => {
        const html = renderToStaticMarkup(
            <ProjectStatusBadge status="active" visibility="restricted" />,
        );
        const m = html.match(
            /<[a-z]+\b[^>]*data-slot="badge"[^>]*>\s*Restricted\s*<\/[a-z]+>/,
        );

        expect(m, `no Restricted badge in: ${html}`).not.toBeNull();
        expect(classTokens(m![0])).toEqual(
            expect.arrayContaining(badgeTokens('outline')),
        );
    });
});

// ---------------------------------------------------------------------------
// AC8: NativeSelect and Textarea
// ---------------------------------------------------------------------------

describe('AC8: NativeSelect', () => {
    const html = renderToStaticMarkup(
        <NativeSelect
            name="method"
            defaultValue=""
            aria-label="HTTP method"
            className="w-40"
        >
            <option value="">All</option>
            <option value="GET">GET</option>
        </NativeSelect>,
    );

    it('renders a native select that keeps name, aria-label and the empty selection', () => {
        expect(html.startsWith('<select ')).toBe(true);
        expect(html).toContain('name="method"');
        expect(html).toContain('aria-label="HTTP method"');
        expect(html).toContain('data-slot="native-select"');
        expect(html).toMatch(/<option value=""[^>]*\bselected\b/);
        expect(html).not.toMatch(/<option value="GET"[^>]*\bselected\b/);
    });

    it('keeps the caller class next to the base tokens', () => {
        const tokens = slotTokens(html, 'native-select');

        expect(tokens).toContain('w-40');
        expect(tokens).toContain('h-8');
    });

    it('forwards onChange to the element', () => {
        const fn = vi.fn();

        expect(NativeSelect({ onChange: fn }).props.onChange).toBe(fn);
    });
});

describe('AC8: Textarea', () => {
    const html = renderToStaticMarkup(
        <Textarea name="notes" defaultValue="x" className="font-mono" />,
    );

    it('renders a native textarea that keeps name, value and class', () => {
        expect(html.startsWith('<textarea ')).toBe(true);
        expect(html).toContain('name="notes"');
        expect(html).toContain('data-slot="textarea"');
        expect(html).toMatch(/>x<\/textarea>$/);
        expect(slotTokens(html, 'textarea')).toContain('font-mono');
    });

    it('forwards onChange to the element', () => {
        const fn = vi.fn();

        expect(Textarea({ onChange: fn }).props.onChange).toBe(fn);
    });
});

// ---------------------------------------------------------------------------
// AC10: Heading
// ---------------------------------------------------------------------------

describe('AC10: Heading', () => {
    const html = renderToStaticMarkup(<Heading title="T" description="D" />);

    it('renders an h2 with the title and a p with the description', () => {
        expect(html).toMatch(/<h2\b[^>]*>T<\/h2>/);
        expect(html).toMatch(/<p\b[^>]*>D<\/p>/);
    });

    it('has no mb-* class on the root; spacing comes from the parent gap', () => {
        expect(rootTokens(html).filter((t) => /(?:^|:)-?mb-/.test(t))).toEqual(
            [],
        );
    });
});

// ---------------------------------------------------------------------------
// AC27: density table
// ---------------------------------------------------------------------------

const tokensOf = (cls: string) => tokenize(cls);

describe('AC27: density classes', () => {
    it.each([
        ['default', buttonVariants({}), ['h-8', 'px-3']],
        ['sm', buttonVariants({ size: 'sm' }), ['h-7', 'px-2.5', 'text-xs']],
        ['lg', buttonVariants({ size: 'lg' }), ['h-9', 'px-4']],
        ['icon', buttonVariants({ size: 'icon' }), ['size-8']],
    ])('buttonVariants %s', (_name, cls, expected) => {
        expect(tokensOf(cls)).toEqual(expect.arrayContaining(expected));
    });

    it('Input and NativeSelect include h-8 px-2.5 text-sm', () => {
        const input = slotTokens(renderToStaticMarkup(<Input />), 'input');
        const select = slotTokens(
            renderToStaticMarkup(<NativeSelect />),
            'native-select',
        );

        for (const tokens of [input, select]) {
            expect(tokens).toEqual(
                expect.arrayContaining(['h-8', 'px-2.5', 'text-sm']),
            );
        }
    });

    it('Textarea includes min-h-16', () => {
        expect(
            slotTokens(renderToStaticMarkup(<Textarea />), 'textarea'),
        ).toContain('min-h-16');
    });

    it('Card root includes rounded-lg and Badge includes rounded-md', () => {
        expect(
            slotTokens(renderToStaticMarkup(<Card>x</Card>), 'card'),
        ).toContain('rounded-lg');
        expect(badgeTokens('default')).toContain('rounded-md');
    });

    it('Heading h2 type scale, default and small', () => {
        expect(
            tagTokens(renderToStaticMarkup(<Heading title="T" />), 'h2'),
        ).toEqual(
            expect.arrayContaining([
                'text-lg',
                'font-semibold',
                'tracking-tight',
            ]),
        );
        expect(
            tagTokens(
                renderToStaticMarkup(<Heading title="T" variant="small" />),
                'h2',
            ),
        ).toEqual(expect.arrayContaining(['text-sm', 'font-semibold']));
    });
});

// ---------------------------------------------------------------------------
// AC22 (Vitest part): the kit focus ring
// ---------------------------------------------------------------------------

describe('AC22: kit focus ring tokens', () => {
    const RING = ['focus-visible:ring-[3px]', 'focus-visible:ring-ring/50'];

    it.each([
        [
            'BreadcrumbLink',
            renderToStaticMarkup(<BreadcrumbLink href="/x">x</BreadcrumbLink>),
        ],
        ['Input', renderToStaticMarkup(<Input />)],
        ['NativeSelect', renderToStaticMarkup(<NativeSelect />)],
        ['Textarea', renderToStaticMarkup(<Textarea />)],
    ])('%s carries the ring', (_name, html) => {
        expect(rootTokens(html)).toEqual(expect.arrayContaining(RING));
    });
});

// ---------------------------------------------------------------------------
// AC30: motion and pop-up classes
// ---------------------------------------------------------------------------

/** Slice for `X`: from `function X(` to the next `\nfunction ` or `\nexport `. */
function slice(file: string, name: string) {
    const src = fs.readFileSync(path.join(UI_DIR, file), 'utf8');
    const start = src.indexOf(`function ${name}(`);

    expect(
        start,
        `function ${name}( not found in ${file}`,
    ).toBeGreaterThanOrEqual(0);

    const rest = src.slice(start + 1);
    const ends = [rest.indexOf('\nfunction '), rest.indexOf('\nexport ')]
        .filter((i) => i >= 0)
        .sort((a, b) => a - b);

    return tokenize(
        ends.length ? src.slice(start, start + 1 + ends[0]) : src.slice(start),
    );
}

const fileTokens = (file: string) =>
    tokenize(fs.readFileSync(path.join(UI_DIR, file), 'utf8'));

describe('AC30: rendered kit motion classes', () => {
    it('buttonVariants has the revision 4 transition and press scale', () => {
        const tokens = tokensOf(buttonVariants({}));

        expect(tokens).toContain(
            'transition-[color,background-color,border-color,box-shadow,transform]',
        );
        expect(tokens).toContain('motion-safe:active:scale-[0.98]');
        expect(tokens).not.toContain('transition-[color,box-shadow]');
    });

    it('Skeleton is a muted, reduced-motion-safe pulse', () => {
        const tokens = slotTokens(
            renderToStaticMarkup(<Skeleton />),
            'skeleton',
        );

        expect(tokens).toEqual(
            expect.arrayContaining([
                'bg-muted',
                'animate-pulse',
                'rounded-md',
                'motion-reduce:animate-none',
            ]),
        );
        expect(tokens).not.toContain('bg-primary/10');
    });

    it('Card has the one card style', () => {
        expect(
            slotTokens(renderToStaticMarkup(<Card>x</Card>), 'card'),
        ).toEqual(
            expect.arrayContaining([
                'bg-card',
                'text-card-foreground',
                'border',
                'rounded-lg',
                'shadow-xs',
            ]),
        );
    });

    it('indeterminate Progress stops pulsing under reduced motion', () => {
        const html = renderToStaticMarkup(<Progress label="Scan" />);
        const pulsing = [...html.matchAll(/\bclass="([^"]*)"/g)]
            .map((m) => tokenize(unescape(m[1])))
            .filter((tokens) => tokens.includes('animate-pulse'));

        expect(pulsing.length).toBeGreaterThan(0);

        for (const tokens of pulsing) {
            expect(tokens).toContain('motion-reduce:animate-none');
        }
    });
});

describe('AC30: ui/dialog.tsx source tokens', () => {
    it('DialogOverlay has the scrim and blur', () => {
        expect(slice('dialog.tsx', 'DialogOverlay')).toEqual(
            expect.arrayContaining(['bg-black/50', 'backdrop-blur-xs']),
        );
    });

    it('DialogContent has the popover surface and the revision 4 motion', () => {
        expect(slice('dialog.tsx', 'DialogContent')).toEqual(
            expect.arrayContaining([
                'bg-popover',
                'text-popover-foreground',
                'duration-(--duration-base)',
                'ease-standard',
                'data-[state=closed]:duration-(--duration-fast)',
                'data-[state=closed]:ease-exit',
                'data-[state=open]:zoom-in-95',
                'data-[state=open]:slide-in-from-bottom-2',
                'data-[state=closed]:pointer-events-none',
            ]),
        );
    });

    it('the file has no bg-black/80 and no numeric duration-*', () => {
        const tokens = fileTokens('dialog.tsx');

        expect(tokens).not.toContain('bg-black/80');
        expect(tokens.filter((t) => /^duration-\d+$/.test(t))).toEqual([]);
    });
});

describe('AC30: ui/sheet.tsx source tokens', () => {
    it('SheetOverlay has the scrim and blur', () => {
        expect(slice('sheet.tsx', 'SheetOverlay')).toEqual(
            expect.arrayContaining(['bg-black/50', 'backdrop-blur-xs']),
        );
    });

    it('SheetContent opens slow and closes at base with the exit easing', () => {
        expect(slice('sheet.tsx', 'SheetContent')).toEqual(
            expect.arrayContaining([
                'data-[state=open]:duration-(--duration-slow)',
                'data-[state=closed]:duration-(--duration-base)',
                'ease-standard',
                'data-[state=closed]:ease-exit',
            ]),
        );
    });

    it('the file drops the old scrim and timing', () => {
        const tokens = fileTokens('sheet.tsx');

        for (const old of [
            'bg-black/80',
            'ease-in-out',
            'data-[state=open]:duration-500',
            'data-[state=closed]:duration-300',
        ]) {
            expect(tokens).not.toContain(old);
        }
    });
});

describe('AC30: dropdown and select content motion', () => {
    it.each([
        ['dropdown-menu.tsx', 'DropdownMenuContent'],
        ['dropdown-menu.tsx', 'DropdownMenuSubContent'],
        ['select.tsx', 'SelectContent'],
    ])('%s %s uses the fast duration and standard easing', (file, name) => {
        expect(slice(file, name)).toEqual(
            expect.arrayContaining([
                'duration-(--duration-fast)',
                'ease-standard',
            ]),
        );
    });
});

// ---------------------------------------------------------------------------
// AC32: Alert variants
// ---------------------------------------------------------------------------

describe('AC32: Alert variants', () => {
    const alertTag = (variant: string, role?: string) =>
        tagFor(
            renderToStaticMarkup(
                <Alert
                    variant={variant as 'default'}
                    {...(role ? { role } : {})}
                >
                    <AlertDescription>d</AlertDescription>
                </Alert>,
            ),
            'alert',
        );

    it('default is bg-card text-card-foreground', () => {
        expect(classTokens(alertTag('default'))).toEqual(
            expect.arrayContaining(['bg-card', 'text-card-foreground']),
        );
    });

    it.each(['info', 'success', 'warning', 'destructive'])(
        '%s is the tinted tone with a tone description',
        (tone) => {
            expect(classTokens(alertTag(tone))).toEqual(
                expect.arrayContaining([
                    `border-${tone}/30`,
                    `bg-${tone}/10`,
                    `text-${tone}`,
                    `*:data-[slot=alert-description]:text-${tone}`,
                ]),
            );
        },
    );

    it.each(['default', 'info', 'success', 'warning', 'destructive'])(
        '%s has no destructive-foreground and no /80 or /90 token',
        (variant) => {
            const tokens = classTokens(alertTag(variant));

            expect(tokens).not.toContain('text-destructive-foreground');
            expect(tokens.filter((t) => /\/(?:80|90)$/.test(t))).toEqual([]);
        },
    );

    it('defaults to role="alert" with data-slot="alert"', () => {
        const tag = alertTag('default');

        expect(tag).toContain('role="alert"');
        expect(tag).toContain('data-slot="alert"');
    });

    it('an explicit role={undefined} keeps role="alert"', () => {
        const tag = tagFor(
            renderToStaticMarkup(
                <Alert role={undefined}>
                    <AlertDescription>d</AlertDescription>
                </Alert>,
            ),
            'alert',
        );

        expect(tag).toContain('role="alert"');
    });

    it.each(['note', 'status'])(
        'a caller role="%s" overrides the default',
        (role) => {
            const tag = alertTag('info', role);

            expect(tag).toContain(`role="${role}"`);
            expect(tag).not.toContain('role="alert"');
        },
    );
});

// ---------------------------------------------------------------------------
// AC37: Progress with a bad value
// ---------------------------------------------------------------------------

describe('AC37: Progress NaN renders the indeterminate bar', () => {
    it('has no NaN, no aria-valuenow, and a pulsing inner bar', () => {
        const html = renderToStaticMarkup(
            <Progress label="Upload" value={Number.NaN} />,
        );

        expect(html).not.toContain('NaN');
        expect(html).not.toContain('aria-valuenow');
        expect(html).not.toContain('width:');

        const pulsing = [...html.matchAll(/\bclass="([^"]*)"/g)].filter((m) =>
            tokenize(m[1]).includes('animate-pulse'),
        );

        expect(pulsing.length).toBeGreaterThan(0);
    });
});

// ---------------------------------------------------------------------------
// AC40: contract-view status colors (chunk 3, revisions 8 and 9)
// ---------------------------------------------------------------------------

const contractResponse = (status: number | null) => ({
    status,
    status_source: 'exact' as const,
    kind: 'empty',
    triggers: ['t'],
    example: null,
    notes: [],
    holes: [],
});

const R = [201, 302, 404, 503, null].map(contractResponse);
const R2 = [100, 299, 300, 399, 400, 499, 500].map(contractResponse);

const TONES: readonly string[] = [
    'text-success',
    'text-info',
    'text-warning',
    'text-destructive',
];

const toneTokens = (tokens: string[]) =>
    tokens.filter((t) => TONES.includes(t));

/** Class tokens of every data-slot="badge" element, in document order. */
const badgeClassLists = (html: string) =>
    [...html.matchAll(/<[a-zA-Z0-9]+\b[^>]*data-slot="badge"[^>]*>/g)].map(
        (m) => classTokens(m[0]),
    );

/** Class tokens of every element carrying a class attribute. */
const allClassLists = (html: string) =>
    [...html.matchAll(/\bclass="([^"]*)"/g)].map((m) =>
        tokenize(unescape(m[1])),
    );

describe('AC40: ResponseList status badges', () => {
    const html = renderToStaticMarkup(
        <ResponseList empty="none" responses={R} />,
    );
    const badges = badgeClassLists(html);

    it('renders exactly five badges, in input order', () => {
        expect(badges).toHaveLength(5);

        const labels = [
            ...html.matchAll(
                /<[a-zA-Z0-9]+\b[^>]*data-slot="badge"[^>]*>([^<]*)</g,
            ),
        ].map((m) => m[1].trim());

        expect(labels).toEqual(['201', '302', '404', '503', 'status computed']);
    });

    it.each([
        [0, 'text-success'],
        [1, 'text-info'],
        [2, 'text-warning'],
        [3, 'text-destructive'],
    ])('badge %i has exactly the tone %s', (i, tone) => {
        expect(toneTokens(badges[i] ?? [])).toEqual([tone]);
        expect(badges[i]).not.toContain('border-dashed');
    });

    it('the null status is the dashed outline badge with no tone', () => {
        expect(toneTokens(badges[4] ?? [])).toEqual([]);
        expect(badges[4]).toContain('text-foreground');
        expect(badges[4]).toContain('border-dashed');
    });

    it('every badge is mono', () => {
        for (const tokens of badges) expect(tokens).toContain('font-mono');
    });

    it('the source legend uses the kit tones, in italics', () => {
        const lists = allClassLists(html);

        for (const tone of ['text-info', 'text-warning']) {
            const withTone = lists.filter((c) => c.includes(tone));

            // The status badge plus the SourceLegend span.
            expect(withTone).toHaveLength(2);
            expect(withTone.filter((c) => c.includes('italic'))).toHaveLength(
                1,
            );
        }
    });
});

describe('AC40: status thresholds', () => {
    const html = renderToStaticMarkup(
        <ResponseList empty="none" responses={R2} />,
    );
    const badges = badgeClassLists(html);

    it('renders exactly seven badges', () => {
        expect(badges).toHaveLength(7);
    });

    it.each([
        [100, 0, 'text-success'],
        [299, 1, 'text-success'],
        [300, 2, 'text-info'],
        [399, 3, 'text-info'],
        [400, 4, 'text-warning'],
        [499, 5, 'text-warning'],
        [500, 6, 'text-destructive'],
    ])('status %i (badge %i) has exactly the tone %s', (_status, i, tone) => {
        expect(toneTokens(badges[i] ?? [])).toEqual([tone]);
    });
});

describe('AC40: CopyButton is the kit Button', () => {
    const src = fs.readFileSync(
        path.join(
            fileURLToPath(new URL('.', import.meta.url)),
            'contract-views.tsx',
        ),
        'utf8',
    );
    const start = src.indexOf('function CopyButton(');
    const rest = src.slice(start + 1);
    const ends = [rest.indexOf('\nfunction '), rest.indexOf('\nexport ')]
        .filter((i) => i >= 0)
        .sort((a, b) => a - b);
    const copyButton = ends.length
        ? src.slice(start, start + 1 + ends[0])
        : src.slice(start);

    it('finds the CopyButton function', () => {
        expect(start).toBeGreaterThanOrEqual(0);
    });

    it('is a Button, outline, sm, and not a raw <button', () => {
        expect(copyButton).toContain('<Button');
        expect(copyButton).toContain('variant="outline"');
        expect(copyButton).toContain('size="sm"');
        expect(copyButton).not.toContain('<button');
    });
});

// ---------------------------------------------------------------------------
// AC44: kit gradient and icon classes (chunk 3b, revision 11)
// ---------------------------------------------------------------------------

const COMPONENTS_DIR = fileURLToPath(new URL('.', import.meta.url));
const JS_ROOT = path.join(COMPONENTS_DIR, '..');

/** Class tokens of one source file (path relative to resources/js), token-split as in AC30. */
const sourceTokens = (relPath: string) =>
    tokenize(fs.readFileSync(path.join(JS_ROOT, relPath), 'utf8'));

const svgTags = (html: string) => html.match(/<svg\b[^>]*>/g) ?? [];

describe('AC44: Button default variant is solid green (revision 12)', () => {
    // Revision 12 changed this: the default variant no longer carries `brand-gradient`
    // (it was asserted here in revision 11); it is solid `bg-primary`.
    it('is solid green: bg-primary, shadow tokens, no hover tint', () => {
        const tokens = tokensOf(buttonVariants({}));

        for (const t of [
            'bg-primary',
            'text-primary-foreground',
            'shadow-xs',
            'hover:shadow-md',
        ]) {
            expect(tokens).toContain(t);
        }

        expect(tokens).not.toContain('hover:bg-primary/90');
    });

    it.each([
        'default',
        'destructive',
        'outline',
        'secondary',
        'ghost',
        'link',
    ] as const)('the %s variant has no gradient token', (variant) => {
        expect(
            tokensOf(buttonVariants({ variant })).filter((t) =>
                /gradient/.test(t),
            ),
        ).toEqual([]);
    });

    it('the link variant is blue text, not green', () => {
        const tokens = tokensOf(buttonVariants({ variant: 'link' }));

        expect(tokens).toEqual(
            expect.arrayContaining([
                'text-info',
                'underline-offset-4',
                'hover:underline',
            ]),
        );
        expect(tokens).not.toContain('text-primary');
    });

    it('keeps the AC30 transition token', () => {
        expect(tokensOf(buttonVariants({}))).toContain(
            'transition-[color,background-color,border-color,box-shadow,transform]',
        );
    });
});

describe('AC44: Heading hero', () => {
    const html = renderToStaticMarkup(
        <Heading title="T" description="D" icon={FolderKanban}>
            <button>A</button>
        </Heading>,
    );

    it('renders one root header with data-slot="page-hero"', () => {
        expect(html.startsWith('<header ')).toBe(true);
        expect(html.endsWith('</header>')).toBe(true);
        expect(html.match(/<header\b/g)).toHaveLength(1);
        expect(html.match(/data-slot="page-hero"/g)).toHaveLength(1);
        expect(tagFor(html, 'page-hero').startsWith('<header ')).toBe(true);
    });

    it('has the hero tokens on the root', () => {
        expect(rootTokens(html)).toEqual(
            expect.arrayContaining([
                'brand-gradient',
                'text-gradient-foreground',
                'rounded-lg',
                'p-4',
                'md:p-5',
                'flex',
                'flex-wrap',
                'items-center',
                '[--color-ring:var(--gradient-foreground)]',
                // Revision 12b: fallback under the image, and the full-opacity focus ring.
                'bg-gradient-from',
                '[&_:focus-visible]:ring-ring!',
            ]),
        );
        expect(rootTokens(html)).not.toContain('bg-primary');
    });

    it('holds one hidden svg inside the icon tile span', () => {
        expect(svgTags(html)).toHaveLength(1);
        expect(svgTags(html)[0]).toContain('aria-hidden="true"');

        const tile = html.match(/<span\b([^>]*)>\s*<svg\b/);

        expect(tile, `no icon tile span in: ${html}`).not.toBeNull();
        expect(classTokens(tile![0])).toEqual(
            expect.arrayContaining([
                'bg-gradient-foreground/15',
                'size-10',
                'rounded-lg',
            ]),
        );
    });

    it('the h2 is the AC27 default set plus wrap-anywhere', () => {
        expect(tagTokens(html, 'h2')).toEqual(
            expect.arrayContaining([
                'text-lg',
                'font-semibold',
                'tracking-tight',
                'wrap-anywhere',
            ]),
        );
    });

    it('the description is text-sm, wraps anywhere and is not muted (revision 12)', () => {
        expect(tagTokens(html, 'p')).toEqual(
            expect.arrayContaining(['text-sm', 'wrap-anywhere']),
        );
        expect(tagTokens(html, 'p')).not.toContain('text-muted-foreground');
        expect(html).toMatch(/<p\b[^>]*>D<\/p>/);
    });

    it('the text block can grow but is at least 192px wide (C0-F55)', () => {
        const block = html.match(/<div\b([^>]*)>\s*<h2\b/);

        expect(block, `no div holding the h2 in: ${html}`).not.toBeNull();

        const tokens = classTokens(block![0]);

        expect(tokens).toContain('min-w-48');
        expect(tokens).toContain('flex-1');
        expect(tokens).not.toContain('min-w-0');
    });

    it('puts the action inside the header', () => {
        expect(html).toContain('<button>A</button>');
        expect(html.indexOf('<button>A</button>')).toBeGreaterThan(
            html.indexOf('<header '),
        );
        expect(html.indexOf('<button>A</button>')).toBeLessThan(
            html.lastIndexOf('</header>'),
        );
    });

    it('still passes AC10: h2, p and no mb-* on the root', () => {
        expect(html).toMatch(/<h2\b[^>]*>T<\/h2>/);
        expect(rootTokens(html).filter((t) => /(?:^|:)-?mb-/.test(t))).toEqual(
            [],
        );
    });

    it('renders no svg without an icon', () => {
        expect(renderToStaticMarkup(<Heading title="T" />)).not.toContain(
            '<svg',
        );
    });
});

describe('AC44: Heading small variant', () => {
    const html = renderToStaticMarkup(
        <Heading variant="small" title="S" icon={Wrench} />,
    );

    it('the h2 is the AC27 small set plus flex items-center gap-2', () => {
        expect(tagTokens(html, 'h2')).toEqual(
            expect.arrayContaining([
                'text-sm',
                'font-semibold',
                'flex',
                'items-center',
                'gap-2',
            ]),
        );
    });

    it('holds one hidden blue svg of size-4 (revision 12b: text-info, not text-primary)', () => {
        expect(svgTags(html)).toHaveLength(1);
        expect(svgTags(html)[0]).toContain('aria-hidden="true"');
        expect(classTokens(svgTags(html)[0]!)).toEqual(
            expect.arrayContaining(['size-4', 'shrink-0', 'text-info']),
        );
        expect(classTokens(svgTags(html)[0]!)).not.toContain('text-primary');
    });

    it('renders children inside the header, after the h2 (revision 12)', () => {
        const withChild = renderToStaticMarkup(
            <Heading variant="small" title="T">
                <button type="button">Act</button>
            </Heading>,
        );

        expect(withChild.startsWith('<header')).toBe(true);
        expect(withChild.endsWith('</header>')).toBe(true);
        expect(withChild).toContain('<button type="button">Act</button>');
        expect(withChild.indexOf('<button')).toBeGreaterThan(
            withChild.indexOf('</h2>'),
        );
        expect(withChild.indexOf('</button>')).toBeLessThan(
            withChild.lastIndexOf('</header>'),
        );
    });

    it('is not a page hero', () => {
        expect(html).not.toContain('page-hero');
    });

    it('renders no svg without an icon', () => {
        expect(
            renderToStaticMarkup(<Heading variant="small" title="S" />),
        ).not.toContain('<svg');
    });
});

describe('AC44: EmptyState icon tile', () => {
    const html = renderToStaticMarkup(
        <EmptyState icon={FolderKanban} title="X" />,
    );

    it('renders the icon in a gradient tile as one hidden svg', () => {
        expect(svgTags(html)).toHaveLength(1);
        expect(svgTags(html)[0]).toContain('aria-hidden="true"');

        const tile = html.match(/<span\b([^>]*)>\s*<svg\b/);

        expect(tile, `no icon tile span in: ${html}`).not.toBeNull();
        expect(classTokens(tile![0])).toEqual(
            expect.arrayContaining([
                'brand-gradient',
                'text-gradient-foreground',
                'size-10',
                'rounded-lg',
            ]),
        );
    });
});

describe('AC44: shell source classes', () => {
    it('nav-main.tsx has the gradient indicator and keeps bg-sidebar-primary', () => {
        const tokens = sourceTokens('components/nav-main.tsx');

        expect(tokens).toContain('data-[active=true]:before:brand-gradient');
        expect(tokens).toContain(
            'data-[active=true]:before:bg-sidebar-primary',
        );
    });

    it('app-logo.tsx has the gradient brand mark', () => {
        const tokens = sourceTokens('components/app-logo.tsx');

        expect(tokens).toContain('brand-gradient');
        expect(tokens).toContain('text-gradient-foreground');
        // Revision 12b: the fallback under the image is the gradient start, not green.
        expect(tokens).toContain('bg-gradient-from');
        expect(tokens).not.toContain('bg-primary');
    });

    it('app-sidebar-header.tsx has the accent line and keeps h-12', () => {
        const tokens = sourceTokens('components/app-sidebar-header.tsx');

        for (const t of [
            'relative',
            'after:absolute',
            'after:inset-x-0',
            'after:-bottom-px',
            'after:h-0.5',
            'after:brand-gradient',
            'h-12',
        ]) {
            expect(tokens).toContain(t);
        }
    });

    it('ui/sidebar.tsx puts the page gradient behind the inset shell', () => {
        const tokens = sourceTokens('components/ui/sidebar.tsx');

        expect(tokens).toContain('has-data-[variant=inset]:page-gradient');
        expect(tokens).toContain('group-data-[variant=inset]:bg-transparent');
        expect(tokens).not.toContain('has-data-[variant=inset]:bg-sidebar');
    });

    it('the auth layout has the page gradient and the gradient card border', () => {
        const tokens = sourceTokens('layouts/auth/auth-simple-layout.tsx');

        expect(tokens).toContain('page-gradient');
        expect(tokens).toContain('brand-gradient-border');
        expect(tokens).not.toContain('bg-muted/40');
    });
});

// Revision 12b (gate-r12): kit parts that followed --primary are blue or neutral.
describe('AC44: Blue and neutral kit parts', () => {
    const noPrimary = (tokens: string[]) =>
        expect(tokens.filter((t) => t.includes('primary'))).toEqual([]);

    it('ui/checkbox.tsx checks to blue', () => {
        const tokens = sourceTokens('components/ui/checkbox.tsx');

        expect(tokens).toEqual(
            expect.arrayContaining([
                'data-[state=checked]:bg-info',
                'data-[state=checked]:text-info-foreground',
                'data-[state=checked]:border-info',
            ]),
        );
        noPrimary(tokens);
    });

    // TooltipContent renders through a Portal, so static markup is empty: read the source.
    it('ui/tooltip.tsx is dark neutral: content and Arrow', () => {
        const text = fs.readFileSync(
            path.join(JS_ROOT, 'components/ui/tooltip.tsx'),
            'utf8',
        );
        const at = text.indexOf('TooltipPrimitive.Arrow');

        expect(at, 'no TooltipPrimitive.Arrow').toBeGreaterThan(0);

        const content = tokenize(text.slice(0, at));
        const arrow = tokenize(text.slice(at));

        expect(content).toEqual(
            expect.arrayContaining(['bg-foreground', 'text-background']),
        );
        expect(arrow).toEqual(
            expect.arrayContaining(['bg-foreground', 'fill-foreground']),
        );
        noPrimary(tokenize(text));
    });

    it('the default Badge is solid blue', () => {
        const tokens = tokensOf(badgeVariants({}));

        expect(tokens).toEqual(
            expect.arrayContaining([
                'bg-info',
                'text-info-foreground',
                '[a&]:hover:bg-info/90',
            ]),
        );
        noPrimary(tokens);
    });

    it('the Progress fill is blue', () => {
        const html = renderToStaticMarkup(<Progress label="L" value={50} />);
        const lists = allClassLists(html);

        expect(lists.some((l) => l.includes('bg-info'))).toBe(true);
        noPrimary(lists.flat());
    });

    it.each([
        'components/ui/input.tsx',
        'components/ui/native-select.tsx',
        'components/ui/textarea.tsx',
    ])('%s selects in blue', (file) => {
        const tokens = sourceTokens(file);

        expect(tokens).toEqual(
            expect.arrayContaining([
                'selection:bg-info',
                'selection:text-info-foreground',
            ]),
        );
        noPrimary(tokens);
    });

    it.each([
        ['components/framework-detection-card.tsx', 2],
        ['pages/projects/index.tsx', 1],
        ['pages/projects/wizard.tsx', 1],
    ])('%s: native radios and checkboxes use accent-info', (file, count) => {
        const tokens = sourceTokens(file);

        expect(
            tokens.filter((t) => t === 'accent-info').length,
        ).toBeGreaterThanOrEqual(count);
        expect(tokens).not.toContain('accent-primary');
    });

    it('pages/projects/wizard.tsx: checked card border and current step are blue', () => {
        const text = fs.readFileSync(
            path.join(JS_ROOT, 'pages/projects/wizard.tsx'),
            'utf8',
        );

        expect(tokenize(text)).toContain('has-checked:border-info');
        expect(text.replace(/\s+/g, ' ')).toContain(
            'border-info bg-info text-info-foreground',
        );
        noPrimary(tokenize(text));
    });

    it.each([
        'pages/dashboard.tsx',
        'pages/projects/index.tsx',
        'pages/projects/show.tsx',
    ])('%s: the card-hover border is blue (AC42)', (file) => {
        const tokens = sourceTokens(file);

        expect(tokens).toContain('hover:border-info/40');
        expect(tokens).not.toContain('hover:border-primary/40');
    });

    it.each(['components/endpoint-analysis.tsx', 'components/zip-upload.tsx'])(
        '%s uses border-info',
        (file) => {
            const tokens = sourceTokens(file);

            expect(tokens).toContain('border-info');
            expect(tokens).not.toContain('border-primary');
        },
    );
});
