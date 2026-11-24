<?php

namespace WorkflowFixture\App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function rules(): array
    {
        return ['total' => ['required', 'integer', 'min:1'], 'notes' => ['nullable', 'string']];
    }
}
