<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\File;

trait AnalyzesFixtures
{
    /** The analysis fixture app (tests/Fixtures/analysis) as snapshot files, with a composer.json for its namespace. */
    protected function fixtureApp(array $extra = []): array
    {
        $files = [
            'composer.json' => json_encode(['require' => ['laravel/framework' => '^11.0', 'laravel/sanctum' => '^4.0'], 'autoload' => ['psr-4' => ['Fixture\\App\\' => 'app/']]]),
            'config/auth.php' => "<?php\nreturn [\n    'defaults' => ['guard' => 'web'],\n    'guards' => [\n        'web' => ['driver' => 'session', 'provider' => 'users'],\n        'api' => ['driver' => 'passport', 'provider' => 'users'],\n    ],\n];\n",
            'config/response.php' => "<?php\nreturn [\n    'unprocessable_entity_422' => [\n        'identical_code' => 'unprocessable_entity_422',\n        'message' => 'Validation failed',\n        'http_response_code' => 422,\n    ],\n];\n",
            'composer.lock' => json_encode(['packages' => [['name' => 'laravel/framework', 'version' => 'v11.30.0']]]),
            'artisan' => "<?php\n",
            'bootstrap/app.php' => "<?php\nreturn Application::configure(basePath: dirname(__DIR__))->withRouting(api: __DIR__.'/../routes/api.php')->create();\n",
        ];

        foreach (File::allFiles(base_path('tests/Fixtures/analysis/app')) as $file) {
            $files['app/'.$file->getRelativePathname()] = $file->getContents();
        }

        return $extra + $files;
    }
}
