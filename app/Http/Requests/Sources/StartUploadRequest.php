<?php

namespace App\Http\Requests\Sources;

use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StartUploadRequest extends FormRequest
{
    /** Runs before validation: another tenant gets 404, not "file too large". */
    public function authorize(): bool
    {
        Gate::authorize('update', $this->project());

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'total_bytes' => ['required', 'integer', 'min:1', 'max:'.(int) config('sources.zip.max_upload_bytes')],
            // Only checked for the extension; the worker verifies the real ZIP structure. Never stored.
            'file_name' => ['required', 'string', 'max:255', 'regex:/\.zip$/i'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $gb = round((int) config('sources.zip.max_upload_bytes') / 1073741824, 1);

        return [
            'total_bytes.max' => __('The archive is larger than :size GB.', ['size' => $gb]),
            'file_name.regex' => __('Upload a .zip archive of your Laravel project.'),
        ];
    }

    public function project(): Project
    {
        $project = $this->route('project');
        abort_unless($project instanceof Project, 404);

        return $project;
    }
}
