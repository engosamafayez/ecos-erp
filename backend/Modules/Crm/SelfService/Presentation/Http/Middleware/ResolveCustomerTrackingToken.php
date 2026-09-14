<?php

declare(strict_types=1);

namespace Modules\Crm\SelfService\Presentation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Crm\SelfService\Domain\Services\CustomerTrackingTokenService;
use Symfony\Component\HttpFoundation\Response;

/**
 * §6 — the ONE canonical resolver every tokenized `track/*` route depends on. Never accepts
 * customer_id/company_id/brand_id/order_id from the request body or query string as an
 * authorization claim — those always come from the resolved CustomerTrackingToken row, attached
 * here for controllers to read via `$request->attributes->get('customer_tracking_token')`.
 */
final class ResolveCustomerTrackingToken
{
    public function __construct(private readonly CustomerTrackingTokenService $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $raw = $request->bearerToken();

        if ($raw === null || $raw === '') {
            return response()->json(['message' => 'A tracking session is required.'], 401);
        }

        $token = $this->tokens->resolve($raw);

        if ($token === null) {
            return response()->json(['message' => 'This tracking session is invalid, expired, or has been revoked.'], 401);
        }

        $request->attributes->set('customer_tracking_token', $token);

        return $next($request);
    }
}
