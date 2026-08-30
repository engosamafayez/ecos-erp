<?php

declare(strict_types=1);

namespace Modules\Inventory\DomainEvents\Events;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Modules\Inventory\DomainEvents\Contracts\DomainEvent;

/**
 * Raised when a product's `allow_negative_stock` policy transitions false → true.
 *
 * Publisher : Product model observer (after DB transaction commits)
 * Trigger   : a product/raw-material update that turns Allow Negative Stock ON
 *
 * TASK-ORDER-PREPARATION-FULFILLABILITY-CONTRACT-001 §6B. Under the order-driven
 * preparation contract a raw material satisfies a recipe when it is physically
 * available OR `allow_negative_stock = true`. Turning the flag ON can therefore make
 * a previously-blocked recipe executable, which must re-open the finished-product
 * orders that were Awaiting Stock because of it. This event carries that business
 * fact to the EXISTING reservation-retry recovery (RetryReservationOnStockAvailableListener),
 * which re-evaluates only the affected orders — no new recovery engine, no polling.
 *
 * Only the false → true direction is published: turning the policy OFF never
 * unblocks anything, so it carries no recovery consequence.
 *
 * Payload contains only IDs (string UUIDs) and scalars — no Eloquent models.
 */
final class ProductNegativeStockEnabled implements DomainEvent
{
    private readonly string $eventId;

    private readonly DateTimeImmutable $occurredAt;

    public function __construct(
        public readonly string $productId,
        public readonly string $companyId,
        public readonly ?int $actorId = null,
    ) {
        $this->eventId = self::generateUuid();
        $this->occurredAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function eventId(): string
    {
        return $this->eventId;
    }

    public function eventName(): string
    {
        return 'inventory.product.negative_stock_enabled';
    }

    public function eventVersion(): int
    {
        return 1;
    }

    public function correlationId(): string
    {
        return $this->eventId;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'event_name' => $this->eventName(),
            'version' => $this->eventVersion(),
            'correlation_id' => $this->correlationId(),
            'occurred_at' => $this->occurredAt->format(DateTimeInterface::ATOM),
            'product_id' => $this->productId,
            'company_id' => $this->companyId,
            'actor_id' => $this->actorId,
        ];
    }

    private static function generateUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return implode('-', [
            bin2hex(substr($bytes, 0, 4)),
            bin2hex(substr($bytes, 4, 2)),
            bin2hex(substr($bytes, 6, 2)),
            bin2hex(substr($bytes, 8, 2)),
            bin2hex(substr($bytes, 10, 6)),
        ]);
    }
}
