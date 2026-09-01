<?php

declare(strict_types=1);

namespace Modules\Sales\Customers\Application\Actions;

use Modules\Sales\Customers\Domain\Models\CustomerAddress;

/**
 * Canonical write path for upserting a customer's default delivery address.
 *
 * Callers outside this module (e.g. Commerce\Orders) must go through this
 * action rather than writing to CustomerAddress directly — see
 * TASK-ECOS-COMMERCE-ORDERS-CUSTOMERS-CLOSURE-001 (C1). Only an explicit,
 * caller-gated intent should reach this action; it performs no gating of
 * its own.
 */
final class SyncCustomerDefaultAddressAction
{
    /**
     * Upserts the customer's default address using the given non-null fields.
     * Null values are skipped so a partial payload never blanks out previously
     * stored data. No-ops if nothing usable was supplied.
     *
     * @param  array<string, mixed>  $fields  governorate, city, area, address_line,
     *                                        building, floor, apartment, landmark, address_notes, google_maps_lat,
     *                                        google_maps_lng, google_maps_url, location_source
     */
    public function execute(string $customerId, array $fields): void
    {
        $updates = array_filter($fields, static fn ($v) => $v !== null);

        if (empty($updates)) {
            return;
        }

        $existing = CustomerAddress::where('customer_id', $customerId)
            ->where('is_default', true)
            ->first();

        if ($existing !== null) {
            $existing->update($updates);

            return;
        }

        // customer_addresses.governorate is NOT NULL with no default — creating a
        // fresh default address without one would fail the insert. Skip rather
        // than crash when the triggering payload didn't carry a governorate.
        if (! array_key_exists('governorate', $updates)) {
            return;
        }

        CustomerAddress::create(array_merge($updates, [
            'customer_id' => $customerId,
            'label' => 'Default',
            'is_default' => true,
        ]));
    }
}
