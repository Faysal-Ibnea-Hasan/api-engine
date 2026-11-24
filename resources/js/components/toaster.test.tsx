import { renderToStaticMarkup } from 'react-dom/server';
import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import { Toaster } from '@/components/ui/sonner';

// AC31: Toaster props. Sonner is replaced by a recorder, so this checks what
// the kit passes to it, not sonner's own rendering.

const recorded = vi.hoisted(() => ({
    calls: [] as Record<string, unknown>[],
}));

vi.mock('sonner', () => ({
    Toaster: (props: Record<string, unknown>) => {
        recorded.calls.push(props);

        return null;
    },
    toast: Object.assign(() => {}, {
        success: () => {},
        error: () => {},
        info: () => {},
        warning: () => {},
    }),
}));

vi.mock('@/hooks/use-appearance', () => ({
    useAppearance: () => ({ appearance: 'dark' }),
}));

vi.mock('@/hooks/use-flash-toast', () => ({
    useFlashToast: () => {},
}));

const STYLE = {
    '--normal-bg': 'var(--popover)',
    '--normal-text': 'var(--popover-foreground)',
    '--normal-border': 'var(--border)',
    '--border-radius': 'var(--radius)',
    '--success-bg': 'color-mix(in srgb, var(--success) 10%, var(--popover))',
    '--success-text': 'var(--success)',
    '--success-border':
        'color-mix(in srgb, var(--success) 30%, var(--popover))',
    '--info-bg': 'color-mix(in srgb, var(--info) 10%, var(--popover))',
    '--info-text': 'var(--info)',
    '--info-border': 'color-mix(in srgb, var(--info) 30%, var(--popover))',
    '--warning-bg': 'color-mix(in srgb, var(--warning) 10%, var(--popover))',
    '--warning-text': 'var(--warning)',
    '--warning-border':
        'color-mix(in srgb, var(--warning) 30%, var(--popover))',
    '--error-bg': 'color-mix(in srgb, var(--destructive) 10%, var(--popover))',
    '--error-text': 'var(--destructive)',
    '--error-border':
        'color-mix(in srgb, var(--destructive) 30%, var(--popover))',
};

const last = () => recorded.calls[recorded.calls.length - 1];

beforeEach(() => {
    recorded.calls.length = 0;
});

describe('AC31: Toaster', () => {
    it('passes theme, position, duration, richColors and closeButton', () => {
        renderToStaticMarkup(<Toaster />);

        expect(recorded.calls).toHaveLength(1);
        expect(last().theme).toBe('dark');
        expect(last().position).toBe('bottom-right');
        expect(last().duration).toBe(5000);
        expect(last().richColors).toBe(true);
        expect(last().closeButton).toBe(true);
    });

    it('passes the toast style map exactly', () => {
        renderToStaticMarkup(<Toaster />);

        expect(last().style).toEqual(STYLE);
    });

    it('lets a caller override the position', () => {
        renderToStaticMarkup(<Toaster position="top-center" />);

        expect(last().position).toBe('top-center');
        expect(last().duration).toBe(5000);
    });
});
