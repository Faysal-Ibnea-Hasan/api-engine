<?php

namespace App\Http\Controllers\Framework;

use App\Domain\Framework\LaravelDetector;
use App\Models\FrameworkDetection;

/** The only shape detections leave the server in. File-level evidence only for roles that may see source. */
final class DetectionPresenter
{
    /** @return array<string, mixed> */
    public static function present(FrameworkDetection $detection, bool $withEvidence): array
    {
        $result = $detection->result;
        $roots = is_array($result['roots'] ?? null) ? $result['roots'] : [];

        return [
            'id' => $detection->id,
            'status' => $detection->status,
            'framework' => $result['framework'] ?? null,
            'roots' => array_map(fn (array $root) => [
                'path' => $root['path'],
                'declaredVersion' => $root['declared_version_constraint'] ?? null,
                'lockedVersion' => $root['locked_framework_version'] ?? null,
                'phpConstraint' => $root['php_constraint'] ?? null,
                'routeLayout' => $root['route_layout'] ?? 'unknown',
                'routeFiles' => $withEvidence ? ($root['route_files'] ?? []) : null,
                'routeFileCount' => count($root['route_files'] ?? []),
                'modules' => $withEvidence ? ($root['modules'] ?? []) : [],
                'confidence' => $root['confidence'] ?? 'medium',
                'evidence' => $withEvidence ? ($root['evidence'] ?? []) : null,
            ], $roots),
            // Warnings name files (e.g. "backend/composer.json could not be parsed"): source-access roles only.
            'warnings' => $withEvidence ? ($result['warnings'] ?? []) : [],
            'hiddenWarningCount' => $withEvidence ? 0 : count($result['warnings'] ?? []),
            'selectedRoot' => $detection->selected_root,
            'selectionSource' => $detection->root_selection_source,
            'selectedAt' => $detection->root_selected_at?->toIso8601String(),
            'detectorVersion' => $detection->detector_version,
            'outdated' => $detection->detector_version !== LaravelDetector::VERSION,
            'detectedAt' => $detection->created_at?->toIso8601String(),
        ];
    }
}
