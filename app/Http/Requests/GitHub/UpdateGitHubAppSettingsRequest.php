<?php

namespace App\Http\Requests\GitHub;

use App\Models\GitHubAppSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * Platform admins only (others: 404). Secrets are write-only: a blank field keeps the stored
 * value. The private key is checked with OpenSSL and never echoed in errors or logs.
 */
class UpdateGitHubAppSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless(Gate::allows('platform-admin'), 404);

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $stored = GitHubAppSettings::current();

        return [
            'app_id' => ['required', 'string', 'regex:/^\d{1,20}$/'],
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9][a-z0-9-]*$/i'],
            'client_id' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._-]+$/'],
            'client_secret' => [($stored->client_secret ?? '') === '' ? 'required' : 'nullable', 'string', 'max:255'],
            'private_key' => ['nullable', 'string', 'max:16384'],
            'private_key_file' => ['nullable', 'file', 'max:16'], // KB; a PEM is ~2 KB
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'app_id.regex' => __('The App ID is the number shown on the GitHub App page.'),
            'slug.regex' => __('The slug is the last part of github.com/apps/<slug>.'),
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->has('private_key') || $validator->errors()->has('private_key_file')) {
                return;
            }

            $key = $this->privateKey();

            if ($key === null && (GitHubAppSettings::current()->private_key ?? '') === '') {
                $validator->errors()->add('private_key', __('Paste the private key or upload the .pem file.'));
            } elseif ($key !== null && openssl_pkey_get_private($key) === false) {
                while (openssl_error_string() !== false); // OpenSSL's error queue can describe the key: drop it
                $validator->errors()->add('private_key', __('That is not a valid PEM private key. Paste the whole .pem file, including the BEGIN and END lines.'));
            }
        }];
    }

    /** The newly supplied PEM (uploaded file wins over the text box), or null to keep the stored one. */
    public function privateKey(): ?string
    {
        $file = $this->file('private_key_file');
        $text = $file !== null && ! is_array($file) ? (string) file_get_contents($file->getRealPath()) : (string) $this->input('private_key', '');

        return trim($text) === '' ? null : trim($text)."\n";
    }
}
