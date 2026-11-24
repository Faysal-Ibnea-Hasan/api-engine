import { renderToStaticMarkup } from 'react-dom/server';
import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import ProjectController from '@/actions/App/Http/Controllers/Projects/ProjectController';
import WorkspaceController from '@/actions/App/Http/Controllers/Workspaces/WorkspaceController';
import WorkspaceMemberController from '@/actions/App/Http/Controllers/Workspaces/WorkspaceMemberController';
import Dashboard from '@/pages/dashboard';
import { edit } from '@/routes/profile';
import type { ProjectSummary } from '@/types';

// AC24 (three-branch dashboard render) and the AC33 Vitest part (card-hover tiles).
// There is no DOM environment, so Inertia is stubbed and the page is rendered to markup.

const state = vi.hoisted(() => ({ props: {} as Record<string, unknown> }));

vi.mock('@inertiajs/react', async (orig) => ({
    ...(await orig<typeof import('@inertiajs/react')>()),
    usePage: () => ({ props: state.props }),
    Head: () => null,
    Link: ({
        href,
        className,
        children,
    }: {
        href: string | { url: string };
        className?: string;
        children?: React.ReactNode;
    }) => (
        <a
            href={typeof href === 'string' ? href : href.url}
            className={className}
        >
            {children}
        </a>
    ),
}));

const tokenize = (s: string) => s.split(/[\s"'`]+/).filter(Boolean);

const unescape = (s: string) =>
    s
        .replace(/&gt;/g, '>')
        .replace(/&lt;/g, '<')
        .replace(/&quot;/g, '"')
        .replace(/&#x27;/g, "'")
        .replace(/&amp;/g, '&');

type Anchor = { href: string; tokens: string[] };

const anchors = (html: string): Anchor[] =>
    [...html.matchAll(/<a\b([^>]*)>/g)].map((m) => ({
        href: unescape(m[1].match(/\bhref="([^"]*)"/)?.[1] ?? ''),
        tokens: tokenize(unescape(m[1].match(/\bclass="([^"]*)"/)?.[1] ?? '')),
    }));

const classTokens = (html: string) =>
    [...html.matchAll(/\bclass="([^"]*)"/g)].flatMap((m) =>
        tokenize(unescape(m[1])),
    );

const workspace = {
    id: 'ws-1',
    name: 'Acme Platform Team',
    role: 'owner',
    roleLabel: 'Workspace Owner',
    can: {},
};

const render = (
    props: Record<string, unknown>,
    recentProjects?: ProjectSummary[],
) => {
    state.props = {
        name: 'API Engine',
        auth: { user: { id: 1, name: 'Ada', email: 'ada@test.local' } },
        sidebarOpen: true,
        ...props,
    };

    return renderToStaticMarkup(<Dashboard recentProjects={recentProjects} />);
};

const withWorkspace = (
    projectWizard: boolean,
    recentProjects?: ProjectSummary[],
) =>
    render(
        {
            features: { project_wizard: projectWizard },
            workspaces: [
                {
                    id: workspace.id,
                    name: workspace.name,
                    roleLabel: workspace.roleLabel,
                },
            ],
            currentWorkspace: workspace,
        },
        recentProjects,
    );

const PROJECTS = ProjectController.index().url;
const MEMBERS = WorkspaceMemberController.index(workspace.id).url;
const PROFILE = edit().url;

beforeEach(() => {
    state.props = {};
});

describe('AC24: workspace branch', () => {
    describe.each([true, false])('project_wizard = %s', (wizard) => {
        const html = () => withWorkspace(wizard);

        it('shows the workspace name and role label', () => {
            expect(html()).toContain(workspace.name);
            expect(html()).toContain(workspace.roleLabel);
        });

        it('links to members and to the profile settings', () => {
            const hrefs = anchors(html()).map((a) => a.href);

            expect(hrefs).toContain(MEMBERS);
            expect(hrefs).toContain(PROFILE);
        });

        it(`${wizard ? 'links' : 'does not link'} to the projects list`, () => {
            const hrefs = anchors(html()).map((a) => a.href);

            if (wizard) {
                expect(hrefs).toContain(PROJECTS);
            } else {
                expect(hrefs).not.toContain(PROJECTS);
            }
        });

        it('has no placeholder pattern and no full-height block', () => {
            const markup = html();
            const tokens = classTokens(markup);

            expect(markup).not.toContain('<pattern');
            expect(tokens).not.toContain('min-h-[100vh]');
            expect(tokens).not.toContain('min-h-screen');
        });
    });
});

describe('AC24: no-workspace branch', () => {
    const html = () =>
        render({
            features: { project_wizard: true },
            workspaces: [],
            currentWorkspace: null,
        });

    it('keeps the empty state and its link', () => {
        expect(html()).toContain('No workspace yet');
        expect(html()).toContain('Create a workspace');
        expect(anchors(html()).map((a) => a.href)).toContain(
            WorkspaceController.index().url,
        );
    });
});

describe('AC24: workspace management off', () => {
    const html = () =>
        render({
            features: { project_wizard: true },
            workspaces: null,
            currentWorkspace: null,
        });

    it('shows the Dashboard heading and exactly one link, to the profile settings', () => {
        expect(html()).toMatch(/<h[1-6][^>]*>\s*Dashboard\s*</);
        expect(anchors(html()).map((a) => a.href)).toEqual([PROFILE]);
    });

    it('has no placeholder pattern', () => {
        expect(html()).not.toContain('<pattern');
    });
});

describe('AC33: dashboard tiles use card-hover', () => {
    const tiles = () =>
        anchors(withWorkspace(true)).filter((a) =>
            [PROJECTS, MEMBERS, PROFILE].includes(a.href),
        );

    it('has all three tiles', () => {
        expect(
            tiles()
                .map((t) => t.href)
                .sort(),
        ).toEqual([PROJECTS, MEMBERS, PROFILE].sort());
    });

    it.each([
        ['projects', PROJECTS],
        ['members', MEMBERS],
        ['profile', PROFILE],
    ])(
        'the %s tile carries the card-hover and focus ring tokens',
        (_n, href) => {
            const tile = tiles().find((t) => t.href === href)!;

            for (const token of [
                'transition-[border-color,box-shadow]',
                'hover:border-primary/40',
                'hover:shadow-sm',
                'outline-none',
                'focus-visible:ring-[3px]',
                'focus-visible:ring-ring/50',
            ]) {
                expect(tile.tokens).toContain(token);
            }
        },
    );

    it('no tile lifts or scales on hover', () => {
        for (const tile of tiles()) {
            for (const token of tile.tokens) {
                expect(token).not.toMatch(/(?:^|:)(?:-?translate|scale)-/);
            }
        }
    });
});

describe('AC24: no recent-projects section without the prop', () => {
    it.each([
        ['workspace', () => withWorkspace(true)],
        [
            'no-workspace',
            () =>
                render({
                    features: { project_wizard: true },
                    workspaces: [],
                    currentWorkspace: null,
                }),
        ],
        [
            'workspace management off',
            () =>
                render({
                    features: { project_wizard: true },
                    workspaces: null,
                    currentWorkspace: null,
                }),
        ],
    ])('the %s branch has no "Recent projects" text', (_n, html) => {
        expect(html()).not.toContain('Recent projects');
    });
});

describe('AC24: recent projects branch', () => {
    const R: ProjectSummary[] = [
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

    const tagTokens = (attrs: string) =>
        tokenize(unescape(attrs.match(/\bclass="([^"]*)"/)?.[1] ?? ''));

    const lists = (html: string) =>
        [...html.matchAll(/<ul\b([^>]*)>([\s\S]*?)<\/ul>/g)].map((m) => ({
            tokens: tagTokens(m[1]),
            body: m[2],
        }));

    const dividedLists = (html: string) =>
        lists(html).filter((u) => u.tokens.includes('divide-y'));

    const rows = (html: string) =>
        [
            ...dividedLists(html)[0].body.matchAll(
                /<li\b[^>]*>([\s\S]*?)<\/li>/g,
            ),
        ].map((m) => m[1]);

    const viewAll = (html: string) =>
        [...html.matchAll(/<a\b([^>]*)>\s*View all projects\s*<\/a>/g)].map(
            (m) => ({
                href: unescape(m[1].match(/\bhref="([^"]*)"/)?.[1] ?? ''),
                tokens: tagTokens(m[1]),
                index: m.index,
            }),
        );

    it('has a Recent projects h2', () => {
        expect(withWorkspace(true, R)).toMatch(
            /<h2\b[^>]*>\s*Recent projects\s*<\/h2>/,
        );
    });

    it('has one divided list with two rows in input order', () => {
        const html = withWorkspace(true, R);

        expect(dividedLists(html)).toHaveLength(1);
        const r = rows(html);

        expect(r).toHaveLength(2);
        expect(r[0]).toContain('Billing API');
        expect(r[1]).toContain('Internal Admin API');
        expect(r[0]).not.toContain('Internal Admin API');
    });

    it.each([0, 1])(
        'row %i has one link to the project with row tokens',
        (i) => {
            const row = rows(withWorkspace(true, R))[i];
            const links = anchors(row);

            expect(links).toHaveLength(1);
            expect(links[0].href).toBe(ProjectController.show(R[i].id).url);

            for (const token of [
                'transition-[color,background-color,box-shadow]',
                'hover:bg-muted/50',
                'outline-none',
                'focus-visible:ring-[3px]',
                'focus-visible:ring-ring/50',
                'focus-visible:ring-inset',
                'px-3',
                'py-2',
            ]) {
                expect(links[0].tokens).toContain(token);
            }

            for (const token of links[0].tokens) {
                expect(token).not.toMatch(/(?:^|:)(?:-?translate|scale)-/);
            }
        },
    );

    it.each([0, 1])('row %i shows the name and a truncating slug code', (i) => {
        const row = rows(withWorkspace(true, R))[i];
        const code = row.match(/<code\b([^>]*)>([\s\S]*?)<\/code>/)!;

        expect(row).toContain(R[i].name);
        expect(code[2]).toBe(R[i].slug);
        expect(code[1].match(/\btitle="([^"]*)"/)?.[1]).toBe(R[i].slug);

        for (const token of ['font-mono', 'text-code', 'min-w-0', 'truncate']) {
            expect(tagTokens(code[1])).toContain(token);
        }
    });

    it('shows the status badges for each row', () => {
        const [r1, r2] = rows(withWorkspace(true, R));

        expect(r1).toContain('Active');
        expect(r1).not.toContain('Restricted');
        expect(tokenize(r1.match(/class="([^"]*)"/g)!.join(' '))).toContain(
            'text-success',
        );
        expect(r2).toContain('Draft');
        expect(r2).toContain('Restricted');
        expect(r2).not.toContain('text-success');
    });

    it('has a View all projects link outside the list', () => {
        const html = withWorkspace(true, R);
        const links = viewAll(html);

        expect(links).toHaveLength(1);
        expect(links[0].href).toBe(PROJECTS);
        expect(links[0].tokens).toContain('focus-visible:ring-[3px]');
        expect(
            lists(html).some((u) => u.body.includes('View all projects')),
        ).toBe(false);
    });

    it('shows no setup text', () => {
        expect(withWorkspace(true, R)).not.toContain('Setup not finished');
    });

    it('renders the empty state for an empty list', () => {
        const html = withWorkspace(true, []);

        expect(html).toContain('Recent projects');
        expect(html).toContain('No projects yet');
        expect(html).toContain('Projects your team creates will show up here.');
        expect(viewAll(html)).toHaveLength(1);
        expect(dividedLists(html)).toHaveLength(0);
    });

    it('renders an HTML-like name as escaped text', () => {
        const html = withWorkspace(true, [
            { ...R[0], name: '<img src=x onerror=alert(1)>' },
        ]);

        expect(html).toContain('&lt;img');
        expect(html).not.toContain('<img');
    });
});
