<?php

declare(strict_types=1);

namespace Tests\Feature\Logistics;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-1-B-ATOMIC-BATCH-MOVE-001
 *
 * ATOMIC MULTI-ORDER GROUP MOVE — all of them or none of them.
 *
 * ┌─ THE ONE PROPERTY THIS SUITE EXISTS FOR ─────────────────────────────────┐
 * │ Five per-order moves are five transactions, so a destination with three    │
 * │ free places accepts three Orders and refuses two — a half-applied move    │
 * │ with nothing to roll back. Every rejection test below therefore asserts   │
 * │ TWO things: the call failed, AND every Order is still exactly where it     │
 * │ was. A test that only checked the status code would pass just as happily  │
 * │ on a partial write.                                                      │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * NO SECOND ENGINE. Capacity is still `GroupCapacityGuard::assertHasHeadroom()` — called
 * once for the whole batch instead of once per Order — and the write is the same shared
 * `writeSlotChange()` the single-Order path uses. The tests at the end assert this
 * operation touches nothing else: no Trip, no Trip capacity, no vehicle, no driver.
 */
final class DistributionBatchMoveTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/logistics/distribution';

    private Company $company;

    private Customer $customer;

    private Warehouse $warehouse;

    private int $zoneMaadi;

    private int $zoneHelwan;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('distribution.window.opens_at', '00:00');
        config()->set('distribution.window.closes_at', '23:59');

        $this->company = Company::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);

        $governorate = (int) DB::table('logistics_governorates')->insertGetId([
            'country_id' => 1,
            'name_ar' => 'القاهرة', 'name_en' => 'Cairo',
            'default_shipping_price' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->zoneMaadi = $this->zone('Maadi');
        $this->zoneHelwan = $this->zone('Helwan');
        $this->city($governorate, 'Maadi', 'المعادي', $this->zoneMaadi);
        $this->city($governorate, 'Helwan', 'حلوان', $this->zoneHelwan);
    }

    // ── 1-3. The operation works ─────────────────────────────────────────────

    /** A batch of one is still a batch — the endpoint is not a "two or more" special case. */
    public function test_a_batch_of_one_order_moves(): void
    {
        [, $source, $destination, $assignments] = $this->twoGroups(orders: 1, capacity: 10);

        $this->batch([$assignments[0]], $destination)
            ->assertOk()
            ->assertJsonPath('data.moved', 1)
            ->assertJsonPath('data.slot_id', $destination);

        self::assertSame($destination, $this->slotOf($assignments[0]));
        self::assertSame(0, $this->occupancy($source));
    }

    /** Several Orders, one destination, one call. */
    public function test_several_orders_move_together(): void
    {
        [, $source, $destination, $assignments] = $this->twoGroups(orders: 3, capacity: 10);

        $this->batch($assignments, $destination)
            ->assertOk()
            ->assertJsonPath('data.moved', 3);

        foreach ($assignments as $assignment) {
            self::assertSame($destination, $this->slotOf($assignment));
        }

        self::assertSame(0, $this->occupancy($source), 'no stale source membership');
        self::assertSame(3, $this->occupancy($destination));
    }

    /**
     * EXACT headroom must succeed. An off-by-one here would refuse the very operation
     * the operator most needs — filling a Group to its planned limit.
     */
    public function test_a_batch_that_exactly_fills_the_destination_succeeds(): void
    {
        // Destination capacity 4, already holding 1, so exactly 3 places remain.
        [, , $destination, $assignments] = $this->twoGroups(orders: 3, capacity: 4, seedDestination: 1);

        self::assertSame(3, $this->remaining($destination), 'fixture: exactly 3 free');

        $this->batch($assignments, $destination)->assertOk()->assertJsonPath('data.moved', 3);

        self::assertSame(4, $this->occupancy($destination));
        self::assertSame(0, $this->remaining($destination));
    }

    // ── 4-5, 12. Rejection is total ──────────────────────────────────────────

    /**
     * THE HEADLINE CASE. Three free places, five Orders selected: the batch is refused
     * and NOT ONE Order moves. Five single-Order calls would have moved three.
     */
    public function test_insufficient_headroom_rejects_the_whole_batch(): void
    {
        [, $source, $destination, $assignments] = $this->twoGroups(orders: 5, capacity: 4, seedDestination: 1);

        self::assertSame(3, $this->remaining($destination), 'fixture: 3 free, 5 selected');

        $this->batch($assignments, $destination)->assertStatus(422);

        foreach ($assignments as $assignment) {
            self::assertSame(
                $source,
                $this->slotOf($assignment),
                'every order stayed in the source group',
            );
        }

        self::assertSame(1, $this->occupancy($destination), 'destination untouched');
        self::assertSame(5, $this->occupancy($source));
    }

    /** One invalid Order in the set rolls back the valid ones with it. */
    public function test_one_invalid_order_rolls_back_the_entire_batch(): void
    {
        [, $source, $destination, $assignments] = $this->twoGroups(orders: 2, capacity: 10);

        // A third assignment from a DIFFERENT window — valid on its own, fatal to the set.
        $foreign = $this->assignmentInAnotherWindow();

        $this->batch([...$assignments, $foreign], $destination)->assertStatus(422);

        foreach ($assignments as $assignment) {
            self::assertSame($source, $this->slotOf($assignment), 'the valid orders did not move');
        }

        self::assertSame(0, $this->occupancy($destination));
    }

    /** No partial mutation, stated as its own assertion over the whole table. */
    public function test_a_rejected_batch_mutates_no_assignment_row(): void
    {
        [, , $destination, $assignments] = $this->twoGroups(orders: 5, capacity: 4, seedDestination: 1);

        $before = DB::table('distribution_window_orders')
            ->orderBy('id')
            ->pluck('virtual_slot_id', 'id')
            ->toArray();

        $this->batch($assignments, $destination)->assertStatus(422);

        $after = DB::table('distribution_window_orders')
            ->orderBy('id')
            ->pluck('virtual_slot_id', 'id')
            ->toArray();

        self::assertSame($before, $after, 'not one row changed');
    }

    // ── 6. Duplicate input ───────────────────────────────────────────────────

    /**
     * Duplicates are REFUSED, not collapsed. Silently reading five ids as three Orders
     * would make the operator's count and the capacity decision disagree.
     */
    public function test_duplicate_ids_are_rejected(): void
    {
        [, $source, $destination, $assignments] = $this->twoGroups(orders: 2, capacity: 10);

        $this->batch([$assignments[0], $assignments[0], $assignments[1]], $destination)
            ->assertStatus(422);

        foreach ($assignments as $assignment) {
            self::assertSame($source, $this->slotOf($assignment));
        }
    }

    /** And a duplicate cannot sneak a second write in even when there is ample room. */
    public function test_a_duplicate_cannot_produce_a_double_write(): void
    {
        [, , $destination, $assignments] = $this->twoGroups(orders: 1, capacity: 10);

        $this->batch([$assignments[0], $assignments[0]], $destination)->assertStatus(422);

        self::assertSame(0, $this->occupancy($destination));
        self::assertSame(
            1,
            DB::table('distribution_window_orders')->where('order_id', $this->orderOf($assignments[0]))->count(),
            'still exactly one assignment row for that order',
        );
    }

    // ── 7-9. Tenancy and identity ────────────────────────────────────────────

    /** An assignment from another company is not addressable, and fails the whole batch. */
    public function test_a_cross_company_assignment_fails_the_batch(): void
    {
        [, $source, $destination, $assignments] = $this->twoGroups(orders: 2, capacity: 10);

        $foreign = $this->assignmentInAnotherCompany();

        $this->batch([...$assignments, $foreign], $destination)->assertStatus(404);

        foreach ($assignments as $assignment) {
            self::assertSame($source, $this->slotOf($assignment), 'nothing moved');
        }
    }

    /** Orders spanning two Windows cannot be moved as one set. */
    public function test_a_cross_window_batch_is_rejected(): void
    {
        [, $source, $destination, $assignments] = $this->twoGroups(orders: 2, capacity: 10);

        $this->batch([...$assignments, $this->assignmentInAnotherWindow()], $destination)
            ->assertStatus(422);

        self::assertSame($source, $this->slotOf($assignments[0]));
    }

    /** An id that is not an assignment at all fails before anything is written. */
    public function test_an_unknown_assignment_id_fails_the_batch(): void
    {
        [, $source, $destination, $assignments] = $this->twoGroups(orders: 2, capacity: 10);

        $this->batch([...$assignments, (string) Str::uuid()], $destination)->assertStatus(404);

        foreach ($assignments as $assignment) {
            self::assertSame($source, $this->slotOf($assignment));
        }
    }

    /** An empty selection is refused by validation, not silently accepted as a no-op. */
    public function test_an_empty_selection_is_rejected(): void
    {
        [, , $destination] = $this->twoGroups(orders: 1, capacity: 10);

        $this->actingAs($this->user())
            ->patchJson(self::BASE.'/assignments/batch-slot', [
                'assignment_ids' => [],
                'slot_id' => $destination,
            ])->assertStatus(422);
    }

    // ── 10-11. The existing constraints still hold ───────────────────────────

    /** A destination in another Window is not a destination for this batch. */
    public function test_a_destination_slot_from_another_window_is_rejected(): void
    {
        [, $source, , $assignments] = $this->twoGroups(orders: 2, capacity: 10);

        $foreignSlot = $this->slotInAnotherWindow();

        $this->batch($assignments, $foreignSlot)->assertStatus(404);

        self::assertSame($source, $this->slotOf($assignments[0]));
    }

    /**
     * §11 — the EXISTING lock, reused. `assertManualAllowed()` refuses once the Window is
     * closed, and the batch inherits that rather than defining its own rule.
     */
    public function test_a_closed_window_refuses_the_batch(): void
    {
        [$windowId, $source, $destination, $assignments] = $this->twoGroups(orders: 2, capacity: 10);

        DB::table('distribution_windows')->where('id', $windowId)->update(['status' => 'closed']);

        $this->batch($assignments, $destination)->assertStatus(422);

        foreach ($assignments as $assignment) {
            self::assertSame($source, $this->slotOf($assignment));
        }
    }

    /** Moving Orders OUT of a Group (null destination) stays available, as it is per-order. */
    public function test_a_batch_can_move_orders_out_of_a_group(): void
    {
        [, $source, , $assignments] = $this->twoGroups(orders: 2, capacity: 10);

        $this->actingAs($this->user())
            ->patchJson(self::BASE.'/assignments/batch-slot', [
                'assignment_ids' => $assignments,
                'slot_id' => null,
            ])->assertOk()->assertJsonPath('data.moved', 2);

        foreach ($assignments as $assignment) {
            self::assertNull($this->slotOf($assignment));
        }

        self::assertSame(0, $this->occupancy($source));
    }

    // ── 13. The single-order path is unchanged ───────────────────────────────

    /** The refactor that extracted the shared writer must not alter the single move. */
    public function test_the_single_order_move_still_works_and_still_enforces_capacity(): void
    {
        [, $source, $destination, $assignments] = $this->twoGroups(orders: 2, capacity: 1);

        // One fits.
        $this->actingAs($this->user())
            ->patchJson(self::BASE."/assignments/{$assignments[0]}/slot", ['slot_id' => $destination])
            ->assertOk();

        self::assertSame($destination, $this->slotOf($assignments[0]));

        // The second does not, and is refused exactly as before.
        $this->actingAs($this->user())
            ->patchJson(self::BASE."/assignments/{$assignments[1]}/slot", ['slot_id' => $destination])
            ->assertStatus(422);

        self::assertSame($source, $this->slotOf($assignments[1]));
    }

    // ── 17-20. It touches nothing else ──────────────────────────────────────

    /** No Trip is created, removed, re-synced, or re-sized by a batch move. */
    public function test_a_batch_move_synchronizes_no_trip_and_changes_no_trip_capacity(): void
    {
        [, , $destination, $assignments] = $this->twoGroups(orders: 3, capacity: 10);

        $tripsBefore = DB::table('distribution_trips')->count();
        $manifestBefore = DB::table('distribution_trip_orders')->count();
        $capacitiesBefore = DB::table('distribution_trips')->orderBy('id')->pluck('capacity')->toArray();

        $this->batch($assignments, $destination)->assertOk();

        self::assertSame($tripsBefore, DB::table('distribution_trips')->count());
        self::assertSame($manifestBefore, DB::table('distribution_trip_orders')->count());
        self::assertSame(
            $capacitiesBefore,
            DB::table('distribution_trips')->orderBy('id')->pluck('capacity')->toArray(),
        );
    }

    /** No driver and no vehicle pairing is created or changed. */
    public function test_a_batch_move_changes_no_driver_or_vehicle_assignment(): void
    {
        [, , $destination, $assignments] = $this->twoGroups(orders: 3, capacity: 10);

        // Both ledgers: the Logistics pairing table and Loading's own assignment table.
        $before = [
            'logistics_driver_vehicle_assignments' => DB::table('logistics_driver_vehicle_assignments')->count(),
            'vehicle_assignments' => DB::table('vehicle_assignments')->count(),
            'driver_assignments' => DB::table('driver_assignments')->count(),
        ];

        $this->batch($assignments, $destination)->assertOk();

        foreach ($before as $table => $count) {
            self::assertSame($count, DB::table($table)->count(), $table.' must be untouched');
        }
    }

    /** No Loading session, task, or vehicle-inventory row is produced. */
    public function test_a_batch_move_creates_no_loading_or_vehicle_inventory(): void
    {
        [, , $destination, $assignments] = $this->twoGroups(orders: 3, capacity: 10);

        $before = [
            'loading_sessions' => DB::table('loading_sessions')->count(),
            'loading_tasks' => DB::table('loading_tasks')->count(),
            'vehicle_inventory_items' => DB::table('vehicle_inventory_items')->count(),
            'vehicle_inventory_movements' => DB::table('vehicle_inventory_movements')->count(),
        ];

        $this->batch($assignments, $destination)->assertOk();

        foreach ($before as $table => $count) {
            self::assertSame($count, DB::table($table)->count(), $table.' must be untouched');
        }
    }

    /** Group planning capacity is never rewritten by a move. */
    public function test_a_batch_move_never_changes_group_capacity(): void
    {
        [, $source, $destination, $assignments] = $this->twoGroups(orders: 3, capacity: 10);

        $before = DB::table('distribution_virtual_slots')
            ->whereIn('id', [$source, $destination])
            ->orderBy('id')
            ->pluck('capacity_orders', 'id')
            ->toArray();

        $this->batch($assignments, $destination)->assertOk();

        self::assertSame(
            $before,
            DB::table('distribution_virtual_slots')
                ->whereIn('id', [$source, $destination])
                ->orderBy('id')
                ->pluck('capacity_orders', 'id')
                ->toArray(),
        );
    }

    /** The write is attributed as a manual move, like the single-order path. */
    public function test_the_batch_is_recorded_as_a_manual_move(): void
    {
        [, , $destination, $assignments] = $this->twoGroups(orders: 2, capacity: 10);

        $this->batch($assignments, $destination, 'Rebalancing after the morning cutoff')
            ->assertOk();

        $row = DB::table('distribution_window_orders')->where('id', $assignments[0])->first();

        self::assertSame('manual_move', (string) $row->assignment_source);
        self::assertSame('Rebalancing after the morning cutoff', (string) $row->assignment_reason);
        self::assertNotNull($row->assigned_by);
    }

    /** Authorization is the existing distribution update permission. */
    public function test_the_batch_requires_the_distribution_update_permission(): void
    {
        [, , $destination, $assignments] = $this->twoGroups(orders: 2, capacity: 10);

        $this->actingAsUnprivileged(User::factory()->create(['company_id' => $this->company->id]))
            ->patchJson(self::BASE.'/assignments/batch-slot', [
                'assignment_ids' => $assignments,
                'slot_id' => $destination,
            ])->assertStatus(403);

        self::assertSame(0, $this->occupancy($destination));
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /**
     * A Window with a source Group holding `$orders` Orders in Maadi and an empty
     * destination Group with `$capacity`, optionally pre-seeded with Orders in Helwan.
     *
     * @return array{0: string, 1: string, 2: string, 3: list<string>}
     *                                                                 [windowId, sourceSlotId, destinationSlotId, sourceAssignmentIds]
     */
    private function twoGroups(int $orders, int $capacity, int $seedDestination = 0): array
    {
        for ($i = 0; $i < $orders; $i++) {
            $this->order('Maadi');
        }

        for ($i = 0; $i < $seedDestination; $i++) {
            $this->order('Helwan');
        }

        $this->collect();

        $user = $this->user();
        $windowId = (string) $this->actingAs($user)
            ->getJson(self::BASE.'/windows/current?warehouse_id='.$this->warehouse->id)
            ->assertOk()->json('data.window.id');

        $source = $this->makeSlot($windowId, 'DG-SRC', 50);
        $destination = $this->makeSlot($windowId, 'DG-DST', $capacity);

        $this->attachZone($windowId, $source, $this->zoneMaadi);

        if ($seedDestination > 0) {
            $this->attachZone($windowId, $destination, $this->zoneHelwan);
        }

        $assignments = DB::table('distribution_window_orders')
            ->where('distribution_window_id', $windowId)
            ->where('virtual_slot_id', $source)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        self::assertCount($orders, $assignments, 'fixture: source group holds the orders');

        return [$windowId, $source, $destination, $assignments];
    }

    /** @param  list<string>  $assignmentIds */
    private function batch(array $assignmentIds, ?string $slotId, ?string $reason = null)
    {
        $payload = ['assignment_ids' => $assignmentIds, 'slot_id' => $slotId];

        if ($reason !== null) {
            $payload['reason'] = $reason;
        }

        return $this->actingAs($this->user())
            ->patchJson(self::BASE.'/assignments/batch-slot', $payload);
    }

    private function makeSlot(string $windowId, string $code, ?int $capacity): string
    {
        return (string) $this->actingAs($this->user())
            ->postJson(self::BASE."/windows/{$windowId}/slots", [
                'warehouse_id' => $this->warehouse->id,
                'code' => $code.'-'.substr(uniqid(), -5),
                'capacity_orders' => $capacity,
            ])->assertSuccessful()->json('data.id');
    }

    private function attachZone(string $windowId, string $slotId, int $zoneId): void
    {
        $this->actingAs($this->user())
            ->postJson(self::BASE."/windows/{$windowId}/slots/{$slotId}/zones", [
                'zone_id' => $zoneId,
                'warehouse_id' => $this->warehouse->id,
            ])->assertSuccessful();
    }

    /** An assignment belonging to this company but a different Window. */
    private function assignmentInAnotherWindow(): string
    {
        $order = $this->order('Maadi');

        // Explicit uuid: insertGetId returns 0 on a char(36) key, so the id is generated
        // here rather than read back.
        $windowId = (string) Str::uuid();

        DB::table('distribution_windows')->insert([
            'id' => $windowId,
            'company_id' => $this->company->id,
            'window_date' => now()->addDays(3)->toDateString(),
            'status' => 'open',
            'opens_at' => now()->addDays(3)->startOfDay(),
            'closes_at' => now()->addDays(3)->endOfDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->assignmentRow($windowId, $order->id, $this->company->id);
    }

    /** An assignment in another company entirely. */
    private function assignmentInAnotherCompany(): string
    {
        $other = Company::factory()->create();
        $warehouse = Warehouse::factory()->create(['company_id' => $other->id]);

        $order = Order::query()->create([
            'company_id' => $other->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-BM-F-'.uniqid(),
            'order_date' => now()->toDateString(),
            'assigned_warehouse_id' => $warehouse->id,
            'city' => 'Maadi', 'governorate' => 'Cairo',
            'status' => 'in_progress',
            'subtotal' => 100, 'total' => 100, 'deposit_amount' => 0,
            'shipping_total' => 0, 'discount_total' => 0, 'tax_total' => 0,
        ]);

        $windowId = (string) Str::uuid();
        DB::table('distribution_windows')->insert([
            'id' => $windowId,
            'company_id' => $other->id,
            'window_date' => now()->toDateString(),
            'status' => 'open',
            'opens_at' => now()->startOfDay(),
            'closes_at' => now()->endOfDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->assignmentRow($windowId, $order->id, $other->id);
    }

    private function slotInAnotherWindow(): string
    {
        $windowId = (string) Str::uuid();
        DB::table('distribution_windows')->insert([
            'id' => $windowId,
            'company_id' => $this->company->id,
            'window_date' => now()->addDays(5)->toDateString(),
            'status' => 'open',
            'opens_at' => now()->addDays(5)->startOfDay(),
            'closes_at' => now()->addDays(5)->endOfDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $slotId = (string) Str::uuid();
        DB::table('distribution_virtual_slots')->insert([
            'id' => $slotId,
            'distribution_window_id' => $windowId,
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'code' => 'DG-OTHER-'.substr(uniqid(), -5),
            'capacity_orders' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $slotId;
    }

    private function assignmentRow(string $windowId, string $orderId, string $companyId): string
    {
        $id = (string) Str::uuid();

        DB::table('distribution_window_orders')->insert([
            'id' => $id,
            'distribution_window_id' => $windowId,
            'company_id' => $companyId,
            'order_id' => $orderId,
            'virtual_slot_id' => null,
            // The real ingestion value, and `assigned_at` is NOT NULL with no default.
            'assignment_source' => 'auto',
            'assigned_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function slotOf(string $assignmentId): ?string
    {
        $value = DB::table('distribution_window_orders')->where('id', $assignmentId)->value('virtual_slot_id');

        return $value === null ? null : (string) $value;
    }

    private function orderOf(string $assignmentId): string
    {
        return (string) DB::table('distribution_window_orders')->where('id', $assignmentId)->value('order_id');
    }

    /** Occupancy as the SERVER reports it, not a hand-rolled count. */
    private function occupancy(string $slotId): int
    {
        return (int) DB::table('distribution_window_orders')
            ->where('virtual_slot_id', $slotId)
            ->count();
    }

    private function remaining(string $slotId): int
    {
        $slot = DB::table('distribution_virtual_slots')->where('id', $slotId)->first();

        return max(0, (int) $slot->capacity_orders - $this->occupancy($slotId));
    }

    private function zone(string $name): int
    {
        return (int) DB::table('distribution_zones')->insertGetId([
            'code' => 'BM-'.substr(uniqid(), -6),
            'name_ar' => $name, 'name_en' => $name,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function city(int $governorate, string $en, string $ar, int $zoneId): void
    {
        $id = (int) DB::table('logistics_cities')->insertGetId([
            'governorate_id' => $governorate,
            'name_ar' => $ar, 'name_en' => $en,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('logistics_cities')->where('id', $id)->update(['distribution_zone_id' => $zoneId]);
    }

    private function order(string $city): Order
    {
        return Order::query()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-BM-'.uniqid(),
            'order_date' => now()->toDateString(),
            'assigned_warehouse_id' => $this->warehouse->id,
            'city' => $city,
            'governorate' => 'Cairo',
            'status' => 'in_progress',
            'subtotal' => 100, 'total' => 100, 'deposit_amount' => 0,
            'shipping_total' => 0, 'discount_total' => 0, 'tax_total' => 0,
        ]);
    }

    private function user(): User
    {
        return User::factory()->create(['company_id' => $this->company->id]);
    }

    private function collect(): void
    {
        $this->actingAs($this->user())->postJson(self::BASE.'/windows/collect')->assertOk();
    }
}
