import { afterEach, describe, expect, it, vi } from 'vite-plus/test';
import { copyText } from '@/hooks/use-clipboard';

function fakeDocument(execResult: boolean) {
    const area = {
        value: '',
        style: {} as Record<string, string>,
        setAttribute: vi.fn(),
        select: vi.fn(),
        remove: vi.fn(),
    };
    const doc = {
        createElement: vi.fn(() => area),
        body: { appendChild: vi.fn() },
        execCommand: vi.fn(() => execResult),
    };
    vi.stubGlobal('document', doc);

    return { doc, area };
}

afterEach(() => vi.unstubAllGlobals());

describe('copyText', () => {
    it('uses the async clipboard API on secure origins', async () => {
        const writeText = vi.fn(() => Promise.resolve());
        vi.stubGlobal('window', { isSecureContext: true });
        vi.stubGlobal('navigator', { clipboard: { writeText } });
        const { doc } = fakeDocument(true);

        expect(await copyText('curl x')).toBe(true);
        expect(writeText).toHaveBeenCalledWith('curl x');
        expect(doc.execCommand).not.toHaveBeenCalled();
    });

    it('falls back to execCommand on plain-http hosts, where navigator.clipboard is missing', async () => {
        vi.stubGlobal('window', { isSecureContext: false });
        vi.stubGlobal('navigator', {});
        const { doc, area } = fakeDocument(true);

        expect(await copyText('{"a":1}')).toBe(true);
        expect(area.value).toBe('{"a":1}');
        expect(doc.execCommand).toHaveBeenCalledWith('copy');
        expect(area.remove).toHaveBeenCalled();
    });

    it('falls back when the clipboard API rejects, and reports failure when nothing works', async () => {
        vi.stubGlobal('window', { isSecureContext: true });
        vi.stubGlobal('navigator', {
            clipboard: { writeText: () => Promise.reject(new Error('denied')) },
        });
        const { area } = fakeDocument(false);

        expect(await copyText('x')).toBe(false);
        expect(area.remove).toHaveBeenCalled();
    });
});
