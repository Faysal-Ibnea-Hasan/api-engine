<?php

namespace App\Http\Requests\Analysis;

use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class TraceWorkflowRequest extends FormRequest
{
    /** Runs before validation: another tenant gets 404, a role that can't analyze gets 403. */
    public function authorize(): bool
    {
        $project = $this->route('project');
        abort_unless($project instanceof Project, 404);
        Gate::authorize('analyze', $project);

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['reanalyze' => ['sometimes', 'boolean']];
    }
}
