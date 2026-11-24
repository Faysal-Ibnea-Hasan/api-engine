<?php

namespace App\Http\Requests\GitHub;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** Choosing the repository changes the project (ManageProjects). Authorized before validation. */
class SelectGitHubRepositoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('update', $this->project());

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'installation_id' => ['required', 'string', 'uuid'],
            // A numeric GitHub repository ID; never a URL or a name.
            'repository_id' => ['required', 'integer', 'min:1', 'max:9007199254740991'],
        ];
    }

    public function project(): Project
    {
        $project = $this->route('project');
        abort_unless($project instanceof Project, 404);

        return $project;
    }
}
