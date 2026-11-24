import { router, usePage } from '@inertiajs/react';
import { Undo2, UserRoundCog } from 'lucide-react';
import { useState } from 'react';
import WorkspaceImpersonationController from '@/actions/App/Http/Controllers/Workspaces/WorkspaceImpersonationController';
import { Button } from '@/components/ui/button';

export function ImpersonationBanner() {
    const { impersonation } = usePage().props;
    const [processing, setProcessing] = useState(false);

    if (!impersonation) {
        return null;
    }

    const stop = () => {
        setProcessing(true);
        router.delete(WorkspaceImpersonationController.destroy.url(), {
            onFinish: () => setProcessing(false),
        });
    };

    return (
        <div
            className="flex flex-wrap items-center gap-3 border-b border-warning/30 bg-warning/10 px-4 py-2 text-sm text-warning"
            role="status"
            data-test="impersonation-banner"
        >
            <UserRoundCog aria-hidden="true" className="size-4 shrink-0" />
            <p className="min-w-0 flex-1 wrap-anywhere">
                Logged in as <strong>{impersonation.member.name}</strong> (
                {impersonation.member.email}) in{' '}
                <strong>{impersonation.workspace.name}</strong>. Actions use
                this member&apos;s permissions.
            </p>
            <Button
                type="button"
                size="sm"
                variant="outline"
                disabled={processing}
                onClick={stop}
            >
                <Undo2 aria-hidden="true" />
                Return to{' '}
                <span
                    className="max-w-48 truncate"
                    title={impersonation.owner.name}
                >
                    {impersonation.owner.name}
                </span>
            </Button>
        </div>
    );
}
