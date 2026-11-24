<?php

namespace App\Http\Requests\Workspaces;

use App\Domain\Workspaces\Capability;
use App\Domain\Workspaces\WorkspaceRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class InviteMemberRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }

    /** Runs before validation, so a non-member gets 404 even for an invalid body. */
    public function authorize(): bool
    {
        Gate::authorize('perform', [$this->route('workspace'), Capability::ManageMembers]);

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', Rule::enum(WorkspaceRole::class)],
        ];
    }

    public function role(): WorkspaceRole
    {
        return WorkspaceRole::from($this->string('role')->value());
    }
}
