<?php

namespace App\Http\Requests\GitHub;

use App\Domain\Workspaces\Capability;
use App\Models\Workspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** Owners and Maintainers (ManageIntegrations). Non-members get 404. */
class StartGitHubConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('perform', [$this->workspace(), Capability::ManageIntegrations]);

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }

    public function workspace(): Workspace
    {
        $workspace = $this->route('workspace');
        abort_unless($workspace instanceof Workspace, 404);

        return $workspace;
    }
}
