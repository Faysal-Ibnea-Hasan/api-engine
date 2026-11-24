<?php

namespace Fixture\App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => 'required|integer|exists:customers,id',
            'note' => ['nullable', 'string', 'max:500'],
            'coupon' => 'sometimes|string',
            'type' => ['required', Rule::in(['delivery', 'pickup'])],
            'address' => 'required_if:type,delivery|string',
            'items' => 'required|array|min:1',
            'items.*.id' => ['required', 'integer'],
            'items.*.qty' => 'required|integer|between:1,99',
            'attachment' => 'nullable|file|mimes:jpg,png|max:2048',
            'email' => 'required|email|exists:users,email',
            'scheduled_at' => 'nullable|date|after:now',
        ];
    }
}
