<?php

use App\Exceptions\Domain\DomainException;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LogOutDeletedUser;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance']);

        $middleware->web(append: [
            LogOutDeletedUser::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Run these before the route model binding, so that a 404 for a missing model
        // also gets the user, the appearance and the shared props of the layout.
        foreach ([LogOutDeletedUser::class, HandleAppearance::class, HandleInertiaRequests::class] as $class) {
            $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: $class);
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (DomainException $exception, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }

            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            $field = $exception->field();

            return $field === null
                ? back()
                : back()->withErrors([$field => $exception->getMessage()]);
        });

        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            if ($response->getStatusCode() !== 404
                || $request->route() === null
                || $request->is('api/*')
                || $request->expectsJson()) {
                return $response;
            }

            $isEvent = $request->is('events/*');

            return Inertia::render('errors/not-found', [
                'title' => $isEvent ? __('Event not found') : __('Page not found'),
                'message' => $isEvent
                    ? __('This event does not exist, or you cannot see it.')
                    : __('This page does not exist, or you cannot see it.'),
            ])->toResponse($request)->setStatusCode(404);
        });
    })->create();
