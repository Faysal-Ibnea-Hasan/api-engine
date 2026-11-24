<?php

namespace App\Http\Controllers\Projects;

use App\Domain\Projects\ProjectOptions;
use App\Domain\Projects\ProjectService;
use App\Domain\Projects\WizardStep;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\SaveWizardStepRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** The five-step setup. Every step saves on the server, so a refresh resumes where you were. */
class ProjectWizardController extends Controller
{
    public function edit(Project $project, string $step): Response|RedirectResponse
    {
        Gate::authorize('update', $project);
        $step = WizardStep::tryFrom($step) ?? abort(404);

        // Steps open up in order; jumping past the furthest saved step resumes there instead.
        if ($project->wizard_completed_at === null && $step->position() > $project->wizard_step->position()) {
            return to_route('projects.setup.edit', [$project, $project->wizard_step->value]);
        }

        return self::page($project, $step->value);
    }

    public function update(SaveWizardStepRequest $request, Project $project, string $step, ProjectService $projects): RedirectResponse
    {
        $step = $request->step();
        $lock = $request->integer('lock_version');

        if ($step === WizardStep::Details) {
            /** @var array{name: string, slug: string, description?: string|null, visibility: string} $details */
            $details = $request->safe()->except('lock_version');
            $projects->updateDetails($request->user(), $project, $details, $lock);
        } else {
            $projects->saveStep($request->user(), $project, $step, $request->settings(), $lock);
        }

        if ($step === WizardStep::Review) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Setup saved. The project stays a draft until a source is imported.')]);

            return to_route('projects.show', $project);
        }

        return to_route('projects.setup.edit', [$project, $step->next()->value]);
    }

    public static function page(?Project $project, string $step): Response
    {
        return Inertia::render('projects/wizard', [
            'project' => $project === null ? null : ProjectController::present($project),
            'step' => $step,
            'steps' => array_map(fn (WizardStep $s) => $s->value, WizardStep::cases()),
            'options' => ProjectOptions::all(),
        ]);
    }
}
