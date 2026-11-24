import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vite-plus/test';
import { ImpersonationBanner } from '@/components/impersonation-banner';

// AC49 (revision 12, R12-3): the banner wraps long text and truncates the owner name.

const page = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    url: '/dashboard',
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => page,
    router: {
        put: () => {},
        post: () => {},
        delete: () => {},
        reload: () => {},
        flushAll: () => {},
    },
}));

const tokenize = (s: string) => s.split(/[\s"'`]+/).filter(Boolean);
const classes = (tag: string) =>
    tokenize(tag.match(/\bclass="([^"]*)"/)?.[1] ?? '');

const render = (owner: string) => {
    page.props = {
        impersonation: {
            active: true,
            startedAt: '2026-11-24T00:00:00Z',
            owner: { id: 1, name: owner },
            member: { id: 2, name: 'Vic Viewer', email: 'vic@example.test' },
            workspace: { id: 'w1', name: 'Acme' },
        },
    };

    return renderToStaticMarkup(<ImpersonationBanner />);
};

describe('AC49: impersonation banner wraps and truncates (revision 12)', () => {
    const owner = 'Olivia Owner';
    const html = render(owner);

    it('the p tokens include wrap-anywhere', () => {
        const p = html.match(/<p\b[^>]*>/);

        expect(p, 'no <p> in banner').not.toBeNull();
        expect(classes(p![0])).toContain('wrap-anywhere');
    });

    it('the owner name sits in a max-w-48 truncate span with a title equal to the name', () => {
        const span = [...html.matchAll(/<span\b[^>]*>/g)]
            .map((m) => m[0])
            .find((t) => classes(t).includes('truncate'));

        expect(span, `no truncate span in: ${html}`).toBeDefined();
        expect(classes(span!)).toEqual(
            expect.arrayContaining(['max-w-48', 'truncate']),
        );
        expect(span).toContain(`title="${owner}"`);
        expect(html).toMatch(
            new RegExp(`<span\\b[^>]*title="${owner}"[^>]*>${owner}</span>`),
        );
    });

    it('a hostile long name stays escaped inside the span title and text', () => {
        const hostile = '<img src=x onerror=alert(1)>'.repeat(5);
        const out = render(hostile);

        expect(out).not.toContain('<img');
        expect(out).toContain('&lt;img');
    });
});
