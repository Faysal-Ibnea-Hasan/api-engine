import { Form, router } from '@inertiajs/react';
import { Layers } from 'lucide-react';
import { useState } from 'react';
import DetectionController from '@/actions/App/Http/Controllers/Framework/DetectionController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { DetectedRoot, FrameworkDetectionData } from '@/types';

const statusText: Record<FrameworkDetectionData['status'], string> = {
    detected: 'Laravel detected',
    partial: 'Needs your input',
    unsupported: 'Not supported',
    not_found: 'No application found',
};

const folder = (path: string) => (path === '.' ? 'archive root' : `${path}/`);

export function versionLabel(root: DetectedRoot): string {
    if (root.lockedVersion) {
        return `Laravel ${root.lockedVersion}`;
    }

    return root.declaredVersion
        ? `Laravel ${root.declaredVersion} (declared; exact version unknown without composer.lock)`
        : 'Laravel (version unknown)';
}

type Props = {
    projectId: string;
    snapshotId: string;
    detection: FrameworkDetectionData | null;
    canDetect: boolean;
    canSelectRoot: boolean;
};

export default function FrameworkDetectionCard({
    projectId,
    snapshotId,
    detection,
    canDetect,
    canSelectRoot,
}: Props) {
    const args = { project: projectId, snapshot: snapshotId };
    const OTHER = '__other__';
    const [choice, setChoice] = useState<string>(detection?.selectedRoot ?? '');

    const runButton = canDetect && (!detection || detection.outdated) && (
        <Button
            variant="outline"
            size="sm"
            onClick={() =>
                router.post(
                    DetectionController.detect.url(args),
                    {},
                    { preserveScroll: true },
                )
            }
        >
            {detection ? 'Re-run with the latest detector' : 'Detect framework'}
        </Button>
    );

    if (!detection) {
        return (
            <section className="flex flex-col gap-3">
                <Heading
                    variant="small"
                    title="Framework"
                    icon={Layers}
                    description="Detection runs automatically after an import. It reads files only; nothing is executed."
                />
                {runButton}
            </section>
        );
    }

    const needsChoice = detection.roots.length > 0 && !detection.selectedRoot;

    return (
        <section className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center gap-2">
                <Heading variant="small" title="Framework" icon={Layers} />
                <Badge
                    variant={
                        detection.status === 'detected'
                            ? 'info'
                            : detection.status === 'partial'
                              ? 'secondary'
                              : 'destructive'
                    }
                >
                    {Object.hasOwn(statusText, detection.status) &&
                        statusText[detection.status]}
                </Badge>
                <span className="ml-auto">{runButton}</span>
            </div>

            {detection.roots.map((root) => (
                <div
                    key={root.path}
                    className="space-y-2 rounded-lg border bg-card p-4 text-sm"
                >
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="font-medium">
                            {versionLabel(root)}
                        </span>
                        <span className="text-muted-foreground">
                            in {folder(root.path)}
                        </span>
                        {detection.selectedRoot === root.path && (
                            <Badge variant="info">Selected root</Badge>
                        )}
                    </div>
                    <dl className="grid grid-cols-[max-content_1fr] gap-x-6 gap-y-1 text-muted-foreground">
                        <dt>Declared constraint</dt>
                        <dd>
                            <code>{root.declaredVersion ?? 'unknown'}</code>
                        </dd>
                        <dt>Locked version</dt>
                        <dd>{root.lockedVersion ?? 'no composer.lock'}</dd>
                        <dt>PHP requirement</dt>
                        <dd>
                            <code>{root.phpConstraint ?? 'not stated'}</code>
                        </dd>
                        <dt>Routing</dt>
                        <dd>
                            {root.routeLayout === 'bootstrap_app'
                                ? 'bootstrap/app.php (Laravel 11+)'
                                : root.routeLayout === 'route_service_provider'
                                  ? 'RouteServiceProvider'
                                  : 'unknown layout'}
                            {' · '}
                            {root.routeFileCount} route{' '}
                            {root.routeFileCount === 1 ? 'file' : 'files'}
                        </dd>
                        {root.modules.length > 0 && (
                            <>
                                <dt>Modules</dt>
                                <dd>{root.modules.join(', ')}</dd>
                            </>
                        )}
                    </dl>
                    {root.evidence && (
                        <details className="text-xs">
                            <summary className="cursor-pointer rounded-sm text-muted-foreground outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50">
                                Evidence ({root.evidence.length} files)
                            </summary>
                            <ul className="mt-1 space-y-0.5">
                                {root.evidence.map((path) => (
                                    <li key={path}>
                                        <code>{path}</code>
                                    </li>
                                ))}
                            </ul>
                        </details>
                    )}
                </div>
            ))}

            {detection.hiddenWarningCount > 0 && (
                <p className="text-sm text-muted-foreground">
                    {detection.hiddenWarningCount}{' '}
                    {detection.hiddenWarningCount === 1 ? 'note' : 'notes'}{' '}
                    about source files, visible to roles with source access.
                </p>
            )}

            {detection.warnings.length > 0 && (
                <ul className="list-inside list-disc space-y-1 text-sm text-muted-foreground">
                    {detection.warnings.map((warning) => (
                        <li key={warning}>{warning}</li>
                    ))}
                </ul>
            )}

            {canSelectRoot && detection.roots.length > 0 && (
                <Form
                    {...DetectionController.selectRoot.form(args)}
                    options={{ preserveScroll: true }}
                    className="space-y-3 rounded-lg border bg-card p-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <p className="text-sm font-medium">
                                {needsChoice
                                    ? 'Choose which application to document'
                                    : 'Application root'}
                            </p>
                            {detection.roots.map((root) => (
                                <label
                                    key={root.path}
                                    className="flex items-center gap-2 text-sm"
                                >
                                    <input
                                        type="radio"
                                        className="size-4 accent-info focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                        checked={choice === root.path}
                                        onChange={() => setChoice(root.path)}
                                    />
                                    <code>{folder(root.path)}</code>
                                    <span className="text-muted-foreground">
                                        {versionLabel(root)}
                                    </span>
                                </label>
                            ))}
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="radio"
                                    className="size-4 accent-info focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                    checked={choice === OTHER}
                                    onChange={() => setChoice(OTHER)}
                                />
                                Another folder
                            </label>
                            {choice === OTHER ? (
                                <Input
                                    name="root"
                                    placeholder="e.g. services/api"
                                    maxLength={1024}
                                    required
                                />
                            ) : (
                                <input
                                    type="hidden"
                                    name="root"
                                    value={choice}
                                />
                            )}
                            <InputError message={errors.root} />
                            <Button
                                type="submit"
                                size="sm"
                                disabled={processing}
                            >
                                Confirm root
                            </Button>
                        </>
                    )}
                </Form>
            )}

            {!canSelectRoot && needsChoice && (
                <p className="text-sm text-muted-foreground">
                    Someone who can manage this project needs to choose the
                    application root.
                </p>
            )}

            <p className="text-xs text-muted-foreground">
                Detected from files only; nothing was executed. Detector{' '}
                {detection.detectorVersion}
                {detection.selectionSource === 'project_setting' &&
                    ' · root taken from the project setup'}
                {detection.selectionSource === 'user' &&
                    ' · root chosen by a person'}
                .
            </p>
        </section>
    );
}
