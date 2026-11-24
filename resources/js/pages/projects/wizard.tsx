import { Form, Head, Link } from '@inertiajs/react';
import { Check } from 'lucide-react';
import ProjectController from '@/actions/App/Http/Controllers/Projects/ProjectController';
import ProjectWizardController from '@/actions/App/Http/Controllers/Projects/ProjectWizardController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import type { ProjectDetail, ProjectOption, ProjectOptions } from '@/types';

type Props = {
    project: ProjectDetail | null;
    step: string;
    steps: string[];
    options: ProjectOptions;
};

const titles: Record<string, string> = {
    details: 'Details',
    source: 'Source',
    framework: 'Framework',
    analysis: 'Analysis',
    review: 'Review',
};

function Choice({
    name,
    option,
    checked,
}: {
    name: string;
    option: ProjectOption;
    checked: boolean;
}) {
    return (
        <label
            className={cn(
                'flex gap-3 rounded-lg border p-3',
                option.available
                    ? 'cursor-pointer has-checked:border-primary'
                    : 'cursor-not-allowed opacity-60',
            )}
        >
            <input
                type="radio"
                name={name}
                value={option.value}
                defaultChecked={checked && option.available}
                disabled={!option.available}
                required
                className="mt-1 size-4 accent-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
            />
            <span>
                <span className="block text-sm font-medium">
                    {option.label}
                    {!option.available && ' (not yet supported)'}
                </span>
                {option.note && (
                    <span className="block text-sm text-muted-foreground">
                        {option.note}
                    </span>
                )}
            </span>
        </label>
    );
}

function labelOf(options: ProjectOption[], value: string | null) {
    return options.find((o) => o.value === value)?.label ?? 'Not chosen';
}

export default function ProjectWizard({
    project,
    step,
    steps,
    options,
}: Props) {
    const current = steps.indexOf(step);
    const reachable = project ? steps.indexOf(project.wizardStep) : 0;
    const previous = current > 0 ? steps[current - 1] : null;

    const form = project
        ? ProjectWizardController.update.form({ project: project.id, step })
        : ProjectController.store.form();

    return (
        <>
            <Head
                title={project ? `Set up ${project.name}` : 'Create project'}
            />
            <div className="flex w-full max-w-2xl flex-col gap-4 p-4 md:p-6">
                <Heading
                    title={
                        project ? `Set up ${project.name}` : 'Create project'
                    }
                    description="Each step is saved as you continue. You can leave and pick up where you stopped."
                />

                <ol
                    className="flex flex-wrap gap-2 text-sm"
                    aria-label="Setup steps"
                >
                    {steps.map((s, i) => {
                        const done =
                            project !== null &&
                            (project.setupComplete || i < reachable);
                        const canOpen =
                            project !== null &&
                            (project.setupComplete || i <= reachable);
                        const label = (
                            <>
                                {done && (
                                    <Check
                                        className="size-3"
                                        aria-hidden="true"
                                    />
                                )}
                                {i + 1}. {titles[s]}
                            </>
                        );

                        return (
                            <li key={s}>
                                {canOpen && s !== step ? (
                                    <Link
                                        href={ProjectWizardController.edit({
                                            project: project.id,
                                            step: s,
                                        })}
                                        className="flex items-center gap-1 rounded-full border px-3 py-1 outline-none hover:bg-muted focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                    >
                                        {label}
                                    </Link>
                                ) : (
                                    <span
                                        aria-current={
                                            s === step ? 'step' : undefined
                                        }
                                        className={cn(
                                            'flex items-center gap-1 rounded-full border px-3 py-1',
                                            s === step
                                                ? 'border-primary bg-primary text-primary-foreground'
                                                : 'text-muted-foreground',
                                        )}
                                    >
                                        {label}
                                    </span>
                                )}
                            </li>
                        );
                    })}
                </ol>

                <Form
                    {...form}
                    options={{ preserveScroll: true }}
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            {project && (
                                <input
                                    type="hidden"
                                    name="lock_version"
                                    value={project.lockVersion}
                                />
                            )}

                            {step === 'details' && (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="name">
                                            Project name
                                        </Label>
                                        <Input
                                            id="name"
                                            name="name"
                                            required
                                            maxLength={120}
                                            defaultValue={project?.name}
                                            autoFocus
                                        />
                                        <InputError message={errors.name} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="slug">Slug</Label>
                                        <Input
                                            id="slug"
                                            name="slug"
                                            maxLength={80}
                                            required={project !== null}
                                            defaultValue={project?.slug}
                                            placeholder={
                                                project
                                                    ? undefined
                                                    : 'Leave empty to generate it from the name'
                                            }
                                        />
                                        <p className="text-sm text-muted-foreground">
                                            Lowercase letters, numbers and
                                            dashes. Unique in this workspace.
                                        </p>
                                        <InputError message={errors.slug} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="description">
                                            Description
                                        </Label>
                                        <Textarea
                                            id="description"
                                            name="description"
                                            maxLength={2000}
                                            rows={3}
                                            defaultValue={
                                                project?.description ?? ''
                                            }
                                        />
                                        <InputError
                                            message={errors.description}
                                        />
                                    </div>
                                    <fieldset className="grid gap-2">
                                        <legend className="mb-2 text-sm font-medium">
                                            Who can see it
                                        </legend>
                                        <Choice
                                            name="visibility"
                                            checked={
                                                (project?.visibility ??
                                                    'workspace') === 'workspace'
                                            }
                                            option={{
                                                value: 'workspace',
                                                label: 'Everyone in the workspace',
                                                available: true,
                                            }}
                                        />
                                        <Choice
                                            name="visibility"
                                            checked={
                                                project?.visibility ===
                                                'restricted'
                                            }
                                            option={{
                                                value: 'restricted',
                                                label: 'Restricted',
                                                available: true,
                                                note: 'Only Owners, Maintainers and you, as its creator.',
                                            }}
                                        />
                                        <InputError
                                            message={errors.visibility}
                                        />
                                    </fieldset>
                                </>
                            )}

                            {step === 'source' && project && (
                                <fieldset className="grid gap-2">
                                    <legend className="mb-2 text-sm font-medium">
                                        Where will the source code come from?
                                    </legend>
                                    {options.source_preference.map((o) => (
                                        <Choice
                                            key={o.value}
                                            name="source_preference"
                                            option={o}
                                            checked={
                                                project.sourcePreference ===
                                                o.value
                                            }
                                        />
                                    ))}
                                    <InputError
                                        message={errors.source_preference}
                                    />
                                </fieldset>
                            )}

                            {step === 'framework' && project && (
                                <>
                                    <fieldset className="grid gap-2">
                                        <legend className="mb-2 text-sm font-medium">
                                            Framework
                                        </legend>
                                        {options.framework.map((o) => (
                                            <Choice
                                                key={o.value}
                                                name="framework"
                                                option={o}
                                                checked={
                                                    project.framework ===
                                                    o.value
                                                }
                                            />
                                        ))}
                                        <InputError
                                            message={errors.framework}
                                        />
                                    </fieldset>
                                    <div className="grid gap-2">
                                        <Label htmlFor="root_path">
                                            Application folder
                                        </Label>
                                        <Input
                                            id="root_path"
                                            name="root_path"
                                            required
                                            maxLength={255}
                                            defaultValue={project.rootPath}
                                        />
                                        <p className="text-sm text-muted-foreground">
                                            Folder that contains{' '}
                                            <code>artisan</code>, relative to
                                            the repository root. Use{' '}
                                            <code>.</code> for the root.
                                        </p>
                                        <InputError
                                            message={errors.root_path}
                                        />
                                    </div>
                                </>
                            )}

                            {step === 'analysis' && project && (
                                <>
                                    <fieldset className="grid gap-2">
                                        <legend className="mb-2 text-sm font-medium">
                                            Analysis
                                        </legend>
                                        {options.analysis_mode.map((o) => (
                                            <Choice
                                                key={o.value}
                                                name="analysis_mode"
                                                option={o}
                                                checked={
                                                    project.analysisMode ===
                                                    o.value
                                                }
                                            />
                                        ))}
                                        <InputError
                                            message={errors.analysis_mode}
                                        />
                                    </fieldset>
                                    <fieldset className="grid gap-2">
                                        <legend className="mb-2 text-sm font-medium">
                                            Documentation
                                        </legend>
                                        {options.documentation_mode.map((o) => (
                                            <Choice
                                                key={o.value}
                                                name="documentation_mode"
                                                option={o}
                                                checked={
                                                    project.documentationMode ===
                                                    o.value
                                                }
                                            />
                                        ))}
                                        <InputError
                                            message={errors.documentation_mode}
                                        />
                                    </fieldset>
                                </>
                            )}

                            {step === 'review' && project && (
                                <>
                                    <dl className="grid grid-cols-[max-content_1fr] gap-x-6 gap-y-1.5 rounded-lg border p-3 text-sm lg:grid-cols-[max-content_1fr_max-content_1fr]">
                                        <dt className="text-muted-foreground">
                                            Name
                                        </dt>
                                        <dd>{project.name}</dd>
                                        <dt className="text-muted-foreground">
                                            Slug
                                        </dt>
                                        <dd>
                                            <code className="font-mono text-code">
                                                {project.slug}
                                            </code>
                                        </dd>
                                        <dt className="text-muted-foreground">
                                            Visibility
                                        </dt>
                                        <dd>
                                            {project.visibility === 'restricted'
                                                ? 'Restricted'
                                                : 'Everyone in the workspace'}
                                        </dd>
                                        <dt className="text-muted-foreground">
                                            Source
                                        </dt>
                                        <dd>
                                            {labelOf(
                                                options.source_preference,
                                                project.sourcePreference,
                                            )}
                                        </dd>
                                        <dt className="text-muted-foreground">
                                            Framework
                                        </dt>
                                        <dd>
                                            {labelOf(
                                                options.framework,
                                                project.framework,
                                            )}{' '}
                                            in{' '}
                                            <code className="font-mono text-code">
                                                {project.rootPath}
                                            </code>
                                        </dd>
                                        <dt className="text-muted-foreground">
                                            Analysis
                                        </dt>
                                        <dd>
                                            {labelOf(
                                                options.analysis_mode,
                                                project.analysisMode,
                                            )}
                                        </dd>
                                        <dt className="text-muted-foreground">
                                            Documentation
                                        </dt>
                                        <dd>
                                            {labelOf(
                                                options.documentation_mode,
                                                project.documentationMode,
                                            )}
                                        </dd>
                                    </dl>
                                    <p className="text-sm text-muted-foreground">
                                        Finishing setup saves these choices.
                                        Nothing is imported or scanned yet: the
                                        project stays a draft until source
                                        import is available.
                                    </p>
                                </>
                            )}

                            <div className="flex flex-wrap items-center gap-2">
                                {project && previous && (
                                    <Button variant="outline" asChild>
                                        <Link
                                            href={ProjectWizardController.edit({
                                                project: project.id,
                                                step: previous,
                                            })}
                                        >
                                            Back
                                        </Link>
                                    </Button>
                                )}
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    {step === 'review'
                                        ? 'Finish setup'
                                        : 'Save and continue'}
                                </Button>
                                <Button
                                    variant="ghost"
                                    asChild
                                    className="ml-auto"
                                >
                                    <Link
                                        href={
                                            project
                                                ? ProjectController.show(
                                                      project.id,
                                                  )
                                                : ProjectController.index()
                                        }
                                    >
                                        {project ? 'Finish later' : 'Cancel'}
                                    </Link>
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

ProjectWizard.layout = {
    breadcrumbs: [{ title: 'Projects', href: ProjectController.index() }],
};
