<?php

namespace App\Http\Requests\Projects;

use App\Domain\Projects\ProjectOptions;
use App\Domain\Projects\WizardStep;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/** Steps after Details. Only options the platform supports today are accepted. */
class SaveWizardStepRequest extends UpdateProjectDetailsRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $lock = ['lock_version' => ['required', 'integer', 'min:0']];

        return $lock + match ($this->step()) {
            WizardStep::Details => parent::rules(),
            WizardStep::Source => [
                'source_preference' => ['required', Rule::in(ProjectOptions::accepted('source_preference'))],
            ],
            WizardStep::Framework => [
                'framework' => ['required', Rule::in(ProjectOptions::accepted('framework'))],
                // A path inside the archive: relative, no "..", no backslashes.
                'root_path' => ['required', 'string', 'max:255', 'regex:#^(?!/)(?!.*(?:^|/)\.\.(?:/|$))[A-Za-z0-9._/-]+$#'],
            ],
            WizardStep::Analysis => [
                'analysis_mode' => ['required', Rule::in(ProjectOptions::accepted('analysis_mode'))],
                'documentation_mode' => ['required', Rule::in(ProjectOptions::accepted('documentation_mode'))],
            ],
            WizardStep::Review => [],
        };
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return parent::messages() + [
            'root_path.regex' => __('Use a relative folder inside the project, such as "." or "backend". ".." is not allowed.'),
            'analysis_mode.in' => __('That analysis mode is not available yet.'),
        ];
    }

    public function step(): WizardStep
    {
        return WizardStep::tryFrom((string) $this->route('step')) ?? abort(404);
    }

    /** @return array<string, string> */
    public function settings(): array
    {
        return collect($this->validated())->except('lock_version')->map(fn ($v) => (string) $v)->all();
    }
}
