<?php

namespace App\Domain\Framework;

use App\Contracts\Analysis\FrameworkAnalyzer;
use App\Contracts\Analysis\FrameworkDetectionResult;
use App\Domain\Sources\SnapshotReader;
use App\Models\SourceSnapshot;

/**
 * Detects Laravel applications in a snapshot from composer metadata and indicator files.
 * Text parsing only: no Composer, no artisan, no include. Deterministic for a given
 * snapshot, so results are stored per (snapshot, version).
 */
final class LaravelDetector implements FrameworkAnalyzer
{
    public const VERSION = 'laravel-detector-v1';

    /** Bound the work a crafted archive can cause (each candidate costs a few reads). */
    private const MAX_CANDIDATES = 50;

    public function __construct(private readonly SnapshotReader $reader) {}

    public function version(): string
    {
        return self::VERSION;
    }

    public function detect(SourceSnapshot $snapshot): FrameworkDetectionResult
    {
        $composerFiles = $this->reader->paths($snapshot, like: '%composer.json');
        $composerFiles = array_values(array_filter($composerFiles, fn ($p) => basename($p) === 'composer.json'));

        $warnings = [];
        if (count($composerFiles) > self::MAX_CANDIDATES) {
            // Shallowest first: real application roots sit near the top.
            usort($composerFiles, fn ($a, $b) => substr_count($a, '/') <=> substr_count($b, '/') ?: strcmp($a, $b));
            $composerFiles = array_slice($composerFiles, 0, self::MAX_CANDIDATES);
            $warnings[] = 'More than '.self::MAX_CANDIDATES.' composer.json files were found; only the '.self::MAX_CANDIDATES.' closest to the archive root were inspected.';
        }

        $roots = [];
        $evidence = [];
        $sawLumen = false;
        $sawPhpProject = false;

        foreach ($composerFiles as $composerPath) {
            $dir = dirname($composerPath);
            $candidate = $this->inspect($snapshot, $dir, $composerPath);

            if ($candidate === null) {
                $sawPhpProject = true;

                continue;
            }

            if ($candidate['framework'] === 'lumen') {
                $sawLumen = true;
                $evidence[] = $composerPath;

                continue;
            }

            $roots[] = $candidate;
            $evidence = [...$evidence, ...$candidate['evidence']];
        }

        $apps = array_values(array_filter($roots, fn ($r) => $r['kind'] === 'application'));
        $packages = array_values(array_filter($roots, fn ($r) => $r['kind'] === 'package'));

        foreach ($packages as $package) {
            $warnings[] = "Ignored {$this->label($package['path'])}: it requires Laravel but has no artisan or bootstrap/app.php, so it looks like a package, not an application.";
        }

        foreach ($apps as $app) {
            $warnings = [...$warnings, ...$app['warnings']];
        }

        [$status, $recommended] = match (true) {
            $apps === [] && $sawLumen => ['unsupported', null],
            $apps === [] && ($sawPhpProject || $packages !== []) => ['unsupported', null],
            $apps === [] => ['not_found', null],
            count($apps) > 1 => ['partial', null],
            $apps[0]['confidence'] === 'high' => ['detected', $apps[0]['path']],
            default => ['partial', $apps[0]['path']],
        };

        if ($sawLumen) {
            $warnings[] = 'Lumen is not supported; only full Laravel applications are.';
        }
        if ($status === 'unsupported' && ! $sawLumen) {
            $warnings[] = 'This looks like a PHP project, but no Laravel application was found.';
        }
        if ($status === 'not_found') {
            $warnings[] = 'No composer.json was found, so this does not look like a PHP application.';
        }
        if (count($apps) > 1) {
            $warnings[] = 'Several Laravel applications were found. Choose which one to document.';
        }

        return new FrameworkDetectionResult(
            status: $status,
            framework: $apps === [] ? null : 'laravel',
            roots: array_map(fn ($a) => array_diff_key($a, ['evidence' => 1, 'warnings' => 1, 'kind' => 1, 'framework' => 1]) + ['evidence' => $a['evidence']], $apps),
            recommendedRoot: $recommended,
            warnings: array_values(array_unique($warnings)),
            evidence: array_values(array_unique($evidence)),
            analyzerVersion: self::VERSION,
            snapshotId: $snapshot->id,
        );
    }

    /** @return array<string, mixed>|null null when this composer.json isn't Laravel at all */
    private function inspect(SourceSnapshot $snapshot, string $dir, string $composerPath): ?array
    {
        $at = fn (string $relative) => $dir === '.' ? $relative : "{$dir}/{$relative}";
        $evidence = [$composerPath];
        $warnings = [];

        $composer = json_decode((string) $this->reader->read($snapshot, $composerPath), true);
        $hasArtisan = $this->reader->exists($snapshot, $at('artisan'));
        $hasBootstrap = $this->reader->exists($snapshot, $at('bootstrap/app.php'));

        if (! is_array($composer)) {
            // Can't read the metadata, but the layout may still say "Laravel app".
            if (! $hasArtisan && ! $hasBootstrap) {
                return null;
            }
            $warnings[] = "{$composerPath} could not be parsed as JSON, so the Laravel version is unknown.";
            $composer = [];
        }

        $require = is_array($composer['require'] ?? null) ? $composer['require'] : [];

        if (isset($require['laravel/lumen-framework'])) {
            return ['framework' => 'lumen'];
        }

        $declared = is_string($require['laravel/framework'] ?? null) ? $require['laravel/framework'] : null;

        if ($declared === null && $composer !== []) {
            return null; // a PHP project that doesn't require Laravel
        }

        $kind = $hasArtisan || $hasBootstrap ? 'application' : 'package';
        $hasArtisan && $evidence[] = $at('artisan');
        $hasBootstrap && $evidence[] = $at('bootstrap/app.php');

        $locked = null;
        $lockPath = $at('composer.lock');
        $lock = $this->reader->read($snapshot, $lockPath);
        if ($lock === null) {
            $warnings[] = "{$this->label($dir)}: no composer.lock, so only the declared constraint is known, not the installed version.";
        } else {
            $locked = $this->lockedVersion($lock);
            $locked !== null ? $evidence[] = $lockPath : $warnings[] = "{$lockPath} does not list laravel/framework.";
        }

        $routeFiles = array_values(array_filter(
            $this->reader->paths($snapshot, $at('routes'), like: '%.php'),
            fn ($p) => dirname($p) === $at('routes'),
        ));
        $evidence = [...$evidence, ...$routeFiles];

        $layout = 'unknown';
        if ($this->reader->exists($snapshot, $at('app/Providers/RouteServiceProvider.php'))) {
            $layout = 'route_service_provider';
            $evidence[] = $at('app/Providers/RouteServiceProvider.php');
        } elseif ($hasBootstrap && str_contains((string) $this->reader->read($snapshot, $at('bootstrap/app.php')), 'withRouting')) {
            $layout = 'bootstrap_app';
        }

        $modules = [];
        foreach ($this->reader->paths($snapshot, $at('Modules'), like: '%/module.json') as $moduleJson) {
            if (dirname($moduleJson, 2) === $at('Modules')) {
                $modules[] = basename(dirname($moduleJson));
            }
        }

        if ($kind === 'application' && $routeFiles === []) {
            $warnings[] = "{$this->label($dir)}: no route files in routes/, so route discovery may find nothing.";
        }

        $confidence = $kind === 'application' && $hasArtisan && $hasBootstrap && $declared !== null && $routeFiles !== []
            ? 'high'
            : 'medium';

        return [
            'kind' => $kind,
            'framework' => 'laravel',
            'path' => $dir,
            'declared_version_constraint' => $declared,
            'locked_framework_version' => $locked,
            'php_constraint' => is_string($require['php'] ?? null) ? $require['php'] : null,
            'route_layout' => $layout,
            'route_files' => $routeFiles,
            'modules' => $modules,
            'confidence' => $confidence,
            'evidence' => $evidence,
            'warnings' => $warnings,
        ];
    }

    private function lockedVersion(string $lock): ?string
    {
        $data = json_decode($lock, true);

        foreach (['packages', 'packages-dev'] as $section) {
            foreach (is_array($data[$section] ?? null) ? $data[$section] : [] as $package) {
                if (is_array($package) && ($package['name'] ?? null) === 'laravel/framework' && is_string($package['version'] ?? null)) {
                    return ltrim($package['version'], 'v');
                }
            }
        }

        return null;
    }

    private function label(string $dir): string
    {
        return $dir === '.' ? 'the archive root' : "{$dir}/";
    }
}
