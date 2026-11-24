<?php

namespace App\Http\Requests\Sources;

use App\Domain\GitHub\GitHubRepositories;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Importing needs ImportAndAnalyze, like a ZIP upload. Authorized before validation. */
class StartGitHubImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('analyze', $this->project());

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ref_type' => ['required', Rule::in(GitHubRepositories::REF_TYPES)],
            'ref' => ['nullable', 'required_unless:ref_type,default', 'string', 'max:255'],
        ];
    }

    public function project(): Project
    {
        $project = $this->route('project');
        abort_unless($project instanceof Project, 404);

        return $project;
    }
}
