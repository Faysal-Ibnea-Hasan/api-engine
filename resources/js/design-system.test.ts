import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vite-plus/test';

const JS_DIR = fileURLToPath(new URL('.', import.meta.url));
const CSS_FILE = path.join(JS_DIR, '../css/app.css');
const BLADE_FILE = path.join(JS_DIR, '../views/app.blade.php');

const read = (file: string) => fs.readFileSync(file, 'utf8');
const collapse = (s: string) => s.replace(/\s+/g, ' ').trim();

// Verbatim copy of the spec's palette table (revision 12, GitHub Primer): the single source of truth for AC1 and AC5.
const PALETTE: Record<string, { light: string; dark: string }> = {
    '--background': {
        light: 'oklch(1 0 0)',
        dark: 'oklch(0.1763 0.014 258.41)',
    },
    '--foreground': {
        light: 'oklch(0.2542 0.0111 254)',
        dark: 'oklch(0.9425 0.011 243.65)',
    },
    '--card': {
        light: 'oklch(1 0 0)',
        dark: 'oklch(0.2202 0.0157 256.78)',
    },
    '--card-foreground': {
        light: 'oklch(0.2542 0.0111 254)',
        dark: 'oklch(0.9425 0.011 243.65)',
    },
    '--popover': {
        light: 'oklch(1 0 0)',
        dark: 'oklch(0.2202 0.0157 256.78)',
    },
    '--popover-foreground': {
        light: 'oklch(0.2542 0.0111 254)',
        dark: 'oklch(0.9425 0.011 243.65)',
    },
    '--primary': {
        light: 'oklch(0.5517 0.145 148.22)',
        dark: 'oklch(0.546 0.1474 146.26)',
    },
    '--primary-foreground': {
        light: 'oklch(1 0 0)',
        dark: 'oklch(1 0 0)',
    },
    '--secondary': {
        light: 'oklch(0.9782 0.0034 247.87)',
        dark: 'oklch(0.2666 0.015 256.8)',
    },
    '--secondary-foreground': {
        light: 'oklch(0.2542 0.0111 254)',
        dark: 'oklch(0.9425 0.011 243.65)',
    },
    '--muted': {
        light: 'oklch(0.9782 0.0034 247.87)',
        dark: 'oklch(0.2666 0.015 256.8)',
    },
    '--muted-foreground': {
        light: 'oklch(0.4951 0.0215 250.77)',
        dark: 'oklch(0.6691 0.0181 250.89)',
    },
    '--accent': {
        light: 'oklch(0.9782 0.0034 247.87)',
        dark: 'oklch(0.2666 0.015 256.8)',
    },
    '--accent-foreground': {
        light: 'oklch(0.2542 0.0111 254)',
        dark: 'oklch(0.9425 0.011 243.65)',
    },
    '--destructive': {
        light: 'oklch(0.4591 0.1777 21.87)',
        dark: 'oklch(0.6651 0.2046 26.96)',
    },
    '--destructive-foreground': {
        light: 'oklch(1 0 0)',
        dark: 'oklch(0.1763 0.014 258.41)',
    },
    '--success': {
        light: 'oklch(0.4391 0.1179 148.07)',
        dark: 'oklch(0.6951 0.1809 145.62)',
    },
    '--success-foreground': {
        light: 'oklch(1 0 0)',
        dark: 'oklch(0.1763 0.014 258.41)',
    },
    '--warning': {
        light: 'oklch(0.4665 0.1009 70.18)',
        dark: 'oklch(0.7196 0.1401 79.92)',
    },
    '--warning-foreground': {
        light: 'oklch(1 0 0)',
        dark: 'oklch(0.1763 0.014 258.41)',
    },
    '--info': {
        light: 'oklch(0.4509 0.1641 258.18)',
        dark: 'oklch(0.7152 0.1518 253.31)',
    },
    '--info-foreground': {
        light: 'oklch(1 0 0)',
        dark: 'oklch(0.1763 0.014 258.41)',
    },
    '--border': {
        light: 'oklch(0.8758 0.0123 248)',
        dark: 'oklch(0.33 0.0149 252.32)',
    },
    '--input': {
        light: 'oklch(0.8758 0.0123 248)',
        dark: 'oklch(0.33 0.0149 252.32)',
    },
    '--ring': {
        light: 'oklch(0.5399 0.1906 257.48)',
        dark: 'oklch(0.6182 0.1935 258.33)',
    },
    '--chart-1': {
        light: 'oklch(0.5399 0.1906 257.48)',
        dark: 'oklch(0.6182 0.1935 258.33)',
    },
    '--chart-2': {
        light: 'oklch(0.4391 0.1179 148.07)',
        dark: 'oklch(0.6951 0.1809 145.62)',
    },
    '--chart-3': {
        light: 'oklch(0.4665 0.1009 70.18)',
        dark: 'oklch(0.7196 0.1401 79.92)',
    },
    '--chart-4': {
        light: 'oklch(0.4591 0.1777 21.87)',
        dark: 'oklch(0.6651 0.2046 26.96)',
    },
    '--chart-5': {
        light: 'oklch(0.5631 0.2069 294.97)',
        dark: 'oklch(0.6606 0.1935 298.14)',
    },
    '--sidebar': {
        light: 'oklch(0.9782 0.0034 247.87)',
        dark: 'oklch(0.1039 0.0194 248.34)',
    },
    '--sidebar-foreground': {
        light: 'oklch(0.2542 0.0111 254)',
        dark: 'oklch(0.9425 0.011 243.65)',
    },
    '--sidebar-primary': {
        light: 'oklch(0.5399 0.1906 257.48)',
        dark: 'oklch(0.6182 0.1935 258.33)',
    },
    '--sidebar-primary-foreground': {
        light: 'oklch(1 0 0)',
        dark: 'oklch(0.1763 0.014 258.41)',
    },
    '--sidebar-accent': {
        light: 'oklch(0.9472 0.0069 247.89)',
        dark: 'oklch(0.2666 0.015 256.8)',
    },
    '--sidebar-accent-foreground': {
        light: 'oklch(0.2542 0.0111 254)',
        dark: 'oklch(0.9425 0.011 243.65)',
    },
    '--sidebar-border': {
        light: 'oklch(0.8758 0.0123 248)',
        dark: 'oklch(0.33 0.0149 252.32)',
    },
    '--sidebar-ring': {
        light: 'oklch(0.5399 0.1906 257.48)',
        dark: 'oklch(0.6182 0.1935 258.33)',
    },
    // Revision 11 rows (chunk 3b); revision 12 values.
    '--gradient-from': {
        light: 'oklch(0.5399 0.1906 257.48)',
        dark: 'oklch(0.6182 0.1935 258.33)',
    },
    '--gradient-to': {
        light: 'oklch(0.5631 0.2069 294.97)',
        dark: 'oklch(0.6606 0.1935 298.14)',
    },
    '--gradient-foreground': {
        light: 'oklch(1 0 0)',
        dark: 'oklch(0.1763 0.014 258.41)',
    },
    '--page-from': {
        light: 'oklch(0.9782 0.0034 247.87)',
        dark: 'oklch(0.1039 0.0194 248.34)',
    },
    '--page-to': {
        light: 'oklch(1 0 0)',
        dark: 'oklch(0.2202 0.0157 256.78)',
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
    it('the PALETTE copy holds the 43 spec tokens (38 plus the 5 revision 11 rows)', () => {
        expect(Object.keys(PALETTE)).toHaveLength(43);
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

    it("Inertia progress.color is 'var(--info)'", () => {
        const app = read(path.join(JS_DIR, 'app.tsx'));

        expect(app).toMatch(
            /progress:\s*\{[^}]*?color:\s*(['"])var\(--info\)\1/,
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

describe('AC5: converter self-checks', () => {
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

    // Revision 12: fixed literals (no Primer value clamps); they still prove the clamp step.
    it('oklch(0.505 0.213 27.518) has an unclamped G < 0 and the #c10007 luminance', () => {
        const c = toLinear('oklch(0.505 0.213 27.518)');
        const y = luminance(c.rgb);

        expect(c.raw[1]).toBeLessThan(0);
        expect(y).toBeGreaterThanOrEqual(0.112);
        expect(y).toBeLessThanOrEqual(0.115);
    });

    it('oklch(0.673 0.182 276.935) has an unclamped B > 1 and the #7c86ff luminance', () => {
        const c = toLinear('oklch(0.673 0.182 276.935)');
        const y = luminance(c.rgb);

        expect(c.raw[2]).toBeGreaterThan(1);
        expect(y).toBeGreaterThanOrEqual(0.283);
        expect(y).toBeLessThanOrEqual(0.289);
    });

    it('light --primary has the #1f883d luminance', () => {
        const y = luminance(toLinear(PALETTE['--primary'].light).rgb);

        expect(y).toBeGreaterThanOrEqual(0.18);
        expect(y).toBeLessThanOrEqual(0.185);
    });

    it('dark --background has the #0d1117 luminance', () => {
        const y = luminance(toLinear(PALETTE['--background'].dark).rgb);

        expect(y).toBeGreaterThanOrEqual(0.005);
        expect(y).toBeLessThanOrEqual(0.006);
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
    // Revision 11 (chunk 3b): gradient text, gradient numbers and the page gradient.
    { id: 'P28', fg: '--gradient-foreground', bg: '--gradient-from' },
    { id: 'P29', fg: '--gradient-foreground', bg: '--gradient-to' },
    {
        // The midpoint: --gradient-to at alpha 0.5 over --gradient-from, by the tint method.
        id: 'P30',
        fg: '--gradient-foreground',
        bg: '--gradient-from',
        tint: { tone: '--gradient-to', alpha: 0.5 },
    },
    ...(
        [
            ['P31', '--gradient-from'],
            ['P32', '--gradient-to'],
        ] as const
    ).flatMap(([id, fg]) => SURFACES.map((bg) => ({ id, fg, bg }))),
    ...(
        [
            ['P33', '--sidebar-foreground'],
            ['P34', '--muted-foreground'],
            ['P35', '--foreground'],
            ['P36', '--info'],
        ] as const
    ).flatMap(([id, fg]) =>
        ['--page-from', '--page-to'].map((bg) => ({ id, fg, bg })),
    ),
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

// Revision 12: P11 and P12 are non-text pairs (WCAG 1.4.11), floor 3; every other pair is text, 4.5.
const NON_TEXT_PAIRS = ['P11', 'P12'];
const floorOf = (pair: Pair) => (NON_TEXT_PAIRS.includes(pair.id) ? 3 : 4.5);

describe('AC5: WCAG contrast of every spec pair (4.5 text, 3 non-text P11/P12)', () => {
    it('has the 36 spec pairs plus the P9 accent twin, with P11 and P12 as the only non-text pairs', () => {
        expect(new Set(PAIRS.map((p) => p.id)).size).toBe(37);
        expect(PAIRS.filter((p) => floorOf(p) === 3).map((p) => p.id)).toEqual([
            'P11',
            'P12',
        ]);
    });

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
            expect(pairRatio(pair, mode)).toBeGreaterThanOrEqual(floorOf(pair));
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
    // Chunk 3b, revision 11b: the outside GitHub and impersonation screens.
    'components/impersonation-banner.tsx',
    'components/github-guides.tsx',
    'components/github-source.tsx',
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
        // Revision 12b: the new class names match no forbidden pattern.
        'bg-gradient-from',
        '[&_:focus-visible]:ring-ring!',
        'text-gradient-foreground',
        'bg-gradient-foreground/15',
        '[--color-ring:var(--gradient-foreground)]',
        'bg-info text-info-foreground accent-info border-info hover:border-info/40',
        'bg-foreground text-background fill-foreground',
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
            // Chunk 3b, revision 11b.
            'components/impersonation-banner.tsx',
            'components/github-guides.tsx',
            'components/github-source.tsx',
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

// ---------------------------------------------------------------------------
// Revision 11 (chunk 3b): helpers shared by AC43, AC45, AC46 and AC49
// ---------------------------------------------------------------------------

/** Token-wise split, as in AC30. */
const tokenize = (s: string) => s.split(/[\s"'`]+/).filter(Boolean);

const jsFile = (r: string) => path.join(JS_DIR, r);
const src = (r: string) => read(jsFile(r));

/**
 * Opening tags `<Name ...>`: the slice from `<Name` to its first `>` at brace
 * depth 0, so a Prettier wrap or an arrow function inside braces changes nothing.
 */
function openingTags(text: string, name: string) {
    const escaped = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const re = new RegExp(`<${escaped}(?![\\w.-])`, 'g');
    const tags: string[] = [];

    for (const m of text.matchAll(re)) {
        let depth = 0;
        let quote = '';
        let i = m.index;

        for (; i < text.length; i++) {
            const c = text[i];

            if (quote) {
                if (c === quote) quote = '';

                continue;
            }

            if (depth === 0 && (c === '"' || c === "'")) quote = c;
            else if (c === '{') depth++;
            else if (c === '}') depth--;
            else if (c === '>' && depth === 0) break;
        }

        tags.push(collapse(text.slice(m.index, i + 1)));
    }

    return tags;
}

/** local name -> imported name, for every `import { ... } from 'lucide-react'`. */
function lucideImports(text: string) {
    const map = new Map<string, string>();

    for (const m of text.matchAll(
        /import\s+(?:type\s+)?\{([^}]*)\}\s+from\s+['"]lucide-react['"]/g,
    )) {
        for (const spec of m[1].split(',')) {
            const s = spec.trim().replace(/^type\s+/, '');

            if (!s) continue;

            const [imported, local] = s.split(/\s+as\s+/);

            map.set((local ?? imported).trim(), imported.trim());
        }
    }

    return map;
}

// ---------------------------------------------------------------------------
// AC43: gradient tokens and utilities
// ---------------------------------------------------------------------------

describe('AC43: gradient tokens and utilities', () => {
    const css = () => read(CSS_FILE).replace(/\/\*[\s\S]*?\*\//g, '');
    const utilities = () =>
        parseBlocks(css()).filter((b) => /^@utility\b/.test(b.selector));

    const PINNED: Record<string, string> = {
        'brand-gradient': 'background-image: var(--brand-gradient);',
        'brand-gradient-text':
            'background-image: var(--brand-gradient); -webkit-background-clip: text; background-clip: text; color: transparent;',
        'brand-gradient-border':
            'border-color: transparent; background-image: linear-gradient(var(--card), var(--card)), var(--brand-gradient); background-origin: border-box; background-clip: padding-box, border-box;',
        'page-gradient':
            'background-image: linear-gradient(160deg, var(--page-from), var(--page-to)); background-attachment: fixed;',
    };

    it(':root declares --brand-gradient', () => {
        // Whitespace-collapsed; a Prettier wrap inside the parentheses is formatting only.
        const tight = (v = '') => collapse(v).replace(/\s*([(),])\s*/g, '$1');

        expect(tight(rootDecls()['--brand-gradient'])).toBe(
            tight(
                'linear-gradient(135deg, var(--gradient-from), var(--gradient-to))',
            ),
        );
    });

    it('.dark does not declare --brand-gradient', () => {
        expect(darkDecls()['--brand-gradient']).toBeUndefined();
    });

    it('holds exactly the four @utility blocks', () => {
        expect(
            utilities()
                .map((b) => b.selector)
                .sort(),
        ).toEqual(
            Object.keys(PINNED)
                .map((n) => `@utility ${n}`)
                .sort(),
        );
    });

    it.each(Object.keys(PINNED))('@utility %s has the pinned body', (name) => {
        const block = utilities().find(
            (b) => b.selector === `@utility ${name}`,
        );

        expect(block, `@utility ${name} missing`).toBeDefined();
        expect(collapse(block!.body)).toBe(PINNED[name]);
    });

    it.each(Object.keys(PINNED))(
        '@utility %s sits at top level, after the @theme static block',
        (name) => {
            const text = css();
            const at = text.search(new RegExp(`@utility\\s+${name}\\s*\\{`));
            const theme = text.indexOf('@theme static');

            expect(at).toBeGreaterThanOrEqual(0);
            expect(theme).toBeGreaterThanOrEqual(0);
            expect(at).toBeGreaterThan(theme);

            const before = text.slice(0, at);
            const open = (before.match(/\{/g) ?? []).length;
            const close = (before.match(/\}/g) ?? []).length;

            expect(open - close).toBe(0);
        },
    );

    it.each(Object.keys(PINNED))(
        '@utility %s has no color literal, animation or transition',
        (name) => {
            const body = utilities().find(
                (b) => b.selector === `@utility ${name}`,
            )?.body;

            expect(body, `@utility ${name} missing`).toBeDefined();

            for (const banned of [
                'oklch(',
                'rgb(',
                'rgba(',
                '#',
                'animation',
                'transition',
            ]) {
                expect(body!).not.toContain(banned);
            }
        },
    );
});

// ---------------------------------------------------------------------------
// AC45: gradient-free zones
// ---------------------------------------------------------------------------

// The "Files" column of the Contract rule table, at chunk 3b scope.
// Chunk 4 adds pages/welcome.tsx.
const GRADIENT_FILES = [
    'components/app-logo.tsx',
    'layouts/auth/auth-simple-layout.tsx',
    'components/nav-main.tsx',
    'components/heading.tsx',
    'pages/dashboard.tsx',
    'pages/projects/index.tsx',
    'components/empty-state.tsx',
    'pages/projects/show.tsx',
    'pages/projects/endpoints.tsx',
    'components/app-sidebar-header.tsx',
    'components/ui/sidebar.tsx',
];

const GRADIENT_FREE = [
    'components/ui/badge.tsx',
    'components/ui/alert.tsx',
    'components/method-badge.tsx',
    'components/ui/input.tsx',
    'components/ui/native-select.tsx',
    'components/ui/textarea.tsx',
    'components/ui/dialog.tsx',
    'components/ui/sheet.tsx',
    'components/ui/sonner.tsx',
    'components/ui/card.tsx',
    'components/contract-views.tsx',
    // Revision 11b.
    'components/impersonation-banner.tsx',
    'components/github-guides.tsx',
    // Revision 12: the primary button is solid green, so button.tsx leaves GRADIENT_FILES.
    'components/ui/button.tsx',
];

const GRADIENT_RE = /\b(?:brand-gradient(?:-text|-border)?|page-gradient)\b/;

describe('AC45: gradient-free zones', () => {
    it.each([
        'brand-gradient',
        'brand-gradient-text',
        'brand-gradient-border',
        'page-gradient',
        'has-data-[variant=inset]:page-gradient',
        'data-[active=true]:before:brand-gradient',
    ])('the scan pattern matches %s', (s) => {
        expect(GRADIENT_RE.test(s)).toBe(true);
    });

    it.each([
        'text-gradient-foreground',
        'bg-gradient-foreground/15',
        '[--color-ring:var(--gradient-foreground)]',
        'var(--gradient-from)',
        // Revision 12b classes.
        'bg-gradient-from',
        '[&_:focus-visible]:ring-ring!',
    ])('the scan pattern does not match %s', (s) => {
        expect(GRADIENT_RE.test(s)).toBe(false);
    });

    it('only the GRADIENT_FILES carry a gradient class', () => {
        const offenders = scopedFiles()
            .filter((f) => GRADIENT_RE.test(read(f)))
            .map(rel)
            .filter((r) => !GRADIENT_FILES.includes(r));

        expect(offenders).toEqual([]);
    });

    it('every GRADIENT_FILES entry is in scope and carries a gradient class', () => {
        const scoped = scopedFiles().map(rel);

        for (const f of GRADIENT_FILES) {
            expect(scoped, `${f} not in scan scope`).toContain(f);
            expect(GRADIENT_RE.test(src(f)), `${f} has no gradient`).toBe(true);
        }
    });

    it.each(GRADIENT_FREE)('%s is in scope and has no gradient class', (f) => {
        expect(scopedFiles().map(rel)).toContain(f);
        expect(GRADIENT_RE.test(src(f))).toBe(false);
    });
});

// ---------------------------------------------------------------------------
// AC46: icon map (rows marked 3b)
// ---------------------------------------------------------------------------

// [file, local name, imported lucide name, expected number of Heading tags (at least)]
type HeadingRow = [string, string, string, number];

const HERO_ICONS: HeadingRow[] = [
    ['pages/dashboard.tsx', 'LayoutGrid', 'LayoutGrid', 2],
    ['pages/projects/index.tsx', 'FolderKanban', 'FolderKanban', 1],
    ['pages/projects/show.tsx', 'FolderKanban', 'FolderKanban', 1],
    ['pages/projects/endpoints.tsx', 'RouteIcon', 'Route', 1],
    ['pages/projects/endpoint.tsx', 'RouteIcon', 'Route', 1],
    ['pages/projects/snapshot.tsx', 'FileArchive', 'FileArchive', 1],
    ['pages/projects/documentation.tsx', 'FileText', 'FileText', 1],
    ['pages/projects/wizard.tsx', 'FolderPlus', 'FolderPlus', 1],
    ['layouts/settings/layout.tsx', 'Settings', 'Settings', 1],
];

const SECTION_ICONS: HeadingRow[] = [
    ['pages/dashboard.tsx', 'History', 'History', 1],
    ['pages/projects/show.tsx', 'Wrench', 'Wrench', 1],
    ['pages/projects/show.tsx', 'PencilLine', 'PencilLine', 1],
    ['pages/projects/show.tsx', 'Archive', 'Archive', 1],
    ['components/delete-project.tsx', 'TriangleAlert', 'TriangleAlert', 1],
    ['components/source-panel.tsx', 'Upload', 'Upload', 1],
    ['components/framework-detection-card.tsx', 'Layers', 'Layers', 2],
    ['pages/projects/endpoints.tsx', 'ListChecks', 'ListChecks', 1],
    ['pages/projects/endpoint.tsx', 'Info', 'Info', 1],
    ['pages/projects/endpoint.tsx', 'Code', 'Code', 1],
    ['components/endpoint-analysis.tsx', 'Braces', 'Braces', 1],
    ['pages/projects/snapshot.tsx', 'FileCode', 'FileCode', 1],
];

// Icons rendered as elements: every `<Name ...>` must carry aria-hidden="true".
const ELEMENT_ICONS: [string, string, string][] = [
    // Dashboard tiles, project cards and the endpoints stat tiles.
    ['pages/dashboard.tsx', 'FolderKanban', 'FolderKanban'],
    ['pages/dashboard.tsx', 'Users', 'Users'],
    ['pages/dashboard.tsx', 'Settings', 'Settings'],
    ['pages/projects/index.tsx', 'FolderKanban', 'FolderKanban'],
    ['pages/projects/endpoints.tsx', 'RouteIcon', 'Route'],
    ['pages/projects/endpoints.tsx', 'CircleCheck', 'CircleCheck'],
    ['pages/projects/endpoints.tsx', 'CircleDashed', 'CircleDashed'],
    ['pages/projects/endpoints.tsx', 'CircleHelp', 'CircleHelp'],
    ['pages/projects/endpoints.tsx', 'FileCode', 'FileCode'],
];

describe('AC46: icon map, imports and Heading calls', () => {
    it.each(HERO_ICONS)(
        '%s: the hero Heading has icon={%s}, imported as %s',
        (file, local, imported, min) => {
            const text = src(file);
            const hits = openingTags(text, 'Heading').filter(
                (t) =>
                    t.includes(`icon={${local}}`) &&
                    !t.includes('variant="small"'),
            );

            expect(hits.length).toBeGreaterThanOrEqual(min);
            expect(lucideImports(text).get(local)).toBe(imported);
        },
    );

    it.each(SECTION_ICONS)(
        '%s: a small Heading has icon={%s}, imported as %s',
        (file, local, imported, min) => {
            const text = src(file);
            const hits = openingTags(text, 'Heading').filter(
                (t) =>
                    t.includes(`icon={${local}}`) &&
                    t.includes('variant="small"'),
            );

            expect(hits.length).toBeGreaterThanOrEqual(min);
            expect(lucideImports(text).get(local)).toBe(imported);
        },
    );

    it.each(ELEMENT_ICONS)(
        '%s: <%s> is imported as %s and every use is aria-hidden',
        (file, local, imported) => {
            const text = src(file);
            const tags = openingTags(text, local);

            expect(tags.length).toBeGreaterThan(0);
            expect(lucideImports(text).get(local)).toBe(imported);

            for (const tag of tags) {
                expect(tag).toContain('aria-hidden="true"');
            }
        },
    );

    it('the sidebar nav icons are unchanged and nav-main hides them', () => {
        const text = src('components/app-sidebar.tsx');
        const imports = lucideImports(text);

        for (const n of ['LayoutGrid', 'FolderKanban', 'Users', 'GitBranch']) {
            expect(imports.get(n)).toBe(n);
            expect(text).toContain(`icon: ${n}`);
        }

        expect(collapse(src('components/nav-main.tsx'))).toContain(
            '<item.icon aria-hidden="true"',
        );
    });
});

describe('AC46: settings nav', () => {
    const text = () => src('layouts/settings/layout.tsx');

    it.each(['UserRound', 'ShieldCheck', 'Palette', 'GitBranch'])(
        'has icon: %s, imported from lucide-react',
        (name) => {
            expect(text()).toContain(`icon: ${name}`);
            expect(lucideImports(text()).get(name)).toBe(name);
        },
    );

    it('has no icon: null', () => {
        expect(text()).not.toMatch(/icon:\s*null/);
    });

    it('puts the GitBranch icon on the GitHub App item', () => {
        expect(collapse(text())).toMatch(
            /href: editGitHubApp\(\), icon: GitBranch/,
        );
    });

    it('renders the item icon hidden at size-4', () => {
        expect(collapse(text())).toContain(
            '<item.icon aria-hidden="true" className="size-4"',
        );
    });
});

describe('AC46: empty states', () => {
    it('"No endpoints match" passes icon={RouteIcon}', () => {
        const text = src('pages/projects/endpoints.tsx');
        const tag = openingTags(text, 'EmptyState').find((t) =>
            t.includes('No endpoints match'),
        );

        expect(tag, 'no EmptyState titled "No endpoints match"').toBeDefined();
        expect(tag).toContain('icon={RouteIcon}');
    });

    it('every in-scope EmptyState call passes an icon', () => {
        const missing = scopedFiles().flatMap((f) =>
            openingTags(read(f), 'EmptyState')
                .filter((t) => !/\sicon=/.test(t))
                .map((t) => `${rel(f)}: ${t}`),
        );

        expect(missing).toEqual([]);
    });
});

// ---------------------------------------------------------------------------
// AC49 (chunk 3b part): outside screens follow the kit
// ---------------------------------------------------------------------------

describe('AC49: components/impersonation-banner.tsx', () => {
    const text = () => src('components/impersonation-banner.tsx');

    it('the root div is the warning strip', () => {
        const root = openingTags(text(), 'div')[0];

        expect(tokenize(root)).toEqual(
            expect.arrayContaining([
                'border-b',
                'border-warning/30',
                'bg-warning/10',
                'text-warning',
            ]),
        );
    });

    it('the p wraps long text and the owner name is a truncated span with a title (revision 12, R12-3)', () => {
        const p = openingTags(text(), 'p');

        expect(p.length).toBeGreaterThan(0);
        expect(p.some((t) => tokenize(t).includes('wrap-anywhere'))).toBe(true);

        const spans = openingTags(text(), 'span').filter((t) =>
            tokenize(t).includes('truncate'),
        );

        expect(spans).toHaveLength(1);
        expect(tokenize(spans[0])).toEqual(
            expect.arrayContaining(['max-w-48', 'truncate']),
        );
        expect(spans[0]).toMatch(/\btitle=\{/);
    });

    it('has no amber and no bg-white', () => {
        expect(text()).not.toContain('amber');
        expect(text()).not.toContain('bg-white');
    });

    it('the Button has no className', () => {
        const tags = openingTags(text(), 'Button');

        expect(tags.length).toBeGreaterThan(0);

        for (const t of tags) expect(t).not.toContain('className=');
    });

    it.each([
        'role="status"',
        'data-test="impersonation-banner"',
        'disabled={processing}',
        'onClick={stop}',
    ])('still contains %s', (needle) => {
        expect(collapse(text())).toContain(needle);
    });

    it.each(['UserRoundCog', 'Undo2'])('<%s> is aria-hidden', (name) => {
        const tags = openingTags(text(), name);

        expect(tags.length).toBeGreaterThan(0);

        for (const t of tags) expect(t).toContain('aria-hidden="true"');
    });
});

describe('AC49: components/github-guides.tsx', () => {
    const text = () => src('components/github-guides.tsx');

    it('the link constant has the focus ring', () => {
        const m = text().match(/\bconst link\s*=\s*(['"`])([\s\S]*?)\1/);

        expect(m, 'no `const link = ...`').not.toBeNull();
        expect(tokenize(m![2])).toEqual(
            expect.arrayContaining([
                'rounded-sm',
                'outline-none',
                'focus-visible:ring-[3px]',
                'focus-visible:ring-ring/50',
            ]),
        );
    });

    it('the safety note is an info Alert with a hidden ShieldCheck', () => {
        const c = collapse(text());

        expect(c).toContain('<Alert variant="info" role="note"');
        expect(c).toContain('<ShieldCheck aria-hidden="true"');
        expect(c).not.toContain('rounded-md bg-muted p-3');
    });

    it.each(['ChevronDown', 'Check', 'Copy'])(
        '<%s> appears and is aria-hidden everywhere',
        (name) => {
            const tags = openingTags(text(), name);

            expect(tags.length).toBeGreaterThan(0);

            for (const t of tags) expect(t).toContain('aria-hidden="true"');
        },
    );
});

describe('AC49: components/github-source.tsx', () => {
    const text = () => src('components/github-source.tsx');

    it('has exactly two underlined links, both with the ring', () => {
        const links = [
            ...openingTags(text(), 'a'),
            ...openingTags(text(), 'Link'),
        ].filter((t) => tokenize(t).includes('underline'));

        expect(links).toHaveLength(2);

        for (const t of links) {
            expect(tokenize(t)).toEqual(
                expect.arrayContaining([
                    'rounded-sm',
                    'focus-visible:ring-[3px]',
                ]),
            );
        }
    });

    it('every <GitBranch className=...> is aria-hidden', () => {
        const tags = openingTags(text(), 'GitBranch').filter((t) =>
            t.includes('className='),
        );

        expect(tags.length).toBeGreaterThan(0);

        for (const t of tags) expect(t).toContain('aria-hidden="true"');
    });
});

// ---------------------------------------------------------------------------
// AC50 (revision 12b, gate-r12): green stays only on the primary button
// ---------------------------------------------------------------------------

const PRIMARY_TOKEN =
    /(?:^|:)(?:bg|text|border|fill|stroke|accent|ring|outline|from|via|to|decoration|caret|divide|shadow)-primary(?:-foreground)?(?:\/\d+)?!?$/;

// The 17 files of the Contract table "Revision 12b class edits".
const REV_12B_FILES = [
    'app.tsx',
    'components/ui/checkbox.tsx',
    'components/ui/tooltip.tsx',
    'components/ui/badge.tsx',
    'components/ui/input.tsx',
    'components/ui/native-select.tsx',
    'components/ui/textarea.tsx',
    'components/progress.tsx',
    'components/framework-detection-card.tsx',
    'pages/projects/index.tsx',
    'pages/projects/wizard.tsx',
    'components/endpoint-analysis.tsx',
    'components/zip-upload.tsx',
    'pages/dashboard.tsx',
    'pages/projects/show.tsx',
    'components/heading.tsx',
    'components/app-logo.tsx',
];

const primaryTokens = (text: string) =>
    tokenize(text).filter((t) => PRIMARY_TOKEN.test(t));

describe('AC50: green stays only on the primary button', () => {
    it.each([
        'bg-primary',
        '[a&]:hover:bg-primary/90',
        'data-[state=checked]:border-primary',
        'selection:text-primary-foreground',
        'accent-primary',
    ])('the token regex matches %s', (t) => {
        expect(PRIMARY_TOKEN.test(t)).toBe(true);
    });

    it.each([
        'data-[active=true]:before:bg-sidebar-primary',
        'bg-info',
        'text-sidebar-primary-foreground',
    ])('the token regex does not match %s', (t) => {
        expect(PRIMARY_TOKEN.test(t)).toBe(false);
    });

    it('the scanned set holds all 17 revision 12b files', () => {
        expect(REV_12B_FILES).toHaveLength(17);

        const scoped = scopedFiles().map(rel);

        for (const f of REV_12B_FILES) {
            expect(scoped, `${f} not in scan scope`).toContain(f);
        }
    });

    it('no scanned file but ui/button.tsx has a *-primary token', () => {
        const found = scopedFiles()
            .filter((f) => rel(f) !== 'components/ui/button.tsx')
            .flatMap((f) =>
                primaryTokens(read(f)).map((t) => `${rel(f)}: ${t}`),
            );

        expect(found).toEqual([]);
    });

    it('ui/button.tsx has only bg-primary and text-primary-foreground, in the default variant', () => {
        const text = src('components/ui/button.tsx');

        expect([...new Set(primaryTokens(text))].sort()).toEqual([
            'bg-primary',
            'text-primary-foreground',
        ]);

        // The default variant sits between `default:` and `destructive:` in the cva table.
        const m = text.match(/\bdefault:([\s\S]*?)\bdestructive:/);

        expect(m, 'no default variant').not.toBeNull();
        expect(tokenize(m![1])).toEqual(
            expect.arrayContaining(['bg-primary', 'text-primary-foreground']),
        );
    });

    it('no scanned file contains var(--primary, and app.tsx uses var(--info)', () => {
        const found = scopedFiles()
            .filter((f) => read(f).includes('var(--primary'))
            .map(rel);

        expect(found).toEqual([]);
        expect(src('app.tsx')).not.toContain('var(--primary');
        expect(src('app.tsx')).toContain('var(--info)');
    });
});
