import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import type { ReactNode } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { afterEach, describe, expect, it, vi } from 'vite-plus/test';
import {
    ExplanationBody,
    WorkflowExplanationView,
} from '@/components/workflow-explanation';
import type {
    WorkflowAi,
    WorkflowExplanation,
    WorkflowExplanationDocument,
} from '@/types';

// No DOM environment: buttons and the toggle are recorded so a click can be replayed, and sonner is a spy.
const recorded = vi.hoisted(() => ({
    buttons: [] as { children: ReactNode; onClick?: () => unknown }[],
    toggle: null as null | { onValueChange?: (v: string) => void },
    toast: { success: vi.fn(), error: vi.fn() },
    // A static render never re-renders, so the view state is replayed by hand: what setView received, and what the next render reads.
    view: { set: vi.fn(), next: null as string | null },
}));

vi.mock('react', async (orig) => {
    const react = await orig<typeof import('react')>();

    return {
        ...react,
        useState: (init: unknown) =>
            init === 'preview'
                ? [recorded.view.next ?? init, recorded.view.set]
                : react.useState(init),
    };
});

vi.mock('@/components/ui/toggle-group', async (orig) => {
    const ui = await orig<typeof import('@/components/ui/toggle-group')>();

    return {
        ...ui,
        ToggleGroup: (props: Parameters<typeof ui.ToggleGroup>[0]) => {
            recorded.toggle = props as typeof recorded.toggle;

            return <ui.ToggleGroup {...props} />;
        },
    };
});

vi.mock('sonner', () => ({ toast: recorded.toast }));

vi.mock('@/components/ui/button', () => ({
    Button: (props: {
        children: ReactNode;
        onClick?: () => unknown;
        disabled?: boolean;
    }) => {
        recorded.buttons.push(props);

        return (
            <button type="button" disabled={props.disabled}>
                {props.children}
            </button>
        );
    },
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

const ready: WorkflowAi = {
    available: true,
    reason: null,
    provider: 'Anthropic',
    model: 'claude-opus-5-5',
    effort: 'medium',
    left: 790000,
    period: 'month',
    settingsUrl: null,
};

const off: WorkflowAi = {
    ...ready,
    available: false,
    reason: 'No AI provider is set up. A platform admin can add one in Settings → AI.',
    provider: null,
    model: null,
    effort: null,
    left: null,
    period: null,
};

const MARKDOWN = '## Overview\n\nPlaces the **order**.';

const done: WorkflowExplanationDocument = {
    hidden: false,
    status: 'completed',
    stage: null,
    failureMessage: null,
    retryable: false,
    markdown: MARKDOWN,
    html: '<h2>Overview</h2>\n<p>Places the <strong>order</strong>.</p>\n',
    truncated: false,
    omitted: [],
    provider: 'Anthropic',
    model: 'claude-opus-5-5',
    inputUnits: 1200,
    outputUnits: 300,
    promptVersion: 'workflow-explain-v1',
    finishedAt: '2026-11-24T14:02:00+00:00',
};

const render = (
    explanation: WorkflowExplanation | null,
    ai: WorkflowAi = ready,
    canExplain = true,
    onExplain: (again: boolean) => void = () => {},
) =>
    renderToStaticMarkup(
        <WorkflowExplanationView
            explanation={explanation}
            ai={ai}
            canExplain={canExplain}
            onExplain={onExplain}
        />,
    );

const label = (children: ReactNode): string =>
    (Array.isArray(children) ? children : [children])
        .filter((c) => typeof c === 'string')
        .join('');

const button = (name: string) =>
    recorded.buttons.findLast((b) => label(b.children) === name);

afterEach(() => {
    recorded.buttons.length = 0;
    recorded.toggle = null;
    recorded.view.next = null;
    vi.unstubAllGlobals();
    vi.clearAllMocks();
});

describe('WorkflowExplanationView', () => {
    it('disables the button and gives the gate reason when AI is not ready', () => {
        const html = render(null, off);

        expect(html).toMatch(
            /<button[^>]*disabled=""[^>]*>(<svg.*?<\/svg>)?Explain workflow with AI<\/button>/,
        );
        expect(text(html)).toContain(off.reason);
        expect(html).not.toContain('Open AI settings');
    });

    it('shows the AI settings link only when settingsUrl is set', () => {
        const html = render(null, {
            ...off,
            settingsUrl: 'http://api-engine.test/settings/ai',
        });

        expect(html).toMatch(
            /<a[^>]*href="http:\/\/api-engine.test\/settings\/ai"[^>]*>Open AI settings<\/a>/,
        );
        expect(render(null, ready)).not.toContain('Open AI settings');
    });

    it('names provider, model, effort and tokens left under the button, and starts a run', () => {
        const onExplain = vi.fn();
        const html = render(null, ready, true, onExplain);

        expect(text(html)).toContain(
            'Uses Anthropic · claude-opus-5-5 · Medium effort · 790,000 tokens left this month',
        );
        button('Explain workflow with AI')?.onClick?.();
        expect(onExplain).toHaveBeenCalledWith(false);

        expect(
            text(render(null, { ...ready, left: null, period: null })),
        ).toContain('Uses Anthropic · claude-opus-5-5 · Medium effort');
        expect(
            text(render(null, { ...ready, left: null, period: null })),
        ).not.toContain('tokens left');
    });

    it('shows the running stage instead of the button', () => {
        const tracing = render({
            ...done,
            status: 'tracing',
            stage: 'tracing',
            markdown: null,
            html: null,
        });
        expect(tracing).toContain('Tracing the code…');
        expect(tracing).not.toContain('Explain workflow with AI');

        expect(
            render({
                ...done,
                status: 'running',
                stage: 'writing',
                markdown: null,
                html: null,
            }),
        ).toContain('Writing the explanation…');
    });

    it('renders a completed explanation as HTML by default, with a labelled Preview | Markdown toggle and Explain again', () => {
        const onExplain = vi.fn();
        const html = render(done, ready, true, onExplain);

        expect(html).toContain(done.html);
        expect(html).not.toContain('<pre');
        expect(html).toMatch(
            /role="radiogroup"[^>]*aria-label="Explanation view"/,
        );
        expect(html).toMatch(/data-state="on"[^>]*>Preview<\/button>/);
        expect(html).toMatch(/data-state="off"[^>]*>Markdown<\/button>/);
        expect(text(html)).toContain(
            'Anthropic · claude-opus-5-5 · prompt workflow-explain-v1 · 1,200 in / 300 out ·',
        );

        button('Explain again')?.onClick?.();
        expect(onExplain).toHaveBeenCalledWith(true);
    });

    it('shows the raw Markdown in a pre in the Markdown view', () => {
        const html = renderToStaticMarkup(
            <ExplanationBody explanation={done} view="markdown" />,
        );

        expect(html).toMatch(
            /<pre[^>]*>## Overview\n\nPlaces the \*\*order\*\*.<\/pre>/,
        );
        expect(html).not.toContain('<h2>');
        expect(
            renderToStaticMarkup(
                <ExplanationBody explanation={done} view="preview" />,
            ),
        ).toContain('<h2>Overview</h2>');
    });

    it('copies the Markdown and toasts', async () => {
        const writeText = vi.fn(() => Promise.resolve());
        vi.stubGlobal('window', { isSecureContext: true });
        vi.stubGlobal('navigator', { clipboard: { writeText } });

        render(done);
        await button('Copy')?.onClick?.();

        expect(writeText).toHaveBeenCalledWith(MARKDOWN);
        expect(recorded.toast.success).toHaveBeenCalledWith(
            'Markdown copied to the clipboard',
        );
    });

    it('notes a truncated explanation and lists what was not sent', () => {
        const html = render({
            ...done,
            truncated: true,
            omitted: ['OrderService::audit', 'OrderService::log'],
        });

        expect(text(html)).toContain(
            'This explanation was cut short by the output limit. Run Explain again or choose a model with a larger output.',
        );
        expect(html).toMatch(
            /<details[^>]*>\s*<summary[^>]*>Not sent to AI \(2\)<\/summary>/,
        );
        expect(html).toContain('OrderService::audit');
        expect(render(done)).not.toContain('cut short');
        expect(render(done)).not.toContain('Not sent to AI');
    });

    it('shows the failure and Try again only when retryable', () => {
        const onExplain = vi.fn();
        const failed = {
            ...done,
            status: 'failed' as const,
            markdown: null,
            html: null,
            failureMessage:
                'The AI provider is rate-limiting requests. Try again in a minute.',
        };

        const html = render(
            { ...failed, retryable: true },
            ready,
            true,
            onExplain,
        );
        expect(text(html)).toContain(failed.failureMessage);
        button('Try again')?.onClick?.();
        expect(onExplain).toHaveBeenCalledWith(false);

        recorded.buttons.length = 0;
        render({ ...failed, retryable: false });
        expect(button('Try again')).toBeUndefined();
        expect(button('Explain workflow with AI')).toBeDefined();

        // A role that may not start a run (or an archived project) gets no retry either.
        recorded.buttons.length = 0;
        const readOnly = render({ ...failed, retryable: true }, ready, false);
        expect(text(readOnly)).toContain(failed.failureMessage);
        expect(button('Try again')).toBeUndefined();
        expect(button('Explain workflow with AI')).toBeUndefined();
    });

    it('switches the completed view to the raw Markdown through the toggle', () => {
        const before = render(done);
        expect(before).toContain('<h2>Overview</h2>');

        recorded.toggle?.onValueChange?.('markdown');
        expect(recorded.view.set).toHaveBeenCalledWith('markdown');

        recorded.view.next = 'markdown';
        const after = render(done);
        expect(after).toMatch(
            /<pre[^>]*>## Overview\n\nPlaces the \*\*order\*\*.<\/pre>/,
        );
        expect(after).not.toContain('<h2>');
        expect(after).toMatch(/data-state="on"[^>]*>Markdown<\/button>/);

        // Clicking the pressed item off keeps a view shown.
        recorded.view.set.mockClear();
        recorded.toggle?.onValueChange?.('');
        expect(recorded.view.set).not.toHaveBeenCalled();
    });

    it('tells roles that cannot read source why the explanation is hidden, after showing the stage', () => {
        const hidden = render({ hidden: true, stage: null }, ready, false);
        expect(text(hidden)).toContain(
            'The AI workflow explanation is available to members who can read source code.',
        );
        expect(hidden).not.toContain('Explain workflow with AI');

        const running = render(
            { hidden: true, stage: 'writing' },
            ready,
            false,
        );
        expect(running).toContain('Writing the explanation…');
    });

    it('never says unknown, not stated or can’t tell, in any state', () => {
        const states: [WorkflowExplanation | null, WorkflowAi, boolean][] = [
            [null, off, true],
            [null, { ...off, settingsUrl: '/settings/ai' }, true],
            [null, ready, true],
            [null, ready, false],
            [{ ...done, status: 'tracing', stage: 'tracing' }, ready, true],
            [{ ...done, status: 'queued', stage: 'writing' }, ready, true],
            [{ ...done, truncated: true, omitted: ['A::b'] }, ready, true],
            [
                {
                    ...done,
                    inputUnits: null,
                    outputUnits: null,
                    finishedAt: null,
                    provider: null,
                    model: null,
                },
                off,
                true,
            ],
            [
                {
                    ...done,
                    status: 'failed',
                    markdown: null,
                    html: null,
                    failureMessage:
                        'The AI key was changed while this was waiting. Run it again.',
                    retryable: true,
                },
                ready,
                true,
            ],
            [{ hidden: true, stage: 'tracing' }, ready, false],
            [{ hidden: true, stage: null }, off, false],
        ];

        for (const [explanation, ai, canExplain] of states) {
            const shown = text(render(explanation, ai, canExplain));

            for (const pattern of [
                /unknown/i,
                /not stated/i,
                /can.?t tell/i,
                /\?/,
            ]) {
                expect(shown).not.toMatch(pattern);
            }
        }

        const source = fs.readFileSync(
            path.join(
                fileURLToPath(new URL('.', import.meta.url)),
                'workflow-explanation.tsx',
            ),
            'utf8',
        );
        expect(source).not.toMatch(
            /['"`][^'"`]*\b(unknown|not stated|can't tell)\b[^'"`]*['"`]/i,
        );
    });
});
