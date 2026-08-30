<?php

declare(strict_types=1);

namespace Tests\Feature\Logistics;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Logistics\Distribution\Domain\Models\Trip;
use Modules\Logistics\Distribution\Domain\Models\VirtualCapacitySlot;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-1-B — GROUP ↔ TRIP VISIBILITY.
 *
 * ┌─ WHAT THIS PINS ─────────────────────────────────────────────────────────┐
 * │ `distribution_trip_orders` is an EXECUTION MANIFEST — a snapshot taken at │
 * │ Finalize. Group membership lives in                                       │
 * │ `distribution_window_orders.virtual_slot_id`. The approved contract        │
 * │ deliberately does NOT synchronise them.                                   │
 * │                                                                          │
 * │ These rows assert that the difference is REPORTED and never CLOSED: an     │
 * │ order joining the Group after Finalize stays unassigned, and an order      │
 * │ leaving the Group stays on the Trip as an exception. Anything that started │
 * │ auto-reconciling would fail here.                                         │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * The difference is a SET difference, never `group_orders - trip_orders`:
 * subtraction is wrong whenever a manifest row is no longer a member, which is
 * exactly the live DG-001 shape reproduced in
 * test_the_difference_is_a_set_difference_not_a_subtraction.
 */
class GroupTripReconciliationVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/logistics/distribution';

    private Company $company;

    private Customer $customer;

    private Warehouse $warehouse;

    private int $zoneA;

    private int $zoneB;

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

        $this->zoneA = $this->zone('Maadi');
        $this->zoneB = $this->zone('Nasr City');
        $this->city($governorate, 'Maadi', 'المعادي', $this->zoneA);
        $this->city($governorate, 'Nasr City', 'مدينة نصر', $this->zoneB);
    }

    // ── 1-3. The three counts, and where they come from ──────────────────────

    public function test_a_finalized_group_reports_matching_counts_and_no_difference(): void
    {
        [$windowId, $slotId] = $this->groupWith(['Maadi', 'Maadi']);
        $this->finalize($windowId, $slotId);

        $data = $this->reconciliation($windowId, $slotId);

        self::assertSame(2, $data['summary']['group_orders']);
        self::assertSame(2, $data['summary']['trip_orders']);
        self::assertSame(0, $data['summary']['unassigned_orders']);
        self::assertSame(0, $data['summary']['exception_orders']);
        self::assertSame([], $data['unassigned_orders']);
        self::assertSame([], $data['exceptions']);
    }

    /**
     * The core scenario: an order joins the Group AFTER Finalize. It must appear as
     * unassigned and must NOT be added to the manifest.
     */
    public function test_an_order_joining_after_finalize_is_reported_unassigned_and_never_added(): void
    {
        [$windowId, $slotId] = $this->groupWith(['Maadi']);
        $this->finalize($windowId, $slotId);

        $manifestBefore = DB::table('distribution_trip_orders')->count();
        self::assertSame(1, $manifestBefore);

        // A second zone — and its orders — joins the Group after the Trip was sealed.
        $late = $this->order('Nasr City');
        $this->collect();
        $this->attachZone($windowId, $slotId, $this->zoneB);

        $data = $this->reconciliation($windowId, $slotId);

        self::assertSame(2, $data['summary']['group_orders']);
        self::assertSame(1, $data['summary']['trip_orders'], 'the manifest did not grow');
        self::assertSame(1, $data['summary']['unassigned_orders']);
        self::assertSame(0, $data['summary']['exception_orders']);

        self::assertCount(1, $data['unassigned_orders']);
        self::assertSame($late->order_number, $data['unassigned_orders'][0]['order_number']);

        // THE POINT: no automatic re-synchronisation happened.
        self::assertSame(
            $manifestBefore,
            DB::table('distribution_trip_orders')->count(),
            'reading the difference must never write the manifest',
        );
    }

    /**
     * The mirror scenario: an order leaves the Group while still on the Trip. It must
     * surface as an exception and must NOT be removed.
     */
    public function test_an_order_leaving_the_group_becomes_an_exception_and_is_never_removed(): void
    {
        [$windowId, $slotId] = $this->groupWith(['Maadi', 'Maadi']);
        $this->finalize($windowId, $slotId);

        $manifestBefore = DB::table('distribution_trip_orders')->count();

        // Detaching the zone removes its orders from the Group — the Trip keeps them.
        $this->actingAs($this->user())
            ->deleteJson(self::BASE."/windows/{$windowId}/slots/{$slotId}/zones/{$this->zoneA}")
            ->assertOk();

        $data = $this->reconciliation($windowId, $slotId);

        self::assertSame(0, $data['summary']['group_orders'], 'the Group lost its members');
        self::assertSame(2, $data['summary']['trip_orders'], 'the manifest kept them');
        self::assertSame(0, $data['summary']['unassigned_orders']);
        self::assertSame(2, $data['summary']['exception_orders']);
        self::assertCount(2, $data['exceptions']);
        self::assertSame('auto', $data['exceptions'][0]['assignment_type']);

        self::assertSame(
            $manifestBefore,
            DB::table('distribution_trip_orders')->count(),
            'no automatic removal',
        );
    }

    /**
     * Reproduces the live DG-001 shape: members the manifest lacks AND a manifest row
     * the Group lacks, simultaneously. `group_orders - trip_orders` would be wrong.
     */
    public function test_the_difference_is_a_set_difference_not_a_subtraction(): void
    {
        // Group starts with zone A (2 orders) → finalize → manifest = 2.
        [$windowId, $slotId] = $this->groupWith(['Maadi', 'Maadi']);
        $this->finalize($windowId, $slotId);

        // Zone A leaves (its 2 orders become exceptions); zone B joins with 3 orders.
        $this->order('Nasr City');
        $this->order('Nasr City');
        $this->order('Nasr City');
        $this->collect();
        $this->actingAs($this->user())
            ->deleteJson(self::BASE."/windows/{$windowId}/slots/{$slotId}/zones/{$this->zoneA}")
            ->assertOk();
        $this->attachZone($windowId, $slotId, $this->zoneB);

        $data = $this->reconciliation($windowId, $slotId);
        $summary = $data['summary'];

        self::assertSame(3, $summary['group_orders']);
        self::assertSame(2, $summary['trip_orders']);

        // Subtraction would say 3 - 2 = 1 unassigned and no exceptions. Both wrong.
        self::assertSame(3, $summary['unassigned_orders'], 'every member is off-manifest');
        self::assertSame(2, $summary['exception_orders'], 'every manifest row left the group');
        self::assertNotSame(
            $summary['group_orders'] - $summary['trip_orders'],
            $summary['unassigned_orders'],
            'the difference is two set differences, not a subtraction',
        );
    }

    // ── 4. Trip presentation ─────────────────────────────────────────────────

    public function test_each_trip_reports_its_own_capacity_and_remaining_capacity(): void
    {
        [$windowId, $slotId] = $this->groupWith(['Maadi', 'Maadi']);
        $this->finalize($windowId, $slotId);

        $trip = $this->reconciliation($windowId, $slotId)['trips'][0];

        self::assertSame(2, $trip['orders_count']);
        self::assertSame(60, $trip['capacity'], 'Trip capacity keeps its own default');
        self::assertSame(58, $trip['remaining_capacity'], 'derived by the Trip, not the client');
        self::assertArrayHasKey('vehicle', $trip);
        self::assertArrayHasKey('driver', $trip);
        self::assertNull($trip['vehicle']);
        self::assertNull($trip['driver']);
    }

    /** Trip capacity is independent of Group capacity — neither derives from the other. */
    public function test_trip_capacity_is_independent_of_group_capacity(): void
    {
        [$windowId, $slotId] = $this->groupWith(['Maadi'], capacity: 20);
        $this->finalize($windowId, $slotId);

        self::assertSame(
            20,
            (int) DB::table('distribution_virtual_slots')->where('id', $slotId)->value('capacity_orders'),
        );
        self::assertSame(
            60,
            (int) DB::table('distribution_trips')->where('virtual_slot_id', $slotId)->value('capacity'),
            'Group capacity must not be copied onto the Trip',
        );
    }

    // ── 5-6. The contracts this task must not break ──────────────────────────

    /** Finalize stays idempotent, and reading the difference does not disturb it. */
    public function test_finalize_remains_idempotent_across_a_reconciliation_read(): void
    {
        [$windowId, $slotId] = $this->groupWith(['Maadi', 'Maadi']);
        $first = $this->finalize($windowId, $slotId);

        $this->reconciliation($windowId, $slotId);

        $second = $this->finalize($windowId, $slotId);

        self::assertSame($first[0]['trip_number'], $second[0]['trip_number']);
        self::assertSame(1, Trip::query()->where('virtual_slot_id', $slotId)->count());
        self::assertSame(2, DB::table('distribution_trip_orders')->count(), 'manifest unchanged');
    }

    /** The ownership guard still refuses an order from outside the Trip's Group. */
    public function test_the_group_ownership_guard_still_refuses_a_foreign_order(): void
    {
        [$windowId, $slotId] = $this->groupWith(['Maadi']);
        $this->finalize($windowId, $slotId);

        // An order collected into the window but belonging to no group.
        $outsider = $this->order('Nasr City');
        $this->collect();

        $tripUuid = (string) Trip::query()->where('virtual_slot_id', $slotId)->value('uuid');

        $this->actingAs($this->user())
            ->postJson(self::BASE."/trips/{$tripUuid}/orders", ['order_id' => $outsider->id])
            ->assertStatus(422);

        self::assertSame(1, DB::table('distribution_trip_orders')->count());
    }

    // ── 9. The read mutates nothing ──────────────────────────────────────────

    public function test_the_reconciliation_read_mutates_nothing(): void
    {
        [$windowId, $slotId] = $this->groupWith(['Maadi', 'Maadi']);
        $this->finalize($windowId, $slotId);

        $before = [
            'trips' => DB::table('distribution_trips')->count(),
            'manifest' => DB::table('distribution_trip_orders')->count(),
            'assignments' => DB::table('distribution_window_orders')->count(),
            'slots' => DB::table('distribution_virtual_slots')->count(),
            'slot_zones' => DB::table('distribution_slot_zones')->count(),
            'windows' => DB::table('distribution_windows')->count(),
            'waves' => DB::table('preparation_waves')->count(),
            'capacities' => DB::table('distribution_trips')->orderBy('id')->pluck('capacity')->implode(','),
            'membership' => DB::table('distribution_window_orders')->orderBy('order_id')
                ->pluck('virtual_slot_id', 'order_id')->toArray(),
        ];

        $this->reconciliation($windowId, $slotId);
        $this->reconciliation($windowId, $slotId);

        self::assertSame($before['trips'], DB::table('distribution_trips')->count());
        self::assertSame($before['manifest'], DB::table('distribution_trip_orders')->count());
        self::assertSame($before['assignments'], DB::table('distribution_window_orders')->count());
        self::assertSame($before['slots'], DB::table('distribution_virtual_slots')->count());
        self::assertSame($before['slot_zones'], DB::table('distribution_slot_zones')->count());
        self::assertSame($before['windows'], DB::table('distribution_windows')->count());
        self::assertSame($before['waves'], DB::table('preparation_waves')->count());
        self::assertSame(
            $before['capacities'],
            DB::table('distribution_trips')->orderBy('id')->pluck('capacity')->implode(','),
        );
        self::assertSame(
            $before['membership'],
            DB::table('distribution_window_orders')->orderBy('order_id')
                ->pluck('virtual_slot_id', 'order_id')->toArray(),
        );
    }

    /** The read is guarded by the same view permission as its sibling Group reads. */
    public function test_the_reconciliation_read_requires_the_view_permission(): void
    {
        [$windowId, $slotId] = $this->groupWith(['Maadi']);

        $this->actingAsUnprivileged($this->user())
            ->getJson(self::BASE."/windows/{$windowId}/slots/{$slotId}/reconciliation")
            ->assertStatus(403);
    }

    // ── TASK-1-B-FINAL: state discriminator, overflow, Loading guard ──────────

    /** Within capacity, no Trip yet → Finalize is the next step, not an error. */
    public function test_a_group_within_capacity_and_not_finalized_is_awaiting_finalization(): void
    {
        [$windowId, $slotId] = $this->groupWith(['Maadi', 'Maadi'], capacity: 20);

        $data = $this->reconciliation($windowId, $slotId);

        self::assertSame('awaiting_finalization', $data['state']);
        // Field-by-field rather than a whole-array compare: the payload legitimately
        // grows (TASK-1-B-A2 added the approval fields) and this row is about the
        // capacity figures, not the payload's exact shape.
        self::assertSame(20, $data['capacity']['maximum']);
        self::assertSame(2, $data['capacity']['current']);
        self::assertSame(18, $data['capacity']['remaining']);
        self::assertSame(0, $data['capacity']['overflow']);
        self::assertFalse($data['capacity']['overflow_approved']);
    }

    /** Finalized and complete → resolved. "Not assigned to a trip" is never the state. */
    public function test_a_finalized_group_with_every_order_on_a_trip_is_resolved(): void
    {
        [$windowId, $slotId] = $this->groupWith(['Maadi', 'Maadi'], capacity: 20);
        $this->finalize($windowId, $slotId);

        self::assertSame('resolved', $this->reconciliation($windowId, $slotId)['state']);
    }

    /** A member joining after Finalize is an ACTION ITEM, not a resting state. */
    public function test_a_member_joining_after_finalize_is_added_after_finalization(): void
    {
        [$windowId, $slotId] = $this->groupWith(['Maadi'], capacity: 20);
        $this->finalize($windowId, $slotId);

        $this->order('Nasr City');
        $this->collect();
        $this->attachZone($windowId, $slotId, $this->zoneB);

        $data = $this->reconciliation($windowId, $slotId);

        self::assertSame('added_after_finalization', $data['state']);
        self::assertSame(0, $data['capacity']['overflow'], 'still within capacity');
        self::assertSame(1, $data['summary']['unassigned_orders']);
    }

    /**
     * Over the PLANNING capacity → the operator owes a decision, and the capacity value
     * itself is untouched. Overflow is derived, never stored.
     */
    public function test_a_group_over_capacity_requires_a_capacity_decision(): void
    {
        // Capacity 1, then a second order arrives by automatic ingestion — which
        // GroupCapacityGuard deliberately does not police, so overflow is reachable.
        [$windowId, $slotId] = $this->groupWith(['Maadi'], capacity: 1);
        $this->order('Maadi');
        $this->collect();

        $data = $this->reconciliation($windowId, $slotId);

        self::assertSame('capacity_decision_required', $data['state']);
        self::assertSame(1, $data['capacity']['maximum'], 'the planning capacity is NOT raised');
        self::assertSame(2, $data['capacity']['current']);
        self::assertSame(1, $data['capacity']['overflow']);

        self::assertSame(
            1,
            (int) DB::table('distribution_virtual_slots')->where('id', $slotId)->value('capacity_orders'),
            'nothing mutated capacity_orders',
        );
    }

    /** Finalize stays blocked while the decision is unresolved — the excess cannot progress. */
    public function test_finalize_is_refused_while_the_group_is_over_capacity(): void
    {
        [$windowId, $slotId] = $this->groupWith(['Maadi'], capacity: 1);
        $this->order('Maadi');
        $this->collect();

        $this->actingAs($this->user())
            ->postJson(self::BASE."/windows/{$windowId}/slots/{$slotId}/finalize")
            ->assertStatus(422);

        self::assertSame(
            0,
            DB::table('distribution_trips')->where('virtual_slot_id', $slotId)->count(),
            'no Trip — so nothing reaches Vehicle, Driver or Loading',
        );
        self::assertSame(0, DB::table('distribution_trip_orders')->count());
    }

    /** No automatic move, defer, Group creation or Trip creation on overflow. */
    public function test_overflow_moves_defers_and_creates_nothing_automatically(): void
    {
        [$windowId, $slotId] = $this->groupWith(['Maadi'], capacity: 1);
        $this->order('Maadi');
        $this->collect();

        $before = [
            'slots' => DB::table('distribution_virtual_slots')->count(),
            'trips' => DB::table('distribution_trips')->count(),
            'membership' => DB::table('distribution_window_orders')->orderBy('order_id')
                ->pluck('virtual_slot_id', 'order_id')->toArray(),
        ];

        $this->reconciliation($windowId, $slotId);
        $this->reconciliation($windowId, $slotId);

        self::assertSame($before['slots'], DB::table('distribution_virtual_slots')->count(), 'no Group created');
        self::assertSame($before['trips'], DB::table('distribution_trips')->count(), 'no Trip created');
        self::assertSame(
            $before['membership'],
            DB::table('distribution_window_orders')->orderBy('order_id')
                ->pluck('virtual_slot_id', 'order_id')->toArray(),
            'no order moved or deferred',
        );
    }

    /**
     * The operator move: one order, one atomic call, destination chosen explicitly.
     * Reuses the existing PATCH /assignments/{assignment}/slot — no new mutation.
     */
    public function test_an_operator_can_move_one_overflow_order_to_a_chosen_group(): void
    {
        [$windowId, $slotId] = $this->groupWith(['Maadi'], capacity: 1);
        $this->order('Maadi');
        $this->collect();

        $destination = (string) $this->actingAs($this->user())
            ->postJson(self::BASE."/windows/{$windowId}/slots", [
                'warehouse_id' => $this->warehouse->id,
                'code' => 'DG-T1B-DST',
                'capacity_orders' => 20,
            ])->assertSuccessful()->json('data.id');

        $moving = DB::table('distribution_window_orders')
            ->where('virtual_slot_id', $slotId)->orderBy('order_id')->first();

        $this->actingAs($this->user())
            ->patchJson(self::BASE."/assignments/{$moving->id}/slot", ['slot_id' => $destination])
            ->assertSuccessful();

        self::assertSame(
            $destination,
            DB::table('distribution_window_orders')->where('id', $moving->id)->value('virtual_slot_id'),
        );
        // The capacity decision is resolved: the source is back within capacity and its
        // next legitimate step is Finalize. It is NOT 'resolved' — that state means every
        // member is on a Trip, and this Group has never been finalized.
        $after = $this->reconciliation($windowId, $slotId);
        self::assertSame('awaiting_finalization', $after['state']);
        self::assertSame(0, $after['capacity']['overflow'], 'the overflow is gone');
        self::assertSame(1, $after['capacity']['current']);
    }

    /** The destination's own capacity is enforced by the existing guard. */
    public function test_a_move_into_a_full_destination_is_refused(): void
    {
        [$windowId, $slotId] = $this->groupWith(['Maadi'], capacity: 1);
        $this->order('Maadi');
        $this->collect();

        // A destination whose maximum is already met by its own zone's order.
        $full = (string) $this->actingAs($this->user())
            ->postJson(self::BASE."/windows/{$windowId}/slots", [
                'warehouse_id' => $this->warehouse->id,
                'code' => 'DG-T1B-FULL',
                'capacity_orders' => 1,
            ])->assertSuccessful()->json('data.id');
        $this->order('Nasr City');
        $this->collect();
        $this->attachZone($windowId, $full, $this->zoneB);

        $moving = DB::table('distribution_window_orders')
            ->where('virtual_slot_id', $slotId)->orderBy('order_id')->first();

        $this->actingAs($this->user())
            ->patchJson(self::BASE."/assignments/{$moving->id}/slot", ['slot_id' => $full])
            ->assertStatus(422);

        self::assertSame(
            $slotId,
            DB::table('distribution_window_orders')->where('id', $moving->id)->value('virtual_slot_id'),
            'the refused move left the order where it was',
        );
    }

    // ── Loading guard (§7) ───────────────────────────────────────────────────

    /**
     * THE LOADING GUARD. A Trip carrying an order that has left its Group must not open
     * Loading. Fails closed, and repairs nothing.
     */
    public function test_loading_is_refused_when_the_trip_carries_a_non_member(): void
    {
        [$windowId, $slotId] = $this->groupWith(['Maadi', 'Maadi'], capacity: 20);
        $this->finalize($windowId, $slotId);

        $manifestBefore = DB::table('distribution_trip_orders')->count();
        self::assertSame(2, $manifestBefore);

        // The zone leaves the Group; the manifest keeps its orders.
        $this->actingAs($this->user())
            ->deleteJson(self::BASE."/windows/{$windowId}/slots/{$slotId}/zones/{$this->zoneA}")
            ->assertOk();

        $tripUuid = (string) Trip::query()->where('virtual_slot_id', $slotId)->value('uuid');

        $response = $this->actingAs($this->user())
            ->postJson(self::BASE."/windows/{$windowId}/slots/{$slotId}/trips/{$tripUuid}/loading");

        $response->assertStatus(422);
        self::assertStringContainsString(
            'no longer members',
            (string) $response->json('message'),
            'the refusal names the integrity problem',
        );

        // It repaired nothing and created nothing.
        self::assertSame($manifestBefore, DB::table('distribution_trip_orders')->count());
        self::assertSame(0, DB::table('loading_sessions')->count());
        self::assertSame(0, DB::table('vehicle_assignments')->count());
    }

    // ── TASK-1-B-A2: explicit overflow approval ───────────────────────────────

    /** Approving lets Finalize through, and EVERY accepted order lands on the Trip. */
    public function test_approving_the_overflow_allows_finalize_with_every_accepted_order(): void
    {
        [$windowId, $slotId] = $this->overflowGroup();

        $trips = $this->actingAs($this->user())
            ->postJson(self::BASE."/windows/{$windowId}/slots/{$slotId}/finalize", [
                'approve_overflow' => true,
            ])
            ->assertOk()
            ->json('data');

        self::assertCount(1, $trips, 'one Trip — 3 orders fit inside Trip capacity 60');
        self::assertSame(3, (int) $trips[0]['orders_count'], 'all three accepted orders');
        self::assertSame(3, DB::table('distribution_trip_orders')->count());
    }

    /** THE CONTRACT: the planning capacity is NOT changed by an approval. */
    public function test_approval_does_not_change_the_planning_capacity(): void
    {
        [$windowId, $slotId] = $this->overflowGroup();

        $this->approve($windowId, $slotId);

        $row = DB::table('distribution_virtual_slots')->where('id', $slotId)->first();

        self::assertSame(2, (int) $row->capacity_orders, 'the maximum is still 2, never raised to 3');
        self::assertNotNull($row->capacity_orders, 'and never nulled');
        self::assertSame(3, (int) $row->overflow_approved_orders, 'the approved count is recorded');
        self::assertNotNull($row->overflow_approved_at);
        self::assertNotNull($row->overflow_approved_by, 'the approver is recorded');

        // And the read model still reports the PLANNING capacity, not the approved figure.
        $data = $this->reconciliation($windowId, $slotId);
        self::assertSame(2, $data['capacity']['maximum']);
        self::assertSame(3, $data['capacity']['current']);
        self::assertSame(1, $data['capacity']['overflow']);
        self::assertTrue($data['capacity']['overflow_approved']);
        self::assertSame('overflow_approved', $data['state']);
    }

    /** The approval survives Finalize — it is a record, not a one-shot token. */
    public function test_the_approval_survives_the_finalize_operation(): void
    {
        [$windowId, $slotId] = $this->overflowGroup();

        $this->actingAs($this->user())
            ->postJson(self::BASE."/windows/{$windowId}/slots/{$slotId}/finalize", ['approve_overflow' => true])
            ->assertOk();

        $row = DB::table('distribution_virtual_slots')->where('id', $slotId)->first();
        self::assertSame(3, (int) $row->overflow_approved_orders);
        self::assertSame(2, (int) $row->capacity_orders);
    }

    /** Approving twice writes the same figure and creates nothing. */
    public function test_approval_is_idempotent(): void
    {
        [$windowId, $slotId] = $this->overflowGroup();

        $this->approve($windowId, $slotId);
        $first = DB::table('distribution_virtual_slots')->where('id', $slotId)
            ->first(['overflow_approved_orders', 'overflow_approved_at']);

        $this->approve($windowId, $slotId);
        $second = DB::table('distribution_virtual_slots')->where('id', $slotId)
            ->first(['overflow_approved_orders', 'overflow_approved_at']);

        self::assertSame(
            (int) $first->overflow_approved_orders,
            (int) $second->overflow_approved_orders,
            'the approved count does not drift',
        );
        self::assertSame(1, DB::table('distribution_trips')->count(), 'and no second Trip');
        self::assertSame(3, DB::table('distribution_trip_orders')->count(), 'no duplicate manifest rows');
    }

    /** Finalize stays idempotent when the approval flag is repeated. */
    public function test_finalize_with_approval_remains_idempotent(): void
    {
        [$windowId, $slotId] = $this->overflowGroup();

        $first = $this->actingAs($this->user())
            ->postJson(self::BASE."/windows/{$windowId}/slots/{$slotId}/finalize", ['approve_overflow' => true])
            ->assertOk()->json('data');

        $second = $this->actingAs($this->user())
            ->postJson(self::BASE."/windows/{$windowId}/slots/{$slotId}/finalize", ['approve_overflow' => true])
            ->assertOk()->json('data');

        self::assertSame($first[0]['trip_number'], $second[0]['trip_number']);
        self::assertSame(1, DB::table('distribution_trips')->count());
        self::assertSame(3, DB::table('distribution_trip_orders')->count());
    }

    /**
     * THE APPROVAL IS BOUNDED, NOT A WAIVER — asserted on the rule itself.
     *
     * The stored count is what stops an approval becoming "capacity is unlimited": it
     * covers the occupancy that was approved and nothing beyond it.
     */
    public function test_the_approved_count_bounds_the_approval(): void
    {
        [$windowId, $slotId] = $this->overflowGroup();
        $this->approve($windowId, $slotId);

        /** @var VirtualCapacitySlot $group */
        $group = VirtualCapacitySlot::query()->findOrFail($slotId);

        self::assertSame(3, (int) $group->overflow_approved_orders);
        self::assertTrue($group->hasApprovedOverflowFor(3), 'covers what was approved');
        self::assertTrue($group->hasApprovedOverflowFor(2), 'and anything smaller');
        self::assertFalse($group->hasApprovedOverflowFor(4), 'but never more than approved');
        self::assertFalse($group->hasApprovedOverflowFor(40));
    }

    /** A Group with no approval is never treated as approved. */
    public function test_a_group_without_an_approval_is_never_treated_as_approved(): void
    {
        [, $slotId] = $this->overflowGroup();

        /** @var VirtualCapacitySlot $group */
        $group = VirtualCapacitySlot::query()->findOrFail($slotId);

        self::assertNull($group->overflow_approved_orders);
        self::assertFalse($group->hasApprovedOverflowFor(1));
        self::assertFalse($group->hasApprovedOverflowFor(3));
    }

    /**
     * After finalization, growth does NOT re-open the capacity question — and that is
     * the idempotency contract winning, not a hole in the bound.
     *
     * Finalize short-circuits on the existing Trip before any prerequisite is read, so a
     * later order cannot make it refuse. The resolution is therefore move-the-order or
     * add-it-to-the-Trip, never re-Finalize. Asserted so the behaviour is pinned rather
     * than assumed.
     */
    public function test_after_finalization_growth_does_not_reopen_finalize(): void
    {
        [$windowId, $slotId] = $this->overflowGroup();

        $first = $this->actingAs($this->user())
            ->postJson(self::BASE."/windows/{$windowId}/slots/{$slotId}/finalize", ['approve_overflow' => true])
            ->assertOk()->json('data');

        // A fourth order arrives by ingestion, beyond the three that were approved.
        $this->order('Maadi');
        $this->collect();

        $second = $this->actingAs($this->user())
            ->postJson(self::BASE."/windows/{$windowId}/slots/{$slotId}/finalize")
            ->assertOk()->json('data');

        self::assertSame($first[0]['trip_number'], $second[0]['trip_number'], 'the same Trip');
        self::assertSame(1, DB::table('distribution_trips')->count(), 'no second Trip');
        self::assertSame(
            3,
            DB::table('distribution_trip_orders')->count(),
            'and the manifest was NOT re-synced to include the late order',
        );
    }

    /** Nothing to approve → refused, so no misleading audit row is written. */
    public function test_approving_a_group_within_capacity_is_refused(): void
    {
        [$windowId, $slotId] = $this->groupWith(['Maadi'], capacity: 20);

        $this->actingAs($this->user())
            ->postJson(self::BASE."/windows/{$windowId}/slots/{$slotId}/finalize", ['approve_overflow' => true])
            ->assertStatus(422);

        self::assertNull(
            DB::table('distribution_virtual_slots')->where('id', $slotId)->value('overflow_approved_orders'),
        );
    }

    /** A Group with no maximum has no overflow to approve. */
    public function test_approving_a_group_with_no_maximum_is_refused(): void
    {
        [$windowId, $slotId] = $this->groupWith(['Maadi']); // capacity null = unconstrained

        $this->actingAs($this->user())
            ->postJson(self::BASE."/windows/{$windowId}/slots/{$slotId}/finalize", ['approve_overflow' => true])
            ->assertStatus(422);

        self::assertNull(
            DB::table('distribution_virtual_slots')->where('id', $slotId)->value('overflow_approved_orders'),
        );
    }

    /** Without the flag, an over-capacity Group is still refused — unchanged behaviour. */
    public function test_finalize_without_the_flag_is_still_refused_when_over_capacity(): void
    {
        [$windowId, $slotId] = $this->overflowGroup();

        $this->actingAs($this->user())
            ->postJson(self::BASE."/windows/{$windowId}/slots/{$slotId}/finalize")
            ->assertStatus(422);

        self::assertSame(0, DB::table('distribution_trips')->count());
        self::assertNull(
            DB::table('distribution_virtual_slots')->where('id', $slotId)->value('overflow_approved_orders'),
            'a refused Finalize records no approval',
        );
    }

    /** The approval reuses Finalize's own permission boundary — no new permission. */
    public function test_an_unauthorized_operator_cannot_approve_or_finalize(): void
    {
        [$windowId, $slotId] = $this->overflowGroup();

        $this->actingAsUnprivileged($this->user())
            ->postJson(self::BASE."/windows/{$windowId}/slots/{$slotId}/finalize", ['approve_overflow' => true])
            ->assertStatus(403);

        self::assertNull(
            DB::table('distribution_virtual_slots')->where('id', $slotId)->value('overflow_approved_orders'),
        );
        self::assertSame(0, DB::table('distribution_trips')->count());
    }

    /** The approval is not mass-assignable through the Group's own edit endpoint. */
    public function test_the_approval_cannot_be_set_through_the_slot_update_endpoint(): void
    {
        [$windowId, $slotId] = $this->overflowGroup();

        $this->actingAs($this->user())
            ->patchJson(self::BASE."/windows/{$windowId}/slots/{$slotId}", [
                'overflow_approved_orders' => 99,
            ]);

        self::assertNull(
            DB::table('distribution_virtual_slots')->where('id', $slotId)->value('overflow_approved_orders'),
            'not fillable, so a payload cannot approve an overflow',
        );
    }

    /**
     * A Group over capacity by one, built the way production gets there: the operator
     * path fills it to the maximum, then automatic ingestion — which
     * GroupCapacityGuard deliberately does not police — pushes it past.
     *
     * @return array{0: string, 1: string} [windowId, slotId]
     */
    private function overflowGroup(): array
    {
        [$windowId, $slotId] = $this->groupWith(['Maadi', 'Maadi'], capacity: 2);

        $this->order('Maadi');
        $this->collect();

        return [$windowId, $slotId];
    }

    private function approve(string $windowId, string $slotId): void
    {
        $this->actingAs($this->user())
            ->postJson(self::BASE."/windows/{$windowId}/slots/{$slotId}/finalize", ['approve_overflow' => true])
            ->assertOk();
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function zone(string $name): int
    {
        return (int) DB::table('distribution_zones')->insertGetId([
            'code' => 'T1B-'.substr(uniqid(), -6),
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
            'order_number' => 'ORD-T1B-'.uniqid(),
            'order_date' => now()->toDateString(),
            'assigned_warehouse_id' => $this->warehouse->id,
            'city' => $city,
            'governorate' => 'Cairo',
            'status' => 'in_progress',
            'subtotal' => 100, 'total' => 100,
            'deposit_amount' => 0,
            'shipping_total' => 0, 'discount_total' => 0, 'tax_total' => 0,
        ]);
    }

    private function user(): User
    {
        return User::factory()->create(['company_id' => $this->company->id]);
    }

    private function collect(): void
    {
        $this->actingAs($this->user())
            ->postJson(self::BASE.'/windows/collect')
            ->assertOk();
    }

    /**
     * A Group holding one zone's orders.
     *
     * @param  list<string>  $cities  one order per entry, all in the same zone
     * @return array{0: string, 1: string} [windowId, slotId]
     */
    private function groupWith(array $cities, ?int $capacity = null): array
    {
        foreach ($cities as $city) {
            $this->order($city);
        }

        $this->collect();

        $user = $this->user();
        $windowId = (string) $this->actingAs($user)
            ->getJson(self::BASE.'/windows/current?warehouse_id='.$this->warehouse->id)
            ->assertOk()->json('data.window.id');

        $slotId = (string) $this->actingAs($user)
            ->postJson(self::BASE."/windows/{$windowId}/slots", array_filter([
                'warehouse_id' => $this->warehouse->id,
                'code' => 'DG-T1B-'.substr(uniqid(), -5),
                'capacity_orders' => $capacity,
            ], static fn ($v): bool => $v !== null))
            ->assertSuccessful()->json('data.id');

        $this->attachZone($windowId, $slotId, $this->zoneA);

        return [$windowId, $slotId];
    }

    private function attachZone(string $windowId, string $slotId, int $zoneId): void
    {
        $this->actingAs($this->user())
            ->postJson(self::BASE."/windows/{$windowId}/slots/{$slotId}/zones", [
                'zone_id' => $zoneId,
                'warehouse_id' => $this->warehouse->id,
            ])
            ->assertSuccessful();
    }

    /** @return array<int, array<string, mixed>> */
    private function finalize(string $windowId, string $slotId): array
    {
        return $this->actingAs($this->user())
            ->postJson(self::BASE."/windows/{$windowId}/slots/{$slotId}/finalize")
            ->assertOk()
            ->json('data');
    }

    /** @return array<string, mixed> */
    private function reconciliation(string $windowId, string $slotId): array
    {
        return $this->actingAs($this->user())
            ->getJson(self::BASE."/windows/{$windowId}/slots/{$slotId}/reconciliation")
            ->assertOk()
            ->json('data');
    }
}
