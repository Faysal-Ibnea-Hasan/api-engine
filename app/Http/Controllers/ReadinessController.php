<?php

namespace App\Http\Controllers;

use App\Models\GitHubAppSettings;
use App\Support\Features;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Readiness: can this instance serve real work? Reports only ok/fail per
 * dependency; failure details go to the log, never to the response.
 */
class ReadinessController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::select('select 1')),
            'queue' => $this->check(fn () => Queue::connection()->size()),
            'storage' => $this->check(function () {
                $disk = Storage::disk('snapshots');
                $disk->put('.ready-probe', 'ok');
                $disk->delete('.ready-probe');
            }),
        ];

        $ready = ! in_array('fail', $checks, true);
        $body = ['status' => $ready ? 'ready' : 'unavailable', 'checks' => $checks];

        // Whether the GitHub App is set up, nothing more: never its IDs, secret or key. Missing settings only disable GitHub import.
        if (Features::enabled('git_import')) {
            $body['github_configured'] = rescue(fn () => GitHubAppSettings::configured(), false, report: false);
        }

        return response()->json(
            $body,
            $ready ? 200 : 503,
        );
    }

    private function check(callable $probe): string
    {
        try {
            $probe();

            return 'ok';
        } catch (Throwable $e) {
            report($e);

            return 'fail';
        }
    }
}
