import { Head, Link, usePage } from '@inertiajs/react';
import { Building2, FolderKanban, Settings, Users } from 'lucide-react';
import ProjectController from '@/actions/App/Http/Controllers/Projects/ProjectController';
import WorkspaceController from '@/actions/App/Http/Controllers/Workspaces/WorkspaceController';
import WorkspaceMemberController from '@/actions/App/Http/Controllers/Workspaces/WorkspaceMemberController';
import EmptyState from '@/components/empty-state';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { edit } from '@/routes/profile';

const tile =
    'flex flex-col gap-1 rounded-lg border bg-card p-4 transition-[border-color,box-shadow] outline-none hover:border-primary/40 hover:shadow-sm focus-visible:ring-[3px] focus-visible:ring-ring/50';

export default function Dashboard() {
    const { workspaces, currentWorkspace, features } = usePage().props;

    // Feature on, but the user belongs to no workspace yet.
    if (workspaces !== null && currentWorkspace === null) {
        return (
            <>
                <Head title="Dashboard" />
                <div className="flex w-full flex-col gap-4 p-4 md:p-6">
                    <EmptyState
                        icon={Building2}
                        title="No workspace yet"
                        description="Create a workspace to get started, or ask an owner to invite you to theirs."
                        action={
                            <Button asChild>
                                <Link href={WorkspaceController.index()}>
                                    Create a workspace
                                </Link>
                            </Button>
                        }
                    />
                </div>
            </>
        );
    }

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex w-full flex-col gap-4 p-4 md:p-6">
                {currentWorkspace ? (
                    <Heading
                        title={currentWorkspace.name}
                        description={`Your role: ${currentWorkspace.roleLabel}`}
                    />
                ) : (
                    <Heading title="Dashboard" />
                )}
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {currentWorkspace && features.project_wizard && (
                        <Link href={ProjectController.index()} className={tile}>
                            <FolderKanban
                                aria-hidden="true"
                                className="size-5 text-primary"
                            />
                            <span className="text-sm font-medium">
                                Projects
                            </span>
                            <span className="text-xs text-muted-foreground">
                                APIs you document in this workspace.
                            </span>
                        </Link>
                    )}
                    {currentWorkspace && (
                        <Link
                            href={WorkspaceMemberController.index(
                                currentWorkspace.id,
                            )}
                            className={tile}
                        >
                            <Users
                                aria-hidden="true"
                                className="size-5 text-primary"
                            />
                            <span className="text-sm font-medium">Members</span>
                            <span className="text-xs text-muted-foreground">
                                Who has access, and invitations.
                            </span>
                        </Link>
                    )}
                    <Link href={edit()} className={tile}>
                        <Settings
                            aria-hidden="true"
                            className="size-5 text-primary"
                        />
                        <span className="text-sm font-medium">Settings</span>
                        <span className="text-xs text-muted-foreground">
                            Your profile, security and appearance.
                        </span>
                    </Link>
                </div>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
