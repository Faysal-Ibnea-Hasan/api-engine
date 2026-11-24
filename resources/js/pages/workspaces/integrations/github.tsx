import { Head, router } from '@inertiajs/react';
import { GitBranch } from 'lucide-react';
import { useState } from 'react';
import GitHubConnectionController from '@/actions/App/Http/Controllers/GitHub/GitHubConnectionController';
import EmptyState from '@/components/empty-state';
import ErrorPanel from '@/components/error-panel';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import type { GitHubInstallationSummary } from '@/types';

type Props = {
    workspace: { id: string; name: string };
    configured: boolean;
    can: { manage: boolean };
    installations: GitHubInstallationSummary[];
};

export default function GitHubIntegration({
    workspace,
    configured,
    can,
    installations,
}: Props) {
    const [disconnecting, setDisconnecting] =
        useState<GitHubInstallationSummary | null>(null);
    const connect = () =>
        router.post(GitHubConnectionController.store.url(workspace.id));

    return (
        <>
            <Head title="GitHub" />
            <div className="flex w-full max-w-3xl flex-col gap-4 p-4 md:p-6">
                <div className="flex flex-wrap items-start gap-4">
                    <Heading
                        title="GitHub"
                        description={`GitHub accounts and organizations connected to ${workspace.name}. API Engine only reads repositories; it never writes to them.`}
                    />
                    {can.manage && configured && (
                        <Button className="ml-auto" onClick={connect}>
                            Connect GitHub
                        </Button>
                    )}
                </div>

                {!configured && (
                    <ErrorPanel
                        title="GitHub isn't set up yet"
                        message="A platform admin must add the GitHub App in Settings → GitHub App. ZIP upload keeps working."
                    />
                )}

                {installations.length === 0 ? (
                    <EmptyState
                        icon={GitBranch}
                        title="No GitHub account connected"
                        description={
                            can.manage
                                ? 'Connect GitHub, then choose which repositories API Engine may read.'
                                : 'Ask a workspace Owner or Maintainer to connect GitHub.'
                        }
                    />
                ) : (
                    <ul className="divide-y rounded-lg border bg-card">
                        {installations.map((installation) => (
                            <li
                                key={installation.id}
                                className="flex flex-wrap items-center gap-3 px-4 py-3 text-sm"
                            >
                                <span className="font-medium">
                                    {installation.accountLogin}
                                </span>
                                <Badge variant="secondary">
                                    {installation.accountType}
                                </Badge>
                                <span className="text-muted-foreground">
                                    {installation.repositorySelection === 'all'
                                        ? 'All repositories'
                                        : 'Selected repositories'}
                                </span>
                                {installation.suspended && (
                                    <Badge variant="destructive">
                                        Suspended on GitHub
                                    </Badge>
                                )}
                                <div className="ml-auto flex gap-2">
                                    <Button variant="outline" size="sm" asChild>
                                        <a href={installation.manageUrl}>
                                            Manage on GitHub
                                        </a>
                                    </Button>
                                    {can.manage && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                setDisconnecting(installation)
                                            }
                                        >
                                            Disconnect
                                        </Button>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <Dialog
                open={disconnecting !== null}
                onOpenChange={(open) => !open && setDisconnecting(null)}
            >
                <DialogContent>
                    <DialogTitle>
                        Disconnect {disconnecting?.accountLogin}?
                    </DialogTitle>
                    <DialogDescription>
                        Projects in this workspace can no longer import from
                        this account. Existing snapshots and documentation are
                        kept. To remove the app from GitHub as well, uninstall
                        it there.
                    </DialogDescription>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button variant="secondary">Keep connected</Button>
                        </DialogClose>
                        <Button
                            variant="destructive"
                            onClick={() => {
                                if (disconnecting) {
                                    router.delete(
                                        GitHubConnectionController.destroy.url({
                                            workspace: workspace.id,
                                            installation: disconnecting.id,
                                        }),
                                    );
                                }
                                setDisconnecting(null);
                            }}
                        >
                            Disconnect
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
