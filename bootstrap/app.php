<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Check the role before the model is loaded: a customer gets 403 for every id.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: Authorize::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            // A missing id behind a role check must give the same answer as an existing id.
            // Otherwise a user without the role could find out which ids exist.
            $user = $request->user();

            if ($user !== null) {
                foreach ($request->route()?->gatherMiddleware() ?? [] as $middleware) {
                    if (
                        is_string($middleware)
                        && str_starts_with($middleware, 'can:')
                        && ! str_contains($middleware, ',')
                        && Gate::forUser($user)->denies(substr($middleware, 4))
                    ) {
                        return response()->json([
                            'message' => 'You do not have permission to do this.',
                            'data' => null,
                        ], 403);
                    }
                }
            }

            return response()->json([
                'message' => 'Resource not found.',
                'data' => null,
            ], 404);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                    'data' => null,
                ], 401);
            }
        });

        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'You do not have permission to do this.',
                    'data' => null,
                ], 403);
            }
        });
    })->create();
