<?php

declare(strict_types=1);

namespace Modules\Logistics\Drivers\Domain\Services;

use Modules\Logistics\Drivers\Domain\Exceptions\FleetAssignmentException;
use Modules\Logistics\Drivers\Domain\Models\Driver;
use Modules\Logistics\Vehicles\Domain\Models\Vehicle;

/**
 * VP-1 / D1 + D2 — the ONE place a client-supplied fleet reference becomes a
 * canonical entity.
 *
 * WHY THIS EXISTS
 * ---------------
 * `Operations\Loading` types its vehicle_id / driver_id as char(36) and
 * validates them as uuids, while the canonical identities in
 * `logistics_vehicles` / `logistics_drivers` are bigint. D1-C resolves that by
 * keeping the uuid contract and resolving it here, rather than duplicating the
 * registry or flipping a primary key.
 *
 * WHY IT IS NOT A SECOND SOURCE OF TRUTH
 * --------------------------------------
 * `id` and `uuid` are two addresses of ONE row in ONE table. The uuid is
 * unique-indexed and generated at insert by the model itself, so no value can
 * exist that does not belong to exactly one vehicle/driver, and only
 * `Modules\Logistics\Vehicles` / `...\Drivers` can create one. This is a lookup,
 * not a registry — which is exactly why a uuid↔bigint MAPPING TABLE was rejected:
 * that would create a second record able to disagree.
 *
 * WHY RESOLUTION MUST GO THROUGH THE MODEL (S-1, S-2, S-4, S-5)
 * ------------------------------------------------------------
 * A raw-table `exists:` rule runs on the QUERY BUILDER and therefore bypasses
 * the Eloquent global tenant scope entirely. It proves a row exists somewhere in
 * the table; it says nothing about whose it is. Every method here resolves
 * through the model so the `tenant` scope applies, and a foreign-tenant
 * reference comes back as "not found" rather than as someone else's vehicle.
 *
 * Callers therefore get a uniform failure: a reference that is absent, archived
 * or owned by another company is indistinguishable from the outside, which is
 * what stops the endpoint from being used to probe for foreign ids.
 */
class FleetIdentityResolver
{
    /**
     * Resolve a client-supplied vehicle reference to the canonical Vehicle.
     *
     * Accepts either the cross-module uuid (the Operations contract) or the
     * bigint id (the intra-Logistics contract), because both are addresses of the
     * same row and callers on both sides of the boundary use this one method.
     */
    public function vehicle(string $reference): Vehicle
    {
        $vehicle = $this->findVehicle($reference);

        if ($vehicle === null) {
            throw FleetAssignmentException::vehicleNotResolvable();
        }

        return $vehicle;
    }

    /** Resolve a client-supplied driver reference to the canonical Driver. */
    public function driver(string $reference): Driver
    {
        $driver = $this->findDriver($reference);

        if ($driver === null) {
            throw FleetAssignmentException::driverNotResolvable();
        }

        return $driver;
    }

    /** Non-throwing variant, for validation rules that must return a boolean. */
    public function findVehicle(string $reference): ?Vehicle
    {
        // The global scope on the model supplies the tenant predicate; the
        // column choice below only decides WHICH address is being looked up.
        return Vehicle::query()
            ->where(fn ($q) => $q->where('uuid', $reference)
                ->when($this->looksNumeric($reference), fn ($qq) => $qq->orWhere('id', (int) $reference)))
            ->first();
    }

    /** Non-throwing variant, for validation rules that must return a boolean. */
    public function findDriver(string $reference): ?Driver
    {
        return Driver::query()
            ->where(fn ($q) => $q->where('uuid', $reference)
                ->when($this->looksNumeric($reference), fn ($qq) => $qq->orWhere('id', (int) $reference)))
            ->first();
    }

    /**
     * S-3 — a pairing may never span two companies.
     *
     * Checked on the RESOLVED entities rather than on the request, so it cannot
     * be bypassed by sending ids that individually pass a tenant filter applied
     * at different times. A null owner is the shared/unowned pool and is allowed
     * to pair with either side, matching how both global scopes read null.
     */
    public function assertSameCompany(Vehicle $vehicle, Driver $driver): void
    {
        $vehicleCompany = $vehicle->company_id;
        $driverCompany = $driver->company_id;

        if ($vehicleCompany === null || $driverCompany === null) {
            return;
        }

        if ($vehicleCompany !== $driverCompany) {
            throw FleetAssignmentException::crossCompanyPairing();
        }
    }

    /**
     * A bigint id arrives as a numeric string. A uuid never does, so this only
     * widens the lookup for references that could not be a uuid in the first
     * place — it never lets a uuid-shaped value hit the id column.
     */
    private function looksNumeric(string $reference): bool
    {
        return $reference !== '' && ctype_digit($reference);
    }
}
