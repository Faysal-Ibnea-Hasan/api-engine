import type { ReactNode } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vite-plus/test';
import ProjectController from '@/actions/App/Http/Controllers/Projects/ProjectController';
import ProjectsIndex from '@/pages/projects/index';
import type { ProjectSummary } from '@/types';

// AC42: the /projects card grid. There is no DOM environment, so Inertia is
// stubbed (AC24 mock plus the Form stub the adversarial render uses).

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: {}, url: '/projects' }),
    router: { get() {}, put() {}, post() {}, reload() {}, flushAll() {} },
    Head: () => null,
    Form: ({
        children,
        action,
    }: {
        children?:
            | ReactNode
            | ((state: {
                  processing: boolean;
                  errors: Record<string, string>;
              }) => ReactNode);
        action?: string;
    }) => (
        <form action={action}>
            {typeof children === 'function'
                ? children({ processing: false, errors: {} })
                : children}
        </form>
    ),
    Link: ({
        href,
        className,
        children,
    }: {
        href: string | { url: string };
        className?: string;
        children?: ReactNode;
    }) => (
        <a
            href={typeof href === 'string' ? href : href.url}
            className={className}
        >
            {children}
        </a>
    ),
}));

const P: ProjectSummary[] = [
    {
        id: 'p1',
        name: 'Billing API',
        slug: 'billing-api',
        status: 'active',
        visibility: 'workspace',
        setupComplete: true,
        updatedAt: null,
    },
    {
        id: 'p2',
        name: 'Internal Admin API',
        slug: 'internal-admin-api',
        status: 'draft',
        visibility: 'restricted',
        setupComplete: false,
        updatedAt: null,
    },
];

const render = (projects: ProjectSummary[], canCreate = true) =>
    renderToStaticMarkup(
        <ProjectsIndex
            projects={projects}
            filters={{ q: '', archived: false }}
            canCreate={canCreate}
        />,
    );

const unescape = (s: string) =>
    s
        .replace(/&gt;/g, '>')
        .replace(/&lt;/g, '<')
        .replace(/&quot;/g, '"')
        .replace(/&#x27;/g, "'")
        .replace(/&amp;/g, '&');

const tokens = (attrs: string) =>
    unescape(attrs.match(/\bclass="([^"]*)"/)?.[1] ?? '')
        .split(/\s+/)
        .filter(Boolean);

const attr = (attrs: string, name: string) =>
    attrs.match(new RegExp(`\\b${name}="([^"]*)"`))?.[1];

const grid = (html: string) => {
    const uls = [...html.matchAll(/<ul\b([^>]*)>([\s\S]*?)<\/ul>/g)];

    expect(uls).toHaveLength(1);

    return { tokens: tokens(uls[0][1]), body: uls[0][2] };
};

const cards = (html: string) =>
    [...grid(html).body.matchAll(/<li\b([^>]*)>([\s\S]*?)<\/li>/g)].map(
        (m) => ({
            liTokens: tokens(m[1]),
            body: m[2],
        }),
    );

describe('AC42: projects card grid', () => {
    it('is one responsive grid, not a divided list', () => {
        const g = grid(render(P)).tokens;

        for (const t of ['grid', 'gap-3', 'md:grid-cols-2', 'xl:grid-cols-3']) {
            expect(g).toContain(t);
        }

        expect(g).not.toContain('divide-y');
    });

    it('has two cards in input order, each li able to shrink', () => {
        const c = cards(render(P));

        expect(c).toHaveLength(2);
        expect(c[0].body).toContain('Billing API');
        expect(c[1].body).toContain('Internal Admin API');
        expect(c[0].body).not.toContain('Internal Admin API');

        for (const card of c) {
            expect(card.liTokens).toContain('min-w-0');
        }
    });

    it.each([0, 1])(
        'card %i has one link with panel, hover and ring tokens',
        (i) => {
            const body = cards(render(P))[i].body;
            const links = [...body.matchAll(/<a\b([^>]*)>/g)];

            expect(links).toHaveLength(1);
            expect(unescape(attr(links[0][1], 'href')!)).toBe(
                ProjectController.show(P[i].id).url,
            );

            const t = tokens(links[0][1]);

            for (const token of [
                'rounded-lg',
                'border',
                'bg-card',
                'transition-[border-color,box-shadow]',
                'hover:border-primary/40',
                'hover:shadow-sm',
                'outline-none',
                'focus-visible:ring-[3px]',
                'focus-visible:ring-ring/50',
                'p-4',
            ]) {
                expect(t).toContain(token);
            }

            expect(t).not.toContain('hover:bg-muted/50');
            expect(t).not.toContain('focus-visible:ring-inset');

            for (const token of t) {
                expect(token).not.toMatch(/(?:^|:)(?:-?translate|scale)-/);
            }
        },
    );

    it.each([0, 1])(
        'card %i truncates the name and the slug with titles',
        (i) => {
            const body = cards(render(P))[i].body;
            const name = [
                ...body.matchAll(/<(\w+)\b([^>]*)>([^<]*)<\/\1>/g),
            ].find((m) => m[3] === P[i].name)!;
            const code = body.match(/<code\b([^>]*)>([\s\S]*?)<\/code>/)!;

            expect(tokens(name[2])).toEqual(
                expect.arrayContaining(['truncate', 'font-medium']),
            );
            expect(attr(name[2], 'title')).toBe(P[i].name);
            expect(tokens(code[1])).toEqual(
                expect.arrayContaining(['truncate', 'font-mono', 'text-code']),
            );
            expect(attr(code[1], 'title')).toBe(P[i].slug);
            expect(code[2]).toBe(P[i].slug);
        },
    );

    it('shows the status badges per card', () => {
        const [c1, c2] = cards(render(P));

        expect(c1.body).toContain('Active');
        expect(c1.body).not.toContain('Restricted');
        expect(c2.body).toContain('Draft');
        expect(c2.body).toContain('Restricted');
    });

    it('says "Setup not finished" once, in card 2 only', () => {
        const html = render(P);
        const [c1, c2] = cards(html);

        expect(html.match(/Setup not finished/g)).toHaveLength(1);
        expect(c1.body).not.toContain('Setup not finished');
        expect(c2.body).toContain('Setup not finished');
    });

    it('hides the Create project link when canCreate is false', () => {
        const html = render(P, false);

        expect(html).not.toMatch(/<a\b[^>]*>\s*Create project\s*<\/a>/);
    });

    it('renders the empty state with no list for no projects', () => {
        const html = render([]);

        expect(html).toContain('No projects yet');
        expect(html).not.toContain('<ul');
    });
});
