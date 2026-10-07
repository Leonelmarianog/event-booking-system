<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Closure;
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
            ->response($this->tooManyRequests(__('Too many changes. Wait one minute and try again.'))));

        RateLimiter::for('bookings', fn (Request $request): Limit => Limit::perMinute(10)
            ->by((string) $request->user()?->id)
            ->response($this->tooManyRequests(__('Too many booking requests. Wait one minute and try again.'))));
    }

    /**
     * The response over a limit: a redirect back with an error toast for Inertia requests,
     * and a plain 429 for all other requests.
     *
     * @return Closure(Request, array<string, string>): Response
     */
    protected function tooManyRequests(string $message): Closure
    {
        return function (Request $request, array $headers) use ($message): Response {
            if (! $request->header('X-Inertia')) {
                return response('Too Many Attempts.', 429, $headers);
            }

            Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

            return back()->withHeaders($headers);
        };
    }
}
