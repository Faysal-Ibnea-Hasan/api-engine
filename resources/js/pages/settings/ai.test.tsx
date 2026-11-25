import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vite-plus/test';
import AiSettings from '@/pages/settings/ai';
import type { AiProviderProps } from '@/types/ai';

// No DOM environment: Inertia is stubbed and the page is rendered to markup.
vi.mock('@inertiajs/react', async (orig) => ({
    ...(await orig<typeof import('@inertiajs/react')>()),
    Form: ({
        children,
    }: {
        children?:
            | React.ReactNode
            | ((state: {
                  processing: boolean;
                  errors: Record<string, string>;
              }) => React.ReactNode);
    }) => (
        <form>
            {typeof children === 'function'
                ? children({ processing: false, errors: {} })
                : children}
        </form>
    ),
    Head: () => null,
    usePage: () => ({ props: {} }),
}));

const unescape = (s: string) =>
    s
        .replace(/&gt;/g, '>')
        .replace(/&lt;/g, '<')
        .replace(/&quot;/g, '"')
        .replace(/&#x27;/g, "'")
        .replace(/&amp;/g, '&');

const text = (html: string) =>
    unescape(html.replace(/<[^>]*>/g, ' ')).replace(/\s+/g, ' ');

const provider = (over: Partial<AiProviderProps> = {}): AiProviderProps => ({
    provider: 'openai',
    label: 'OpenAI',
    configured: false,
    keySuffix: null,
    status: 'not_configured',
    statusText: 'it is not set up yet',
    checkedAt: null,
    model: null,
    models: [],
    effort: 'medium',
    enabled: false,
    isDefault: false,
    limitTokens: null,
    limitPeriod: 'month',
    meter: { used: 0, limit: null, left: null, resetsAt: null },
    ...over,
});

const anthropic = provider({
    provider: 'anthropic',
    label: 'Anthropic',
    configured: true,
    keySuffix: 'a91f',
    status: 'ready',
    statusText: 'it is ready',
    checkedAt: '2026-11-24T14:02:00+00:00',
    model: 'claude-haiku-5-5',
    models: [
        { id: 'claude-opus-5-5', effort: true, free: false },
        { id: 'claude-haiku-5-5', effort: false, free: true },
    ],
    enabled: true,
    isDefault: true,
    limitTokens: 2000000,
    meter: {
        used: 1210000,
        limit: 2000000,
        left: 790000,
        resetsAt: '2026-12-01T00:00:00+00:00',
    },
});

// OpenAI has a key that was never tested; Gemini has no key.
const providers = [
    anthropic,
    provider({
        configured: true,
        keySuffix: 'b2c3',
        status: 'unchecked',
        statusText: 'the connection has not been tested yet',
    }),
    provider({ provider: 'gemini', label: 'Google Gemini' }),
];

const render = (list: AiProviderProps[] = providers) =>
    renderToStaticMarkup(<AiSettings providers={list} />);

/** The <select> for one provider's model, with its attributes. */
const modelSelect = (html: string, id: string) =>
    html.match(new RegExp(`<select[^>]*id="${id}-model"[^>]*>`))?.[0] ?? '';

describe('Settings → AI', () => {
    it('renders one card per provider', () => {
        const html = render();

        expect(html.match(/data-provider="/g)).toHaveLength(3);
        for (const label of ['Anthropic', 'OpenAI', 'Google Gemini']) {
            expect(text(html)).toContain(label);
        }
    });

    it('shows the banner text verbatim', () => {
        expect(text(render())).toContain(
            "When AI explains a workflow, the project's own method code (with secrets removed) is sent to the selected provider.",
        );
    });

    it('shows "Not configured" for a provider with no key', () => {
        const html = render([provider()]);

        expect(html.match(/data-provider="/g)).toHaveLength(1);
        expect(text(html)).toContain('Not configured');
        expect(text(html)).toContain('Add key');
    });

    it('shows the meter with numbers, not only a bar', () => {
        const t = text(render());

        expect(t).toContain('1,210,000 used · 790,000 left · resets');
        expect(t).toContain('No limit set · 0 used this month');
    });

    it('tags free-tier models in the model dropdown', () => {
        const t = text(render());

        expect(t).toContain('claude-haiku-5-5 · Free tier');
        expect(t).not.toContain('claude-opus-5-5 · Free tier');
    });

    it('disables the model select with the load hint when no models are loaded', () => {
        const html = render();

        expect(modelSelect(html, 'openai')).toMatch(/\sdisabled=""/);
        expect(text(html)).toContain('Test the connection to load models');
        expect(modelSelect(html, 'anthropic')).not.toMatch(/\sdisabled=""/);
    });

    it('says when the selected model does not support effort', () => {
        expect(text(render())).toContain('Effort not supported by this model');
        expect(
            text(render([{ ...anthropic, model: 'claude-opus-5-5' }])),
        ).not.toContain('Effort not supported by this model');
    });

    it('never renders the key, only its last four characters', () => {
        const html = render();

        expect(html).toContain('a91f');
        expect(html).toMatch(/type="password"/);
    });

    it('uses no vague words', () => {
        expect(text(render())).not.toMatch(/unknown|not stated|can't tell|\?/i);
    });
});
