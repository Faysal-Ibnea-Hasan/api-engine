import { Form, Head, Link, router } from '@inertiajs/react';
import { Upload } from 'lucide-react';
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
    framework,
    endpoints,
}: Props) {
    const archived = project.status === 'archived';
    const editable = can.update && !archived;
    const [archiving, setArchiving] = useState(false);

    return (
        <>
            <Head title={project.name} />
            <div className="flex w-full flex-col gap-4 p-4 md:p-6">
                <div className="flex flex-wrap items-start gap-4">
                    <div className="space-y-2">
                        <Heading
                            title={project.name}
                            description={project.description ?? undefined}
                        />
                        <div className="flex items-center gap-3 text-sm text-muted-foreground">
                            <code className="font-mono text-code">
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
                    </div>
                    {editable && !project.setupComplete && (
                        <Button asChild className="ml-auto">
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
                </div>

                {archived && (
                    <p className="rounded-lg border p-4 text-sm">
                        This project is archived and read-only.
                        {can.update && ' Restore it to make changes.'}
                    </p>
                )}

                <section className="flex flex-col gap-3">
                    <div className="flex items-center gap-2">
                        <Heading variant="small" title="Setup" />
                        {editable && project.setupComplete && (
                            <Button
                                variant="link"
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
                    <dl className="grid grid-cols-[max-content_1fr] gap-x-6 gap-y-1.5 rounded-lg border p-3 text-sm lg:grid-cols-[max-content_1fr_max-content_1fr]">
                        <dt className="text-muted-foreground">Source</dt>
                        <dd>
                            {labelOf(
                                options.source_preference,
                                project.sourcePreference,
                            )}
                        </dd>
                        <dt className="text-muted-foreground">Framework</dt>
                        <dd>
                            {labelOf(options.framework, project.framework)} in{' '}
                            <code className="font-mono text-code">
                                {project.rootPath}
                            </code>
                        </dd>
                        <dt className="text-muted-foreground">Analysis</dt>
                        <dd>
                            {labelOf(
                                options.analysis_mode,
                                project.analysisMode,
                            )}
                        </dd>
                        <dt className="text-muted-foreground">Documentation</dt>
                        <dd>
                            {labelOf(
                                options.documentation_mode,
                                project.documentationMode,
                            )}
                        </dd>
                    </dl>
                </section>

                {framework && (
                    <Link
                        href={SnapshotController.show({
                            project: project.id,
                            snapshot: framework.snapshotId,
                        })}
                        className="block rounded-lg border bg-card p-4 text-sm transition-[border-color,box-shadow] outline-none hover:border-primary/40 hover:shadow-sm focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    >
                        {(() => {
                            const d = framework.detection;
                            const root = d?.roots.find(
                                (r) => r.path === d.selectedRoot,
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
                                ? 'Several Laravel apps found: choose which one to document →'
                                : 'No Laravel application found in the latest snapshot →';
                        })()}
                    </Link>
                )}

                {endpoints?.scan && (
                    <Link
                        href={EndpointController.index(project.id)}
                        className="block rounded-lg border bg-card p-4 text-sm transition-[border-color,box-shadow] outline-none hover:border-primary/40 hover:shadow-sm focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    >
                        {endpoints.scan.active
                            ? 'Discovering API routes…'
                            : endpoints.scan.status === 'failed'
                              ? 'Route discovery failed — open to retry →'
                              : `${endpoints.scan.endpointCount} endpoints found (${endpoints.scan.unresolvedCount} unresolved) → open the API Explorer`}
                    </Link>
                )}

                {sources ? (
                    <SourcePanel
                        projectId={project.id}
                        sourcePreference={project.sourcePreference}
                        canManage={editable}
                        sources={sources}
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

                {editable && (
                    <section className="flex flex-col gap-3">
                        <Heading variant="small" title="Edit details" />
                        <Form
                            {...ProjectController.update.form(project.id)}
                            options={{ preserveScroll: true }}
                            className="grid max-w-md gap-4"
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
                                    <div className="grid gap-2">
                                        <Label htmlFor="name">Name</Label>
                                        <Input
                                            id="name"
                                            name="name"
                                            required
                                            maxLength={120}
                                            defaultValue={project.name}
                                        />
                                        <InputError message={errors.name} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="slug">Slug</Label>
                                        <Input
                                            id="slug"
                                            name="slug"
                                            required
                                            maxLength={80}
                                            defaultValue={project.slug}
                                        />
                                        <InputError message={errors.slug} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="description">
                                            Description
                                        </Label>
                                        <Textarea
                                            id="description"
                                            name="description"
                                            rows={3}
                                            maxLength={2000}
                                            defaultValue={
                                                project.description ?? ''
                                            }
                                        />
                                        <InputError
                                            message={errors.description}
                                        />
                                    </div>
                                    <p className="text-sm text-muted-foreground">
                                        To change who can see this project, use{' '}
                                        <Link
                                            className="rounded-sm underline outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                            href={ProjectWizardController.edit({
                                                project: project.id,
                                                step: 'details',
                                            })}
                                        >
                                            setup details
                                        </Link>
                                        .
                                    </p>
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        className="w-fit"
                                    >
                                        Save details
                                    </Button>
                                </>
                            )}
                        </Form>
                    </section>
                )}

                {can.update && (
                    <section className="flex flex-col gap-3">
                        <Heading
                            variant="small"
                            title={archived ? 'Restore' : 'Archive'}
                        />
                        {archived ? (
                            <Button
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
                                    <Button variant="outline">
                                        Archive project
                                    </Button>
                                </DialogTrigger>
                                <DialogContent>
                                    <DialogTitle>
                                        Archive {project.name}?
                                    </DialogTitle>
                                    <DialogDescription>
                                        It disappears from the project list and
                                        becomes read-only. Nothing is deleted,
                                        and you can restore it any time.
                                    </DialogDescription>
                                    <DialogFooter className="gap-2">
                                        <DialogClose asChild>
                                            <Button variant="secondary">
                                                Cancel
                                            </Button>
                                        </DialogClose>
                                        <Button
                                            disabled={archiving}
                                            onClick={() =>
                                                router.post(
                                                    ProjectController.archive.url(
                                                        project.id,
                                                    ),
                                                    {},
                                                    {
                                                        onStart: () =>
                                                            setArchiving(true),
                                                        onFinish: () =>
                                                            setArchiving(false),
                                                    },
                                                )
                                            }
                                        >
                                            Archive
                                        </Button>
                                    </DialogFooter>
                                </DialogContent>
                            </Dialog>
                        )}
                    </section>
                )}

                {can.delete && (
                    <DeleteProject
                        project={{ id: project.id, name: project.name }}
                    />
                )}
            </div>
        </>
    );
}

ShowProject.layout = {
    breadcrumbs: [{ title: 'Projects', href: ProjectController.index() }],
};
