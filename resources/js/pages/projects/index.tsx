import { Form, Head, Link } from '@inertiajs/react';
import { FolderKanban, Search } from 'lucide-react';
import ProjectController from '@/actions/App/Http/Controllers/Projects/ProjectController';
import EmptyState from '@/components/empty-state';
import Heading from '@/components/heading';
import ProjectStatusBadge from '@/components/project-status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { ProjectSummary } from '@/types';

type Props = {
    projects: ProjectSummary[];
    filters: { q: string; archived: boolean };
    canCreate: boolean;
};

export default function ProjectsIndex({ projects, filters, canCreate }: Props) {
    const filtered = filters.q !== '' || filters.archived;

    return (
        <>
            <Head title="Projects" />
            <div className="flex w-full flex-col gap-4 p-4 md:p-6">
                <Heading
                    title="Projects"
                    description="APIs you document in this workspace."
                    icon={FolderKanban}
                >
                    {canCreate && (
                        <Button asChild variant="secondary">
                            <Link href={ProjectController.create()}>
                                Create project
                            </Link>
                        </Button>
                    )}
                </Heading>

                <Form
                    {...ProjectController.index.form()}
                    options={{ preserveState: true }}
                    className="flex flex-wrap items-center gap-3"
                >
                    <div className="relative w-full max-w-xs">
                        <Search
                            aria-hidden="true"
                            className="absolute top-2.5 left-2.5 size-4 text-muted-foreground"
                        />
                        <Input
                            name="q"
                            type="search"
                            defaultValue={filters.q}
                            placeholder="Search by name or slug"
                            aria-label="Search projects"
                            className="pl-8"
                        />
                    </div>
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            name="archived"
                            className="size-4 accent-info focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                            value="1"
                            defaultChecked={filters.archived}
                            onChange={(e) =>
                                e.currentTarget.form?.requestSubmit()
                            }
                        />
                        Show archived only
                    </label>
                    <Button type="submit" variant="outline" size="sm">
                        Search
                    </Button>
                </Form>

                {projects.length === 0 ? (
                    <EmptyState
                        icon={FolderKanban}
                        title={
                            filtered
                                ? 'No matching projects'
                                : 'No projects yet'
                        }
                        description={
                            filtered
                                ? 'Try another search, or clear the filters.'
                                : canCreate
                                  ? 'Create a project to start documenting an API.'
                                  : 'Projects your team creates will show up here.'
                        }
                        action={
                            filtered ? (
                                <Button asChild variant="outline">
                                    <Link href={ProjectController.index()}>
                                        Clear filters
                                    </Link>
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <ul className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        {projects.map((project) => (
                            <li key={project.id} className="min-w-0">
                                <Link
                                    href={ProjectController.show(project.id)}
                                    className="flex h-full flex-col gap-2 rounded-lg border brand-gradient-border bg-card p-4 text-sm transition-[border-color,box-shadow] outline-none hover:border-info/40 hover:shadow-sm focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                >
                                    <span className="flex min-w-0 items-center gap-3">
                                        <span className="flex size-8 shrink-0 items-center justify-center rounded-md brand-gradient text-gradient-foreground">
                                            <FolderKanban
                                                aria-hidden="true"
                                                className="size-4"
                                            />
                                        </span>
                                        <span className="flex min-w-0 flex-1 flex-col gap-2">
                                            <span
                                                title={project.name}
                                                className="truncate font-medium"
                                            >
                                                {project.name}
                                            </span>
                                            <code
                                                title={project.slug}
                                                className="truncate font-mono text-code text-muted-foreground"
                                            >
                                                {project.slug}
                                            </code>
                                        </span>
                                    </span>
                                    <span className="flex flex-wrap items-center gap-2">
                                        <ProjectStatusBadge {...project} />
                                        {!project.setupComplete && (
                                            <span className="text-xs text-muted-foreground">
                                                Setup not finished
                                            </span>
                                        )}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

ProjectsIndex.layout = {
    breadcrumbs: [{ title: 'Projects', href: ProjectController.index() }],
};
