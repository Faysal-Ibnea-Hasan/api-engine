import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vite-plus/test';
import EmptyState from '@/components/empty-state';
import ErrorPanel from '@/components/error-panel';
import Progress from '@/components/progress';

describe('Progress', () => {
    it('clamps the value and exposes it to assistive tech', () => {
        const html = renderToStaticMarkup(
            <Progress label="Import" value={140} />,
        );

        expect(html).toContain('role="progressbar"');
        expect(html).toContain('aria-label="Import"');
        expect(html).toContain('aria-valuenow="100"');
        expect(html).toContain('width:100%');
    });

    it('omits aria-valuenow while indeterminate', () => {
        const html = renderToStaticMarkup(<Progress label="Scan" />);

        expect(html).not.toContain('aria-valuenow');
    });
});

describe('ErrorPanel', () => {
    it('shows the message and the correlation reference', () => {
        const html = renderToStaticMarkup(
            <ErrorPanel message="Import failed." correlationId="abc-123" />,
        );

        expect(html).toContain('role="alert"');
        expect(html).toContain('Import failed.');
        expect(html).toContain('abc-123');
    });
});

describe('EmptyState', () => {
    it('renders the title and optional description', () => {
        const html = renderToStaticMarkup(
            <EmptyState title="No projects yet" description="Create one." />,
        );

        expect(html).toContain('No projects yet');
        expect(html).toContain('Create one.');
    });
});
