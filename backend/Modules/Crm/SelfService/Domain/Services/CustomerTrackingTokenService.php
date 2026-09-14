<?php

declare(strict_types=1);

namespace Modules\Crm\SelfService\Domain\Services;

use Illuminate\Support\Str;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Crm\Customers\Domain\Models\Customer;
use Modules\Crm\SelfService\Domain\Models\CustomerTrackingToken;

/**
 * §5/§6/§33 — issues and resolves guest order-tracking sessions. The raw token is visible
 * exactly once, at issuance, as an ephemeral (non-persisted) attribute on the returned model —
 * every subsequent lookup goes through the SHA-256 hash, never the plaintext.
 *
 * Fixed 7-day expiry (approved decision #3): `last_used_at` is stamped on every successful
 * resolve() call but is informational only — it is never read back to extend `expires_at`.
 */
final class CustomerTrackingTokenService
{
    private const TOKEN_TTL_DAYS = 7;

    /** The one and only place the raw token is generated and briefly held. */
    public function issue(Customer $customer, Order $order): CustomerTrackingToken
    {
        $raw = Str::random(64);

        $token = CustomerTrackingToken::create([
            'customer_id' => $customer->id,
            'company_id' => $order->company_id,
            'brand_id' => $order->channel?->brand_id,
            // Approved decision #3 / architecture §26 default: order-scoped, never a
            // customer-wide token that would silently expose every historical order.
            'order_id' => $order->id,
            'channel' => 'email',
            'token_hash' => self::hash($raw),
            'expires_at' => now()->addDays(self::TOKEN_TTL_DAYS),
        ]);

        $token->setAttribute('plain_text_token', $raw);

        return $token;
    }

    /**
     * Resolves a raw bearer token to its (live, non-expired, non-revoked) row, or null.
     * NEVER trusts customer_id/company_id/brand_id/order_id from anywhere but this row.
     */
    public function resolve(string $rawToken): ?CustomerTrackingToken
    {
        $token = CustomerTrackingToken::query()->where('token_hash', self::hash($rawToken))->first();

        if ($token === null || ! $token->isLive()) {
            return null;
        }

        $token->update(['last_used_at' => now()]);

        return $token;
    }

    public function revoke(CustomerTrackingToken $token): void
    {
        $token->update(['revoked_at' => now()]);
    }

    private static function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }
}
