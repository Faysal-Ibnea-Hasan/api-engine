import { Head, Link, usePage } from '@inertiajs/react';
import { Building2, FolderKanban, Settings, Users } from 'lucide-react';
import ProjectController from '@/actions/App/Http/Controllers/Projects/ProjectController';
import WorkspaceController from '@/actions/App/Http/Controllers/Workspaces/WorkspaceController';
import WorkspaceMemberController from '@/actions/App/Http/Controllers/Workspaces/WorkspaceMemberController';
import EmptyState from '@/components/empty-state';
import Heading from '@/components/heading';
import ProjectStatusBadge from '@/components/project-status-badge';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { edit } from '@/routes/profile';
import type { ProjectSummary } from '@/types';

const tile =
    'flex flex-col gap-1 rounded-lg border bg-card p-4 transition-[border-color,box-shadow] outline-none hover:border-primary/40 hover:shadow-sm focus-visible:ring-[3px] focus-visible:ring-ring/50';

export default function Dashboard({
    recentProjects,
}: {
    recentProjects?: ProjectSummary[];
}) {
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
                {recentProjects && (
                    <section className="flex flex-col gap-3">
                        <div className="flex items-center justify-between gap-3">
                            <Heading variant="small" title="Recent projects" />
                            <TextLink
                                href={ProjectController.index()}
                                className="text-sm"
                            >
                                View all projects
                            </TextLink>
                        </div>
                        {recentProjects.length === 0 ? (
                            <EmptyState
                                icon={FolderKanban}
                                title="No projects yet"
                                description="Projects your team creates will show up here."
                            />
                        ) : (
                            <ul className="divide-y rounded-lg border bg-card">
                                {recentProjects.map((project) => (
                                    <li key={project.id}>
                                        <Link
                                            href={ProjectController.show(
                                                project.id,
                                            )}
                                            className="flex flex-wrap items-center gap-x-4 gap-y-1 px-3 py-2 text-sm transition-[color,background-color,box-shadow] outline-none hover:bg-muted/50 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:ring-inset"
                                        >
                                            <span
                                                title={project.name}
                                                className="min-w-0 truncate font-medium"
                                            >
                                                {project.name}
                                            </span>
                                            <code
                                                title={project.slug}
                                                className="min-w-0 truncate font-mono text-code text-muted-foreground"
                                            >
                                                {project.slug}
                                            </code>
                                            <ProjectStatusBadge {...project} />
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                )}
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
