<?php

declare(strict_types=1);

use App\Core\Exceptions\BusinessException;
use App\Core\Responses\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Unauthenticated API requests must return 401 JSON, never a redirect.
        // Without this, Authenticate middleware calls route('login') which does
        // not exist — this project has no web login route. Returning null for
        // non-API requests preserves redirect behaviour for future web routes.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            return null;
        });

        // Render Core business exceptions as the standardized API envelope.
        $exceptions->render(function (BusinessException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), $e->getStatusCode(), $e->getErrors());
            }

            return null;
        });
    })->create();
