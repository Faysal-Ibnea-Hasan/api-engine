<?php

namespace App\Domain\Projects;

/**
 * The wizard's choices. "available" is what the server accepts today; the rest are
 * shown as "not yet supported" so the UI never promises what the platform can't do.
 */
final class ProjectOptions
{
    /** @return array<string, array<int, array{value: string, label: string, available: bool, note?: string}>> */
    public static function all(): array
    {
        return [
            'source_preference' => [
                ['value' => 'zip', 'label' => 'ZIP upload', 'available' => true, 'note' => 'Uploading arrives with ZIP import (P04). For now this only records your preference.'],
                ['value' => 'git', 'label' => 'Git repository', 'available' => true, 'note' => 'Only a preference until Git import (P09) is enabled. No credentials are asked for or stored.'],
            ],
            'framework' => [
                ['value' => 'laravel', 'label' => 'Laravel', 'available' => true],
            ],
            'analysis_mode' => [
                ['value' => 'static', 'label' => 'Static analysis', 'available' => true, 'note' => 'Reads source code without running it.'],
                ['value' => 'static_with_ai', 'label' => 'Static + AI explanations', 'available' => false, 'note' => 'Not yet supported: needs AI settings (P11) and AI enhancement (P12).'],
            ],
            'documentation_mode' => [
                ['value' => 'on_demand', 'label' => 'Generate on demand', 'available' => true, 'note' => 'You pick endpoints to document.'],
                ['value' => 'all_endpoints', 'label' => 'Generate for every endpoint', 'available' => true],
            ],
        ];
    }

    /** @return list<string> */
    public static function accepted(string $field): array
    {
        return array_values(array_map(
            fn (array $option) => $option['value'],
            array_filter(self::all()[$field], fn (array $option) => $option['available']),
        ));
    }
}
