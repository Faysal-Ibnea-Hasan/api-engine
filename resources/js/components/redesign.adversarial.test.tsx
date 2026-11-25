import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { FolderKanban } from 'lucide-react';
import type { ComponentProps, ReactNode } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import { AppSidebar } from '@/components/app-sidebar';
import AppLogo from '@/components/app-logo';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { ResponseList } from '@/components/contract-views';
import EmptyState from '@/components/empty-state';
import { GitHubAppSetupGuide } from '@/components/github-guides';
import Heading from '@/components/heading';
import { ImpersonationBanner } from '@/components/impersonation-banner';
import ImportStatus from '@/components/import-status';
import MethodBadge from '@/components/method-badge';
import { NavUser } from '@/components/nav-user';
import Progress from '@/components/progress';
import ProjectStatusBadge from '@/components/project-status-badge';
import ResolutionBadge from '@/components/resolution-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { NativeSelect } from '@/components/ui/native-select';
import { SidebarProvider } from '@/components/ui/sidebar';
import { Textarea } from '@/components/ui/textarea';
import { TooltipProvider } from '@/components/ui/tooltip';
import { UserInfo } from '@/components/user-info';
import { WorkspaceSwitcher } from '@/components/workspace-switcher';
import { useInitials } from '@/hooks/use-initials';
import AuthSimpleLayout from '@/layouts/auth/auth-simple-layout';
import SettingsLayout from '@/layouts/settings/layout';
import ShowDocumentation from '@/pages/projects/documentation';
import ShowEndpoint from '@/pages/projects/endpoint';
import Endpoints from '@/pages/projects/endpoints';
import ProjectsIndex from '@/pages/projects/index';
import ShowProject from '@/pages/projects/show';
import ShowSnapshot from '@/pages/projects/snapshot';
import type { ProjectSummary, User } from '@/types';

// Chunk 1 adversarial tests: hostile user-controlled strings in the app shell,
// and no new HTML injection sinks. Later chunks extend this file.

const page = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    url: '/dashboard',
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => page,
    router: {
        put: () => {},
        post: () => {},
        reload: () => {},
        flushAll: () => {},
    },
    // Chunk 3 page renders: Head, a render-prop Form and usePoll.
    Head: () => null,
    usePoll: () => ({ start() {}, stop() {} }),
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
        children,
        prefetch: _prefetch,
        preserveScroll: _preserveScroll,
        ...rest
    }: {
        href: string | { url: string };
        children?: ReactNode;
        prefetch?: boolean;
        preserveScroll?: boolean;
        [key: string]: unknown;
    }) => (
        <a href={typeof href === 'string' ? href : href.url} {...rest}>
            {children}
        </a>
    ),
}));

// Radix portals render nothing in static markup; pass children through so
// the workspace list and the user menu actually render.
vi.mock('@/components/ui/dropdown-menu', () => {
    const Pass = ({
        children,
        asChild,
    }: {
        children?: ReactNode;
        asChild?: boolean;
    }) => (asChild ? <>{children}</> : <div>{children}</div>);

    return {
        DropdownMenu: Pass,
        DropdownMenuContent: Pass,
        DropdownMenuGroup: Pass,
        DropdownMenuItem: Pass,
        DropdownMenuLabel: Pass,
        DropdownMenuSeparator: () => <hr />,
        DropdownMenuTrigger: Pass,
    };
});

const XSS = [
    '<img src=x onerror=alert(1)>',
    '</span><script>alert(1)</script>',
    '"><svg onload=alert(1)>',
];

function XSS_ESCAPED(payload: string) {
    return payload
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

const truncated = (text: string) =>
    new RegExp(`<span class="[^"]*\\btruncate\\b[^"]*">${text}</span>`);

/** The payload must be absent raw and present escaped (proves it rendered). */
function expectEscaped(html: string, payload: string) {
    expect(html).not.toContain(payload);
    // Payload-derived tags only: the app's own icons are real <svg>s.
    expect(html).not.toMatch(/<script|<img src=x|<svg onload/i);
    expect(html).toContain(XSS_ESCAPED(payload));
}

const user = (overrides: Partial<User> = {}): User => ({
    id: 1,
    name: 'Ada Lovelace',
    email: 'ada@example.test',
    email_verified_at: null,
    created_at: '2026-01-01',
    updated_at: '2026-01-01',
    ...overrides,
});

const workspace = (id: string, name: string, roleLabel = 'Owner') => ({
    id,
    name,
    roleLabel,
    can: {},
});

const inSidebar = (node: ReactNode) =>
    renderToStaticMarkup(
        <TooltipProvider>
            <SidebarProvider>{node}</SidebarProvider>
        </TooltipProvider>,
    );

beforeEach(() => {
    page.props = {
        name: 'API Engine',
        auth: { user: user() },
        workspaces: [],
        currentWorkspace: null,
        features: { project_wizard: true },
    };
});

describe('Adversary chunk 1: workspace names in the switcher', () => {
    it.each(XSS)('escapes a hostile current workspace name: %s', (payload) => {
        page.props.workspaces = [workspace('w1', payload)];
        page.props.currentWorkspace = workspace('w1', payload);

        expectEscaped(inSidebar(<WorkspaceSwitcher />), payload);
    });

    it.each(XSS)('escapes hostile list names and roles: %s', (payload) => {
        page.props.workspaces = [
            workspace('w1', 'Safe'),
            workspace('w2', payload, payload),
        ];
        page.props.currentWorkspace = workspace('w1', 'Safe');

        const html = inSidebar(<WorkspaceSwitcher />);

        expectEscaped(html, payload);
        expect(html.split(XSS_ESCAPED(payload)).length - 1).toBe(2);
    });

    it('renders nothing when workspaces is null (feature off)', () => {
        page.props.workspaces = null;

        expect(inSidebar(<WorkspaceSwitcher />)).not.toContain(
            'data-test="workspace-switcher"',
        );
    });

    it('shows "No workspace" when there is no current workspace', () => {
        page.props.workspaces = [workspace('w1', 'Other')];

        expect(inSidebar(<WorkspaceSwitcher />)).toContain('No workspace');
    });

    it('keeps truncate on a very long workspace name', () => {
        const long = 'W'.repeat(5000);
        page.props.workspaces = [workspace('w1', long)];
        page.props.currentWorkspace = workspace('w1', long);

        const html = inSidebar(<WorkspaceSwitcher />);

        expect(html).toMatch(truncated(long));
    });
});

describe('Adversary chunk 1: user name and email', () => {
    it.each(XSS)('UserInfo escapes a hostile name and email: %s', (payload) => {
        const html = renderToStaticMarkup(
            <UserInfo
                user={user({ name: payload, email: payload })}
                showEmail
            />,
        );

        expectEscaped(html, payload);
        expect(html.split(XSS_ESCAPED(payload)).length - 1).toBe(2);
    });

    it.each(XSS)('NavUser and its menu escape a name: %s', (payload) => {
        page.props.auth = { user: user({ name: payload, email: payload }) };

        const html = inSidebar(<NavUser />);

        expectEscaped(html, payload);
    });

    it('NavUser renders nothing for a guest', () => {
        page.props.auth = { user: null };

        expect(inSidebar(<NavUser />)).not.toContain(
            'data-test="sidebar-menu-button"',
        );
    });

    it('keeps truncate on very long name and email', () => {
        const name = 'N'.repeat(3000);
        const email = `${'e'.repeat(3000)}@example.test`;
        const html = renderToStaticMarkup(
            <UserInfo user={user({ name, email })} showEmail />,
        );

        expect(html).toMatch(truncated(name));
        expect(html).toMatch(truncated(email));
    });

    it.each([
        ['', ''],
        ['   \t\n ', ''],
        ['😀 Ångström', '😀Å'],
        ['<b>x</b> <i>y</i>', '<<'],
    ])('getInitials handles %j without throwing', (name, expected) => {
        let getInitials: (s: string) => string = () => '';

        const Probe = () => {
            getInitials = useInitials();

            return null;
        };

        renderToStaticMarkup(<Probe />);

        expect(getInitials(name)).toBe(expected);
    });

    it('escapes hostile initials in the avatar fallback', () => {
        const html = renderToStaticMarkup(
            <UserInfo user={user({ name: '<b>x</b> <i>y</i>' })} />,
        );

        expect(html).not.toMatch(/<b>|<i>/);
        expect(html).toContain('&lt;&lt;');
    });
});

describe('Adversary chunk 1: app name, breadcrumbs, auth layout', () => {
    it.each(XSS)('AppLogo escapes a hostile app name: %s', (payload) => {
        page.props.name = payload;

        expectEscaped(renderToStaticMarkup(<AppLogo />), payload);
    });

    it('AppLogo keeps truncate on a very long app name', () => {
        page.props.name = 'A'.repeat(4000);

        expect(renderToStaticMarkup(<AppLogo />)).toMatch(
            /<span class="[^"]*\btruncate\b[^"]*">A{4000}<\/span>/,
        );
    });

    it.each(XSS)('Breadcrumbs escape link and page titles: %s', (payload) => {
        const html = renderToStaticMarkup(
            <Breadcrumbs
                breadcrumbs={[
                    { title: payload, href: '/workspaces' },
                    { title: payload, href: '/workspaces/1' },
                ]}
            />,
        );

        expectEscaped(html, payload);
        expect(html.split(XSS_ESCAPED(payload)).length - 1).toBe(2);
    });

    it.each(XSS)('auth layout escapes title x2 and description: %s', (p) => {
        const html = renderToStaticMarkup(
            <AuthSimpleLayout title={p} description={p}>
                <form />
            </AuthSimpleLayout>,
        );

        expectEscaped(html, p);
        expect(html.split(XSS_ESCAPED(p)).length - 1).toBe(3);
    });
});

describe('Adversary chunk 1: sidebar nav gating survives the restyle', () => {
    const links = () =>
        [...inSidebar(<AppSidebar />).matchAll(/<a [^>]*href="([^"]+)"/g)].map(
            (m) => m[1],
        );

    it('no current workspace: no Projects or Members link', () => {
        const hrefs = links();

        expect(hrefs.some((h) => /\/members\b/.test(h))).toBe(false);
        expect(hrefs.some((h) => /\/projects\b/.test(h))).toBe(false);
        expect(hrefs).toContain('/dashboard');
    });

    it('project_wizard off: no Projects link, Members stays', () => {
        page.props.currentWorkspace = workspace('w1', 'Team');
        page.props.workspaces = [workspace('w1', 'Team')];
        page.props.features = { project_wizard: false };

        const hrefs = links();

        expect(hrefs.some((h) => /\/projects\b/.test(h))).toBe(false);
        expect(hrefs.some((h) => /\/members\b/.test(h))).toBe(true);
    });

    it('renders no external or target=_blank link (footer removed)', () => {
        page.props.currentWorkspace = workspace('w1', 'Team');
        page.props.workspaces = [workspace('w1', 'Team')];

        const html = inSidebar(<AppSidebar />);

        expect(html).not.toContain('target="_blank"');
        expect(html).not.toMatch(/href="https?:\/\//);
    });
});

// ---------------------------------------------------------------------------
// Static: no new HTML injection sinks
// ---------------------------------------------------------------------------

const JS_DIR = path.join(fileURLToPath(new URL('.', import.meta.url)), '..');
const rel = (f: string) => path.relative(JS_DIR, f).split(path.sep).join('/');

function walk(dir: string): string[] {
    return fs.readdirSync(dir, { withFileTypes: true }).flatMap((e) => {
        const full = path.join(dir, e.name);

        if (e.isDirectory()) return walk(full);

        return /\.tsx?$/.test(e.name) && !/\.test\.tsx?$/.test(e.name)
            ? [full]
            : [];
    });
}

const filesMatching = (re: RegExp) =>
    walk(JS_DIR)
        .filter((f) => re.test(fs.readFileSync(f, 'utf8')))
        .map(rel)
        .sort((a, b) => a.localeCompare(b));

describe('Adversary chunk 1: no new HTML injection sinks', () => {
    it('dangerouslySetInnerHTML only in the 2FA setup modal', () => {
        // Outside AI feature: workflow-explanation.tsx allowed at one sink, user "HTML sink: allow ... PINNED to safe mode" (ledger); pinned by the next row.
        expect(filesMatching(/dangerouslySetInnerHTML/)).toEqual([
            'components/two-factor-setup-modal.tsx',
            'components/workflow-explanation.tsx',
        ]);
        expect(
            readRoot('resources/js/components/workflow-explanation.tsx').match(
                /dangerouslySetInnerHTML/g,
            ),
        ).toHaveLength(1);
    });

    it('the workflow explanation html is Str::markdown with html_input strip and allow_unsafe_links false (pin)', () => {
        const src = readRoot(
            'app/Http/Controllers/Analysis/WorkflowController.php',
        );
        const opts = src.match(
            /'html'\s*=>\s*[^,]*?\?\s*null\s*:\s*Str::markdown\(\s*\$explanation->markdown\s*,\s*\[([^\]]*)\]\s*\)/,
        )?.[1];

        expect(src.match(/'html'\s*=>/g)).toHaveLength(1);
        expect(opts).toMatch(/'html_input'\s*=>\s*'strip'/);
        expect(opts).toMatch(/'allow_unsafe_links'\s*=>\s*false\b/);
    });

    it('no innerHTML, insertAdjacentHTML, eval or document.write', () => {
        const sinks = [
            /\.(?:inner|outer)HTML\s*=/,
            /insertAdjacentHTML/,
            /\beval\(/,
            /new Function\(/,
            /document\.write/,
        ];

        expect(sinks.flatMap((re) => filesMatching(re))).toEqual([]);
    });

    it('target="_blank" only where it already was (none in the shell)', () => {
        // The spec keeps existing external links as they are (rel included),
        // so this guards against new ones, not missing rel on existing ones.
        expect(filesMatching(/target="_blank"/)).toEqual([
            'components/app-header.tsx',
            'pages/welcome.tsx',
        ]);
    });

    it('app.blade.php has no unescaped {!! !!} output', () => {
        const blade = fs.readFileSync(
            path.join(JS_DIR, '../views/app.blade.php'),
            'utf8',
        );

        expect(blade).not.toContain('{!!');
    });
});

// ===========================================================================
// Chunk 2: the shared kit under hostile input
// ===========================================================================

const ROOT_DIR = path.join(JS_DIR, '../..');
const readRoot = (f: string) => fs.readFileSync(path.join(ROOT_DIR, f), 'utf8');

const tokensOfTag = (tag: string) =>
    (tag.match(/\bclass="([^"]*)"/)?.[1] ?? '')
        .replace(/&amp;/g, '&')
        .split(/\s+/)
        .filter(Boolean);

const slotTag = (html: string, slot: string) => {
    const m = html.match(
        new RegExp(`<[a-z]+\\b[^>]*data-slot="${slot}"[^>]*>`),
    );

    expect(m, `no data-slot="${slot}" in: ${html}`).not.toBeNull();

    return m![0];
};

const TONE_TOKEN =
    /^text-(?:success|info|warning|destructive|muted-foreground)$/;

describe('Adversary chunk 2: MethodBadge with discovered (uploaded) methods', () => {
    // RouteEvaluator upper-cases any literal verb in Route::match(), so the
    // method text is attacker-controlled by whoever uploads the source.
    it.each(XSS.map((p) => p.toUpperCase()))('escapes %s', (payload) => {
        expectEscaped(
            renderToStaticMarkup(<MethodBadge method={payload} />),
            payload,
        );
    });

    it.each([
        'TOSTRING',
        'CONSTRUCTOR',
        '__PROTO__',
        'HASOWNPROPERTY',
        'VALUEOF',
        'get',
        ' GET',
        'GET ',
        'GET|HEAD',
        '',
    ])('%j gets exactly one tone token, the muted fallback', (method) => {
        const html = renderToStaticMarkup(<MethodBadge method={method} />);
        const tokens = tokensOfTag(slotTag(html, 'method-badge'));

        expect(tokens.filter((t) => TONE_TOKEN.test(t))).toEqual([
            'text-muted-foreground',
        ]);
        expect(tokens).toContain('bg-muted');
    });

    it('a very long method keeps its full text but is width-capped (AC14)', () => {
        // methods_label is varchar(255) ("|VERB|HEAD|"), so ~240 chars fit.
        const long = 'X'.repeat(240);
        const html = renderToStaticMarkup(<MethodBadge method={long} />);
        const tokens = tokensOfTag(slotTag(html, 'method-badge'));

        expect(html).toContain(long);
        // Endpoint rows are flex-wrap; an uncapped inline-flex badge with one
        // unbroken token cannot shrink and pushes the row past the viewport.
        expect(tokens.some((t) => t.startsWith('max-w-'))).toBe(true);
        expect(
            tokens.some((t) =>
                ['truncate', 'overflow-hidden', 'break-all'].includes(t),
            ),
        ).toBe(true);
    });
});

describe('Adversary chunk 2: status badges with unknown and prototype keys', () => {
    const PROTO = [
        'toString',
        'constructor',
        'hasOwnProperty',
        'valueOf',
        '__proto__',
    ];
    const secondary = tokensOfTag(
        slotTag(
            renderToStaticMarkup(<Badge variant="secondary">x</Badge>),
            'badge',
        ),
    );

    it.each([...PROTO, 'superseded'])(
        'ImportStatus %j renders and falls back to secondary (contract: everything else)',
        (status) => {
            const html = renderToStaticMarkup(
                <ImportStatus status={status as never} />,
            );

            expect(tokensOfTag(slotTag(html, 'badge'))).toEqual(
                expect.arrayContaining(secondary),
            );
        },
    );

    it.each(PROTO)(
        'ProjectStatusBadge %j renders without throwing',
        (status) => {
            expect(() =>
                renderToStaticMarkup(
                    <ProjectStatusBadge
                        status={status as never}
                        visibility="workspace"
                    />,
                ),
            ).not.toThrow();
        },
    );

    it.each(PROTO)(
        'ResolutionBadge %j renders without throwing',
        (resolution) => {
            expect(() =>
                renderToStaticMarkup(
                    <ResolutionBadge resolution={resolution as never} />,
                ),
            ).not.toThrow();
        },
    );

    it.each(XSS)('Badge escapes and keeps a very long child: %s', (payload) => {
        const long = payload.repeat(500);
        const html = renderToStaticMarkup(<Badge variant="info">{long}</Badge>);

        expectEscaped(html, payload);
        expect(html).toContain(XSS_ESCAPED(long));
    });
});

describe('Adversary chunk 2: Alert role and content', () => {
    it('an explicit role={undefined} keeps the default role="alert"', () => {
        // e.g. <Alert role={isStatus ? 'status' : undefined}>: the spread
        // after role="alert" would silently drop the live-region role.
        const html = renderToStaticMarkup(
            <Alert variant="destructive" role={undefined}>
                <AlertDescription>d</AlertDescription>
            </Alert>,
        );

        expect(slotTag(html, 'alert')).toContain('role="alert"');
    });

    it.each(XSS)('escapes a hostile description: %s', (payload) => {
        expectEscaped(
            renderToStaticMarkup(
                <Alert variant="info" role="note">
                    <AlertDescription>{payload}</AlertDescription>
                </Alert>,
            ),
            payload,
        );
    });
});

describe('Adversary chunk 2: NativeSelect and Textarea forward every prop', () => {
    const options = (
        <>
            <option value="">All</option>
            <option value="GET">GET</option>
            <option value="POST">POST</option>
        </>
    );

    it('defaultValue selects only the matching option', () => {
        const html = renderToStaticMarkup(
            <NativeSelect defaultValue="GET">{options}</NativeSelect>,
        );

        expect(html).toMatch(/<option value="GET"[^>]*\bselected\b/);
        expect(html).not.toMatch(/<option value=""[^>]*\bselected\b/);
        expect(html).not.toMatch(/<option value="POST"[^>]*\bselected\b/);
    });

    it('multiple + array defaultValue selects each value', () => {
        const html = renderToStaticMarkup(
            <NativeSelect multiple defaultValue={['GET', 'POST']}>
                {options}
            </NativeSelect>,
        );

        expect(html).toContain('multiple=""');
        expect(html).toMatch(/<option value="GET"[^>]*\bselected\b/);
        expect(html).toMatch(/<option value="POST"[^>]*\bselected\b/);
    });

    it.each([
        [
            'NativeSelect',
            () => (
                <NativeSelect disabled required aria-invalid="true" name="m" />
            ),
            'native-select',
        ],
        [
            'Textarea',
            () => <Textarea disabled required aria-invalid="true" name="m" />,
            'textarea',
        ],
    ] as const)(
        '%s forwards disabled, required and aria-invalid',
        (_n, el, slot) => {
            const tag = slotTag(renderToStaticMarkup(el()), slot);

            expect(tag).toContain('disabled=""');
            expect(tag).toContain('required=""');
            expect(tag).toContain('aria-invalid="true"');
            expect(tag).toContain('name="m"');
            expect(tokensOfTag(tag)).toContain(
                'aria-invalid:border-destructive',
            );
        },
    );

    it('a caller h-10 replaces the base h-8 instead of stacking', () => {
        const tokens = tokensOfTag(
            slotTag(
                renderToStaticMarkup(<NativeSelect className="h-10" />),
                'native-select',
            ),
        );

        expect(tokens).toContain('h-10');
        expect(tokens).not.toContain('h-8');
    });

    it('forwards ref and value/onChange (controlled use)', () => {
        const ref = { current: null };
        const fn = vi.fn();

        const select = NativeSelect({ ref, value: 'GET', onChange: fn });
        const area = Textarea({ ref, value: 'x', onChange: fn });

        expect(select.props.ref).toBe(ref);
        expect(select.props.value).toBe('GET');
        expect(area.props.ref).toBe(ref);
        expect(area.props.value).toBe('x');
    });

    it('Textarea escapes a defaultValue that tries to close the element', () => {
        const payload = '</textarea><script>alert(1)</script>';
        const html = renderToStaticMarkup(<Textarea defaultValue={payload} />);

        expect(html).not.toContain('<script');
        expect(html.match(/<\/textarea>/g)).toHaveLength(1);
        expect(html).toContain('&lt;/textarea&gt;&lt;script&gt;');
    });

    it('Textarea keeps a 100k-character value intact', () => {
        const long = 'y'.repeat(100_000);

        expect(
            renderToStaticMarkup(<Textarea defaultValue={long} />),
        ).toContain(`>${long}</textarea>`);
    });
});

describe('Adversary chunk 2: Progress with out-of-range values', () => {
    // zip-upload passes (sent / file.size) * 100, which is NaN for a 0-byte file.
    it.each([
        [Number.NaN, null],
        [Number.POSITIVE_INFINITY, '100'],
        [-5, '0'],
        [150, '100'],
    ])('value %s never renders NaN and clamps to %s', (value, now) => {
        const html = renderToStaticMarkup(
            <Progress label="Upload" value={value} />,
        );

        expect(html).not.toContain('NaN');

        if (now !== null) {
            expect(html).toContain(`aria-valuenow="${now}"`);
            expect(html).toContain(`width:${now}%`);
        }
    });
});

describe('Adversary chunk 2: double click on dialog actions', () => {
    const dialogContent = () => {
        const src = readRoot('resources/js/components/ui/dialog.tsx');
        const start = src.indexOf('function DialogContent(');
        const end = src.indexOf('\nfunction ', start + 1);

        expect(start).toBeGreaterThanOrEqual(0);

        return src.slice(start, end < 0 ? undefined : end).split(/[\s"'`]+/);
    };

    it('closing DialogContent stops taking clicks during its exit animation', () => {
        // Radix Presence keeps the content mounted (unmountSuspended) for the
        // whole animate-out, and DismissableLayer drops its pointer-events
        // guard as soon as open=false. The second click of a double click on
        // an action inside <DialogClose> therefore runs its onClick again.
        expect(dialogContent()).toContain(
            'data-[state=closed]:pointer-events-none',
        );
    });

    it('Button forwards disabled, so a processing flag blocks the second click', () => {
        const html = renderToStaticMarkup(
            <Button variant="destructive" disabled type="submit">
                Disable 2FA
            </Button>,
        );

        expect(html).toMatch(/<button\b[^>]*\bdisabled=""/);
        expect(html).toContain('type="submit"');
        expect(tokensOfTag(html.match(/<button\b[^>]*>/)![0])).toContain(
            'disabled:pointer-events-none',
        );
    });
});

describe('Adversary chunk 2: Toaster style map and toast text', () => {
    const sonnerDist = () => readRoot('node_modules/sonner/dist/index.mjs');
    const map = () =>
        [
            ...readRoot('resources/js/components/ui/sonner.tsx').matchAll(
                /'(--[\w-]+)':\s*'([^']*)'/g,
            ),
        ].map((m) => [m[1], m[2]] as const);

    const block = (css: string, selector: string) => {
        const at = css.search(
            new RegExp(`^${selector.replace('.', '\\.')}\\s*\\{`, 'm'),
        );

        expect(at, `no ${selector} block`).toBeGreaterThanOrEqual(0);

        return css.slice(at, css.indexOf('}', at));
    };

    it('every style-map key is a variable sonner 2.x actually reads', () => {
        const dist = sonnerDist();

        expect(map().length).toBe(16);

        for (const [key] of map()) {
            expect(dist, `sonner never reads var(${key})`).toContain(
                `var(${key})`,
            );
        }
    });

    it('every token the map references is declared in :root and .dark', () => {
        const css = readRoot('resources/css/app.css');
        const root = block(css, ':root');
        const dark = block(css, '.dark');
        const refs = new Set(
            map().flatMap(([, v]) =>
                [...v.matchAll(/var\((--[\w-]+)\)/g)].map((m) => m[1]),
            ),
        );

        for (const ref of refs) {
            expect(root, `:root lacks ${ref}`).toMatch(new RegExp(`${ref}:`));

            if (ref !== '--radius') {
                expect(dark, `.dark lacks ${ref}`).toMatch(
                    new RegExp(`${ref}:`),
                );
            }
        }
    });

    it('sonner wraps long unbroken text and has no HTML sink for messages', () => {
        const dist = sonnerDist();

        expect(dist).toContain('overflow-wrap:anywhere');
        expect(dist).not.toMatch(/dangerouslySetInnerHTML|innerHTML/);
    });

    it('the app mounts one Toaster with no props, so the kit defaults hold', () => {
        expect(filesMatching(/<Toaster\b/)).toEqual(['app.tsx']);
        expect(fs.readFileSync(path.join(JS_DIR, 'app.tsx'), 'utf8')).toMatch(
            /<Toaster\s*\/>/,
        );
    });
});

// ===========================================================================
// Chunk 3: dashboard and project pages under hostile and oversized data
// ===========================================================================

// The app shell puts `min-w-0 overflow-x-clip` on the sidebar inset, so an
// unbroken string never widens the document (AC14's scrollWidth check
// passes) but is silently cut off at the viewport edge. AC14's second
// sentence ("scrolls or truncates inside its own container") needs a token
// on the element itself. Accepted tokens depend on the element's context:
// - a flex item: wrapping, or overflow other than visible (min-width 0);
// - inline text in a grid dd or a heading: only a wrapping token works.
const WRAPS = ['break-all', 'wrap-anywhere'];
const FLEX_OK = [...WRAPS, 'truncate', 'overflow-hidden', 'overflow-x-auto'];

/** The opening tag of the nearest `<tag` before the first `text`. */
function openTagBefore(html: string, text: string, tag: string) {
    const at = html.indexOf(text);

    expect(at, `"${text.slice(0, 20)}…" not rendered`).toBeGreaterThan(-1);

    const start = html.lastIndexOf(`<${tag}`, at);

    expect(start, `no <${tag} before the text`).toBeGreaterThan(-1);

    return html.slice(start, html.indexOf('>', start) + 1);
}

const expectOneOf = (tag: string, allowed: string[]) =>
    expect(
        tokensOfTag(tag).filter((t) => allowed.includes(t)),
        `none of ${allowed.join(', ')} on ${tag}`,
    ).not.toEqual([]);

// Data limits (migrations and ProjectDetailsRules): slug max 80 matching
// /^[a-z0-9]+(?:-[a-z0-9]+)*$/, root_path 255, snapshot file path 1024 (from
// the uploaded ZIP), route uri 2048 and source path 1024 (from uploaded code).
const SLUG = 'a'.repeat(80);
const ROOT = `apps/${'r'.repeat(250)}`;
const FILE_PATH = `src/${'p'.repeat(1020)}`;
const URI = `api/${'u'.repeat(2044)}`;
const SOURCE_PATH = `routes/${'s'.repeat(1017)}`;

const projectDetail = (over: Record<string, unknown> = {}) => ({
    id: 'p1',
    name: 'Billing API',
    description: null,
    slug: 'billing-api',
    status: 'active',
    visibility: 'workspace',
    creator: null,
    setupComplete: true,
    wizardStep: 'details',
    sourcePreference: 'zip',
    framework: 'laravel',
    rootPath: '.',
    analysisMode: 'static',
    documentationMode: 'static',
    lockVersion: 1,
    ...over,
});

const OPTIONS = {
    source_preference: [{ value: 'zip', label: 'ZIP upload' }],
    framework: [{ value: 'laravel', label: 'Laravel' }],
    analysis_mode: [{ value: 'static', label: 'Static' }],
    documentation_mode: [{ value: 'static', label: 'Static' }],
};

const renderShow = (over: Record<string, unknown> = {}) =>
    renderToStaticMarkup(
        <ShowProject
            {...({
                project: projectDetail(),
                options: OPTIONS,
                can: { update: true, delete: true },
                sources: null,
                framework: null,
                endpoints: null,
                ...over,
            } as unknown as ComponentProps<typeof ShowProject>)}
        />,
    );

const scanSummary = {
    id: 'scan-1',
    status: 'completed',
    active: false,
    applicationRoot: '.',
    analyzerVersion: 'routes-v1',
    endpointCount: 1,
    resolvedCount: 1,
    partialCount: 0,
    unresolvedCount: 0,
    findingCount: 0,
    fileCount: 1,
    coverage: null,
    failureCode: null,
    finishedAt: null,
};

const endpointRow = (over: Record<string, unknown> = {}) => ({
    id: 'e1',
    methods: ['GET'],
    uri: 'api/invoices',
    domain: null,
    name: null,
    handlerType: 'controller',
    controller: 'App\\Http\\Controllers\\InvoiceController',
    action: 'index',
    middleware: [],
    auth: 'unknown',
    analysisAuth: null,
    group: 'api',
    module: null,
    resolution: 'resolved',
    reasons: [],
    routeIdentity: 'GET|api/invoices',
    source: { path: 'routes/api.php', line: 12, expression: null },
    ...over,
});

const renderEndpoints = (over: Record<string, unknown> = {}) =>
    renderToStaticMarkup(
        <Endpoints
            {...({
                project: { id: 'p1', name: 'Billing API' },
                snapshotId: 's1',
                needsRoot: false,
                scan: scanSummary,
                canScan: true,
                analysisEnabled: false,
                endpoints: {
                    data: [endpointRow()],
                    current_page: 1,
                    last_page: 1,
                    total: 1,
                    prev_page_url: null,
                    next_page_url: null,
                },
                groups: ['api'],
                filters: {
                    q: '',
                    method: '',
                    group: 'all',
                    auth: '',
                    resolution: '',
                },
                findings: [],
                ...over,
            } as unknown as ComponentProps<typeof Endpoints>)}
        />,
    );

const renderEndpoint = (over: Record<string, unknown> = {}) =>
    renderToStaticMarkup(
        <ShowEndpoint
            {...({
                project: { id: 'p1', name: 'Billing API' },
                endpoint: endpointRow(),
                scan: scanSummary,
                isCurrent: true,
                analysisEnabled: false,
                analysis: null,
                canAnalyze: true,
                canViewSource: true,
                docsState: 'none',
                ...over,
            } as unknown as ComponentProps<typeof ShowEndpoint>)}
        />,
    );

const staticDocument = (over: Record<string, unknown> = {}) => ({
    schema_version: '1',
    builder_version: 'static-docs-v2',
    analyzer_version: 'laravel-ast-v6',
    analysis_status: 'completed',
    notices: [],
    overview: {
        methods: ['GET'],
        uri: 'api/invoices',
        domain: null,
        name: null,
        handler: 'InvoiceController@index',
        group: 'api',
        middleware: [],
        auth: { status: 'required', reason: null },
        dependencies: [],
    },
    request: {
        path: [],
        query: [],
        body: [],
        query_or_body: [],
        payload: null,
    },
    headers: [],
    validation: [],
    curl: { command: 'curl {{base_url}}/api/invoices', source: 'contract' },
    responses: { success: [], errors: [] },
    legacy_responses: [],
    unknowns: [],
    ...over,
});

const renderDocs = (over: Record<string, unknown> = {}, doc = {}) =>
    renderToStaticMarkup(
        <ShowDocumentation
            {...({
                project: { id: 'p1', name: 'Billing API' },
                endpointId: 'e1',
                documentation: {
                    id: 'd1',
                    status: 'ready',
                    builderVersion: 'static-docs-v2',
                    analyzerVersion: 'laravel-ast-v6',
                    snapshotId: 's1',
                    analysisId: 'an1',
                    generatedAt: null,
                    checksum: 'c'.repeat(64),
                    snapshotHash: 'h'.repeat(64),
                    markdown: '# Docs',
                    document: staticDocument(doc),
                },
                drafts: [
                    {
                        id: 'd1',
                        generatedAt: null,
                        analyzerVersion: 'laravel-ast-v6',
                        builderVersion: 'static-docs-v2',
                    },
                ],
                canGenerate: true,
                ...over,
            } as unknown as ComponentProps<typeof ShowDocumentation>)}
        />,
    );

/** Trimmed text of every <button>, tags stripped. */
const buttonTexts = (html: string) =>
    [...html.matchAll(/<button\b[^>]*>([\s\S]*?)<\/button>/g)].map((m) =>
        m[1]
            .replace(/<[^>]*>/g, '')
            .replace(/&#x27;/g, "'")
            .trim(),
    );

const buttonTag = (html: string, text: string) =>
    [...html.matchAll(/<button\b([^>]*)>([\s\S]*?)<\/button>/g)].find(
        (m) => m[2].replace(/<[^>]*>/g, '').trim() === text,
    )?.[1];

describe('Endpoint list filters', () => {
    it('keeps only the HTTP method filter', () => {
        const html = renderEndpoints();
        const selects = [...html.matchAll(/<select\b[^>]*>/g)].map(
            (match) => match[0],
        );

        expect(selects).toHaveLength(1);
        expect(selects[0]).toContain('name="method"');
        expect(selects[0]).toContain('aria-label="HTTP method"');
        expect(html).not.toContain('name="q"');
        expect(html).not.toContain('name="group"');
        expect(html).not.toContain('name="auth"');
        expect(html).not.toContain('name="resolution"');
        expect(buttonTexts(html)).not.toContain('Filter');
    });
});

describe('Adversary chunk 3: long slugs, paths and URIs stay in their container', () => {
    it('projects list: an 80-char slug in the flex row can shrink (minor)', () => {
        const html = renderToStaticMarkup(
            <ProjectsIndex
                projects={
                    [
                        {
                            ...projectDetail({ slug: SLUG }),
                            archived: false,
                        },
                    ] as unknown as ComponentProps<
                        typeof ProjectsIndex
                    >['projects']
                }
                filters={{ q: '', archived: false }}
                canCreate
            />,
        );

        expectOneOf(openTagBefore(html, SLUG, 'code'), FLEX_OK);
    });

    it('project page header: an 80-char slug in the no-wrap flex row can shrink (minor)', () => {
        const html = renderShow({ project: projectDetail({ slug: SLUG }) });

        expectOneOf(openTagBefore(html, SLUG, 'code'), FLEX_OK);
    });

    it('project page Setup dl: a 255-char root path wraps inside its dd (minor)', () => {
        const html = renderShow({ project: projectDetail({ rootPath: ROOT }) });

        expectOneOf(openTagBefore(html, ROOT, 'code'), WRAPS);
    });

    it('snapshot page: a 1024-char uploaded file path can shrink in its row (major)', () => {
        const html = renderToStaticMarkup(
            <ShowSnapshot
                {...({
                    project: { id: 'p1', name: 'Billing API' },
                    snapshot: {
                        id: 's1',
                        status: 'ready',
                        sourceType: 'zip',
                        sha256: 'f'.repeat(64),
                        fileCount: 1,
                        excludedCount: 0,
                        totalBytes: 10,
                        readyAt: null,
                        manifestVersion: 1,
                        failureMessage: null,
                    },
                    files: {
                        data: [
                            {
                                path: FILE_PATH,
                                bytes: 10,
                                language: 'php',
                                excludedReason: null,
                            },
                        ],
                        current_page: 1,
                        last_page: 1,
                        total: 1,
                        prev_page_url: null,
                        next_page_url: null,
                    },
                    detection: null,
                } as unknown as ComponentProps<typeof ShowSnapshot>)}
            />,
        );

        // Guard: the content hash already wraps.
        expectOneOf(openTagBefore(html, 'f'.repeat(64), 'code'), WRAPS);
        expectOneOf(openTagBefore(html, FILE_PATH, 'code'), FLEX_OK);
    });

    it('endpoints list: a 2048-char discovered URI can shrink in its row (major)', () => {
        const html = renderEndpoints({
            endpoints: {
                data: [endpointRow({ uri: URI })],
                current_page: 1,
                last_page: 1,
                total: 1,
                prev_page_url: null,
                next_page_url: null,
            },
        });

        expectOneOf(openTagBefore(html, URI, 'code'), FLEX_OK);
    });

    it('endpoint page: the 2048-char URI title wraps inside its heading (major)', () => {
        const html = renderEndpoint({ endpoint: endpointRow({ uri: URI }) });

        // Heading renders a plain h2 with no wrap utility; the title is text.
        expectOneOf(openTagBefore(html, URI, 'h2'), WRAPS);
    });

    it('endpoint page: a 1024-char "Defined in" source path wraps in its dd (major)', () => {
        const html = renderEndpoint({
            endpoint: endpointRow({
                source: { path: SOURCE_PATH, line: 7, expression: null },
            }),
        });

        expectOneOf(openTagBefore(html, SOURCE_PATH, 'code'), WRAPS);
    });

    it('endpoint page: long handler and route identity already wrap (guard)', () => {
        const controller = `App\\${'C'.repeat(500)}`;
        const identity = `GET|${URI}`;
        const html = renderEndpoint({
            endpoint: endpointRow({ controller, routeIdentity: identity }),
        });

        expectOneOf(openTagBefore(html, `${controller}@index`, 'code'), WRAPS);
        expectOneOf(openTagBefore(html, identity, 'code'), WRAPS);
    });

    it('documentation page: long URI, handler and provenance codes wrap (guard)', () => {
        const handler = `App\\${'H'.repeat(500)}@index`;
        const html = renderDocs(
            {},
            {
                overview: {
                    ...staticDocument().overview,
                    uri: URI,
                    handler,
                },
            },
        );

        expectOneOf(openTagBefore(html, URI, 'code'), WRAPS);
        expectOneOf(openTagBefore(html, handler, 'code'), WRAPS);
        expectOneOf(openTagBefore(html, 'h'.repeat(64), 'code'), WRAPS);
        expectOneOf(openTagBefore(html, 'c'.repeat(64), 'code'), WRAPS);
    });

    it('a long curl command stays in a scrolling pre (guard)', () => {
        const command = `curl {{base_url}}/${URI}`;
        const html = renderDocs({}, { curl: { command, source: 'contract' } });

        expectOneOf(openTagBefore(html, command, 'pre'), ['overflow-x-auto']);
    });
});

describe('Adversary chunk 3: uploaded strings on project pages render as text', () => {
    it.each(XSS)('endpoint list and detail escape a hostile URI: %s', (p) => {
        const row = endpointRow({ uri: p, name: p, controller: p });

        expectEscaped(
            renderEndpoints({
                endpoints: {
                    data: [row],
                    current_page: 1,
                    last_page: 1,
                    total: 1,
                    prev_page_url: null,
                    next_page_url: null,
                },
            }),
            p,
        );
        expectEscaped(renderEndpoint({ endpoint: row }), p);
    });

    it.each(XSS)('documentation escapes a hostile URI and handler: %s', (p) => {
        expectEscaped(
            renderDocs(
                {},
                {
                    overview: {
                        ...staticDocument().overview,
                        uri: p,
                        handler: p,
                    },
                },
            ),
            p,
        );
    });
});

describe('Adversary chunk 3: permission-gated blocks stay gated', () => {
    const MANAGE = [
        'Archive project',
        'Restore project',
        'Save details',
        'Delete project',
        'Continue setup',
    ];

    it('owner on an active project: Archive opens a dialog, Edit details shows (control)', () => {
        const html = renderShow();

        expect(buttonTag(html, 'Archive project')).toContain(
            'aria-haspopup="dialog"',
        );
        expect(buttonTexts(html)).toContain('Save details');
        // The dialog is portaled and closed: its "Archive" action is absent.
        expect(buttonTexts(html)).not.toContain('Archive');
    });

    it('viewer (can.update and can.delete false): no manage control at all', () => {
        const html = renderShow({
            can: { update: false, delete: false },
            project: projectDetail({ setupComplete: false }),
        });

        for (const label of MANAGE) {
            expect(buttonTexts(html)).not.toContain(label);
            expect(html).not.toContain(label);
        }
        expect(html).not.toContain('Edit details');
    });

    it('archived project with update right: Restore only, no edit or archive', () => {
        const html = renderShow({
            project: projectDetail({
                status: 'archived',
                setupComplete: false,
            }),
            can: { update: true, delete: false },
        });

        expect(buttonTexts(html)).toContain('Restore project');
        expect(html).not.toContain('Edit details');
        expect(buttonTexts(html)).not.toContain('Archive project');
        expect(html).not.toContain('Continue setup');
    });

    it('archived project with a running import: no R4-4 Cancel trigger', () => {
        const html = renderShow({
            project: projectDetail({ status: 'archived' }),
            sources: {
                latestRun: {
                    id: 'run-1',
                    status: 'running',
                    active: true,
                    attempts: 1,
                    deduplicated: false,
                    failureCode: null,
                    failureMessage: null,
                    snapshotId: null,
                    cancelRequested: false,
                    createdAt: null,
                    finishedAt: null,
                },
                snapshots: [],
                canImportFixture: false,
                canUpload: false,
                maxUploadBytes: 1024,
                maxSourceBytes: 1024,
                rootCandidates: [],
            },
        });

        expect(html).toContain('Import progress');
        expect(buttonTexts(html)).not.toContain('Cancel');
        expect(html).not.toContain('Stop import');
    });

    it('documentation: a viewer (canGenerate false) sees no Regenerate', () => {
        expect(buttonTexts(renderDocs({ canGenerate: false }))).not.toContain(
            'Regenerate',
        );
    });

    it('documentation: no draft means no Regenerate even with the right', () => {
        const html = renderDocs({ documentation: null, canGenerate: true });

        expect(html).toContain('No documentation yet');
        expect(buttonTexts(html)).not.toContain('Regenerate');
    });

    it('documentation: canGenerate true shows Regenerate (control)', () => {
        expect(buttonTexts(renderDocs())).toContain('Regenerate');
    });

    it('endpoint page: a viewer gets no Analyze and a disabled Generate documentation', () => {
        page.props.features = { project_wizard: true, static_docs: true };
        page.props.currentWorkspace = {
            ...workspace('w1', 'Team'),
            can: { submit_docs: false },
        };

        const html = renderEndpoint({
            analysisEnabled: true,
            canAnalyze: false,
            canViewSource: false,
        });

        // Positive control: EndpointAnalysis did render, in its viewer branch.
        expect(html).toContain(
            'A Developer, Maintainer or Owner can run the analysis',
        );
        expect(buttonTexts(html)).not.toContain('Analyze');
        expect(buttonTexts(html)).not.toContain('Reanalyze');
        expect(buttonTag(html, 'Generate documentation')).toContain(
            'disabled=""',
        );
    });

    it('endpoints page: no scan right means no Discover or Retry button', () => {
        expect(
            buttonTexts(renderEndpoints({ scan: null, canScan: false })),
        ).not.toContain('Discover routes');
        expect(
            buttonTexts(
                renderEndpoints({
                    scan: { ...scanSummary, status: 'failed' },
                    canScan: false,
                }),
            ),
        ).not.toContain('Retry discovery');
        // Control: the right brings the button back.
        expect(
            buttonTexts(renderEndpoints({ scan: null, canScan: true })),
        ).toContain('Discover routes');
    });
});

describe('Adversary chunk 3: StatusBadge with odd status values', () => {
    const TONES = [
        'text-success',
        'text-info',
        'text-warning',
        'text-destructive',
    ];
    const response = (status: unknown) => ({
        status: status as number | null,
        status_source: 'exact' as const,
        kind: 'empty',
        triggers: ['t'],
        example: null,
        notes: [],
        holes: [],
    });
    const badges = (statuses: unknown[]) =>
        [
            ...renderToStaticMarkup(
                <ResponseList
                    empty="none"
                    responses={statuses.map(response)}
                />,
            ).matchAll(/(<[a-z]+\b[^>]*data-slot="badge"[^>]*>)([^<]*)</g),
        ].map((m) => ({ tokens: tokensOfTag(m[1]), text: m[2] }));

    // The backend emits is_int() codes or null (ContractBuilder::group), so
    // 0, negatives and 999 are reachable from uploaded code; NaN and strings
    // are not, and are here only to prove the chain never throws or doubles.
    it.each([
        [0, 'text-success'],
        [99, 'text-success'],
        [-1, 'text-success'],
        [-500, 'text-success'],
        [600, 'text-destructive'],
        [999, 'text-destructive'],
        [Number.MAX_SAFE_INTEGER, 'text-destructive'],
        [Number.NaN, 'text-destructive'],
        ['404', 'text-warning'],
    ])('status %j gets exactly the tone %s', (status, tone) => {
        const [badge] = badges([status]);

        expect(badge.tokens.filter((t) => TONES.includes(t))).toEqual([tone]);
        expect(badge.tokens).not.toContain('border-dashed');
    });

    it('status 0 shows "0", not "status computed" (?? not ||)', () => {
        const [badge] = badges([0]);

        expect(badge.text).toBe('0');
        expect(badge.tokens).toContain('font-mono');
    });
});

describe('Adversary chunk 3: documentation notices', () => {
    const NOTICES = [
        'partial',
        'legacy_result',
        'budget_reached',
        'auth_unknown',
    ];

    it('every notice is a warning Alert with role="status" and its text', () => {
        const html = renderDocs({}, { notices: NOTICES });
        const list = html.match(
            /<ul[^>]*aria-label="Notices"[^>]*>([\s\S]*?)<\/ul>/,
        );

        expect(list).not.toBeNull();

        const items = [...list![1].matchAll(/<li>([\s\S]*?)<\/li>/g)];

        expect(items).toHaveLength(4);

        for (const [, li] of items) {
            const alert = slotTag(li, 'alert');

            expect(alert).toContain('role="status"');
            expect(tokensOfTag(alert)).toContain('text-warning');
            expect(li).toMatch(/aria-hidden="true"/);
            expect(li.replace(/<[^>]*>/g, '').trim().length).toBeGreaterThan(
                20,
            );
        }
    });

    it('no notices renders no Notices list and no status region', () => {
        const html = renderDocs();

        expect(html).not.toContain('aria-label="Notices"');
        expect(html).not.toContain('role="status"');
    });
});

describe('Adversary chunk 3: loading-toast Dismiss (AC38)', () => {
    it('sonner 2.0.8 still deletes the toast after a cancel onClick', () => {
        const dist = readRoot('node_modules/sonner/dist/index.mjs');
        const at = dist.indexOf('"data-cancel": true');

        expect(at).toBeGreaterThan(-1);

        const handler = dist.slice(at, dist.indexOf('className:', at));

        expect(handler).toMatch(
            /if \(!dismissible\) return;[\s\S]*toast\.cancel\.onClick[\s\S]*deleteToast\(\);/,
        );
    });

    it('zip-upload resolves no toast itself: its only toast call is loading', () => {
        const src = readRoot('resources/js/components/zip-upload.tsx');

        expect([...src.matchAll(/\btoast\.(\w+)\(/g)].map((m) => m[1])).toEqual(
            ['loading'],
        );
    });
});

// Revision 10 and 10b (adversary-2): dashboard recent rows and /projects cards.
// Dashboard is loaded lazily so the pinned C3-F9 line numbers above stay put.
const summary = (over: Partial<ProjectSummary> = {}): ProjectSummary => ({
    id: 'p1',
    name: 'Billing API',
    slug: 'billing-api',
    status: 'active',
    visibility: 'workspace',
    setupComplete: true,
    updatedAt: null,
    ...over,
});

const NAME = 'W'.repeat(120); // ProjectDetailsRules name max:120, no spaces

async function renderDashboard(rows: ProjectSummary[]) {
    const { default: Dashboard } = await import('@/pages/dashboard');
    const acme = workspace('w1', 'Acme');
    page.props = {
        ...page.props,
        workspaces: [acme],
        currentWorkspace: acme,
        features: { project_wizard: true, workspace_management: true },
    };

    return renderToStaticMarkup(<Dashboard recentProjects={rows} />);
}

const renderCards = (rows: ProjectSummary[]) =>
    renderToStaticMarkup(
        <ProjectsIndex
            projects={rows}
            filters={{ q: '', archived: false }}
            canCreate
        />,
    );

const titleOf = (tag: string) => /\btitle="([^"]*)"/.exec(tag)?.[1];

describe('Adversary chunk 3 rev10: long names and slugs in dashboard rows and cards', () => {
    it('dashboard row: an 80-char slug truncates with a title (guard)', async () => {
        const tag = openTagBefore(
            await renderDashboard([summary({ slug: SLUG })]),
            SLUG,
            'code',
        );

        expectOneOf(tag, ['truncate']);
        expectOneOf(tag, ['min-w-0']);
        expect(titleOf(tag)).toBe(SLUG);
    });

    it('dashboard row: a 120-char unbroken name can shrink or wrap (C3-F10)', async () => {
        const html = await renderDashboard([summary({ name: NAME })]);

        expectOneOf(openTagBefore(html, NAME, 'span'), FLEX_OK);
    });

    it('dashboard row: no "Setup not finished" marker, even when unfinished (guard)', async () => {
        const html = await renderDashboard([summary({ setupComplete: false })]);

        expect(html).not.toContain('Setup not finished');
    });

    it('/projects card: 120-char name and 80-char slug truncate with titles; li is min-w-0 (guard)', () => {
        const html = renderCards([summary({ name: NAME, slug: SLUG })]);
        const name = openTagBefore(html, NAME, 'span');
        const slug = openTagBefore(html, SLUG, 'code');

        expectOneOf(name, ['truncate']);
        expect(titleOf(name)).toBe(NAME);
        expectOneOf(slug, ['truncate']);
        expect(titleOf(slug)).toBe(SLUG);
        expectOneOf(openTagBefore(html, NAME, 'li'), ['min-w-0']);
    });
});

describe('Adversary chunk 3 rev10: HTML-like names and slugs render as text', () => {
    it.each(XSS)('dashboard row escapes name and slug: %s', async (p) => {
        const html = await renderDashboard([summary({ name: p, slug: p })]);

        expectEscaped(html, p);
        expect(html).toContain(`title="${XSS_ESCAPED(p)}"`);
    });

    it.each(XSS)('/projects card escapes name and slug: %s', (p) => {
        const html = renderCards([summary({ name: p, slug: p })]);

        expectEscaped(html, p);
        expect(html.split(`title="${XSS_ESCAPED(p)}"`)).toHaveLength(3);
    });

    it('a hostile id stays inside the row href (guard)', async () => {
        const id = '../../admin?x="><script>';
        const html = await renderDashboard([summary({ id })]);

        expect(html).not.toContain('<script>');
        expect(html).toMatch(/href="\/projects\/[^"]*"/);
    });
});

// ---------------------------------------------------------------------------
// Chunk 3b adversarial tests: gradient hero, icons, gradient utilities, the
// tailwind-merge interaction on Button, dark tokens, gradient leaks, and the
// outside impersonation / GitHub / settings screens.

const HERO_RE =
    /<header\b([^>]*\bdata-slot="page-hero"[^>]*)>([\s\S]*?)<\/header>/g;
const heroes = (html: string) => [...html.matchAll(HERO_RE)];
const oneHero = (html: string) => {
    const h = heroes(html);

    expect(h, 'page-hero count').toHaveLength(1);

    return { tag: `<header${h[0][1]}>`, body: h[0][2], whole: h[0][0] };
};

// ProjectDetailsRules: description max 2000, no character rule.
const LONG_WORD = 'D'.repeat(250);
const ROUTE_NAME = `api.v1.${'n'.repeat(250)}`;

const GRADIENT_TOKEN =
    /^(?:\S*:)?(?:brand-gradient(?:-text|-border)?|page-gradient)$/;

/**
 * Static-markup port of the AC47 element whitelist: every opening tag with a
 * gradient token must be one of the allowed kinds. Returns the offenders.
 */
function gradientLeaks(html: string) {
    const leaks: string[] = [];

    for (const m of html.matchAll(/<([a-z][a-z0-9]*)\b[^>]*>/g)) {
        const [tag, name] = m;
        const t = tokensOfTag(tag);

        if (!t.some((x) => GRADIENT_TOKEN.test(x))) {
            continue;
        }

        const before = html.slice(0, m.index);
        const after = html.slice(m.index! + tag.length);
        const insideDl =
            before.lastIndexOf('<dl') > before.lastIndexOf('</dl>') &&
            /(?:<dl\b[^>]*>|<\/div>)$/.test(before);
        const ulAt = before.lastIndexOf('<ul');
        const inDensityList =
            ulAt > before.lastIndexOf('</ul>') &&
            /^<ul\b[^>]*class="[^"]*\bdivide-y\b/.test(before.slice(ulAt));
        const allowed =
            (name === 'header' && tag.includes('data-slot="page-hero"')) ||
            (tag.includes('data-slot="button"') && t.includes('bg-primary')) ||
            ((name === 'span' || name === 'div') &&
                t.includes('brand-gradient') &&
                (t.includes('size-8') || t.includes('size-10')) &&
                new RegExp(
                    `^<svg\\b[^>]*>(?:(?!<svg\\b)[\\s\\S])*?</svg></${name}>`,
                ).test(after)) ||
            (t.includes('brand-gradient-border') &&
                !t.includes('brand-gradient') &&
                ((name === 'a' && !inDensityList) ||
                    (name === 'div' && insideDl))) ||
            (name === 'dd' && t.includes('brand-gradient-text'));

        if (!allowed) {
            leaks.push(tag);
        }
    }

    return leaks;
}

const impersonation = (
    over: Partial<{
        member: string;
        email: string;
        workspace: string;
        owner: string;
    }> = {},
) => ({
    active: true,
    startedAt: '2026-11-24T00:00:00Z',
    owner: { id: 1, name: over.owner ?? 'Olivia Owner' },
    member: {
        id: 2,
        name: over.member ?? 'Vic Viewer',
        email: over.email ?? 'vic@example.test',
    },
    workspace: { id: 'w1', name: over.workspace ?? 'Acme' },
});

const renderBanner = (imp: ReturnType<typeof impersonation>) => {
    page.props = { ...page.props, impersonation: imp };

    return renderToStaticMarkup(<ImpersonationBanner />);
};

const renderSettings = (isPlatformAdmin: boolean, gitImport: boolean) => {
    page.props = {
        ...page.props,
        auth: { user: user(), isPlatformAdmin },
        features: { ...(page.props.features as object), git_import: gitImport },
    };

    return renderToStaticMarkup(
        <SettingsLayout>
            <p>child</p>
        </SettingsLayout>,
    );
};

const finding = {
    severity: 'warning',
    code: 'dynamic_uri',
    message: 'URI is built at runtime.',
    source: { path: 'routes/api.php', line: 3, expression: 'Route::get($x)' },
};

describe('Adversary chunk 3b: hero description with long unbroken data', () => {
    // The hero text block is min-w-48 and the inset is overflow-x-clip, so an
    // unbroken description runs past the banner: near-white text on the page.
    it.each([
        [
            'Heading itself',
            () =>
                renderToStaticMarkup(
                    <Heading title="T" description={LONG_WORD} />,
                ),
            LONG_WORD,
        ],
        [
            'show.tsx project.description',
            () =>
                renderShow({
                    project: projectDetail({ description: LONG_WORD }),
                }),
            LONG_WORD,
        ],
        [
            'endpoint.tsx route name',
            () =>
                renderEndpoint({ endpoint: endpointRow({ name: ROUTE_NAME }) }),
            ROUTE_NAME,
        ],
        [
            'documentation.tsx project name',
            () => renderDocs({ project: { id: 'p1', name: NAME } }),
            NAME,
        ],
        [
            'endpoints.tsx project name',
            () => renderEndpoints({ project: { id: 'p1', name: NAME } }),
            NAME,
        ],
    ])('%s: the hero description p wraps (major)', (_, render, text) => {
        const html = render();
        const hero = oneHero(html);

        expect(hero.body).toContain(text);
        expectOneOf(openTagBefore(hero.body, text, 'p'), WRAPS);
    });

    it('show.tsx: a 120-char unbroken project name wraps in the hero h2 (guard)', () => {
        const hero = oneHero(
            renderShow({ project: projectDetail({ name: NAME }) }),
        );

        expectOneOf(openTagBefore(hero.body, NAME, 'h2'), ['wrap-anywhere']);
        expectOneOf(openTagBefore(hero.body, NAME, 'div'), ['min-w-48']);
    });
});

describe('Adversary chunk 3b: HTML-like strings in the hero render as text', () => {
    it.each(XSS)('show.tsx name and description: %s', (p) => {
        const html = renderShow({
            project: projectDetail({ name: p, description: p }),
        });

        expectEscaped(oneHero(html).body, p);
    });

    it.each(XSS)('endpoint.tsx uri and route name: %s', (p) => {
        const html = renderEndpoint({
            endpoint: endpointRow({ uri: p, name: p }),
        });

        expectEscaped(oneHero(html).body, p);
    });

    it.each(XSS)(
        'documentation.tsx project name in the description: %s',
        (p) => {
            expectEscaped(
                oneHero(renderDocs({ project: { id: 'p1', name: p } })).body,
                p,
            );
        },
    );

    it.each(XSS)('dashboard workspace name and role label: %s', async (p) => {
        const { default: Dashboard } = await import('@/pages/dashboard');
        const ws = workspace('w1', p, p);
        page.props = {
            ...page.props,
            workspaces: [ws],
            currentWorkspace: ws,
            features: { project_wizard: true, workspace_management: true },
        };

        const html = renderToStaticMarkup(<Dashboard recentProjects={[]} />);

        expectEscaped(oneHero(html).body, p);
    });
});

describe('Adversary chunk 3b: Heading hero shapes', () => {
    it('no icon: no tile and no svg in the hero (guard)', () => {
        const hero = oneHero(renderToStaticMarkup(<Heading title="T" />));

        expect(hero.body).not.toContain('<svg');
        expect(hero.body).not.toContain('<span');
        expect(hero.body).not.toContain('<p');
    });

    it('icon: exactly one hidden svg in a non-gradient tile (guard)', () => {
        const hero = oneHero(
            renderToStaticMarkup(<Heading title="T" icon={FolderKanban} />),
        );
        const svgs = [...hero.body.matchAll(/<svg\b[^>]*>/g)];

        expect(svgs).toHaveLength(1);
        expect(svgs[0][0]).toContain('aria-hidden="true"');
        expect(gradientLeaks(hero.body)).toEqual([]);
    });

    it.each([false, null, undefined])(
        'children=%s: nothing follows the text block (guard)',
        (c) => {
            const hero = oneHero(
                renderToStaticMarkup(<Heading title="T">{c}</Heading>),
            );

            expect(hero.body).toMatch(/<\/h2><\/div>$/);
        },
    );

    it('small variant with an icon: no page-hero, no gradient token (guard)', () => {
        const html = renderToStaticMarkup(
            <Heading
                variant="small"
                title="T"
                description="D"
                icon={FolderKanban}
            />,
        );

        expect(html).not.toContain('page-hero');
        expect(gradientLeaks(html)).toEqual([]);
        expect(html).not.toMatch(/gradient/);
    });

    it('small variant keeps its children instead of dropping them (minor)', () => {
        const html = renderToStaticMarkup(
            <Heading variant="small" title="T">
                <button type="button">Act</button>
            </Heading>,
        );

        expect(html).toContain('>Act</button>');
    });

    it('every Heading that takes children is a page hero, not small (guard)', () => {
        const calls = filesMatching(/<\/Heading>/).flatMap((f) =>
            [
                ...fs
                    .readFileSync(path.join(JS_DIR, f), 'utf8')
                    .matchAll(/<Heading\b([^>]*?)>(?!\s*\/)/g),
            ]
                .filter((m) => !m[0].endsWith('/>'))
                .map((m) => `${f}: ${m[1].replace(/\s+/g, ' ')}`),
        );

        expect(calls.length).toBeGreaterThan(0);
        expect(calls.filter((c) => c.includes('variant="small"'))).toEqual([]);
    });
});

describe('Adversary chunk 3b: tailwind-merge drops bg-primary but keeps brand-gradient', () => {
    // AC47 whitelist kind 2 and the AC20 selector identify the gradient button
    // by its bg-primary token. No live default-variant caller passes bg-* yet.
    it.each(['bg-destructive', 'bg-transparent', 'bg-card'])(
        'default Button with className="%s" has no gradient without bg-primary (minor)',
        (bg) => {
            const t = tokensOfTag(
                renderToStaticMarkup(<Button className={bg}>X</Button>),
            );

            expect(t).toContain(bg);
            expect(
                t.includes('brand-gradient') && !t.includes('bg-primary'),
                t.join(' '),
            ).toBe(false);
        },
    );

    it('a layout-only className keeps solid bg-primary and no gradient (guard, revision 12)', () => {
        const t = tokensOfTag(
            renderToStaticMarkup(<Button className="ml-auto w-full">X</Button>),
        );

        expect(t).toEqual(
            expect.arrayContaining([
                'bg-primary',
                'text-primary-foreground',
                'ml-auto',
            ]),
        );
        expect(t.filter((c) => /gradient/.test(c))).toEqual([]);
    });

    it.each(['bg-destructive', 'bg-card'])(
        'a caller %s cleanly replaces bg-primary: one bg colour, no gradient (guard, revision 12)',
        (bg) => {
            const t = tokensOfTag(
                renderToStaticMarkup(<Button className={bg}>X</Button>),
            );

            expect(t.filter((c) => c.startsWith('bg-'))).toEqual([bg]);
            expect(t.filter((c) => /gradient/.test(c))).toEqual([]);
        },
    );

    it('no live default-variant Button passes a bg-* className (white text would sit on it) (guard)', () => {
        const hits = filesMatching(/<Button\b/).flatMap((f) =>
            [
                ...fs
                    .readFileSync(path.join(JS_DIR, f), 'utf8')
                    .matchAll(/<Button\b([^>]*)>/g),
            ]
                .map((m) => m[1])
                .filter(
                    (a) =>
                        !/\bvariant=/.test(a) &&
                        /className=[^]*?(?<![\w:-])bg-/.test(a),
                )
                .map((a) => `${f}: ${a.replace(/\s+/g, ' ')}`),
        );

        expect(hits.filter((h) => !h.includes('.test.'))).toEqual([]);
    });

    it.each(['destructive', 'outline', 'secondary', 'ghost', 'link'] as const)(
        '%s variant gets no gradient even with a bg className (guard)',
        (variant) => {
            const html = renderToStaticMarkup(
                <Button variant={variant} className="bg-primary">
                    X
                </Button>,
            );

            expect(html).not.toMatch(/gradient/);
        },
    );
});

describe('Adversary chunk 3b: dark mode token gaps', () => {
    const css = readRoot('resources/css/app.css');
    const block = (selector: string) => {
        const at = css.search(new RegExp(`^${selector} \\{`, 'm'));

        expect(at, selector).toBeGreaterThan(-1);

        return css.slice(at, css.indexOf('\n}', at));
    };
    const decl = (b: string, name: string) =>
        b
            .match(new RegExp(`\\s${name}:\\s*([^;]+);`))?.[1]
            .replace(/\s+/g, ' ');
    const root = block(':root');
    const dark = block('\\.dark');

    it.each([
        '--gradient-from',
        '--gradient-to',
        '--gradient-foreground',
        '--page-from',
        '--page-to',
    ])('%s is declared in :root and .dark (guard)', (name) => {
        expect(decl(root, name)).toBeTruthy();
        expect(decl(dark, name)).toBeTruthy();
    });

    it('primary button text is white in both modes; hero text is --gradient-foreground, which equals it in light only (guard, revision 12)', () => {
        // Spec palette: --primary-foreground oklch(1 0 0) both modes; dark
        // --gradient-foreground is the dark text oklch(0.1763 0.014 258.41).
        for (const b of [root, dark]) {
            expect(decl(b, '--primary-foreground')).toBe('oklch(1 0 0)');
        }
        expect(decl(root, '--gradient-foreground')).toBe(
            decl(root, '--primary-foreground'),
        );
        expect(decl(dark, '--gradient-foreground')).not.toBe(
            decl(dark, '--primary-foreground'),
        );
        expect(
            tokensOfTag(
                oneHero(renderToStaticMarkup(<Heading title="T" />)).tag,
            ),
        ).toContain('text-gradient-foreground');
    });

    it('every var() in the gradient utilities is declared in :root (guard)', () => {
        const bodies = [...css.matchAll(/@utility [\w-]+ \{([^}]*)\}/g)]
            .map((m) => m[1])
            .join('\n');
        const used = [...bodies.matchAll(/var\((--[\w-]+)\)/g)].map(
            (m) => m[1],
        );

        expect(used.length).toBeGreaterThan(0);

        for (const v of used) {
            expect(decl(root, v), v).toBeTruthy();
        }
    });

    it('.dark is only ever applied to <html>, so :root-only --brand-gradient resolves per mode (guard)', () => {
        const togglers = filesMatching(
            /classList\.(?:add|toggle)\(\s*['"]dark['"]/,
        );

        expect(togglers).toEqual(['hooks/use-appearance.tsx']);
        expect(
            fs.readFileSync(
                path.join(JS_DIR, 'hooks/use-appearance.tsx'),
                'utf8',
            ),
        ).toMatch(/document\.documentElement\.classList\.toggle\(\s*'dark'/);
        expect(
            filesMatching(/className=["'`][^"'`]*(?<![\w:/-])dark(?![\w:/-])/),
        ).toEqual([]);
        expect(readRoot('resources/views/app.blade.php')).toMatch(
            /<html\b.*@class\(\['dark'/,
        );
    });
});

describe('Adversary chunk 3b: no gradient leaks onto rows, panels or badges', () => {
    it.each([
        [
            'show (setup unfinished, owner)',
            () =>
                renderShow({
                    project: projectDetail({ setupComplete: false }),
                }),
        ],
        [
            'endpoints (completed, rows and findings)',
            () =>
                renderEndpoints({
                    scan: { ...scanSummary, findingCount: 1, partialCount: 1 },
                    findings: [finding],
                    endpoints: {
                        data: [
                            endpointRow(),
                            endpointRow({ id: 'e2', resolution: 'partial' }),
                        ],
                        current_page: 1,
                        last_page: 1,
                        total: 2,
                        prev_page_url: null,
                        next_page_url: null,
                    },
                }),
        ],
        [
            'endpoints (no rows: EmptyState)',
            () =>
                renderEndpoints({
                    endpoints: {
                        data: [],
                        current_page: 1,
                        last_page: 1,
                        total: 0,
                        prev_page_url: null,
                        next_page_url: null,
                    },
                }),
        ],
        [
            'endpoints (failed scan, Retry in hero)',
            () =>
                renderEndpoints({ scan: { ...scanSummary, status: 'failed' } }),
        ],
        [
            'endpoint detail',
            () =>
                renderEndpoint({
                    endpoint: endpointRow({
                        resolution: 'partial',
                        reasons: ['dynamic'],
                        source: {
                            path: 'routes/api.php',
                            line: 1,
                            expression: 'Route::get()',
                        },
                    }),
                }),
        ],
        ['documentation', () => renderDocs()],
        [
            'projects cards',
            () => renderCards([summary(), summary({ id: 'x2' })]),
        ],
        ['impersonation banner', () => renderBanner(impersonation())],
        [
            'GitHub App setup guide',
            () =>
                renderToStaticMarkup(
                    <GitHubAppSetupGuide
                        configured={false}
                        urls={{
                            homepage: 'https://h',
                            callback: 'https://c',
                            newApp: 'https://n',
                        }}
                    />,
                ),
        ],
        ['settings layout (admin)', () => renderSettings(true, true)],
        [
            'EmptyState',
            () =>
                renderToStaticMarkup(
                    <EmptyState icon={FolderKanban} title="X" />,
                ),
        ],
    ])(
        '%s: every gradient element is a whitelisted kind (guard)',
        (_, render) => {
            expect(gradientLeaks(render())).toEqual([]);
        },
    );

    it('the whitelist port flags a gradient row, row link, panel and two-svg tile (self-test)', () => {
        expect(
            gradientLeaks(
                '<ul><li class="brand-gradient-border px-3">r</li></ul>' +
                    '<section class="brand-gradient">p</section>' +
                    '<span class="brand-gradient size-8"><svg></svg><svg></svg></span>' +
                    '<div class="data-[x]:before:brand-gradient">n</div>' +
                    '<ul class="divide-y"><li><a class="brand-gradient-border">r</a></li></ul>',
            ),
        ).toHaveLength(5);
    });

    it('dashboard: every gradient element is a whitelisted kind (guard)', async () => {
        expect(gradientLeaks(await renderDashboard([summary()]))).toEqual([]);
    });

    it.each([
        ['endpoint detail', () => renderEndpoint()],
        ['show', () => renderShow()],
    ])('%s: badges stay out of the hero (guard)', (_, render) => {
        const hero = oneHero(render());

        expect(hero.body).not.toMatch(/data-slot="(?:method-)?badge"/);
    });
});

describe('Adversary chunk 3b: moved hero actions keep their behavior', () => {
    const actions = (body: string) =>
        [...body.matchAll(/<(a|button)\b[^>]*>/g)].map((m) => m[0]);

    it.each([
        ['projects index', () => renderCards([summary()]), 'Create project'],
        [
            'show',
            () =>
                renderShow({
                    project: projectDetail({ setupComplete: false }),
                }),
            'Continue setup',
        ],
        ['documentation', () => renderDocs(), 'Regenerate'],
        [
            'endpoints',
            () =>
                renderEndpoints({ scan: { ...scanSummary, status: 'failed' } }),
            'Retry discovery',
        ],
    ])(
        '%s: the hero action is secondary, not gradient (guard)',
        (_, render, text) => {
            const hero = oneHero(render());
            const a = actions(hero.body);

            expect(a).toHaveLength(1);
            expect(hero.body).toContain(text);

            const t = tokensOfTag(a[0]);

            expect(t).toContain('bg-secondary');
            expect(t).not.toContain('bg-primary');
            expect(t).not.toContain('brand-gradient');
            expect(t).not.toContain('ml-auto');
        },
    );

    it('documentation Regenerate keeps its title and is enabled (guard)', () => {
        const tag = actions(oneHero(renderDocs()).body)[0];

        expect(tag).toContain('title="Build the documentation again');
        expect(tag).not.toMatch(/\sdisabled=""/);
    });

    it('show Continue setup keeps the wizard href with the step (guard)', () => {
        const tag = actions(
            oneHero(
                renderShow({
                    project: projectDetail({
                        setupComplete: false,
                        wizardStep: 'source',
                    }),
                }),
            ).body,
        )[0];

        expect(tag).toMatch(/href="\/projects\/p1\/[^"]*source/);
    });

    it.each([
        [
            'documentation without canGenerate',
            () => renderDocs({ canGenerate: false }),
        ],
        [
            'show for a viewer',
            () =>
                renderShow({
                    project: projectDetail({ setupComplete: false }),
                    can: { update: false, delete: false },
                }),
        ],
        [
            'endpoints without canScan',
            () =>
                renderEndpoints({
                    canScan: false,
                    scan: { ...scanSummary, status: 'failed' },
                }),
        ],
        [
            'projects index without canCreate',
            () =>
                renderToStaticMarkup(
                    <ProjectsIndex
                        projects={[]}
                        filters={{ q: '', archived: false }}
                        canCreate={false}
                    />,
                ),
        ],
    ])('%s: the hero still renders with no action (guard)', (_, render) => {
        expect(actions(oneHero(render()).body)).toEqual([]);
    });
});

describe('Adversary chunk 3b: impersonation banner', () => {
    it.each(XSS)('every name renders as text: %s', (p) => {
        const html = renderBanner(
            impersonation({ member: p, email: p, workspace: p, owner: p }),
        );

        expectEscaped(html, p);
    });

    it('keeps role, data-test, the kit warning tone and no gradient (guard)', () => {
        const html = renderBanner(impersonation());
        const root = html.slice(0, html.indexOf('>') + 1);

        expect(root).toContain('role="status"');
        expect(root).toContain('data-test="impersonation-banner"');
        expect(tokensOfTag(root)).toEqual(
            expect.arrayContaining([
                'border-warning/30',
                'bg-warning/10',
                'text-warning',
            ]),
        );
        expect(html).not.toMatch(/amber|bg-white|gradient/);
    });

    it('Return button: type=button, enabled, outline kit, hidden icons (guard)', () => {
        const html = renderBanner(impersonation());
        const tag = buttonTag(html, 'Return to Olivia Owner');

        expect(tag).toBeDefined();
        expect(tag).toContain('type="button"');
        expect(tag).not.toMatch(/\sdisabled=""/);
        expect(tokensOfTag(`<b${tag}>`)).toEqual(
            expect.arrayContaining(['border-input', 'bg-background', 'h-7']),
        );
        expect(
            html
                .match(/<svg\b[^>]*>/g)!
                .every((s) => s.includes('aria-hidden="true"')),
        ).toBe(true);
    });

    // users.email max 255, no spaces.
    const EMAIL = `${'x'.repeat(240)}@example.test`;
    const OWNER = 'O'.repeat(255);

    it('a 253-char email wraps inside the banner text (minor, pre-existing outside code)', () => {
        const html = renderBanner(impersonation({ email: EMAIL }));

        expectOneOf(openTagBefore(html, EMAIL, 'p'), WRAPS);
    });

    it('a 255-char owner name cannot push "Return to" past the strip (minor, pre-existing outside code)', () => {
        const html = renderBanner(impersonation({ owner: OWNER }));
        const button = tokensOfTag(openTagBefore(html, OWNER, 'button'));
        const wrapped = new RegExp(
            `<(?:span|strong)\\b[^>]*class="[^"]*\\b(?:truncate|wrap-anywhere|break-all)\\b[^"]*"[^>]*>${OWNER}<`,
        ).test(html);

        expect(
            wrapped ||
                (!button.includes('whitespace-nowrap') &&
                    button.some((t) => [...WRAPS, 'truncate'].includes(t))),
            button.join(' '),
        ).toBe(true);
    });
});

describe('Adversary chunk 3b: settings layout and GitHub guide keep outside behavior', () => {
    it.each([
        [false, false],
        [false, true],
        [true, false],
        [true, true],
    ])(
        'isPlatformAdmin=%s git_import=%s: GitHub App only for both (guard)',
        (admin, git) => {
            const html = renderSettings(admin, git);
            const nav = html.slice(
                html.indexOf('<nav'),
                html.indexOf('</nav>'),
            );

            expect(nav.includes('GitHub App')).toBe(admin && git);
            expect(oneHero(html).body).toContain('Settings');

            const svgs = nav.match(/<svg\b[^>]*>/g) ?? [];

            expect(svgs).toHaveLength(admin && git ? 4 : 3);
            expect(svgs.every((s) => s.includes('aria-hidden="true"'))).toBe(
                true,
            );
            expect(html).toContain('<p>child</p>');
        },
    );

    it('GitHub App guide: Copy buttons keep their labels; the safety note is a note Alert (guard)', () => {
        const html = renderToStaticMarkup(
            <GitHubAppSetupGuide
                configured={false}
                urls={{
                    homepage: 'https://h',
                    callback: 'https://c',
                    newApp: 'https://n',
                }}
            />,
        );

        expect(html.match(/aria-label="Copy [^"]+"/g)?.length).toBe(3);

        const alert = slotTag(html, 'alert');

        expect(alert).toContain('role="note"');
        expect(html).toContain('<strong>Safety:</strong>');
    });
});

// ---------------------------------------------------------------------------
// Chunk 3b fix loop 3 (gate C, Supervisor correction 5): the hero ring
// override, AC50 regex misses, the dark tooltip merge and the detection-card
// badges. Appended only: rows above keep their line numbers.

/** Compiles app.css (minus @import/@source) with the real Tailwind compiler. */
async function twCss(candidates: string[]) {
    const { compile } = await import('tailwindcss');
    const app = readRoot('resources/css/app.css').replace(
        /^@(?:import|source)\b.*$/gm,
        '',
    );
    const compiler = await compile(`@tailwind utilities;\n${app}`, {
        loadStylesheet: async () => {
            throw new Error('no @import expected');
        },
    });

    return compiler.build(candidates);
}

const HERO_OVERRIDE = '[&_:focus-visible]:ring-ring!';
const HERO_TOKENS = [
    'bg-gradient-from',
    'brand-gradient',
    'text-gradient-foreground',
    '[--color-ring:var(--gradient-foreground)]',
    HERO_OVERRIDE,
];

describe('Adversary chunk 3b loop 3: hero focus-ring override', () => {
    it('nested focusables (2+ levels deep, a link in a hero action, a caller ring color) all sit inside the overriding header (guard)', () => {
        const hero = oneHero(
            renderToStaticMarkup(
                <Heading title="T" description="D" icon={FolderKanban}>
                    <div>
                        <div>
                            <a href="/deep">deep link</a>
                        </div>
                    </div>
                    <Button asChild variant="secondary">
                        <a href="/action">action link</a>
                    </Button>
                    <Button
                        variant="secondary"
                        className="focus-visible:ring-destructive"
                    >
                        own ring
                    </Button>
                </Heading>,
            ),
        );

        expect(tokensOfTag(hero.tag)).toEqual(
            expect.arrayContaining(HERO_TOKENS),
        );
        expect(hero.body).toContain('<a href="/deep">deep link</a>');

        const action = hero.body.match(/<a\b[^>]*href="\/action"[^>]*>/);
        const own = hero.body.match(/<button\b[^>]*>own ring/);

        expect(action).not.toBeNull();
        expect(own).not.toBeNull();
        // The override sets colour only; the width still comes from the kit.
        expect(tokensOfTag(action![0])).toContain('focus-visible:ring-[3px]');
        expect(tokensOfTag(own![0])).toEqual(
            expect.arrayContaining([
                'focus-visible:ring-[3px]',
                'focus-visible:ring-destructive',
            ]),
        );
    });

    it('compiled CSS: the override is a descendant (any depth) !important rule that beats a caller ring colour (guard)', async () => {
        const css = await twCss([
            HERO_OVERRIDE,
            '[--color-ring:var(--gradient-foreground)]',
            'focus-visible:ring-destructive',
            'focus-visible:ring-destructive!',
        ]);
        const hero = css.indexOf(
            '.\\[\\&_\\:focus-visible\\]\\:ring-ring\\! :focus-visible {',
        );
        const plain = css.indexOf(
            '.focus-visible\\:ring-destructive:focus-visible {',
        );
        const important = css.indexOf(
            '.focus-visible\\:ring-destructive\\!:focus-visible {',
        );

        expect(hero, css).toBeGreaterThan(-1);
        expect(css.slice(hero)).toMatch(
            /^[^{]+\{\s*--tw-ring-color: var\(--color-ring\) !important;/,
        );
        expect(css.slice(plain)).toMatch(
            /^[^{]+\{\s*--tw-ring-color: var\(--color-destructive\);/,
        );
        expect(css).toContain('--color-ring: var(--gradient-foreground);');
        // Both !important with equal specificity (0,2,0): the later rule wins.
        expect(important).toBeGreaterThan(-1);
        expect(hero).toBeGreaterThan(important);
    });

    it('a caller className on Heading is ignored: the override and the gradient stay (guard)', () => {
        const plain = oneHero(renderToStaticMarkup(<Heading title="T" />));
        const hostile = oneHero(
            renderToStaticMarkup(
                <Heading
                    {...({
                        title: 'T',
                        className:
                            'bg-destructive ring-destructive [&_:focus-visible]:ring-destructive! [--color-ring:red]',
                    } as ComponentProps<typeof Heading>)}
                />,
            ),
        );

        expect(hostile.tag).toBe(plain.tag);
        expect(tokensOfTag(hostile.tag)).toEqual(
            expect.arrayContaining(HERO_TOKENS),
        );
        expect(hostile.tag).not.toContain('destructive');
    });

    it.each([true, false])(
        'compact=%s keeps the override, the gradient and the bg-gradient-from fallback (guard)',
        (compact) => {
            const hero = oneHero(
                renderToStaticMarkup(
                    <Heading
                        title="T"
                        description="D"
                        icon={FolderKanban}
                        compact={compact}
                    >
                        <Button variant="secondary">Act</Button>
                    </Heading>,
                ),
            );
            const t = tokensOfTag(hero.tag);

            expect(t).toEqual(expect.arrayContaining(HERO_TOKENS));
            expect(t.filter((x) => x.startsWith('bg-'))).toEqual([
                'bg-gradient-from',
            ]);
            expect(t).not.toContain('bg-primary');
        },
    );
});

// AC50: the live PRIMARY_TOKEN regex and the var(--primary substring check,
// read from design-system.test.ts so a later fix there turns these rows green.
const AC50_SRC = readRoot('resources/js/design-system.test.ts');
const AC50_RE = new RegExp(
    AC50_SRC.match(/const PRIMARY_TOKEN =\s*\/(.+)\/;/)?.[1] ?? '(?!)',
);
const ac50Catches = (cls: string) =>
    cls
        .split(/[\s"'`]+/)
        .filter(Boolean)
        .some((t) => AC50_RE.test(t)) || cls.includes('var(--primary');
const primaryRefs = (css: string) =>
    css.match(/var\(--(?:color-)?primary\)/g)?.length ?? 0;

const AC50_MISSES = [
    '!bg-primary',
    'hover:!bg-primary',
    'ring-offset-primary',
    'bg-(--primary)',
    'bg-[var(--color-primary)]',
    '[color:var(--color-primary)]',
    'border-t-primary',
    'border-x-primary',
    'bg-primary/[0.5]',
    'inset-ring-primary',
    'inset-shadow-primary',
    'placeholder-primary',
    'drop-shadow-primary',
    'text-shadow-primary',
];

describe('Adversary chunk 3b loop 3: AC50 scan misses', () => {
    it('the model matches the live guard: regex extracted, substring check present (guard)', () => {
        expect(AC50_RE.source).not.toBe('(?!)');
        expect(AC50_SRC).toContain(".includes('var(--primary')");
    });

    it.each([
        'bg-primary',
        'outline-primary',
        'bg-[var(--primary)]',
        '[color:var(--primary)]',
    ])('%s emits --primary and AC50 catches it (guard)', async (cls) => {
        expect(primaryRefs(await twCss([cls]))).toBeGreaterThan(
            primaryRefs(await twCss([])),
        );
        expect(ac50Catches(cls)).toBe(true);
    });

    it.each(AC50_MISSES)(
        '%s emits --primary (green), so AC50 must catch it (minor)',
        async (cls) => {
            expect(primaryRefs(await twCss([cls]))).toBeGreaterThan(
                primaryRefs(await twCss([])),
            );
            expect(ac50Catches(cls)).toBe(true);
        },
    );

    it('no live non-test file in resources/js uses a missed form today (guard)', () => {
        const hits = walk(JS_DIR).flatMap((f) => {
            const text = fs.readFileSync(f, 'utf8');

            return AC50_MISSES.filter((m) => text.includes(m)).map(
                (m) => `${rel(f)}: ${m}`,
            );
        });

        expect(hits).toEqual([]);
    });
});

const TOOLTIP_SRC = readRoot('resources/js/components/ui/tooltip.tsx');
const TOOLTIP_BASE =
    TOOLTIP_SRC.match(/className=\{cn\(\s*"([^"]+)"/)?.[1] ?? '';
const TOOLTIP_ARROW =
    TOOLTIP_SRC.match(/<TooltipPrimitive\.Arrow className="([^"]+)"/)?.[1] ??
    '';

describe('Adversary chunk 3b loop 3: dark tooltip and a caller className', () => {
    const merged = async (caller: string) => {
        const { cn } = await import('@/lib/utils');

        return cn(TOOLTIP_BASE, caller).split(' ');
    };

    it.each([
        // caller, kept, dropped
        [
            'max-w-xs',
            [
                'bg-foreground',
                'text-background',
                'dark:bg-border',
                'dark:text-foreground',
            ],
            [],
        ],
        [
            'text-sm',
            [
                'bg-foreground',
                'text-background',
                'dark:bg-border',
                'dark:text-foreground',
            ],
            ['text-xs'],
        ],
        [
            'bg-red-500',
            ['text-background', 'dark:bg-border', 'dark:text-foreground'],
            ['bg-foreground'],
        ],
        [
            'text-white',
            ['bg-foreground', 'dark:bg-border', 'dark:text-foreground'],
            ['text-background'],
        ],
        [
            'bg-card text-card-foreground',
            ['dark:bg-border', 'dark:text-foreground'],
            ['bg-foreground', 'text-background'],
        ],
    ])(
        'caller className="%s" keeps the dark tokens (guard)',
        async (caller, kept, dropped) => {
            const t = await merged(caller);

            expect(TOOLTIP_BASE).not.toBe('');
            expect(t).toEqual(expect.arrayContaining(kept));

            for (const d of dropped) expect(t).not.toContain(d);
        },
    );

    it('only an explicit dark: caller class replaces dark:bg-border (guard)', async () => {
        const t = await merged('dark:bg-card');

        expect(t).toContain('dark:bg-card');
        expect(t).not.toContain('dark:bg-border');
        expect(t).toEqual(
            expect.arrayContaining([
                'bg-foreground',
                'text-background',
                'dark:text-foreground',
            ]),
        );
    });

    it('compiled CSS: dark:bg-border (0,2,0) outranks a caller bg-* (0,1,0) in dark mode (guard)', async () => {
        const css = await twCss(['dark:bg-border', 'dark:fill-border']);

        expect(css).toMatch(
            /\.dark\\:bg-border:is\(\.dark \*\) \{\s*background-color: var\(--color-border\);/,
        );
        expect(css).toMatch(
            /\.dark\\:fill-border:is\(\.dark \*\) \{\s*fill: var\(--color-border\);/,
        );
    });

    it('the Arrow is a literal (no caller className reaches it) with both light and dark tokens (guard)', () => {
        expect(TOOLTIP_ARROW.split(' ')).toEqual(
            expect.arrayContaining([
                'bg-foreground',
                'fill-foreground',
                'dark:bg-border',
                'dark:fill-border',
            ]),
        );
        expect(TOOLTIP_SRC).not.toMatch(
            /<TooltipPrimitive\.Arrow\b[^>]*className=\{/,
        );
    });
});

describe('Adversary chunk 3b loop 3: detection-card badge variants', () => {
    const renderCard = async (status: string, selectedRoot = '.') => {
        const { default: Card } =
            await import('@/components/framework-detection-card');
        type Detection = NonNullable<ComponentProps<typeof Card>['detection']>;
        const detection = {
            id: 'd1',
            status: status as Detection['status'],
            framework: 'laravel',
            roots: [
                {
                    path: '.',
                    declaredVersion: '^11.0',
                    lockedVersion: '11.0.0',
                    phpConstraint: '^8.2',
                    routeLayout: 'bootstrap_app',
                    routeFiles: null,
                    routeFileCount: 1,
                    modules: [],
                    confidence: 'high',
                    evidence: null,
                },
            ],
            warnings: [],
            hiddenWarningCount: 0,
            selectedRoot,
            selectionSource: 'auto',
            selectedAt: null,
            detectorVersion: '1',
            outdated: false,
            detectedAt: null,
        } satisfies Detection;

        const html = renderToStaticMarkup(
            <Card
                projectId="p1"
                snapshotId="s1"
                detection={detection}
                canDetect={false}
                canSelectRoot={false}
            />,
        );

        return [
            ...html.matchAll(/(<span\b[^>]*data-slot="badge"[^>]*>)([^<]*)/g),
        ].map((m) => ({ t: tokensOfTag(m[1]), label: m[2] }));
    };

    const SOLID_DEFAULT = ['bg-info', 'text-info-foreground'];

    it.each([
        ['detected', 'bg-info/10', 'Laravel detected'],
        ['partial', 'bg-secondary', 'Needs your input'],
        ['unsupported', 'bg-destructive/10', 'Not supported'],
        ['not_found', 'bg-destructive/10', 'No application found'],
        ['failed', 'bg-destructive/10', ''],
        ['something-new', 'bg-destructive/10', ''],
        ['', 'bg-destructive/10', ''],
        ['toString', 'bg-destructive/10', ''],
        ['__proto__', 'bg-destructive/10', ''],
    ])(
        'status "%s" maps to %s, never the solid default, and does not crash',
        async (status, bg, label) => {
            const badges = await renderCard(status);
            const statusBadge = badges[0];

            expect(statusBadge.t).toContain(bg);
            expect(statusBadge.label).toBe(label);

            for (const b of badges) {
                for (const s of SOLID_DEFAULT) expect(b.t).not.toContain(s);
            }
        },
    );

    it('"Selected root" is the info tint, and absent when the root does not match (guard)', async () => {
        const on = (await renderCard('detected', '.')).filter(
            (b) => b.label === 'Selected root',
        );
        const off = (await renderCard('detected', 'other')).filter(
            (b) => b.label === 'Selected root',
        );

        expect(on).toHaveLength(1);
        expect(on[0].t).toEqual(
            expect.arrayContaining(['bg-info/10', 'text-info']),
        );
        expect(off).toEqual([]);
    });
});

// ---------------------------------------------------------------------------
// Chunk 3b fix loop 4 (gate C): the revision 12f PRIMARY_TOKEN rule (false
// positives and misses) and the status badge own-key lookup. Appended only.

const ac50Hits = (text: string) =>
    text
        .split(/[\s"'`]+/)
        .filter(Boolean)
        .filter((t) => AC50_RE.test(t));
const paintsPrimary = async (cls: string) =>
    primaryRefs(await twCss([cls])) > primaryRefs(await twCss([]));

describe('Adversary chunk 3b loop 4: AC50 rule false positives', () => {
    it('over every non-test file in resources/js, only ui/button.tsx bg-primary and text-primary-foreground hit (guard)', () => {
        const hits = walk(JS_DIR).flatMap((f) =>
            [...new Set(ac50Hits(fs.readFileSync(f, 'utf8')))].map(
                (t) => `${rel(f)}: ${t}`,
            ),
        );

        expect(hits.sort()).toEqual([
            'components/ui/button.tsx: bg-primary',
            'components/ui/button.tsx: text-primary-foreground',
        ]);
    });

    it('nav-main.tsx bg-sidebar-primary is not a hit (guard)', () => {
        const text = readRoot('resources/js/components/nav-main.tsx');

        expect(text).toContain('data-[active=true]:before:bg-sidebar-primary');
        expect(ac50Hits(text)).toEqual([]);
    });

    it.each([
        'bg-sidebar-primary',
        'hover:bg-sidebar-primary/50!',
        'text-sidebar-primary-foreground/80',
        '!bg-sidebar-primary',
        'primary',
        'Primary',
        'isPrimary',
        'primaryKey',
        'primary-key',
        'variant="primary"',
        '<p>Set as primary.</p>',
        'non-primary.',
        '(primary)',
    ])('%s is not a hit (guard)', (text) => {
        expect(ac50Hits(text)).toEqual([]);
    });

    // C3b-F24 accepted as a fail-safe limit, user "accept (2)", ledger 17:30, spec AC50 Known limit (:769).
    it.each([
        ['<p>Use a non-primary account</p>', 'non-primary'],
        ['<li data-testid="make-primary" />', 'make-primary'],
        ["const id = 'cta-primary';", 'cta-primary'],
    ])(
        '%s paints nothing, yet AC50 flags %s (documented expectation)',
        async (text, word) => {
            const words = text.split(/[\s"'`<>=/]+/).filter(Boolean);

            for (const w of words) expect(await paintsPrimary(w)).toBe(false);

            expect(ac50Hits(text)).toEqual([word]);
        },
    );
});

describe('Adversary chunk 3b loop 4: AC50 rule misses', () => {
    it.each([
        'stroke-primary',
        'fill-primary',
        'caret-primary',
        'accent-primary',
        'decoration-primary',
        'from-primary',
        'via-primary',
        'to-primary',
        'divide-primary',
        'outline-primary/50',
        'shadow-primary/20',
        'ring-primary/[.3]',
        'bg-primary/(--a)',
        'bg-primary/50!',
        'border-primary!',
        'bg-[theme(--color-primary)]',
    ])('%s emits --primary and AC50 catches it (guard)', async (cls) => {
        expect(await paintsPrimary(cls)).toBe(true);
        expect(ac50Catches(cls)).toBe(true);
    });

    it('bg-sidebar-primary emits no --primary (guard)', async () => {
        expect(await paintsPrimary('bg-sidebar-primary')).toBe(false);
    });

    const THEME_MISSES = [
        'bg-[theme(colors.primary)]',
        '[color:theme(colors.primary)]',
        'bg-[color:theme(colors.primary)]',
        'bg-[theme(colors.primary/50%)]',
    ];

    it.each(THEME_MISSES)(
        '%s emits --primary (green), so AC50 must catch it (minor)',
        async (cls) => {
            expect(await paintsPrimary(cls)).toBe(true);
            expect(ac50Catches(cls)).toBe(true);
        },
    );

    it('no live non-test file in resources/js uses theme(colors.primary today (guard)', () => {
        const hits = walk(JS_DIR)
            .filter((f) =>
                fs.readFileSync(f, 'utf8').includes('theme(colors.primary'),
            )
            .map(rel);

        expect(hits).toEqual([]);
    });

    it('no live non-test file builds a bg-/text- class from a template or concatenation (guard)', () => {
        const hits = walk(JS_DIR).flatMap((f) =>
            fs
                .readFileSync(f, 'utf8')
                .split('\n')
                .flatMap((l, i) =>
                    /\b(?:bg|text|border|ring|fill|stroke)-\$\{|['"`](?:bg|text|border|ring)-['"`]\s*\+/.test(
                        l,
                    )
                        ? [`${rel(f)}:${i + 1}`]
                        : [],
                ),
        );

        expect(hits).toEqual([]);
    });
});

describe('Adversary chunk 3b loop 4: status badge own-key lookup', () => {
    const statusBadge = async (status: string) => {
        const { default: Card } =
            await import('@/components/framework-detection-card');
        type Detection = NonNullable<ComponentProps<typeof Card>['detection']>;
        const detection = {
            id: 'd1',
            status: status as Detection['status'],
            framework: 'laravel',
            roots: [],
            warnings: [],
            hiddenWarningCount: 0,
            selectedRoot: null,
            selectionSource: 'auto',
            selectedAt: null,
            detectorVersion: '1',
            outdated: false,
            detectedAt: null,
        } satisfies Detection;
        const html = renderToStaticMarkup(
            <Card
                projectId="p1"
                snapshotId="s1"
                detection={detection}
                canDetect={false}
                canSelectRoot={false}
            />,
        );
        const m = html.match(
            /(<span\b[^>]*data-slot="badge"[^>]*>)(.*?)<\/span>/,
        );

        expect(m, `no badge in: ${html}`).not.toBeNull();

        return { t: tokensOfTag(m![1]), inner: m![2] };
    };

    it.each([
        ['detected', 'bg-info/10', 'Laravel detected'],
        ['partial', 'bg-secondary', 'Needs your input'],
        ['unsupported', 'bg-destructive/10', 'Not supported'],
        ['not_found', 'bg-destructive/10', 'No application found'],
    ])(
        'known status "%s" keeps %s and its label (guard)',
        async (s, bg, label) => {
            const b = await statusBadge(s);

            expect(b.t).toContain(bg);
            expect(b.inner).toBe(label);
        },
    );

    it.each([
        'constructor',
        'hasOwnProperty',
        'valueOf',
        '__proto__',
        'toString',
        'isPrototypeOf',
        '__defineGetter__',
        '0',
        '1e3',
        '-1',
        'NaN',
    ])(
        'status "%s" renders an empty destructive badge, no object or function (guard)',
        async (s) => {
            const b = await statusBadge(s);

            expect(b.t).toContain('bg-destructive/10');
            expect(b.inner).toBe('');
        },
    );

    it('the own-key test reads statusText itself, not a copy (guard)', () => {
        const text = readRoot(
            'resources/js/components/framework-detection-card.tsx',
        );

        expect(text).toMatch(
            /Object\.hasOwn\(statusText, detection\.status\) &&\s*statusText\[detection\.status\]/,
        );
    });
});
