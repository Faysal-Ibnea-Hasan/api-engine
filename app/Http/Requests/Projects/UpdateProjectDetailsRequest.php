<?php

namespace App\Http\Requests\Projects;

use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateProjectDetailsRequest extends FormRequest
{
    use ProjectDetailsRules;

    public function authorize(): bool
    {
        Gate::authorize('update', $this->project());

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->detailsRules($this->project()->workspace_id, $this->project(), slugRequired: true)
            + ['lock_version' => ['required', 'integer', 'min:0']];
    }

    public function project(): Project
    {
        $project = $this->route('project');
        abort_unless($project instanceof Project, 404);

        return $project;
    }
}
