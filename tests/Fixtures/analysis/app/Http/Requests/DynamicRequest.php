<?php

namespace Fixture\App\Http\Requests;

use Fixture\App\Rules\Uppercase;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DynamicRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', Rule::unique('users')->ignore($this->route('user'))],
            'code' => ['required', new Uppercase],
            'token' => ['required', function ($attribute, $value, $fail) {}],
            'role' => [Rule::requiredIf(fn () => $this->user() !== null), 'string'],
            $this->dynamicKey() => 'required',
        ];
    }
}
