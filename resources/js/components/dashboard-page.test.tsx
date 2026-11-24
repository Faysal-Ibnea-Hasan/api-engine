import { renderToStaticMarkup } from 'react-dom/server';
import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import ProjectController from '@/actions/App/Http/Controllers/Projects/ProjectController';
import WorkspaceController from '@/actions/App/Http/Controllers/Workspaces/WorkspaceController';
import WorkspaceMemberController from '@/actions/App/Http/Controllers/Workspaces/WorkspaceMemberController';
import Dashboard from '@/pages/dashboard';
import { edit } from '@/routes/profile';

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

const render = (props: Record<string, unknown>) => {
    state.props = {
        name: 'API Engine',
        auth: { user: { id: 1, name: 'Ada', email: 'ada@test.local' } },
        sidebarOpen: true,
        ...props,
    };

    return renderToStaticMarkup(<Dashboard />);
};

const withWorkspace = (projectWizard: boolean) =>
    render({
        features: { project_wizard: projectWizard },
        workspaces: [
            {
                id: workspace.id,
                name: workspace.name,
                roleLabel: workspace.roleLabel,
            },
        ],
        currentWorkspace: workspace,
    });

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
