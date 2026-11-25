import { Form, Head, Link, router } from '@inertiajs/react';
import {
    Archive,
    FolderKanban,
    PencilLine,
    ScanSearch,
    Upload,
    Wrench,
} from 'lucide-react';
import { useState } from 'react';
import ProjectController from '@/actions/App/Http/Controllers/Projects/ProjectController';
import ProjectWizardController from '@/actions/App/Http/Controllers/Projects/ProjectWizardController';
import EmptyState from '@/components/empty-state';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import ProjectStatusBadge from '@/components/project-status-badge';
import SourcePanel from '@/components/source-panel';
import { versionLabel } from '@/components/framework-detection-card';
import SnapshotController from '@/actions/App/Http/Controllers/Sources/SnapshotController';
import EndpointController from '@/actions/App/Http/Controllers/Discovery/EndpointController';
import DeleteProject from '@/components/delete-project';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type {
    EndpointsSummary,
    FrameworkDetectionData,
    GitHubPanelData,
    ProjectDetail,
    ProjectOption,
    ProjectOptions,
    SourcePanelData,
} from '@/types';

type Props = {
    project: ProjectDetail;
    options: ProjectOptions;
    can: { update: boolean; delete: boolean };
    sources: SourcePanelData | null;
    github: GitHubPanelData | null;
    framework: {
        snapshotId: string;
        detection: FrameworkDetectionData | null;
    } | null;
    endpoints: EndpointsSummary | null;
};

function labelOf(options: ProjectOption[], value: string | null) {
    return options.find((o) => o.value === value)?.label ?? 'Not chosen';
}

export default function ShowProject({
    project,
    options,
    can,
    sources,
    github,
    framework,
    endpoints,
}: Props) {
    const archived = project.status === 'archived';
    const editable = can.update && !archived;
    const [archiving, setArchiving] = useState(false);
    const archiveProject = () =>
        router.post(
            ProjectController.archive.url(project.id),
            {},
            {
                onStart: () => setArchiving(true),
                onFinish: () => setArchiving(false),
            },
        );

    return (
        <>
            <Head title={project.name} />
            <div className="mx-auto flex w-full max-w-[110rem] flex-col gap-3 p-4 md:p-5">
                <Heading
                    title={project.name}
                    description={project.description ?? undefined}
                    icon={FolderKanban}
                    compact
                >
                    {editable && !project.setupComplete && (
                        <Button asChild variant="secondary" size="sm">
                            <Link
                                href={ProjectWizardController.edit({
                                    project: project.id,
                                    step: project.wizardStep,
                                })}
                            >
                                Continue setup
                            </Link>
                        </Button>
                    )}
                </Heading>

                <div className="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1 px-1 text-sm text-muted-foreground">
                    <code className="min-w-0 font-mono text-code break-all">
                        {project.slug}
                    </code>
                    <ProjectStatusBadge
                        status={project.status}
                        visibility={project.visibility}
                    />
                    {project.creator && (
                        <span>Created by {project.creator}</span>
                    )}
                </div>

                {archived && (
                    <p className="rounded-lg border bg-muted/30 px-3 py-2 text-sm">
                        This project is archived and read-only.
                        {can.update && ' Restore it to make changes.'}
                    </p>
                )}

                <div className="grid items-start gap-3 xl:grid-cols-[minmax(0,1fr)_minmax(24rem,0.8fr)]">
                    <section className="overflow-hidden rounded-lg border bg-card">
                        <div className="flex min-h-12 items-center gap-2 border-b px-3 py-2">
                            <Heading
                                variant="small"
                                title="Project setup"
                                icon={Wrench}
                            />
                            {editable && project.setupComplete && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    asChild
                                    className="ml-auto"
                                >
                                    <Link
                                        href={ProjectWizardController.edit({
                                            project: project.id,
                                            step: 'source',
                                        })}
                                    >
                                        Change setup
                                    </Link>
                                </Button>
                            )}
                        </div>
                        <dl className="grid gap-x-6 gap-y-3 p-3 text-sm sm:grid-cols-2">
                            <div className="grid min-w-0 gap-0.5">
                                <dt className="text-muted-foreground">
                                    Source
                                </dt>
                                <dd className="font-medium">
                                    {labelOf(
                                        options.source_preference,
                                        project.sourcePreference,
                                    )}
                                </dd>
                            </div>
                            <div className="grid min-w-0 gap-0.5">
                                <dt className="text-muted-foreground">
                                    Framework
                                </dt>
                                <dd className="min-w-0 font-medium">
                                    {labelOf(
                                        options.framework,
                                        project.framework,
                                    )}{' '}
                                    in{' '}
                                    <code className="font-mono text-code break-all">
                                        {project.rootPath}
                                    </code>
                                </dd>
                            </div>
                            <div className="grid min-w-0 gap-0.5">
                                <dt className="text-muted-foreground">
                                    Analysis
                                </dt>
                                <dd className="font-medium">
                                    {labelOf(
                                        options.analysis_mode,
                                        project.analysisMode,
                                    )}
                                </dd>
                            </div>
                            <div className="grid min-w-0 gap-0.5">
                                <dt className="text-muted-foreground">
                                    Documentation
                                </dt>
                                <dd className="font-medium">
                                    {labelOf(
                                        options.documentation_mode,
                                        project.documentationMode,
                                    )}
                                </dd>
                            </div>
                        </dl>
                    </section>

                    <section className="overflow-hidden rounded-lg border bg-card">
                        <div className="flex min-h-12 items-center border-b px-3 py-2">
                            <Heading
                                variant="small"
                                title="Analysis status"
                                icon={ScanSearch}
                            />
                        </div>
                        <div className="divide-y">
                            {framework && (
                                <Link
                                    href={SnapshotController.show({
                                        project: project.id,
                                        snapshot: framework.snapshotId,
                                    })}
                                    className="flex min-w-0 items-center gap-3 brand-gradient-border px-3 py-2.5 text-sm transition-colors outline-none hover:border-info/40 hover:bg-muted/50 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:ring-inset"
                                >
                                    <div className="min-w-0 flex-1">
                                        <p className="font-medium">
                                            Framework analysis
                                        </p>
                                        <p className="wrap-anywhere text-muted-foreground">
                                            {(() => {
                                                const d = framework.detection;
                                                const root = d?.roots.find(
                                                    (item) =>
                                                        item.path ===
                                                        d.selectedRoot,
                                                );

                                                if (!d) {
                                                    return 'Detecting the framework…';
                                                }
                                                if (root) {
                                                    return `${versionLabel(root)} in ${root.path === '.' ? 'the archive root' : `${root.path}/`}`;
                                                }
                                                if (d.selectedRoot) {
                                                    return `Application root: ${d.selectedRoot}/`;
                                                }

                                                return d.roots.length > 0
                                                    ? 'Several Laravel apps found; choose the application to document.'
                                                    : 'No Laravel application was found in the latest snapshot.';
                                            })()}
                                        </p>
                                    </div>
                                    <span className="shrink-0 text-info">
                                        View details
                                    </span>
                                </Link>
                            )}
                            {endpoints?.scan && (
                                <Link
                                    href={EndpointController.index(project.id)}
                                    className="flex min-w-0 items-center gap-3 brand-gradient-border px-3 py-2.5 text-sm transition-colors outline-none hover:border-info/40 hover:bg-muted/50 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:ring-inset"
                                >
                                    <div className="min-w-0 flex-1">
                                        <p className="font-medium">
                                            API route discovery
                                        </p>
                                        <p className="wrap-anywhere text-muted-foreground">
                                            {endpoints.scan.active
                                                ? 'Discovering API routes…'
                                                : endpoints.scan.status ===
                                                    'failed'
                                                  ? 'Route discovery failed. Open the explorer to retry.'
                                                  : `${endpoints.scan.endpointCount} endpoints found · ${endpoints.scan.unresolvedCount} unresolved`}
                                        </p>
                                    </div>
                                    <span className="shrink-0 text-info">
                                        API Explorer
                                    </span>
                                </Link>
                            )}
                            {!framework && !endpoints?.scan && (
                                <p className="px-3 py-3 text-sm text-muted-foreground">
                                    Analysis starts after a source snapshot is
                                    ready.
                                </p>
                            )}
                        </div>
                    </section>
                </div>

                {sources ? (
                    <SourcePanel
                        projectId={project.id}
                        sourcePreference={project.sourcePreference}
                        canManage={editable}
                        sources={sources}
                        github={github}
                    />
                ) : (
                    <EmptyState
                        icon={Upload}
                        title="Source import isn't available yet"
                        description={
                            project.sourcePreference === 'git'
                                ? 'Git import arrives in a later release. Your preference is saved; no credentials are stored.'
                                : 'ZIP upload arrives in the next release. Until then the project stays a draft.'
                        }
                    />
                )}

                {(editable || can.update || can.delete) && (
                    <div className="grid items-stretch gap-3 lg:grid-cols-2">
                        {editable && (
                            <section className="overflow-hidden rounded-lg border bg-card">
                                <div className="flex min-h-12 items-center border-b px-3 py-2">
                                    <Heading
                                        variant="small"
                                        title="Project details"
                                        icon={PencilLine}
                                    />
                                </div>
                                <Form
                                    {...ProjectController.update.form(
                                        project.id,
                                    )}
                                    options={{ preserveScroll: true }}
                                    className="grid gap-3 p-3 sm:grid-cols-2"
                                >
                                    {({ processing, errors }) => (
                                        <>
                                            <input
                                                type="hidden"
                                                name="lock_version"
                                                value={project.lockVersion}
                                            />
                                            <input
                                                type="hidden"
                                                name="visibility"
                                                value={project.visibility}
                                            />
                                            <div className="grid gap-1.5">
                                                <Label htmlFor="name">
                                                    Name
                                                </Label>
                                                <Input
                                                    id="name"
                                                    name="name"
                                                    required
                                                    maxLength={120}
                                                    defaultValue={project.name}
                                                />
                                                <InputError
                                                    message={errors.name}
                                                />
                                            </div>
                                            <div className="grid gap-1.5">
                                                <Label htmlFor="slug">
                                                    Slug
                                                </Label>
                                                <Input
                                                    id="slug"
                                                    name="slug"
                                                    required
                                                    maxLength={80}
                                                    defaultValue={project.slug}
                                                />
                                                <InputError
                                                    message={errors.slug}
                                                />
                                            </div>
                                            <div className="grid gap-1.5 sm:col-span-2">
                                                <Label htmlFor="description">
                                                    Description
                                                </Label>
                                                <Textarea
                                                    id="description"
                                                    name="description"
                                                    rows={2}
                                                    maxLength={2000}
                                                    defaultValue={
                                                        project.description ??
                                                        ''
                                                    }
                                                />
                                                <InputError
                                                    message={errors.description}
                                                />
                                            </div>
                                            <div className="flex flex-wrap items-center gap-3 sm:col-span-2">
                                                <Button
                                                    type="submit"
                                                    size="sm"
                                                    disabled={processing}
                                                >
                                                    Save details
                                                </Button>
                                                <p className="text-sm text-muted-foreground">
                                                    Visibility is managed in{' '}
                                                    <Link
                                                        className="rounded-sm underline underline-offset-4 outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                                        href={ProjectWizardController.edit(
                                                            {
                                                                project:
                                                                    project.id,
                                                                step: 'details',
                                                            },
                                                        )}
                                                    >
                                                        setup details
                                                    </Link>
                                                    .
                                                </p>
                                            </div>
                                        </>
                                    )}
                                </Form>
                            </section>
                        )}

                        <div className="flex h-full flex-col gap-3">
                            {can.update && (
                                <section className="rounded-lg border bg-card p-3">
                                    <Heading
                                        variant="small"
                                        title="Project lifecycle"
                                        icon={Archive}
                                        description={
                                            archived
                                                ? 'Restore this project to make it editable again.'
                                                : 'Archive this project without deleting its data.'
                                        }
                                    />
                                    <div className="mt-3">
                                        {archived ? (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    router.post(
                                                        ProjectController.restore.url(
                                                            project.id,
                                                        ),
                                                    )
                                                }
                                            >
                                                Restore project
                                            </Button>
                                        ) : (
                                            <Dialog>
                                                <DialogTrigger asChild>
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                    >
                                                        Archive project
                                                    </Button>
                                                </DialogTrigger>
                                                <DialogContent>
                                                    <DialogTitle>
                                                        Archive {project.name}?
                                                    </DialogTitle>
                                                    <DialogDescription>
                                                        It disappears from the
                                                        project list and becomes
                                                        read-only. Nothing is
                                                        deleted, and you can
                                                        restore it any time.
                                                    </DialogDescription>
                                                    <DialogFooter className="gap-2">
                                                        <DialogClose asChild>
                                                            <Button variant="secondary">
                                                                Cancel
                                                            </Button>
                                                        </DialogClose>
                                                        <Button
                                                            disabled={archiving}
                                                            onClick={
                                                                archiveProject
                                                            }
                                                        >
                                                            Archive
                                                        </Button>
                                                    </DialogFooter>
                                                </DialogContent>
                                            </Dialog>
                                        )}
                                    </div>
                                </section>
                            )}

                            {can.delete && (
                                <DeleteProject
                                    project={{
                                        id: project.id,
                                        name: project.name,
                                    }}
                                />
                            )}
                        </div>
                    </div>
                )}
            </div>
        </>
    );
}

ShowProject.layout = {
    breadcrumbs: [{ title: 'Projects', href: ProjectController.index() }],
};
