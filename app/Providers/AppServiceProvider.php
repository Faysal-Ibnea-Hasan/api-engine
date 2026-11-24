<?php

namespace App\Providers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // `composer dev` would otherwise only work the default queue, and imports would sit at "pending".
        DevCommands::artisan('queue:listen --queue=default,imports,discovery,analysis,ai --tries=1 --timeout=0', 'queue');

        $this->configureRateLimits();

        // Ops-only surfaces (/ready, Horizon). The flag is never mass-assignable.
        Gate::define('platform-admin', fn (User $user): bool => $user->is_platform_admin === true);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Named limiters, each with its own counter. A bare `throttle:N,1` keys only on the
     * user, so every throttled route would share one counter: upload pieces would use up
     * the allowance for starting an upload, finishing it, or changing a password.
     */
    private function configureRateLimits(): void
    {
        $by = fn (string $name, Request $request): string => $name.'|'.($request->user()?->getAuthIdentifier() ?? $request->ip());

        RateLimiter::for('upload-start', fn (Request $r) => Limit::perMinute(10)->by($by('upload-start', $r)));
        // A 10 GB archive is ~200 pieces of 50 MB; leave plenty of room for retries.
        RateLimiter::for('upload-chunk', fn (Request $r) => Limit::perMinute(600)->by($by('upload-chunk', $r)));
        RateLimiter::for('upload-complete', fn (Request $r) => Limit::perMinute(30)->by($by('upload-complete', $r)));
        RateLimiter::for('framework-detect', fn (Request $r) => Limit::perMinute(10)->by($by('framework-detect', $r)));
        RateLimiter::for('route-scan', fn (Request $r) => Limit::perMinute(10)->by($by('route-scan', $r)));
        RateLimiter::for('endpoint-analysis', fn (Request $r) => Limit::perMinute(30)->by($by('endpoint-analysis', $r)));
        RateLimiter::for('static-docs', fn (Request $r) => Limit::perMinute(30)->by($by('static-docs', $r)));
        RateLimiter::for('invitations', fn (Request $r) => Limit::perMinute(20)->by($by('invitations', $r)));
    }
}
