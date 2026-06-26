<?php

declare(strict_types=1);

namespace Modules\Inventory\DomainEvents\Events;

use DateTimeImmutable;
use Modules\Inventory\DomainEvents\Contracts\DomainEvent;

/**
 * Raised after reserved stock is physically shipped out of a warehouse.
 *
 * Publisher : ShipStockAction (after DB transaction commits)
 * Trigger   : Fulfilment posting, shipment confirmation
 *
 * Both on_hand and reserved decrease by the shipped quantity.
 */
final class InventoryStockShipped implements DomainEvent
{
    private readonly string $eventId;
    private readonly DateTimeImmutable $occurredAt;

    public function __construct(
        public readonly string  $inventoryItemId,
        public readonly string  $warehouseId,
        public readonly string  $productId,
        public readonly string  $companyId,
        public readonly float   $quantityShipped,
        public readonly float   $onHandBefore,
        public readonly float   $onHandAfter,
        public readonly float   $reservedBefore,
        public readonly float   $reservedAfter,
        public readonly ?string $referenceType = null,
        public readonly ?string $referenceId   = null,
    ) {
        $this->eventId    = self::generateUuid();
        $this->occurredAt = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function eventId(): string
    {
        return $this->eventId;
    }

    public function eventName(): string
    {
        return 'inventory.stock.shipped';
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
            'event_id'          => $this->eventId,
            'event_name'        => $this->eventName(),
            'version'           => $this->eventVersion(),
            'correlation_id'    => $this->correlationId(),
            'occurred_at'       => $this->occurredAt->format(\DateTimeInterface::ATOM),
            'inventory_item_id' => $this->inventoryItemId,
            'warehouse_id'      => $this->warehouseId,
            'product_id'        => $this->productId,
            'company_id'        => $this->companyId,
            'quantity_shipped'  => $this->quantityShipped,
            'on_hand_before'    => $this->onHandBefore,
            'on_hand_after'     => $this->onHandAfter,
            'reserved_before'   => $this->reservedBefore,
            'reserved_after'    => $this->reservedAfter,
            'reference_type'    => $this->referenceType,
            'reference_id'      => $this->referenceId,
        ];
    }

    private static function generateUuid(): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return implode('-', [
            bin2hex(substr($bytes, 0, 4)),
            bin2hex(substr($bytes, 4, 2)),
            bin2hex(substr($bytes, 6, 2)),
            bin2hex(substr($bytes, 8, 2)),
            bin2hex(substr($bytes, 10, 6)),
        ]);
    }
}
