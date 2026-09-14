<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Voice\Application\Services;

use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\CustomerEngagement\Voice\Domain\Enums\CallerVerificationLevel;
use Modules\CustomerEngagement\Voice\Domain\Models\Call;

/**
 * TASK-ECOS-V1.1-CRM-03-OMNICHANNEL-VOICE-BACKEND-IMPLEMENTATION-015 §14 — the smallest safe V1
 * verification method the architecture report approved: an order-number-or-equivalent fact the
 * backend independently confirms, never caller-ID alone and never a custom OTP/authentication
 * universe invented for this (none exists elsewhere in the codebase to reuse — confirmed in the
 * architecture reconciliation).
 */
final class CallerVerificationService
{
    public function verifyByOrderReference(string $companyId, string $customerId, string $orderReference): bool
    {
        $matches = Order::query()
            ->where('company_id', $companyId)
            ->where('customer_id', $customerId)
            ->where('order_number', $orderReference)
            ->exists();

        return $matches;
    }

    public function levelOf(Call $call): CallerVerificationLevel
    {
        $stored = $call->metadata['verification_level'] ?? null;

        return CallerVerificationLevel::tryFrom((string) $stored) ?? CallerVerificationLevel::Unverified;
    }

    public function markVerified(Call $call): void
    {
        $call->metadata = [...($call->metadata ?? []), 'verification_level' => CallerVerificationLevel::OrderCorroborated->value];
    }
}
