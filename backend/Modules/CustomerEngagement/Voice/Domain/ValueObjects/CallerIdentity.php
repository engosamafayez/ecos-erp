<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Voice\Domain\ValueObjects;

/**
 * Result of CallerIdentityResolver::resolve() — exactly one of Customer/Lead resolved, or
 * neither. Never both; a resolved Customer is preferred and a Lead lookup is skipped once one
 * is found (§13).
 */
final class CallerIdentity
{
    private function __construct(
        public readonly ?string $customerId,
        public readonly ?string $leadId,
    ) {}

    public static function customer(string $customerId): self
    {
        return new self($customerId, null);
    }

    public static function lead(string $leadId): self
    {
        return new self(null, $leadId);
    }

    public static function unknown(): self
    {
        return new self(null, null);
    }

    public function isKnown(): bool
    {
        return $this->customerId !== null || $this->leadId !== null;
    }

    public function isCustomer(): bool
    {
        return $this->customerId !== null;
    }
}
