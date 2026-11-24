import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import type { ComponentProps, ReactNode } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import { AppSidebar } from '@/components/app-sidebar';
import AppLogo from '@/components/app-logo';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { ResponseList } from '@/components/contract-views';
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
import ShowDocumentation from '@/pages/projects/documentation';
import ShowEndpoint from '@/pages/projects/endpoint';
import Endpoints from '@/pages/projects/endpoints';
import ProjectsIndex from '@/pages/projects/index';
import ShowProject from '@/pages/projects/show';
import ShowSnapshot from '@/pages/projects/snapshot';
import type { User } from '@/types';

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
        expect(filesMatching(/dangerouslySetInnerHTML/)).toEqual([
            'components/two-factor-setup-modal.tsx',
        ]);
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
