<?php

namespace App\Console\Commands;

use App\Domain\Sources\FixtureSourceProvider;
use App\Models\Project;
use App\Models\User;
use Illuminate\Console\Command;
use Throwable;

/** P03 walkthrough: import a controlled fixture into a project (local/testing only). */
class ImportFixture extends Command
{
    protected $signature = 'app:import-fixture
        {project : Project ID}
        {--as= : Email of the user to act as (must be able to manage the project)}
        {--fixture=laravel-mini : Fixture name}';

    protected $description = 'Import a controlled source fixture as an immutable snapshot (local/testing only)';

    public function handle(FixtureSourceProvider $provider): int
    {
        $project = Project::query()->find($this->argument('project'));
        $user = User::query()->where('email', (string) $this->option('as'))->first();

        if ($project === null || $user === null || ! $user->can('update', $project)) {
            $this->components->error('Project not found, or that user may not import into it.');

            return self::FAILURE;
        }

        try {
            $result = $provider->import($user, $project, ['fixture' => (string) $this->option('fixture')]);
        } catch (Throwable $e) {
            report($e);
            $this->components->error('Import failed. Details are in the application log.');

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Run %s: %s%s.',
            $result->runId,
            $result->status->value,
            $result->alreadyRunning ? ' (identical import already in progress)' : '',
        ));

        if ($result->status->isActive()) {
            $this->line('  A queue worker on the "imports" queue will finish it (composer dev runs one).');
        }

        return self::SUCCESS;
    }
}
