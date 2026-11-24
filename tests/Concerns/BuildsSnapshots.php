<?php

namespace Tests\Concerns;

use App\Domain\Sources\SnapshotImporter;
use App\Domain\Sources\SourceType;
use App\Models\ProcessingRun;
use App\Models\Project;
use App\Models\SourceSnapshot;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;

trait BuildsSnapshots
{
    /** Imports files as a ready snapshot (sync queue runs detection and discovery too). */
    protected function snapshotFrom(array $files, Project $project, User $actor): SourceSnapshot
    {
        [$run] = app(SnapshotImporter::class)->begin($actor, $project, SourceType::Zip, Str::uuid()->toString(), function (Filesystem $q, string $dir) use ($files) {
            foreach ($files as $path => $content) {
                $q->put("{$dir}/{$path}", $content);
            }
        });

        return ProcessingRun::query()->findOrFail($run->id)->snapshot ?? $this->fail('no snapshot');
    }

    /** A Laravel 11 app skeleton; $routing is the withRouting(...) argument list. */
    protected function laravel11(string $routing = "api: __DIR__.'/../routes/api.php', web: __DIR__.'/../routes/web.php'", string $prefix = ''): array
    {
        return [
            "{$prefix}composer.json" => json_encode(['require' => ['laravel/framework' => '^11.0']]),
            "{$prefix}composer.lock" => json_encode(['packages' => [['name' => 'laravel/framework', 'version' => 'v11.30.0']]]),
            "{$prefix}artisan" => "<?php\n",
            "{$prefix}bootstrap/app.php" => "<?php\n\nuse Illuminate\\Foundation\\Application;\n\nreturn Application::configure(basePath: dirname(__DIR__))\n    ->withRouting({$routing})\n    ->create();\n",
            "{$prefix}routes/web.php" => "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('/', fn () => view('welcome'));\n",
        ];
    }
}
