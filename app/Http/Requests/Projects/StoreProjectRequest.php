<?php

namespace App\Http\Requests\Projects;

use App\Domain\Workspaces\Capability;
use App\Models\Workspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** Creates a draft in the user's current workspace from the wizard's Details step. */
class StoreProjectRequest extends FormRequest
{
    use ProjectDetailsRules;

    public function authorize(): bool
    {
        Gate::authorize('perform', [$this->workspace(), Capability::ManageProjects]);

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->detailsRules($this->workspace()->id, null, slugRequired: false);
    }

    public function workspace(): Workspace
    {
        $membership = $this->user()?->currentMembership();
        abort_if($membership === null, 404);

        return $membership->workspace;
    }
}
