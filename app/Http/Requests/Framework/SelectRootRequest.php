<?php

namespace App\Http\Requests\Framework;

use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class SelectRootRequest extends FormRequest
{
    /** Runs before validation: another tenant gets 404, not a validation error. */
    public function authorize(): bool
    {
        $project = $this->route('project');
        abort_unless($project instanceof Project, 404);
        Gate::authorize('update', $project);

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['root' => ['required', 'string', 'max:1024']];
    }
}
