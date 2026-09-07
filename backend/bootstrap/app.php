<?php

declare(strict_types=1);

use App\Core\Exceptions\BusinessException;
use App\Core\Responses\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Modules\IAM\Domain\Exceptions\InvalidUserTransitionException;
use Modules\IAM\Domain\Exceptions\RoleLifecycleException;
use Modules\IAM\Domain\Exceptions\RoleTemplateInUseException;
use Modules\IAM\Domain\Exceptions\SystemTemplateImmutableException;
use Modules\IAM\Domain\Exceptions\UnknownTemplatePermissionException;
use Modules\IAM\Domain\Exceptions\UserSecurityRuleException;
use Modules\Inventory\InventoryItems\Domain\Exceptions\InsufficientStockException;
use Modules\Operations\Fulfillment\Domain\Exceptions\WorkflowPreconditionException;
use Modules\POS\Cart\Domain\Exceptions\InvalidCartTransitionException;
use Modules\POS\Receipt\Domain\Exceptions\ReceiptAlreadyVoidedException;
use Modules\POS\Receipt\Domain\Exceptions\ReprintNotAllowedException;
use Modules\POS\Session\Domain\Exceptions\InvalidSessionTransitionException;
use Modules\POS\Shift\Domain\Exceptions\InvalidShiftTransitionException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // TASK-ECOS-COLLABORATION-MEDIA-VOICE-REALTIME-NOTIFICATIONS-SEARCH-003
    // (ADR-044 §1.8). Registers routes/channels.php and applies auth:sanctum
    // to the broadcasting auth endpoint it creates — the same guard every
    // other authenticated API route in this app already uses. Functions
    // today with the default 'log' broadcast driver (BROADCAST_CONNECTION
    // is unchanged); see config/broadcasting.php's docblock for what
    // switching to 'reverb' additionally requires.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Trust reverse proxies: Docker Nginx, Cloudflare, load balancers.
        // TRUSTED_PROXIES=* is safe in Docker because PHP-FPM (port 9000) is
        // only reachable from the internal bridge network, not the public internet.
        // For bare-metal or mixed deployments use CIDRs: TRUSTED_PROXIES=10.0.0.0/8
        $proxies = (string) env('TRUSTED_PROXIES', '*');
        $middleware->trustProxies(
            at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PREFIX,
        );

        // Replace the framework Authenticate with our subclass that returns null
        // from redirectTo() for api/* requests. Without this, the framework calls
        // route('login') as a constructor argument to AuthenticationException —
        // if the route doesn't exist it throws InvalidArgumentException before
        // AuthenticationException is created, bypassing every exception renderer.
        $middleware->alias([
            'auth' => App\Http\Middleware\Authenticate::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Secondary guard: when AuthenticationException reaches the handler with
        // null redirectTo (set by our Authenticate middleware), return 401 JSON
        // rather than relying on the framework's default unauthenticated() path.
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

        // POS domain exceptions — pure domain types that do not extend BusinessException.
        $exceptions->render(function (InvalidSessionTransitionException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), 422);
            }

            return null;
        });

        $exceptions->render(function (InvalidShiftTransitionException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), 422);
            }

            return null;
        });

        $exceptions->render(function (InvalidCartTransitionException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), 422);
            }

            return null;
        });

        $exceptions->render(function (ReceiptAlreadyVoidedException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), 422);
            }

            return null;
        });

        $exceptions->render(function (ReprintNotAllowedException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), 422);
            }

            return null;
        });

        // Fulfillment domain exceptions — workflow guard failures.
        $exceptions->render(function (WorkflowPreconditionException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), 422);
            }

            return null;
        });

        // Inventory domain exceptions — stock reservation failures.
        $exceptions->render(function (InsufficientStockException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), 422);
            }

            return null;
        });

        // IAM domain exceptions (TASK-ECOS-IAM-SECURE-ADMIN-API-002, §19/D6 error contract).
        // 409 = business/state conflict: the request is well-formed and the target real, but the
        // current domain state doesn't permit the operation. 422 = the submitted definition/
        // payload itself is invalid. This is a more specific classification than the platform's
        // existing precedent (POS/Fulfillment lump their own transition exceptions under 422) —
        // deliberately so for IAM's own exception types only; no other module's mapping changes.
        $exceptions->render(function (InvalidUserTransitionException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), 409);
            }

            return null;
        });

        $exceptions->render(function (UserSecurityRuleException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), 409);
            }

            return null;
        });

        $exceptions->render(function (SystemTemplateImmutableException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), 409);
            }

            return null;
        });

        $exceptions->render(function (RoleTemplateInUseException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), 409);
            }

            return null;
        });

        // TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001, §11: Role lifecycle guard
        // failures (system-role immutability, template-backed immutability, still-assigned
        // on delete) — same 409 contract as the sibling IAM exceptions above.
        $exceptions->render(function (RoleLifecycleException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), 409);
            }

            return null;
        });

        $exceptions->render(function (UnknownTemplatePermissionException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error($e->getMessage(), 422, ['unknown_permissions' => $e->unknown]);
            }

            return null;
        });
    })->create();
