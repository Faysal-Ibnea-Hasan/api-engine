<?php

namespace App\Http\Requests\Sources;

use App\Models\Project;
use App\Models\UploadSession;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** Resume, send a piece, or finish an upload. Only its own uploader, who must still be allowed to change the project. */
class UploadSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('update', $this->project());
        abort_unless($this->session()->created_by === $this->user()?->id, 404);

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->isMethod('PUT') ? ['offset' => ['required', 'integer', 'min:0']] : [];
    }

    public function project(): Project
    {
        $project = $this->route('project');
        abort_unless($project instanceof Project, 404);

        return $project;
    }

    public function session(): UploadSession
    {
        $upload = $this->route('upload');
        abort_unless($upload instanceof UploadSession, 404);

        return $upload;
    }
}
