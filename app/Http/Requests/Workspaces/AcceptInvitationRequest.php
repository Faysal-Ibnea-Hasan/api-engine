<?php

namespace App\Http\Requests\Workspaces;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** Only used when the invitee has no account yet. */
class AcceptInvitationRequest extends FormRequest
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => $this->nameRules(),
            'password' => $this->passwordRules(),
        ];
    }
}
