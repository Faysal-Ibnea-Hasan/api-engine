import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vite-plus/test';
import SourcePanel from '@/components/source-panel';
import type { SourcePanelData } from '@/types/source';

// AC34 R4-4 (Vitest part), AC38 and AC39 (source checks).
// The dialogs are portaled and closed, so renders only show the trigger.

vi.mock('@inertiajs/react', async (orig) => ({
    ...(await orig<typeof import('@inertiajs/react')>()),
    Form: ({
        children,
        ...props
    }: {
        children?:
            | React.ReactNode
            | ((state: {
                  processing: boolean;
                  errors: object;
              }) => React.ReactNode);
        action?: string;
    }) => (
        <form action={props.action}>
            {typeof children === 'function'
                ? children({ processing: false, errors: {} })
                : children}
        </form>
    ),
    usePage: () => ({ props: {} }),
    usePoll: () => ({ start() {}, stop() {} }),
}));

const JS_DIR = path.join(fileURLToPath(new URL('.', import.meta.url)), '..');
const read = (file: string) => fs.readFileSync(path.join(JS_DIR, file), 'utf8');

const unescape = (s: string) =>
    s
        .replace(/&gt;/g, '>')
        .replace(/&lt;/g, '<')
        .replace(/&quot;/g, '"')
        .replace(/&#x27;/g, "'")
        .replace(/&amp;/g, '&');

/** Every <button>, with its attribute string and its visible text. */
const buttons = (html: string) =>
    [...html.matchAll(/<button\b([^>]*)>([\s\S]*?)<\/button>/g)].map((m) => ({
        attrs: m[1],
        text: unescape(m[2].replace(/<[^>]*>/g, '')).trim(),
    }));

// ---------------------------------------------------------------------------
// AC34 R4-4: cancel import
// ---------------------------------------------------------------------------

const sources = (cancelRequested: boolean): SourcePanelData => ({
    latestRun: {
        id: 'run-1',
        status: 'running',
        active: true,
        attempts: 1,
        deduplicated: false,
        failureCode: null,
        failureMessage: null,
        snapshotId: null,
        cancelRequested,
        createdAt: null,
        finishedAt: null,
    },
    snapshots: [],
    canImportFixture: false,
    canUpload: false,
    maxUploadBytes: 1024,
    maxSourceBytes: 1024,
    rootCandidates: [],
});

const renderPanel = (data: SourcePanelData, canManage = true) =>
    renderToStaticMarkup(
        <SourcePanel
            projectId="p1"
            sourcePreference={null}
            canManage={canManage}
            sources={data}
        />,
    );

describe('AC34 R4-4: cancel import confirmation', () => {
    it('the "Cancel" button opens a dialog', () => {
        const cancel = buttons(renderPanel(sources(false))).filter(
            (b) => b.text === 'Cancel',
        );

        expect(cancel).toHaveLength(1);
        expect(cancel[0].attrs).toContain('aria-haspopup="dialog"');
    });

    it('keeps the dialog body out of the closed markup', () => {
        const html = renderPanel(sources(false));

        expect(html).not.toContain('Stop import');
        expect(html).not.toContain('Keep importing');
    });

    it('shows no Cancel button once a cancel was requested', () => {
        const html = renderPanel(sources(true));

        expect(buttons(html).some((b) => b.text === 'Cancel')).toBe(false);
    });

    it('shows no Cancel button without the manage right', () => {
        const html = renderPanel(sources(false), false);

        expect(buttons(html).some((b) => b.text === 'Cancel')).toBe(false);
    });
});

// ---------------------------------------------------------------------------
// AC38: loading toasts can be dismissed
// ---------------------------------------------------------------------------

/** Text from each `needle` up to the next `);`, as the spec reads a call. */
const calls = (src: string, needle: string) =>
    [...src.matchAll(new RegExp(needle.replace(/[.(]/g, '\\$&'), 'g'))].map(
        (m) => {
            const end = src.indexOf(');', m.index);

            return src.slice(m.index, end < 0 ? undefined : end + 2);
        },
    );

const DISMISS =
    /cancel:\s*\{\s*label:\s*'Dismiss',\s*onClick:\s*\(\)\s*=>\s*\{\s*\},?\s*\}/;

describe('AC38: loading toasts get a Dismiss button', () => {
    it.each(['components/source-panel.tsx', 'components/zip-upload.tsx'])(
        '%s has exactly one toast.loading( call, with the Dismiss cancel option',
        (file) => {
            const loading = calls(read(file), 'toast.loading(');

            expect(loading).toHaveLength(1);
            expect(loading[0]).toMatch(DISMISS);
        },
    );

    it.each(['toast.success(', 'toast.info(', 'toast.error('])(
        'source-panel.tsx clears the cancel option on every %s call',
        (needle) => {
            const resolved = calls(read('components/source-panel.tsx'), needle);

            expect(resolved.length).toBeGreaterThan(0);

            for (const call of resolved) {
                expect(call).toMatch(/cancel:\s*undefined/);
            }
        },
    );
});

// ---------------------------------------------------------------------------
// AC39: the archive action sends one request
// ---------------------------------------------------------------------------

describe('AC39: archive is guarded against a second click', () => {
    const show = () => read('pages/projects/show.tsx');

    it('sets archiving on start and clears it on finish', () => {
        expect(show()).toMatch(/onStart:\s*\(\)\s*=>\s*setArchiving\(true\)/);
        expect(show()).toMatch(/onFinish:\s*\(\)\s*=>\s*setArchiving\(false\)/);
    });

    it('disables the Archive button while archiving', () => {
        expect(show()).toMatch(/disabled=\{archiving\}/);
    });
});
