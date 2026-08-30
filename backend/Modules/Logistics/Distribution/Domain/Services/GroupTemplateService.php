<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Services;

use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Modules\Logistics\Distribution\Domain\Exceptions\DistributionException;
use Modules\Logistics\Distribution\Domain\Models\DistributionGroupTemplate;
use Modules\Logistics\Distribution\Domain\Models\DistributionWindow;
use Modules\Logistics\Distribution\Domain\Models\DistributionZone;
use Modules\Logistics\Distribution\Domain\Models\VirtualCapacitySlot;
use Modules\Logistics\Drivers\Domain\Models\Driver;
use Modules\Operations\Preparation\Application\Services\WaveEngine\WaveManager;

/**
 * Distribution Group Templates — reusable Group CONFIGURATION.
 *
 * A template holds a name, a maximum order count and a set of Zones. Applying one
 * COPIES those three things into a new Group and then has nothing more to do with
 * it: there is no link back, so editing or archiving a template can never reach
 * into a Group that was already created from it.
 *
 * ┌─ NO RUNTIME STATE CROSSES THIS BOUNDARY ─────────────────────────────────┐
 * │ Apply creates a Group and attaches Zones. It does NOT create or copy an   │
 * │ Order assignment, a Vehicle, a Driver, a Trip, a loading record or a      │
 * │ prepared quantity — there is nowhere in a template for any of those to    │
 * │ have come from. Orders arrive in the new Group the same way they arrive in │
 * │ every other Group: because their Zone is attached to it, resolved by the   │
 * │ existing zone→slot mapping.                                              │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * WRITES REUSE THE ALREADY-SCOPED PATHS. Apply does not write
 * `distribution_slot_zones` itself; it calls the same
 * `ManualAssignmentService::assignZoneToSlot()` the Zones tab calls. That method
 * already carries the cross-warehouse guard, the (window, warehouse, zone) unique
 * key and the Group capacity check. Re-implementing the attach here would be a
 * second definition of Group membership, and it would be the one without the
 * guards.
 *
 * ZONE TENANCY, HONESTLY. `distribution_zones` has no `company_id` — zones are a
 * global table with globally unique codes. So a zone id CANNOT be proved to belong
 * to the acting company, and this service does not pretend to check it: it
 * validates only that the zone exists and is active. What stops a cross-company
 * effect is that the attach is performed against a Group the caller already owns,
 * inside `assignZoneToSlot`, which is company- and warehouse-scoped. Naming a zone
 * id therefore cannot move another tenant's work. Closing the underlying gap means
 * changing the certified zone contract and is recorded as an architecture
 * follow-up, not attempted here.
 */
final class GroupTemplateService
{
    public function __construct(
        private readonly ManualAssignmentService $manual,
        private readonly WaveManager $waves,
    ) {}

    /**
     * Every live template for one company, newest first, with its Zones loaded.
     *
     * @return list<DistributionGroupTemplate>
     */
    public function listForCompany(string $companyId): array
    {
        return DistributionGroupTemplate::query()
            ->with(['zones', 'recommendedDrivers'])
            ->where('company_id', $companyId)
            ->orderByDesc('created_at')
            ->get()
            ->all();
    }

    /**
     * One template inside the tenant boundary, or null.
     *
     * Null rather than an exception so the caller can decide between 404 and a
     * domain error. A template belonging to another company is indistinguishable
     * from one that does not exist, which is the intended behaviour: its existence
     * is not something a foreign tenant is entitled to learn.
     */
    public function findForCompany(string $companyId, string $templateId): ?DistributionGroupTemplate
    {
        return DistributionGroupTemplate::query()
            ->with(['zones', 'recommendedDrivers'])
            ->where('id', $templateId)
            ->where('company_id', $companyId)
            ->first();
    }

    /**
     * @param  list<int>  $zoneIds
     * @param  list<int>  $driverIds  Recommended Driver ids — suggestions only, never an assignment.
     */
    public function create(
        string $companyId,
        string $name,
        ?int $capacityOrders,
        array $zoneIds,
        ?int $actorId,
        bool $moveZones = false,
        array $driverIds = [],
    ): DistributionGroupTemplate {
        $this->assertNameFree($companyId, $name, null);
        $zoneIds = $this->assertZonesUsable($zoneIds);
        $driverIds = $this->assertDriversUsable($driverIds);

        return DB::transaction(function () use ($companyId, $name, $capacityOrders, $zoneIds, $driverIds, $actorId, $moveZones): DistributionGroupTemplate {
            // Exclusivity is settled INSIDE the transaction, under the Zone row lock —
            // see claimZones(). Deciding it before the transaction would let two
            // concurrent requests both read "free" and both insert.
            $this->claimZones($companyId, null, $zoneIds, $moveZones);

            $template = DistributionGroupTemplate::query()->create([
                'company_id' => $companyId,
                'name' => $name,
                'capacity_orders' => $capacityOrders,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);

            $this->replaceZones($template, $zoneIds);
            $this->replaceDrivers($template, $driverIds);

            return $template->load(['zones', 'recommendedDrivers']);
        });
    }

    /**
     * Edit a template's configuration.
     *
     * `$zoneIds === null` leaves Zone membership alone; an empty array clears it. A
     * template with no Zones is legitimate — it is a name and a capacity, and the
     * operator picks Zones when applying it.
     *
     * `$driverIds === null` leaves the recommended-Driver set alone; an empty array
     * clears it. Recommendations remain suggestions only and are never applied to a
     * Group.
     *
     * @param  list<int>|null  $zoneIds
     * @param  list<int>|null  $driverIds
     */
    public function update(
        DistributionGroupTemplate $template,
        ?string $name,
        ?int $capacityOrders,
        bool $capacityProvided,
        ?array $zoneIds,
        ?int $actorId,
        bool $moveZones = false,
        ?array $driverIds = null,
    ): DistributionGroupTemplate {
        if ($name !== null) {
            $this->assertNameFree($template->company_id, $name, $template->id);
        }

        if ($zoneIds !== null) {
            $zoneIds = $this->assertZonesUsable($zoneIds);
        }

        if ($driverIds !== null) {
            $driverIds = $this->assertDriversUsable($driverIds);
        }

        return DB::transaction(function () use ($template, $name, $capacityOrders, $capacityProvided, $zoneIds, $driverIds, $actorId, $moveZones): DistributionGroupTemplate {
            if ($zoneIds !== null) {
                $this->claimZones($template->company_id, $template->id, $zoneIds, $moveZones);
            }

            $patch = ['updated_by' => $actorId];

            if ($name !== null) {
                $patch['name'] = $name;
            }

            // Distinguished from "not sent": null is a meaningful value here, it
            // removes the maximum. Without the flag, every edit that left the field
            // out would silently clear it.
            if ($capacityProvided) {
                $patch['capacity_orders'] = $capacityOrders;
            }

            $template->forceFill($patch)->save();

            if ($zoneIds !== null) {
                $this->replaceZones($template, $zoneIds);
            }

            if ($driverIds !== null) {
                $this->replaceDrivers($template, $driverIds);
            }

            return $template->load(['zones', 'recommendedDrivers']);
        });
    }

    /**
     * Archive a template — soft delete, following `distribution_zones`.
     *
     * Its Zone rows are left in place: they are part of the archived record, and a
     * platform that supports restore must be able to bring the configuration back
     * intact. Nothing reads them while the parent is trashed, because every read
     * goes through the template.
     *
     * Groups already created from this template are untouched. There is no link
     * back to them by design.
     */
    public function archive(DistributionGroupTemplate $template): void
    {
        $template->delete();
    }

    /**
     * Create a NEW Group from a template.
     *
     * Every value may be overridden by the caller, because §12 requires the
     * operator to be able to adjust name, Zones and maximum before the Group
     * exists. The template supplies the defaults, not the decisions.
     *
     * `$warehouseId` is NOT overridable-from-template because a template does not
     * carry one: a Group's owner is chosen explicitly at creation and is never
     * inferred. The caller must supply it, and the caller has already proved the
     * warehouse belongs to the tenant.
     *
     * ONE TRANSACTION. A Group whose Zones half-attached would be a configuration
     * that matches no template and that the operator did not ask for.
     *
     * NO ACTOR PARAMETER, deliberately. `distribution_virtual_slots` carries no
     * `created_by` / `updated_by` column, and `assignZoneToSlot()` takes no actor
     * either, so there is nowhere here for one to be recorded. Accepting an actor
     * and discarding it would advertise an audit trail that does not exist.
     *
     * @param  list<int>|null  $zoneIdsOverride
     */
    public function applyToNewGroup(
        DistributionWindow $window,
        DistributionGroupTemplate $template,
        string $warehouseId,
        string $code,
        ?string $nameOverride,
        ?int $capacityOverride,
        bool $capacityProvided,
        ?array $zoneIdsOverride,
        bool $enforceCapacity = true,
        ?string $waveId = null,
    ): VirtualCapacitySlot {
        if ($template->company_id !== $window->company_id) {
            // Cross-company application is outside the tenant boundary and must
            // read as not existing, not as forbidden.
            throw new DistributionException('Template not found.');
        }

        // PART 12 — the new Group copies the template's CURRENT PERSISTED configuration.
        //
        // `zoneIds()` reads the loaded `zones` relation, so a caller holding an instance
        // from before an edit would stamp the OLD zone set onto a new Group. Reloading
        // makes the snapshot come from the database rather than from however stale the
        // caller's copy happens to be — "current persisted" is the contract, and an
        // in-memory relation is not that.
        $zoneIds = $zoneIdsOverride !== null
            ? $this->assertZonesUsable($zoneIdsOverride)
            : $template->load('zones')->zoneIds();

        $capacity = $capacityProvided ? $capacityOverride : $template->capacity_orders;

        return DB::transaction(function () use ($window, $template, $warehouseId, $code, $nameOverride, $capacity, $zoneIds, $enforceCapacity, $waveId): VirtualCapacitySlot {
            /** @var VirtualCapacitySlot $group */
            $group = VirtualCapacitySlot::query()->create([
                'company_id' => $window->company_id,
                'distribution_window_id' => $window->id,
                // TASK-002 — the Wave this Group is the operational instance OF.
                //
                // The operational DATE is passed, never omitted: `getActiveWave()` without
                // one returns the newest active wave for the warehouse, which is how a
                // stale Collecting wave stands in for today's. The Window's own date is
                // the operational day being planned, so it is the right anchor.
                //
                // Null when no active wave exists — that is a real state (an operator may
                // plan before a wave opens), and a fabricated wave id would be worse than
                // an absent one.
                // THE CALLER'S WAVE WINS when it names one.
                //
                // The daily lifecycle asks for a specific Wave and then looks the Group
                // up by that same id, so re-resolving here would let the two disagree —
                // and on a day carrying several Waves they DO disagree: every Group ends
                // up stamped with whichever Wave the resolver happens to pick, and the
                // second insert dies on the (wave, template) unique key.
                //
                // Only the operator-driven Apply path passes nothing, and it still gets
                // the resolved active Wave for the day being planned.
                'preparation_wave_id' => $waveId ?? $this->waves->getActiveWave(
                    (string) $window->company_id,
                    $warehouseId,
                    $window->window_date instanceof DateTimeInterface
                        ? $window->window_date->format('Y-m-d')
                        : (string) $window->window_date,
                )?->id,
                // Provenance only. Nothing reads this to derive configuration, so a later
                // Template edit still cannot reach this Group.
                'distribution_group_template_id' => $template->id,
                'warehouse_id' => $warehouseId,
                'code' => $code,
                'name' => $nameOverride ?? $template->name,
                'capacity_orders' => $capacity,
                // ORDER COUNT ONLY. The other three capacity columns are left null
                // deliberately: nothing enforces them, so a template must not be the
                // thing that starts populating them.
            ]);

            // The SAME attach the Zones tab performs, with all of its guards —
            // cross-warehouse protection, the (window, warehouse, zone) unique key,
            // and the Group capacity check. Zones are attached in a stable order so
            // a capacity refusal names the same zone on every retry.
            sort($zoneIds);

            foreach ($zoneIds as $zoneId) {
                $this->manual->assignZoneToSlot($window, $zoneId, $group, $enforceCapacity);
            }

            return $group->refresh();
        });
    }

    /**
     * A name must be free within the company.
     *
     * Checked in application code as well as by the unique index: the index gives
     * the guarantee, this gives the operator a sentence instead of a 500. The index
     * includes `deleted_at`, so an archived template does not hold its name.
     */
    private function assertNameFree(string $companyId, string $name, ?string $exceptId): void
    {
        $taken = DistributionGroupTemplate::query()
            ->where('company_id', $companyId)
            ->where('name', $name)
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();

        if ($taken) {
            throw new DistributionException("A template named \"{$name}\" already exists.");
        }
    }

    /**
     * Zones must exist and be active. Returns them de-duplicated.
     *
     * NOT a tenant check — `distribution_zones` has no company column, so one is
     * not possible here and is not implied. See the class docblock: the tenant
     * boundary is held by the Group the zones are attached to, inside
     * `assignZoneToSlot`.
     *
     * An INACTIVE zone is refused rather than silently dropped: a template that
     * quietly attached fewer zones than it names would be a template the operator
     * could not reason about.
     *
     * @param  list<int>  $zoneIds
     * @return list<int>
     */
    /**
     * Who currently owns each Zone, for one company.
     *
     * ONE SOURCE FOR THE RULE AND THE SCREEN. The picker's "Used in: Morning Cairo" and
     * the guard that refuses a duplicate both read this, so the label can never promise
     * something the write path then rejects.
     *
     * ARCHIVED TEMPLATES DO NOT OWN ZONES. `archive()` soft-deletes the template and
     * deliberately leaves its Zone rows in place so a restore is intact — meaning the
     * pivot legitimately holds rows for templates nobody can use. Counting those would
     * lock a Zone behind a template that no longer appears anywhere. The live database
     * already contains exactly this shape: zone 7 sits in an archived template and an
     * active one.
     *
     * @return array<int, array{template_id: string, template_name: string}> keyed by zone id
     */
    public function zoneOwnership(string $companyId, ?string $exceptTemplateId = null): array
    {
        $rows = DB::table('distribution_group_template_zones as z')
            ->join('distribution_group_templates as t', 't.id', '=', 'z.distribution_group_template_id')
            ->where('t.company_id', $companyId)
            ->whereNull('t.deleted_at')
            ->when(
                $exceptTemplateId !== null,
                static fn ($query) => $query->where('t.id', '!=', $exceptTemplateId),
            )
            ->select('z.distribution_zone_id', 't.id as template_id', 't.name as template_name')
            ->get();

        $ownership = [];

        foreach ($rows as $row) {
            $ownership[(int) $row->distribution_zone_id] = [
                'template_id' => (string) $row->template_id,
                'template_name' => (string) $row->template_name,
            ];
        }

        return $ownership;
    }

    /**
     * Enforce ONE ZONE -> ONE TEMPLATE for a company, and perform an approved Move.
     *
     * ┌─ WHY THE LOCK IS ON THE ZONE ROW ────────────────────────────────────────┐
     * │ The invariant is "this Zone is in at most one template", so the thing two  │
     * │ racing requests contend for is the ZONE — not either template, which may   │
     * │ well be two different rows. Locking `distribution_zones` serialises them    │
     * │ whatever templates they are aiming at, which locking either template        │
     * │ cannot do.                                                                │
     * │                                                                          │
     * │ The database CANNOT enforce this today: the pivot's unique key is          │
     * │ (template_id, zone_id), which stops a duplicate inside ONE template and    │
     * │ permits the same Zone in two. A DB-level key would have to be per company,  │
     * │ and the pivot carries no company_id — that is a migration, so it is        │
     * │ reported for an owner decision rather than taken here.                     │
     * └──────────────────────────────────────────────────────────────────────────┘
     *
     * MOVE IS EXPLICIT. Without `$moveZones` a conflict is refused and names the owning
     * template. With it — set only when the operator confirmed the dialog — the Zone is
     * detached from the old template first, so it is MOVED, never duplicated.
     *
     * CONFIGURATION ONLY. Detaching a Zone rewrites template configuration and nothing
     * else: no Order, Group, Trip, Driver, Vehicle or Loading row is read or written
     * here, and Groups already created from either template keep their own snapshot.
     *
     * Must run inside a transaction; both callers guarantee it.
     *
     * @param  list<int>  $zoneIds
     */
    private function claimZones(
        string $companyId,
        ?string $templateId,
        array $zoneIds,
        bool $moveZones,
    ): void {
        if ($zoneIds === []) {
            return;
        }

        // THE SERIALISATION POINT. Held until this transaction commits.
        DistributionZone::query()->whereIn('id', $zoneIds)->lockForUpdate()->get();

        $owned = array_intersect_key($this->zoneOwnership($companyId, $templateId), array_flip($zoneIds));

        if ($owned === []) {
            return;
        }

        if (! $moveZones) {
            $names = DistributionZone::query()
                ->whereIn('id', array_keys($owned))
                ->pluck('name_en', 'id')
                ->all();

            $conflicts = [];

            foreach ($owned as $zoneId => $owner) {
                $conflicts[] = sprintf(
                    '%s (used in %s)',
                    $names[$zoneId] ?? ('#'.$zoneId),
                    $owner['template_name'],
                );
            }

            throw new DistributionException(
                'These zones already belong to another template: '.implode(', ', $conflicts)
                .'. Confirm the move to reassign them.',
            );
        }

        // The Move: detach from the previous owners. Scoped to the conflicting zones and
        // to templates of THIS company, so no other company's configuration is touched.
        DB::table('distribution_group_template_zones')
            ->whereIn('distribution_zone_id', array_keys($owned))
            ->whereIn(
                'distribution_group_template_id',
                array_values(array_unique(array_map(
                    static fn (array $owner): string => $owner['template_id'],
                    $owned,
                ))),
            )
            ->delete();
    }

    private function assertZonesUsable(array $zoneIds): array
    {
        $unique = array_values(array_unique(array_map('intval', $zoneIds)));

        if ($unique === []) {
            return [];
        }

        $usable = DistributionZone::query()
            ->whereIn('id', $unique)
            ->where('is_active', true)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $missing = array_values(array_diff($unique, $usable));

        if ($missing !== []) {
            throw new DistributionException(
                'These zones do not exist or are not active: '.implode(', ', $missing).'.',
            );
        }

        return $unique;
    }

    /**
     * Replace a template's Zone set wholesale.
     *
     * Delete-then-insert rather than a diff: the set is small, and a diff would be
     * three code paths where one suffices. Runs inside the caller's transaction.
     *
     * @param  list<int>  $zoneIds
     */
    private function replaceZones(DistributionGroupTemplate $template, array $zoneIds): void
    {
        $template->zones()->delete();

        if ($zoneIds === []) {
            return;
        }

        $now = now();

        $template->zones()->insert(array_map(
            static fn (int $zoneId): array => [
                'distribution_group_template_id' => $template->id,
                'distribution_zone_id' => $zoneId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $zoneIds,
        ));
    }

    /**
     * Validate recommended Driver ids against the tenant's own Drivers.
     *
     * Unlike zones, Drivers ARE tenant-scoped: the `Driver` model's global scope
     * limits this query to the acting company's drivers (plus the shared null-owned
     * pool), so a Driver id from another company is simply not found and is
     * rejected — the same set the operator's Driver picker offers. Archived drivers
     * are excluded, matching the picker's eligible list. Empty is valid.
     *
     * @param  list<int>  $driverIds
     * @return list<int>
     */
    private function assertDriversUsable(array $driverIds): array
    {
        $unique = array_values(array_unique(array_map('intval', $driverIds)));

        if ($unique === []) {
            return [];
        }

        $usable = Driver::query()
            ->whereIn('id', $unique)
            ->where('status', '!=', Driver::STATUS_ARCHIVED)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $missing = array_values(array_diff($unique, $usable));

        if ($missing !== []) {
            throw new DistributionException(
                'These drivers do not exist, are archived, or belong to another company: '
                    .implode(', ', $missing).'.',
            );
        }

        return $unique;
    }

    /**
     * Replace a template's recommended-Driver set wholesale.
     *
     * Delete-then-insert, mirroring replaceZones. Runs inside the caller's
     * transaction. These rows are recommendations only — nothing here writes a
     * Group, a Trip or a Driver assignment.
     *
     * @param  list<int>  $driverIds
     */
    private function replaceDrivers(DistributionGroupTemplate $template, array $driverIds): void
    {
        $template->recommendedDrivers()->delete();

        if ($driverIds === []) {
            return;
        }

        $now = now();

        $template->recommendedDrivers()->insert(array_map(
            static fn (int $driverId): array => [
                'distribution_group_template_id' => $template->id,
                'logistics_driver_id' => $driverId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $driverIds,
        ));
    }
}
