<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Domain\Events;

/**
 * An Order's CITY or GOVERNORATE was changed by an operator.
 *
 * ┌─ WHY THIS EVENT EXISTS ──────────────────────────────────────────────────┐
 * │ `orders.logistics_city_id` is the ONLY field OrderZoneResolver reads, and  │
 * │ before this event nothing wrote it on an edit path — only OrderCityBinder's│
 * │ NULL-only sweep and a one-time migration backfill. So an operator could    │
 * │ change an Order's city and the derived city id, and therefore its          │
 * │ Distribution zone, would keep pointing at the OLD city forever.            │
 * │                                                                          │
 * │ Measured: ORD-00007 read `area = 'Obour City'` while `city = 'Maadi'` and  │
 * │ `logistics_city_id = 2` (Maadi), so Distribution faithfully rendered Maadi │
 * │ — stale, but not Distribution's fault.                                    │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ORDERS ANNOUNCES; IT DOES NOT REACH. This event is the boundary. Orders does
 * not know that Geography resolves city text, does not know that Distribution
 * holds a zone, and references neither module — it states a fact about its own
 * aggregate and stops. The subscriber (Distribution) owns the reaction, calls
 * Geography for the city and writes only its own assignment.
 *
 * ONLY AN EXPLICIT EDIT DISPATCHES THIS. It is not raised by a sweep, a backfill
 * or a status transition, which is what keeps the approved contract intact:
 * *"a later geography edit cannot silently move an Order that operators have
 * already planned around"* governs the AUTOMATIC binder, and this is the
 * operator acting deliberately on one Order.
 *
 * The previous values travel with it so a subscriber can log what actually
 * changed without re-reading a row that has already moved on.
 */
final class OrderGeographyChanged
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $companyId,
        /** The new free-text city, exactly as stored. Null is a real value here. */
        public readonly ?string $city,
        public readonly ?string $governorate,
        public readonly ?string $previousCity,
        public readonly ?string $previousGovernorate,
        /** Who made the edit, for the audit trail on the resulting re-zone. */
        public readonly ?int $actorId = null,
    ) {}

    /** Did the city text itself change? A governorate-only edit can still re-narrow it. */
    public function cityChanged(): bool
    {
        return $this->normalise($this->city) !== $this->normalise($this->previousCity);
    }

    private function normalise(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }
}
