<?php

namespace App\Http\Requests\Ai;

use App\Models\AiProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Platform admins only (others: 404). The API key is write-only: a blank field keeps the stored
 * one, and validation messages never echo it.
 */
class UpdateAiProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless(Gate::allows('platform-admin'), 404);

        return true;
    }

    public function provider(): AiProvider
    {
        return AiProvider::for((string) $this->route('provider'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'api_key' => ['nullable', 'string', 'min:20', 'max:500', 'regex:/^\S+$/'],
            'model' => ['nullable', 'string', Rule::in(array_column($this->provider()->available_models ?? [], 'id'))],
            'effort' => ['in:low,medium,high'],
            'enabled' => ['boolean'],
            'is_default' => ['boolean'],
            'limit_tokens' => ['nullable', 'integer', 'min:1000', 'max:1000000000'],
            'limit_period' => ['in:day,month'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'api_key.min' => __('That key is too short. Paste the whole API key from the provider.'),
            'api_key.max' => __('That key is too long. Paste only the API key.'),
            'api_key.regex' => __('An API key has no spaces or line breaks. Paste only the key.'),
            'model.in' => __('Pick a model from the list. Test the connection to refresh it.'),
            'limit_tokens.min' => __('Set a limit of at least 1,000 tokens, or leave it empty for no limit.'),
            'limit_tokens.max' => __('Set a limit of at most 1,000,000,000 tokens.'),
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($this->filled('api_key') || $this->provider()->hasKey()) {
                return;
            }
            foreach (['enabled' => 'enable', 'is_default' => 'make it the default'] as $field => $action) {
                if ($this->boolean($field)) {
                    $validator->errors()->add($field, __('Add an API key before you :action.', ['action' => $action]));
                }
            }
        }];
    }
}
