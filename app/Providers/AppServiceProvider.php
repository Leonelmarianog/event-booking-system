<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

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
        $this->configureRateLimiting();
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
     * Configure the named rate limiters of the application.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('event-writes', fn (Request $request): Limit => Limit::perMinute(20)
            ->by((string) $request->user()?->id)
            ->response(function (Request $request, array $headers): Response {
                if (! $request->header('X-Inertia')) {
                    return response('Too Many Attempts.', 429, $headers);
                }

                Inertia::flash('toast', [
                    'type' => 'error',
                    'message' => __('Too many changes. Wait one minute and try again.'),
                ]);

                return back()->withHeaders($headers);
            }));
    }
}
