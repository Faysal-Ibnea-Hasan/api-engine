<?php

namespace Fixture\App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function rules(): array
    {
        $rules = ['name' => 'required|string|max:255'];
        $rules['email'] = 'required|email';

        if ($this->isMethod('PATCH')) {
            $rules['bio'] = 'nullable|string';
        }

        return array_merge($rules, ['avatar' => 'nullable|image']);
    }
}
