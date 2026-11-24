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
            className="flex flex-wrap items-center gap-3 border-b border-amber-300 bg-amber-50 px-4 py-2 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-50"
            role="status"
            data-test="impersonation-banner"
        >
            <UserRoundCog className="size-4 shrink-0" />
            <p className="min-w-0 flex-1">
                Logged in as <strong>{impersonation.member.name}</strong> (
                {impersonation.member.email}) in{' '}
                <strong>{impersonation.workspace.name}</strong>. Actions use
                this member&apos;s permissions.
            </p>
            <Button
                type="button"
                size="sm"
                variant="outline"
                className="border-amber-400 bg-white hover:bg-amber-100 dark:bg-amber-950 dark:hover:bg-amber-900"
                disabled={processing}
                onClick={stop}
            >
                <Undo2 />
                Return to {impersonation.owner.name}
            </Button>
        </div>
    );
}
