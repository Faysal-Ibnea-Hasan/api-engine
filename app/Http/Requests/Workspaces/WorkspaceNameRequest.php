<?php

namespace App\Http\Requests\Workspaces;

use App\Domain\Workspaces\Capability;
use App\Models\Workspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class WorkspaceNameRequest extends FormRequest
{
    /** Rename only (creating needs no workspace). Runs before validation, so non-members get 404. */
    public function authorize(): bool
    {
        $workspace = $this->route('workspace');

        if ($workspace instanceof Workspace) {
            Gate::authorize('perform', [$workspace, Capability::UpdateWorkspace]);
        }

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:100']];
    }
}
