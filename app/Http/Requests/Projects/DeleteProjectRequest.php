<?php

namespace App\Http\Requests\Projects;

use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class DeleteProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('delete', $this->project());

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // Typing the exact name is the confirmation that this is the project meant.
        return ['confirm_name' => ['required', 'string', 'in:'.$this->project()->name]];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'confirm_name.required' => __('Type the project name to confirm.'),
            'confirm_name.in' => __('That does not match the project name.'),
        ];
    }

    public function project(): Project
    {
        $project = $this->route('project');
        abort_unless($project instanceof Project, 404);

        return $project;
    }
}
