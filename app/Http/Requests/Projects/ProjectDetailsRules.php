<?php

namespace App\Http\Requests\Projects;

use App\Domain\Projects\ProjectVisibility;
use App\Models\Project;
use Illuminate\Validation\Rule;

trait ProjectDetailsRules
{
    /** @return array<string, array<mixed>> */
    protected function detailsRules(string $workspaceId, ?Project $project, bool $slugRequired): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => [
                $slugRequired ? 'required' : 'nullable',
                'string',
                'max:80',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('projects', 'slug')->where('workspace_id', $workspaceId)->ignore($project?->id),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'visibility' => ['required', Rule::enum(ProjectVisibility::class)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'slug.regex' => __('Use lowercase letters, numbers and single dashes only.'),
            'slug.unique' => __('Another project in this workspace already uses this slug.'),
        ];
    }
}
