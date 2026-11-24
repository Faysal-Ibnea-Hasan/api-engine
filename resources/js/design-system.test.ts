import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vite-plus/test';

const JS_DIR = fileURLToPath(new URL('.', import.meta.url));
const CSS_FILE = path.join(JS_DIR, '../css/app.css');
const BLADE_FILE = path.join(JS_DIR, '../views/app.blade.php');

const read = (file: string) => fs.readFileSync(file, 'utf8');
const collapse = (s: string) => s.replace(/\s+/g, ' ').trim();

// Verbatim copy of the spec's palette table: the single source of truth for AC1 and AC5.
const PALETTE: Record<string, { light: string; dark: string }> = {
    '--background': { light: 'oklch(1 0 0)', dark: 'oklch(0.165 0.04 265)' },
    '--foreground': {
        light: 'oklch(0.208 0.042 265.755)',
        dark: 'oklch(0.984 0.003 247.858)',
    },
    '--card': {
        light: 'oklch(1 0 0)',
        dark: 'oklch(0.208 0.042 265.755)',
    },
    '--card-foreground': {
        light: 'oklch(0.208 0.042 265.755)',
        dark: 'oklch(0.984 0.003 247.858)',
    },
    '--popover': {
        light: 'oklch(1 0 0)',
        dark: 'oklch(0.208 0.042 265.755)',
    },
    '--popover-foreground': {
        light: 'oklch(0.208 0.042 265.755)',
        dark: 'oklch(0.984 0.003 247.858)',
    },
    '--primary': {
        light: 'oklch(0.511 0.262 276.966)',
        dark: 'oklch(0.673 0.182 276.935)',
    },
    '--primary-foreground': {
        light: 'oklch(0.984 0.003 247.858)',
        dark: 'oklch(0.129 0.042 264.695)',
    },
    '--secondary': {
        light: 'oklch(0.968 0.007 247.896)',
        dark: 'oklch(0.279 0.041 260.031)',
    },
    '--secondary-foreground': {
        light: 'oklch(0.208 0.042 265.755)',
        dark: 'oklch(0.984 0.003 247.858)',
    },
    '--muted': {
        light: 'oklch(0.968 0.007 247.896)',
        dark: 'oklch(0.279 0.041 260.031)',
    },
    '--muted-foreground': {
        light: 'oklch(0.5 0.042 257)',
        dark: 'oklch(0.704 0.04 256.788)',
    },
    '--accent': {
        light: 'oklch(0.968 0.007 247.896)',
        dark: 'oklch(0.279 0.041 260.031)',
    },
    '--accent-foreground': {
        light: 'oklch(0.208 0.042 265.755)',
        dark: 'oklch(0.984 0.003 247.858)',
    },
    '--destructive': {
        light: 'oklch(0.505 0.213 27.518)',
        dark: 'oklch(0.704 0.191 22.216)',
    },
    '--destructive-foreground': {
        light: 'oklch(0.984 0.003 247.858)',
        dark: 'oklch(0.129 0.042 264.695)',
    },
    '--success': {
        light: 'oklch(0.508 0.118 165.612)',
        dark: 'oklch(0.765 0.177 163.223)',
    },
    '--success-foreground': {
        light: 'oklch(0.984 0.003 247.858)',
        dark: 'oklch(0.129 0.042 264.695)',
    },
    '--warning': {
        light: 'oklch(0.473 0.137 46.201)',
        dark: 'oklch(0.828 0.189 84.429)',
    },
    '--warning-foreground': {
        light: 'oklch(0.984 0.003 247.858)',
        dark: 'oklch(0.129 0.042 264.695)',
    },
    '--info': {
        light: 'oklch(0.5 0.134 242.749)',
        dark: 'oklch(0.746 0.16 232.661)',
    },
    '--info-foreground': {
        light: 'oklch(0.984 0.003 247.858)',
        dark: 'oklch(0.129 0.042 264.695)',
    },
    '--border': {
        light: 'oklch(0.929 0.013 255.508)',
        dark: 'oklch(0.279 0.041 260.031)',
    },
    '--input': {
        light: 'oklch(0.929 0.013 255.508)',
        dark: 'oklch(0.372 0.044 257.287)',
    },
    '--ring': {
        light: 'oklch(0.585 0.233 277.117)',
        dark: 'oklch(0.673 0.182 276.935)',
    },
    '--chart-1': {
        light: 'oklch(0.585 0.233 277.117)',
        dark: 'oklch(0.673 0.182 276.935)',
    },
    '--chart-2': {
        light: 'oklch(0.685 0.169 237.323)',
        dark: 'oklch(0.746 0.16 232.661)',
    },
    '--chart-3': {
        light: 'oklch(0.696 0.17 162.48)',
        dark: 'oklch(0.765 0.177 163.223)',
    },
    '--chart-4': {
        light: 'oklch(0.769 0.188 70.08)',
        dark: 'oklch(0.828 0.189 84.429)',
    },
    '--chart-5': {
        light: 'oklch(0.606 0.25 292.717)',
        dark: 'oklch(0.702 0.183 293.541)',
    },
    '--sidebar': {
        light: 'oklch(0.984 0.003 247.858)',
        dark: 'oklch(0.129 0.042 264.695)',
    },
    '--sidebar-foreground': {
        light: 'oklch(0.372 0.044 257.287)',
        dark: 'oklch(0.869 0.022 252.894)',
    },
    '--sidebar-primary': {
        light: 'oklch(0.511 0.262 276.966)',
        dark: 'oklch(0.673 0.182 276.935)',
    },
    '--sidebar-primary-foreground': {
        light: 'oklch(0.984 0.003 247.858)',
        dark: 'oklch(0.129 0.042 264.695)',
    },
    '--sidebar-accent': {
        light: 'oklch(0.929 0.013 255.508)',
        dark: 'oklch(0.279 0.041 260.031)',
    },
    '--sidebar-accent-foreground': {
        light: 'oklch(0.208 0.042 265.755)',
        dark: 'oklch(0.984 0.003 247.858)',
    },
    '--sidebar-border': {
        light: 'oklch(0.929 0.013 255.508)',
        dark: 'oklch(0.279 0.041 260.031)',
    },
    '--sidebar-ring': {
        light: 'oklch(0.585 0.233 277.117)',
        dark: 'oklch(0.673 0.182 276.935)',
    },
};

type Mode = 'light' | 'dark';
const MODES: Mode[] = ['light', 'dark'];

// ---------------------------------------------------------------------------
// CSS parsing: regex-free brace matcher, recursing through @layer/@media.
// ---------------------------------------------------------------------------

type Block = { selector: string; body: string };

function parseBlocks(css: string): Block[] {
    const text = css.replace(/\/\*[\s\S]*?\*\//g, '');
    const blocks: Block[] = [];
    let i = 0;
    let start = 0;

    while (i < text.length) {
        const ch = text[i];

        if (ch === ';') {
            start = i + 1;
        } else if (ch === '{') {
            let depth = 1;
            let j = i + 1;

            while (j < text.length && depth > 0) {
                if (text[j] === '{') depth++;
                if (text[j] === '}') depth--;
                j++;
            }

            const selector = collapse(text.slice(start, i));
            const body = text.slice(i + 1, j - 1);

            blocks.push({ selector, body });

            if (/^@(layer|media|supports)\b/.test(selector)) {
                blocks.push(...parseBlocks(body));
            }

            i = j;
            start = j;
            continue;
        }

        i++;
    }

    return blocks;
}

function declarations(selectorMatches: (s: string) => boolean) {
    const decls: Record<string, string> = {};

    for (const { selector, body } of parseBlocks(read(CSS_FILE))) {
        if (!selectorMatches(selector)) continue;

        for (const part of body.split(';')) {
            const m = part.match(/^\s*([\w-]+)\s*:([\s\S]*)$/);

            if (m) decls[m[1]] = collapse(m[2]);
        }
    }

    return decls;
}

const rootDecls = () => declarations((s) => s === ':root');
const darkDecls = () => declarations((s) => s === '.dark');
const themeDecls = () => declarations((s) => /^@theme\b/.test(s));
const declsFor = (mode: Mode) => (mode === 'light' ? rootDecls() : darkDecls());

// ---------------------------------------------------------------------------
// AC1: tokens
// ---------------------------------------------------------------------------

describe('AC1: palette tokens in app.css', () => {
    it('the PALETTE copy holds the 38 spec tokens', () => {
        expect(Object.keys(PALETTE)).toHaveLength(38);
    });

    for (const mode of MODES) {
        it.each(Object.keys(PALETTE))(
            `${mode}: %s has the listed value`,
            (token) => {
                const actual = declsFor(mode)[token];

                expect(actual, `${token} missing from ${mode} block`).toBe(
                    collapse(PALETTE[token][mode]),
                );
            },
        );
    }

    it.each(Object.keys(PALETTE))(
        '@theme maps %s to its --color-* variable',
        (token) => {
            const name = `--color-${token.slice(2)}`;

            expect(themeDecls()[name], `${name} missing from @theme`).toBe(
                `var(${token})`,
            );
        },
    );

    it.each(['success', 'warning', 'info'])(
        '@theme maps the %s foreground pair',
        (tone) => {
            const theme = themeDecls();

            expect(theme[`--color-${tone}`]).toBe(`var(--${tone})`);
            expect(theme[`--color-${tone}-foreground`]).toBe(
                `var(--${tone}-foreground)`,
            );
        },
    );
});

// ---------------------------------------------------------------------------
// AC2: font, code size, radius, color-scheme
// ---------------------------------------------------------------------------

describe('AC2: fonts, radius and color-scheme', () => {
    const unquote = (s: string | undefined) => s?.replace(/"/g, "'");

    it('@theme declares --font-mono as the system mono stack', () => {
        expect(unquote(themeDecls()['--font-mono'])).toBe(
            "ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Consolas, 'Liberation Mono', monospace",
        );
    });

    it('@theme declares --text-code and its line height', () => {
        expect(themeDecls()['--text-code']).toBe('0.8125rem');
        expect(themeDecls()['--text-code--line-height']).toBe('1.25rem');
    });

    it(':root declares --radius: 0.5rem', () => {
        expect(rootDecls()['--radius']).toBe('0.5rem');
    });

    it('declares color-scheme per mode', () => {
        expect(rootDecls()['color-scheme']).toBe('light');
        expect(darkDecls()['color-scheme']).toBe('dark');
    });

    it('keeps the --radius-lg/md/sm derivation', () => {
        const theme = themeDecls();

        expect(theme['--radius-lg']).toBeTruthy();
        expect(theme['--radius-md']).toBeTruthy();
        expect(theme['--radius-sm']).toBeTruthy();
    });
});

// ---------------------------------------------------------------------------
// AC3: blade inline background and Inertia progress color
// ---------------------------------------------------------------------------

describe('AC3: pre-hydration background and progress color', () => {
    const blade = collapse(read(BLADE_FILE));

    const bladeValue = (re: RegExp) => {
        const m = blade.match(re);

        return m ? collapse(m[1]) : null;
    };

    it('html background equals the light --background', () => {
        expect(
            bladeValue(/html \{ ?background-color: ?([^;}]+?) ?;? ?\}/),
        ).toBe(collapse(PALETTE['--background'].light));
    });

    it('html.dark background equals the dark --background', () => {
        expect(
            bladeValue(/html\.dark \{ ?background-color: ?([^;}]+?) ?;? ?\}/),
        ).toBe(collapse(PALETTE['--background'].dark));
    });

    it("Inertia progress.color is 'var(--primary)'", () => {
        const app = read(path.join(JS_DIR, 'app.tsx'));

        expect(app).toMatch(
            /progress:\s*\{[^}]*?color:\s*(['"])var\(--primary\)\1/,
        );
    });
});

// ---------------------------------------------------------------------------
// AC5: WCAG contrast from the parsed tokens
// ---------------------------------------------------------------------------

const clamp01 = (x: number) => Math.min(1, Math.max(0, x));

function toLinear(value: string) {
    const m = value.match(/^oklch\(\s*([\d.]+)\s+([\d.]+)\s+([\d.]+)\s*\)$/);

    if (!m) throw new Error(`not an oklch() value: ${value}`);

    const [L, C, h] = [Number(m[1]), Number(m[2]), Number(m[3])];
    const a = C * Math.cos((h * Math.PI) / 180);
    const b = C * Math.sin((h * Math.PI) / 180);
    const l = (L + 0.3963377774 * a + 0.2158037573 * b) ** 3;
    const mm = (L - 0.1055613458 * a - 0.0638541728 * b) ** 3;
    const s = (L - 0.0894841775 * a - 1.291485548 * b) ** 3;
    const raw = [
        4.0767416621 * l - 3.3077115913 * mm + 0.2309699292 * s,
        -1.2684380046 * l + 2.6097574011 * mm - 0.3413193965 * s,
        -0.0041960863 * l - 0.7034186147 * mm + 1.707614701 * s,
    ];

    return { raw, rgb: raw.map(clamp01) };
}

const luminance = (rgb: number[]) =>
    0.2126 * rgb[0] + 0.7152 * rgb[1] + 0.0722 * rgb[2];
const encode = (c: number) =>
    c <= 0.0031308 ? 12.92 * c : 1.055 * c ** (1 / 2.4) - 0.055;
const decode = (c: number) =>
    c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
const ratio = (a: number, b: number) =>
    (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);

describe('AC5: converter self-checks (against the PALETTE copy)', () => {
    it('oklch(1 0 0) has Y = 1 and oklch(0 0 0) has Y = 0', () => {
        expect(
            Math.abs(luminance(toLinear('oklch(1 0 0)').rgb) - 1),
        ).toBeLessThan(1e-4);
        expect(luminance(toLinear('oklch(0 0 0)').rgb)).toBeCloseTo(0, 6);
    });

    it('slate-900 matches the #0f172b reference', () => {
        const y = luminance(toLinear('oklch(0.208 0.042 265.755)').rgb);

        expect(y).toBeGreaterThanOrEqual(0.0086);
        expect(y).toBeLessThanOrEqual(0.0091);
    });

    it('light --destructive has an unclamped G < 0 and the #c10007 luminance', () => {
        const c = toLinear(PALETTE['--destructive'].light);
        const y = luminance(c.rgb);

        expect(c.raw[1]).toBeLessThan(0);
        expect(y).toBeGreaterThanOrEqual(0.112);
        expect(y).toBeLessThanOrEqual(0.115);
    });

    it('dark --primary has an unclamped B > 1 and the #7c86ff luminance', () => {
        const c = toLinear(PALETTE['--primary'].dark);
        const y = luminance(c.rgb);

        expect(c.raw[2]).toBeGreaterThan(1);
        expect(y).toBeGreaterThanOrEqual(0.283);
        expect(y).toBeLessThanOrEqual(0.289);
    });
});

type Pair = {
    id: string;
    fg: string;
    bg: string;
    // Foreground text on `tone` at `alpha` composited over `bg` (a surface token).
    tint?: { tone: string; alpha: number };
};

const SURFACES = ['--background', '--card'];
const PAIRS: Pair[] = [
    { id: 'P1', fg: '--foreground', bg: '--background' },
    { id: 'P2', fg: '--foreground', bg: '--card' },
    { id: 'P3', fg: '--muted-foreground', bg: '--background' },
    { id: 'P4', fg: '--muted-foreground', bg: '--card' },
    { id: 'P5', fg: '--muted-foreground', bg: '--muted' },
    { id: 'P6', fg: '--muted-foreground', bg: '--sidebar' },
    { id: 'P7', fg: '--sidebar-foreground', bg: '--sidebar' },
    {
        id: 'P8',
        fg: '--sidebar-accent-foreground',
        bg: '--sidebar-accent',
    },
    { id: 'P9', fg: '--secondary-foreground', bg: '--secondary' },
    { id: 'P9-accent', fg: '--accent-foreground', bg: '--accent' },
    { id: 'P10', fg: '--primary-foreground', bg: '--primary' },
    { id: 'P11', fg: '--primary', bg: '--background' },
    { id: 'P12', fg: '--primary', bg: '--card' },
    { id: 'P13', fg: '--destructive-foreground', bg: '--destructive' },
    { id: 'P14', fg: '--success-foreground', bg: '--success' },
    { id: 'P15', fg: '--warning-foreground', bg: '--warning' },
    { id: 'P16', fg: '--info-foreground', bg: '--info' },
    ...(
        [
            ['P17', '--destructive'],
            ['P18', '--success'],
            ['P19', '--warning'],
            ['P20', '--info'],
        ] as const
    ).flatMap(([id, fg]) => SURFACES.map((bg) => ({ id, fg, bg }))),
    ...(
        [
            ['P21', '--destructive'],
            ['P22', '--success'],
            ['P23', '--warning'],
            ['P24', '--info'],
        ] as const
    ).flatMap(([id, tone]) =>
        SURFACES.map((bg) => ({
            id,
            fg: tone,
            bg,
            tint: { tone, alpha: 0.1 },
        })),
    ),
    ...SURFACES.map((bg) => ({
        id: 'P25',
        fg: '--foreground',
        bg,
        tint: { tone: '--warning', alpha: 0.15 },
    })),
    // Revision 8 (chunk 3): text-info / text-warning in the contract-views code blocks.
    { id: 'P26', fg: '--info', bg: '--muted' },
    { id: 'P27', fg: '--warning', bg: '--muted' },
];

function tokenRgb(decls: Record<string, string>, token: string) {
    const value = decls[token];

    if (!value) throw new Error(`${token} is missing from app.css`);

    return toLinear(value).rgb;
}

function pairRatio(pair: Pair, mode: Mode) {
    const decls = declsFor(mode);
    const fg = luminance(tokenRgb(decls, pair.fg));

    if (!pair.tint) {
        return ratio(fg, luminance(tokenRgb(decls, pair.bg)));
    }

    // Browsers composite tints per channel in gamma-encoded sRGB.
    const tone = tokenRgb(decls, pair.tint.tone).map(encode);
    const base = tokenRgb(decls, pair.bg).map(encode);
    const a = pair.tint.alpha;
    const tint = tone.map((t, i) => decode(a * t + (1 - a) * base[i]));

    return ratio(fg, luminance(tint));
}

describe('AC5: WCAG contrast of every spec pair is at least 4.5', () => {
    for (const mode of MODES) {
        it.each(
            PAIRS.map(
                (p) =>
                    [
                        `${p.id} ${p.fg} on ${p.tint ? `${p.tint.tone}/${p.tint.alpha * 100}% over ` : ''}${p.bg}`,
                        p,
                    ] as const,
            ),
        )(`${mode}: %s`, (_label, pair) => {
            expect(pairRatio(pair, mode)).toBeGreaterThanOrEqual(4.5);
        });
    }
});

// ---------------------------------------------------------------------------
// AC4: color scan
// ---------------------------------------------------------------------------

const P =
    '(?:bg|text|border(?:-[trblxyse])?|ring(?:-offset)?|outline|fill|stroke|from|via|to|divide|shadow|inset-shadow|inset-ring|accent|caret|decoration|placeholder)';
const RAW_COLORS =
    'slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose';

const ARBITRARY_HEX = /\[#[0-9a-fA-F]{3,8}\]/g;
const INLINE_HEX =
    /(?<!\b(?:href|id|to)=\{?)[:'"`(]\s*#(?:[0-9a-fA-F]{8}|[0-9a-fA-F]{6}|[0-9a-fA-F]{3,4})(?![\w-])/g;

const FORBIDDEN: { name: string; re: RegExp }[] = [
    {
        name: 'raw palette color class',
        re: new RegExp(`\\b${P}-(?:${RAW_COLORS})-\\d{2,3}\\b`, 'g'),
    },
    {
        name: 'black/white color utility',
        re: new RegExp(`\\b${P}-(?:black|white)\\b`, 'g'),
    },
    { name: 'arbitrary hex', re: ARBITRARY_HEX },
    { name: 'inline hex', re: INLINE_HEX },
    { name: 'color function literal', re: /(?:rgba?|oklch|oklab|hsla?)\(/g },
    {
        name: 'dynamic color class',
        re: new RegExp(`\\b${P}-\\$\\{`, 'g'),
    },
    { name: 'rounded-xl/2xl/3xl', re: /\brounded-(?:xl|2xl|3xl)\b/g },
];

// (file relative to resources/js, exact match text) pairs.
const ALLOW_LIST: Record<string, string[]> = {
    'components/ui/dialog.tsx': ['bg-black/50'],
    'components/ui/sheet.tsx': ['bg-black/50'],
    'components/two-factor-setup-modal.tsx': ['bg-white'],
};

const UNUSED_FILES = [
    'components/app-header.tsx',
    'layouts/app/app-header-layout.tsx',
    'layouts/auth/auth-card-layout.tsx',
    'layouts/auth/auth-split-layout.tsx',
];
const EXCLUDED_DIRS = ['actions', 'routes', 'wayfinder', 'types'];

const rel = (file: string) =>
    path.relative(JS_DIR, file).split(path.sep).join('/');

function walk(dir: string): string[] {
    return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const full = path.join(dir, entry.name);

        if (entry.isDirectory()) return walk(full);

        return /\.tsx?$/.test(entry.name) ? [full] : [];
    });
}

function isExcluded(file: string) {
    const r = rel(file);

    return (
        /\.test\.tsx?$/.test(r) ||
        EXCLUDED_DIRS.some((d) => r.startsWith(`${d}/`)) ||
        UNUSED_FILES.includes(r)
    );
}

// Chunk 1 + 2 + 3 scope. Chunk 4 widens this list to all of resources/js.
// It must not name nav-footer.tsx, which chunk 1 deletes.
const SCOPE_PATHS = [
    'app.tsx',
    'layouts',
    'components/app-sidebar.tsx',
    'components/app-sidebar-header.tsx',
    'components/app-content.tsx',
    'components/app-shell.tsx',
    'components/app-logo.tsx',
    'components/nav-main.tsx',
    'components/nav-user.tsx',
    'components/user-info.tsx',
    'components/user-menu-content.tsx',
    'components/workspace-switcher.tsx',
    'components/breadcrumbs.tsx',
    // Chunk 2: the whole ui kit plus the shared components.
    'components/ui',
    'components/heading.tsx',
    'components/empty-state.tsx',
    'components/error-panel.tsx',
    'components/progress.tsx',
    'components/input-error.tsx',
    'components/alert-error.tsx',
    'components/text-link.tsx',
    'components/method-badge.tsx',
    'components/resolution-badge.tsx',
    'components/project-status-badge.tsx',
    'components/import-status.tsx',
    // Chunk 3: dashboard and projects (pages/projects covers endpoint.tsx and documentation.tsx).
    'pages/dashboard.tsx',
    'pages/projects',
    'components/source-panel.tsx',
    'components/zip-upload.tsx',
    'components/framework-detection-card.tsx',
    'components/endpoint-analysis.tsx',
    'components/contract-views.tsx',
    'components/delete-project.tsx',
];

function scopedFiles() {
    return SCOPE_PATHS.flatMap((p) => {
        const full = path.join(JS_DIR, p);

        if (!fs.existsSync(full))
            throw new Error(`scan scope path missing: ${p}`);

        return fs.statSync(full).isDirectory() ? walk(full) : [full];
    }).filter((f) => !isExcluded(f));
}

function violations(file: string, text: string) {
    const allowed = ALLOW_LIST[file] ?? [];
    const found: string[] = [];

    for (const { name, re } of FORBIDDEN) {
        for (const m of text.matchAll(re)) {
            if (allowed.some((a) => text.startsWith(a, m.index))) continue;

            found.push(`${file}: ${name}: ${m[0]}`);
        }
    }

    return found;
}

describe('AC4: hex pattern fixtures', () => {
    const hit = (s: string) =>
        [ARBITRARY_HEX, INLINE_HEX].some((re) => {
            re.lastIndex = 0;

            return re.test(s);
        });

    it.each([`'#ffff'`, `'#0f172b'`, `"#fff"`, '[#1b1b18]', 'color: #abc'])(
        'matches %s',
        (s) => {
            expect(hit(s)).toBe(true);
        },
    );

    it.each([`href="#add"`, `href={'#add'}`, `'#add-member'`, `"#section"`])(
        'does not match %s',
        (s) => {
            expect(hit(s)).toBe(false);
        },
    );
});

describe('AC4: scanner fixtures', () => {
    it.each([
        'bg-red-500',
        'dark:text-slate-200',
        'border-t-gray-300',
        'ring-offset-white',
        'text-white',
        'bg-black',
        'bg-[#1b1b18]',
        'style={{ color: #123456 }}',
        'shadow-[0_0_0_1px_hsl(var(--x))]',
        'bg-[rgba(0,0,0,0.5)]',
        'bg-${tone}-500',
        'rounded-xl',
        'rounded-2xl',
        'rounded-3xl',
    ])('flags %s', (s) => {
        expect(violations('x.tsx', s).length).toBeGreaterThan(0);
    });

    it.each([
        'text-muted-foreground',
        'bg-primary text-primary-foreground',
        'border-border',
        'ring-ring/50',
        'rounded-lg rounded-md rounded-sm rounded-full',
        'bg-destructive/10',
        'href="#add"',
    ])('passes %s', (s) => {
        expect(violations('x.tsx', s)).toEqual([]);
    });

    it('honors the allow-list per file and exact match only', () => {
        expect(violations('components/ui/dialog.tsx', 'bg-black/50')).toEqual(
            [],
        );
        expect(violations('components/ui/sheet.tsx', 'bg-black/50')).toEqual(
            [],
        );
        // Revision 4: the old scrim is no longer allowed.
        expect(
            violations('components/ui/dialog.tsx', 'bg-black/80'),
        ).not.toEqual([]);
        expect(
            violations('components/ui/sheet.tsx', 'bg-black/80'),
        ).not.toEqual([]);
        expect(
            violations('components/two-factor-setup-modal.tsx', 'bg-white'),
        ).toEqual([]);
        expect(violations('components/ui/card.tsx', 'bg-black/80')).not.toEqual(
            [],
        );
        expect(violations('components/ui/dialog.tsx', 'bg-white')).not.toEqual(
            [],
        );
    });
});

describe('AC4: no forbidden color or radius in scoped files (chunk 1 to 3 scope)', () => {
    it('scans the shell files and does not name nav-footer.tsx', () => {
        const files = scopedFiles().map(rel);

        expect(files).toContain('app.tsx');
        expect(files).toContain('layouts/auth/auth-simple-layout.tsx');
        expect(files).toContain('layouts/app/app-sidebar-layout.tsx');
        expect(files).toContain('layouts/settings/layout.tsx');
        expect(files).toContain('components/nav-main.tsx');
        expect(files).not.toContain('components/nav-footer.tsx');

        for (const unused of UNUSED_FILES) expect(files).not.toContain(unused);
    });

    it('scans the ui kit and the chunk 2 shared components', () => {
        const files = scopedFiles().map(rel);

        for (const f of [
            'components/ui/dialog.tsx',
            'components/ui/sheet.tsx',
            'components/ui/sidebar.tsx',
            'components/ui/sonner.tsx',
            'components/ui/native-select.tsx',
            'components/ui/textarea.tsx',
            'components/heading.tsx',
            'components/method-badge.tsx',
            'components/import-status.tsx',
        ]) {
            expect(files).toContain(f);
        }
    });

    it('scans the chunk 3 dashboard, project pages and components', () => {
        const files = scopedFiles().map(rel);

        for (const f of [
            'pages/dashboard.tsx',
            'pages/projects/index.tsx',
            'pages/projects/show.tsx',
            'pages/projects/wizard.tsx',
            'pages/projects/snapshot.tsx',
            'pages/projects/endpoints.tsx',
            'pages/projects/endpoint.tsx',
            'pages/projects/documentation.tsx',
            'components/source-panel.tsx',
            'components/zip-upload.tsx',
            'components/framework-detection-card.tsx',
            'components/endpoint-analysis.tsx',
            'components/contract-views.tsx',
            'components/delete-project.tsx',
        ]) {
            expect(files).toContain(f);
        }
    });

    it('has no violations', () => {
        const found = scopedFiles().flatMap((f) => violations(rel(f), read(f)));

        expect(found).toEqual([]);
    });
});

// ---------------------------------------------------------------------------
// AC28: sidebar footer links removed (Vitest part)
// ---------------------------------------------------------------------------

describe('AC28: sidebar footer links removed', () => {
    const sidebar = () => read(path.join(JS_DIR, 'components/app-sidebar.tsx'));

    it('nav-footer.tsx no longer exists', () => {
        expect(
            fs.existsSync(path.join(JS_DIR, 'components/nav-footer.tsx')),
        ).toBe(false);
    });

    it.each([
        'NavFooter',
        'footerNavItems',
        `'Repository'`,
        `'Documentation'`,
        'github.com/laravel/react-starter-kit',
        'laravel.com/docs/starter-kits',
    ])('app-sidebar.tsx does not contain %s', (needle) => {
        expect(sidebar()).not.toContain(needle);
    });

    it('app-sidebar.tsx keeps <NavUser /> inside <SidebarFooter>', () => {
        const text = sidebar();
        const open = text.indexOf('<SidebarFooter>');
        const close = text.indexOf('</SidebarFooter>');
        const user = text.indexOf('<NavUser />');

        expect(open).toBeGreaterThanOrEqual(0);
        expect(close).toBeGreaterThan(open);
        expect(user).toBeGreaterThan(open);
        expect(user).toBeLessThan(close);
    });

    it('no scanned file anywhere in resources/js references the removed links', () => {
        const needles = [
            '@/components/nav-footer',
            'github.com/laravel/react-starter-kit',
            'laravel.com/docs/starter-kits',
        ];
        const offenders = walk(JS_DIR)
            .filter((f) => !isExcluded(f))
            .flatMap((f) => {
                const text = read(f);

                return needles
                    .filter((n) => text.includes(n))
                    .map((n) => `${rel(f)}: ${n}`);
            });

        expect(offenders).toEqual([]);
    });
});

// ---------------------------------------------------------------------------
// AC29: motion tokens and the reduced-motion block
// ---------------------------------------------------------------------------

describe('AC29: motion tokens in app.css', () => {
    it.each([
        ['--duration-fast', '150ms'],
        ['--duration-base', '200ms'],
        ['--duration-slow', '300ms'],
    ])(':root declares %s: %s', (token, value) => {
        expect(rootDecls()[token]).toBe(value);
    });

    it('.dark declares no --duration-* token', () => {
        expect(
            Object.keys(darkDecls()).filter((k) => k.startsWith('--duration-')),
        ).toEqual([]);
    });

    it.each([
        ['--ease-standard', 'cubic-bezier(0.2, 0, 0, 1)'],
        ['--ease-exit', 'cubic-bezier(0.4, 0, 1, 1)'],
        ['--default-transition-duration', 'var(--duration-fast)'],
        ['--default-transition-timing-function', 'var(--ease-standard)'],
    ])('a separate @theme static block declares %s', (token, value) => {
        const decls = declarations((s) => s === '@theme static');

        expect(decls[token]).toBe(value);
        // Not folded into the plain @theme block.
        expect(declarations((s) => s === '@theme')[token]).toBeUndefined();
    });

    const MEDIA = /@media\s*\(\s*prefers-reduced-motion:\s*reduce\s*\)/;

    const reducedMotionRule = () => {
        const block = parseBlocks(read(CSS_FILE)).find((b) =>
            MEDIA.test(b.selector),
        );

        expect(block, 'no prefers-reduced-motion block').toBeDefined();

        const rule = parseBlocks(block!.body).find(
            (b) => b.selector === '*, ::before, ::after',
        );

        expect(rule, 'no *, ::before, ::after rule').toBeDefined();

        const decls: Record<string, string> = {};

        for (const part of rule!.body.split(';')) {
            const m = part.match(/^\s*([\w-]+)\s*:([\s\S]*)$/);

            if (m) decls[m[1]] = collapse(m[2]);
        }

        return decls;
    };

    it('holds the reduced-motion rule for *, ::before, ::after', () => {
        expect(reducedMotionRule()).toEqual({
            'animation-duration': '0.01ms !important',
            'animation-iteration-count': '1 !important',
            'transition-duration': '0.01ms !important',
            'scroll-behavior': 'auto !important',
        });
    });

    it('puts the reduced-motion block at brace depth 0', () => {
        const css = read(CSS_FILE).replace(/\/\*[\s\S]*?\*\//g, '');
        const at = css.search(MEDIA);

        expect(at).toBeGreaterThanOrEqual(0);

        const before = css.slice(0, at);
        const open = (before.match(/\{/g) ?? []).length;
        const close = (before.match(/\}/g) ?? []).length;

        expect(open - close).toBe(0);
    });

    it('does not edit the palette (AC1 still holds for the new tokens)', () => {
        for (const token of Object.keys(PALETTE)) {
            expect(rootDecls()[token]).toBe(collapse(PALETTE[token].light));
            expect(darkDecls()[token]).toBe(collapse(PALETTE[token].dark));
        }
    });
});

// ---------------------------------------------------------------------------
// AC36: motion scan
// ---------------------------------------------------------------------------

const MOTION_FORBIDDEN: { name: string; re: RegExp }[] = [
    { name: 'numeric duration utility', re: /\bduration-\d+\b/g },
    {
        name: 'linear/in/out easing utility',
        re: /\bease-(?:linear|in|out|in-out)\b/g,
    },
];

// (file, exact match) pairs: the caret-blink cadence.
const MOTION_ALLOW_LIST: Record<string, string[]> = {
    'components/ui/input-otp.tsx': ['duration-1000'],
};

// Only the excluded app-header imports it.
const MOTION_EXCLUDED = ['components/ui/navigation-menu.tsx'];

function motionViolations(file: string, text: string) {
    const allowed = MOTION_ALLOW_LIST[file] ?? [];
    const found: string[] = [];

    for (const { name, re } of MOTION_FORBIDDEN) {
        for (const m of text.matchAll(re)) {
            if (allowed.some((a) => text.startsWith(a, m.index))) continue;

            found.push(`${file}: ${name}: ${m[0]}`);
        }
    }

    return found;
}

describe('AC36: motion scan fixtures', () => {
    it.each([
        'duration-200',
        'data-[state=open]:duration-500',
        'ease-linear',
        'ease-in-out',
        'ease-out',
        'ease-in',
    ])('flags %s', (s) => {
        expect(motionViolations('x.tsx', s).length).toBeGreaterThan(0);
    });

    it.each([
        'duration-(--duration-base)',
        'data-[state=closed]:duration-(--duration-fast)',
        'ease-standard',
        'data-[state=closed]:ease-exit',
        'transition-colors',
    ])('passes %s', (s) => {
        expect(motionViolations('x.tsx', s)).toEqual([]);
    });

    it('allows duration-1000 only in input-otp.tsx', () => {
        expect(
            motionViolations('components/ui/input-otp.tsx', 'duration-1000'),
        ).toEqual([]);
        expect(
            motionViolations('components/ui/input-otp.tsx', 'duration-300'),
        ).not.toEqual([]);
        expect(
            motionViolations('components/ui/dialog.tsx', 'duration-1000'),
        ).not.toEqual([]);
    });
});

describe('AC36: no numeric duration or linear/in/out easing in scoped files', () => {
    const files = () =>
        scopedFiles().filter((f) => !MOTION_EXCLUDED.includes(rel(f)));

    it('scans the kit files that carried the old timing', () => {
        const names = files().map(rel);

        for (const f of [
            'components/ui/dialog.tsx',
            'components/ui/sheet.tsx',
            'components/ui/sidebar.tsx',
            'components/text-link.tsx',
            'components/ui/input-otp.tsx',
            // Chunk 3 scope.
            'pages/dashboard.tsx',
            'pages/projects/show.tsx',
            'pages/projects/endpoint.tsx',
            'pages/projects/documentation.tsx',
            'components/source-panel.tsx',
            'components/zip-upload.tsx',
            'components/endpoint-analysis.tsx',
            'components/contract-views.tsx',
        ]) {
            expect(names).toContain(f);
        }

        expect(names).not.toContain('components/ui/navigation-menu.tsx');
    });

    it('has no violations', () => {
        const found = files().flatMap((f) => motionViolations(rel(f), read(f)));

        expect(found).toEqual([]);
    });
});
