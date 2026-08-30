<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Operations\Preparation\Domain\Models\WarehouseAssignmentPolicy;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-DISTRIBUTION-WAREHOUSE-ASSIGNMENT-RESOLUTION-001
 *
 * MANUAL WAREHOUSE ASSIGNMENT — the operator decides, and only the operator.
 *
 * ┌─ WHAT THIS SUITE IS ACTUALLY GUARDING ───────────────────────────────────┐
 * │ An Order with no warehouse is invisible to planning: it cannot be         │
 * │ reserved, prepared, grouped or dispatched. The fix is an operator saying   │
 * │ which warehouse, in writing, with a reason — never a system guess.        │
 * │                                                                          │
 * │ So the tests below split into two halves that must BOTH hold:            │
 * │   1. the assignment is recorded, attributed and auditable                │
 * │   2. it does nothing else — no inference, no Group, no Trip, no status    │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * NO NEW BACKEND WAS WRITTEN FOR THIS FEATURE. Every assertion here exercises the
 * pre-existing `POST api/orders/{order}/override-warehouse` route, its
 * `sales.orders.update` gate, and `WarehouseAssignmentEngine::override()` writing the
 * pre-existing `warehouse_assignment_overrides` audit trail. The suite is the proof that
 * reusing them is safe, which is why it asserts the contract rather than new code.
 *
 * WHY THE TENANCY TESTS USE A NON-SYSTEM ROLE: `TestCase::actingAs()` attaches the
 * is_system role, which switches the Order tenant global scope OFF by design. A
 * cross-company test run that way would pass while proving nothing, so those cases build
 * a role holding exactly `sales.orders.update` and act as an ordinary tenant user.
 */
final class OrderWarehouseManualAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private const DISTRIBUTION = '/api/logistics/distribution';

    private const REASON = 'Nearest warehouse with stock for this customer';

    private Company $company;

    private Customer $customer;

    private Warehouse $warehouse;

    private Warehouse $otherWarehouse;

    private int $zoneCovered;

    private int $zoneUncovered;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('distribution.window.opens_at', '00:00');
        config()->set('distribution.window.closes_at', '23:59');

        $this->company = Company::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->warehouse = Warehouse::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Primary',
        ]);
        $this->otherWarehouse = Warehouse::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Secondary',
        ]);

        $governorate = (int) DB::table('logistics_governorates')->insertGetId([
            'country_id' => 1,
            'name_ar' => 'القاهرة', 'name_en' => 'Cairo',
            'default_shipping_price' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->zoneCovered = $this->zone('Maadi');
        $this->zoneUncovered = $this->zone('Helwan');
        $this->city($governorate, 'Maadi', 'المعادي', $this->zoneCovered);
        $this->city($governorate, 'Helwan', 'حلوان', $this->zoneUncovered);
    }

    // ── A. The assignment is recorded and attributed ─────────────────────────

    /** The whole point: an Order with no warehouse gets one, from an operator. */
    public function test_a_warehouse_less_order_can_be_assigned_a_warehouse(): void
    {
        $order = $this->order('Helwan', warehouse: null);

        $this->assign($order, $this->warehouse->id)
            ->assertOk()
            ->assertJsonPath('data.warehouse_id', $this->warehouse->id);

        self::assertSame($this->warehouse->id, $order->refresh()->assigned_warehouse_id);
    }

    /** Manual work must be distinguishable from automatic work, forever. */
    public function test_the_assignment_is_stamped_as_a_manual_override(): void
    {
        $order = $this->order('Helwan', warehouse: null);

        $this->assign($order, $this->warehouse->id)->assertOk();

        $order->refresh();
        self::assertSame('manual_override', (string) $order->warehouse_assignment_source);
        self::assertNotNull($order->warehouse_assigned_at, 'when it happened is part of the record');
    }

    /**
     * The four audit facts the existing table already carries: who, when, from, to —
     * plus the operator's reason. No second audit mechanism was introduced.
     */
    public function test_the_existing_audit_trail_records_who_when_and_both_warehouses(): void
    {
        $order = $this->order('Helwan', warehouse: null);
        $user = $this->privilegedUser();

        $this->actingAsUnprivileged($user)
            ->postJson("/api/orders/{$order->id}/override-warehouse", [
                'warehouse_id' => $this->warehouse->id,
                'reason' => self::REASON,
            ])->assertOk();

        $audit = DB::table('warehouse_assignment_overrides')
            ->where('order_id', $order->id)
            ->first();

        self::assertNotNull($audit, 'the assignment must be auditable');
        self::assertNull($audit->previous_warehouse_id, 'it was unassigned before — recorded as such');
        self::assertSame($this->warehouse->id, $audit->new_warehouse_id);
        self::assertSame(self::REASON, $audit->reason);
        self::assertSame((string) $user->id, (string) $audit->overridden_by);
        self::assertNotNull($audit->overridden_at);
    }

    /** The trail is append-only: a correction must not erase what it corrected. */
    public function test_a_second_assignment_appends_a_row_carrying_the_previous_warehouse(): void
    {
        $order = $this->order('Helwan', warehouse: null);

        $this->assign($order, $this->warehouse->id)->assertOk();
        $this->assign($order, $this->otherWarehouse->id, 'Re-routed after a stock recount')->assertOk();

        $rows = DB::table('warehouse_assignment_overrides')
            ->where('order_id', $order->id)
            ->orderBy('created_at')
            ->get();

        self::assertCount(2, $rows, 'the first assignment is still on the record');
        self::assertNull($rows[0]->previous_warehouse_id);
        self::assertSame(
            $this->warehouse->id,
            $rows[1]->previous_warehouse_id,
            'the second row remembers what it replaced',
        );
        self::assertSame($this->otherWarehouse->id, $order->refresh()->assigned_warehouse_id);
    }

    // ── B. Nothing is inferred ───────────────────────────────────────────────

    /**
     * THE CENTRAL GUARANTEE. A policy exists that would pick `otherWarehouse` for this
     * Order's governorate and zone; the operator sends `warehouse` instead, and that is
     * what is stored. The automatic path (`assign-warehouse`, which consults exactly this
     * policy) is a different endpoint and is deliberately not the one being used.
     */
    public function test_the_warehouse_sent_wins_over_any_policy_that_would_match(): void
    {
        WarehouseAssignmentPolicy::create([
            'company_id' => $this->company->id,
            'governorate' => 'Cairo',
            'zone' => 'Helwan',
            'warehouse_id' => $this->otherWarehouse->id,
            'priority' => 1,
            'is_active' => true,
        ]);

        $order = $this->order('Helwan', warehouse: null);

        $this->assign($order, $this->warehouse->id)->assertOk();

        self::assertSame(
            $this->warehouse->id,
            $order->refresh()->assigned_warehouse_id,
            'the operator chose; the policy did not override them',
        );
    }

    /** With no warehouse in the payload nothing is guessed — the call is refused. */
    public function test_an_absent_warehouse_is_refused_rather_than_chosen(): void
    {
        $order = $this->order('Helwan', warehouse: null);

        $this->actingAsUnprivileged($this->privilegedUser())
            ->postJson("/api/orders/{$order->id}/override-warehouse", ['reason' => self::REASON])
            ->assertStatus(422);

        self::assertNull($order->refresh()->assigned_warehouse_id, 'still unassigned');
    }

    // ── C. Validation is the server's, and it fails closed ───────────────────

    /** A reason too short to mean anything is not an audit trail. */
    public function test_a_reason_shorter_than_ten_characters_is_rejected(): void
    {
        $order = $this->order('Helwan', warehouse: null);

        $this->assign($order, $this->warehouse->id, 'too short')->assertStatus(422);

        self::assertNull($order->refresh()->assigned_warehouse_id);
        self::assertSame(0, DB::table('warehouse_assignment_overrides')->count());
    }

    public function test_a_missing_reason_is_rejected(): void
    {
        $order = $this->order('Helwan', warehouse: null);

        $this->actingAsUnprivileged($this->privilegedUser())
            ->postJson("/api/orders/{$order->id}/override-warehouse", [
                'warehouse_id' => $this->warehouse->id,
            ])->assertStatus(422);

        self::assertNull($order->refresh()->assigned_warehouse_id);
    }

    public function test_a_reason_longer_than_the_column_allows_is_rejected(): void
    {
        $order = $this->order('Helwan', warehouse: null);

        $this->assign($order, $this->warehouse->id, str_repeat('a', 501))->assertStatus(422);

        self::assertNull($order->refresh()->assigned_warehouse_id);
    }

    public function test_an_unknown_warehouse_is_rejected(): void
    {
        $order = $this->order('Helwan', warehouse: null);

        $this->assign($order, (string) Str::uuid())->assertStatus(422);

        self::assertNull($order->refresh()->assigned_warehouse_id);
    }

    // ── D. Tenancy and authorization ─────────────────────────────────────────

    /**
     * Company scope is enforced by the SERVER, not by what the selector offered. A
     * warehouse belonging to another company is refused even though it exists.
     */
    public function test_a_warehouse_belonging_to_another_company_is_refused(): void
    {
        $foreign = Warehouse::factory()->create([
            'company_id' => Company::factory()->create()->id,
        ]);
        $order = $this->order('Helwan', warehouse: null);

        $this->assign($order, $foreign->id)->assertStatus(404);

        self::assertNull($order->refresh()->assigned_warehouse_id, 'no cross-company write');
        self::assertSame(0, DB::table('warehouse_assignment_overrides')->count());
    }

    /** An Order in another company is not addressable at all by an ordinary operator. */
    public function test_an_order_belonging_to_another_company_is_not_addressable(): void
    {
        $foreignCompany = Company::factory()->create();
        $foreignOrder = Order::query()->create([
            'company_id' => $foreignCompany->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-FOREIGN-'.uniqid(),
            'order_date' => now()->toDateString(),
            'assigned_warehouse_id' => null,
            'city' => 'Helwan',
            'governorate' => 'Cairo',
            'status' => 'in_progress',
            'subtotal' => 100, 'total' => 100, 'deposit_amount' => 0,
            'shipping_total' => 0, 'discount_total' => 0, 'tax_total' => 0,
        ]);

        $this->actingAsUnprivileged($this->privilegedUser())
            ->postJson("/api/orders/{$foreignOrder->id}/override-warehouse", [
                'warehouse_id' => $this->warehouse->id,
                'reason' => self::REASON,
            ])->assertStatus(404);

        self::assertNull($foreignOrder->refresh()->assigned_warehouse_id);
    }

    /** The existing permission governs it; no new one was invented. */
    public function test_the_existing_order_update_permission_is_required(): void
    {
        $order = $this->order('Helwan', warehouse: null);

        $this->actingAsUnprivileged(User::factory()->create(['company_id' => $this->company->id]))
            ->postJson("/api/orders/{$order->id}/override-warehouse", [
                'warehouse_id' => $this->warehouse->id,
                'reason' => self::REASON,
            ])->assertStatus(403);

        self::assertNull($order->refresh()->assigned_warehouse_id);
    }

    public function test_the_endpoint_requires_authentication(): void
    {
        $order = $this->order('Helwan', warehouse: null);

        $this->postJson("/api/orders/{$order->id}/override-warehouse", [
            'warehouse_id' => $this->warehouse->id,
            'reason' => self::REASON,
        ])->assertStatus(401);
    }

    // ── E. It does nothing else ──────────────────────────────────────────────

    /**
     * Assigning a warehouse is NOT planning. No Group is created, no Zone is attached,
     * and the Order joins no Group — that remains the collector's and the operator's
     * separate decision.
     */
    public function test_the_assignment_creates_no_group_and_joins_none(): void
    {
        [$windowId] = $this->windowWithGroup();
        $order = $this->order('Helwan', warehouse: null);
        $this->collect();

        $groupsBefore = DB::table('distribution_virtual_slots')->count();
        $zonesBefore = DB::table('distribution_slot_zones')->count();

        $this->assign($order, $this->warehouse->id)->assertOk();

        self::assertSame($groupsBefore, DB::table('distribution_virtual_slots')->count());
        self::assertSame($zonesBefore, DB::table('distribution_slot_zones')->count());
        self::assertNull(
            DB::table('distribution_window_orders')
                ->where('distribution_window_id', $windowId)
                ->where('order_id', $order->id)
                ->value('virtual_slot_id'),
            'the order is in no Group',
        );
    }

    /** And no Trip: dispatch is downstream of planning, several decisions away. */
    public function test_the_assignment_creates_no_trip(): void
    {
        $this->windowWithGroup();
        $order = $this->order('Helwan', warehouse: null);
        $this->collect();

        $tripsBefore = DB::table('distribution_trips')->count();

        $this->assign($order, $this->warehouse->id)->assertOk();

        self::assertSame($tripsBefore, DB::table('distribution_trips')->count());
    }

    /** One Order at a time: a neighbouring warehouse-less Order is left alone. */
    public function test_only_the_named_order_is_changed(): void
    {
        $target = $this->order('Helwan', warehouse: null);
        $bystander = $this->order('Helwan', warehouse: null);

        $this->assign($target, $this->warehouse->id)->assertOk();

        self::assertNotNull($target->refresh()->assigned_warehouse_id);
        self::assertNull($bystander->refresh()->assigned_warehouse_id, 'untouched');
        self::assertSame(1, DB::table('warehouse_assignment_overrides')->count());
    }

    /** The endpoint assigns a warehouse; it does not move the Order through its lifecycle. */
    public function test_the_order_status_is_not_rewritten_by_the_assignment(): void
    {
        $order = $this->order('Helwan', warehouse: null);
        $before = (string) $order->getRawOriginal('status');

        $this->assign($order, $this->warehouse->id)->assertOk();

        self::assertSame($before, (string) $order->refresh()->getRawOriginal('status'));
    }

    // ── F. The exception surface re-derives the blocker (§7) ─────────────────

    /**
     * §7 — resolving the warehouse must not make the Order vanish. It moves to the NEXT
     * real blocker, which for an uncovered Zone is `zone_not_in_group`. Nothing was
     * written to make that happen: the read model recomputes the blocker every request.
     */
    public function test_the_order_moves_from_the_warehouse_blocker_to_the_zone_blocker(): void
    {
        [$windowId] = $this->windowWithGroup();
        $order = $this->order('Helwan', warehouse: null);   // Helwan is in no Group
        $this->collect();

        $before = $this->awaitingGroup($windowId);
        self::assertSame(1, $before['summary']['warehouse_unassigned']);
        self::assertSame('warehouse_unassigned', $before['orders'][0]['blocker']);

        $this->assign($order, $this->warehouse->id)->assertOk();

        $after = $this->awaitingGroup($windowId);
        self::assertSame(1, $after['summary']['total'], 'still an exception, not resolved');
        self::assertSame(0, $after['summary']['warehouse_unassigned']);
        self::assertSame(1, $after['summary']['zone_not_in_group']);
        self::assertSame('zone_not_in_group', $after['orders'][0]['blocker']);
    }

    /**
     * When the Zone IS already covered by a Group, the Order still does not join it — it
     * lands in the third bucket until ingestion runs. Proof that assignment and grouping
     * are separate steps.
     */
    public function test_a_covered_zone_leaves_the_order_awaiting_group_assignment(): void
    {
        [$windowId] = $this->windowWithGroup();
        $order = $this->order('Maadi', warehouse: null);    // Maadi IS in the Group
        $this->collect();

        self::assertSame('warehouse_unassigned', $this->awaitingGroup($windowId)['orders'][0]['blocker']);

        $this->assign($order, $this->warehouse->id)->assertOk();

        $after = $this->awaitingGroup($windowId);
        self::assertSame(1, $after['summary']['awaiting_group_assignment']);
        self::assertSame('awaiting_group_assignment', $after['orders'][0]['blocker']);
        self::assertSame($order->order_number, $after['orders'][0]['order_number']);
    }

    /**
     * Choosing a warehouse other than the one filtering the board moves the row out of
     * that view — which is why the dialog warns about it rather than letting it vanish.
     */
    public function test_choosing_another_warehouse_moves_the_row_out_of_a_filtered_view(): void
    {
        [$windowId] = $this->windowWithGroup();
        $order = $this->order('Helwan', warehouse: null);
        $this->collect();

        $this->assign($order, $this->otherWarehouse->id)->assertOk();

        $filtered = $this->awaitingGroup($windowId, $this->warehouse->id);
        self::assertSame(0, $filtered['summary']['total'], 'no longer this warehouse\'s exception');

        $unfiltered = $this->awaitingGroup($windowId);
        self::assertSame(1, $unfiltered['summary']['total'], 'but not lost — it is the other warehouse\'s');
        self::assertSame($this->otherWarehouse->id, $unfiltered['orders'][0]['warehouse_id']);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function assign(Order $order, string $warehouseId, string $reason = self::REASON)
    {
        return $this->actingAsUnprivileged($this->privilegedUser())
            ->postJson("/api/orders/{$order->id}/override-warehouse", [
                'warehouse_id' => $warehouseId,
                'reason' => $reason,
            ]);
    }

    /**
     * A tenant user holding exactly `sales.orders.update` and NO system role, so the
     * Order tenant global scope stays switched on.
     */
    private function privilegedUser(): User
    {
        $permission = Permission::firstOrCreate(
            ['name' => 'sales.orders.update'],
            ['module' => 'sales', 'resource' => 'orders', 'action' => 'update'],
        );

        $role = Role::create([
            'slug' => 'wh-assigner-'.Str::random(6),
            'name' => 'Warehouse Assigner',
            'is_system' => false,
        ]);
        $role->permissions()->attach($permission->id);

        $user = User::factory()->create(['company_id' => $this->company->id]);
        $user->roles()->attach($role->id);

        return $user;
    }

    private function zone(string $name): int
    {
        return (int) DB::table('distribution_zones')->insertGetId([
            'code' => 'WA-'.substr(uniqid(), -6),
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

    /** @param  Warehouse|null  $warehouse  explicit null = no warehouse assigned */
    private function order(?string $city, mixed $warehouse = false): Order
    {
        return Order::query()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-WA-'.uniqid(),
            'order_date' => now()->toDateString(),
            'assigned_warehouse_id' => $warehouse === false
                ? $this->warehouse->id
                : ($warehouse instanceof Warehouse ? $warehouse->id : null),
            'city' => $city,
            'governorate' => 'Cairo',
            'status' => 'in_progress',
            'subtotal' => 100, 'total' => 100, 'deposit_amount' => 0,
            'shipping_total' => 0, 'discount_total' => 0, 'tax_total' => 0,
        ]);
    }

    private function systemUser(): User
    {
        return User::factory()->create(['company_id' => $this->company->id]);
    }

    private function collect(): void
    {
        $this->actingAs($this->systemUser())
            ->postJson(self::DISTRIBUTION.'/windows/collect')
            ->assertOk();
    }

    /**
     * A Window holding a Group that covers `zoneCovered` only.
     *
     * @return array{0: string, 1: string} [windowId, slotId]
     */
    private function windowWithGroup(): array
    {
        $this->order('Maadi');
        $this->collect();

        $user = $this->systemUser();
        $windowId = (string) $this->actingAs($user)
            ->getJson(self::DISTRIBUTION.'/windows/current?warehouse_id='.$this->warehouse->id)
            ->assertOk()->json('data.window.id');

        $slotId = (string) $this->actingAs($user)
            ->postJson(self::DISTRIBUTION."/windows/{$windowId}/slots", [
                'warehouse_id' => $this->warehouse->id,
                'code' => 'DG-WA-'.substr(uniqid(), -5),
                'capacity_orders' => 50,
            ])->assertSuccessful()->json('data.id');

        $this->actingAs($user)
            ->postJson(self::DISTRIBUTION."/windows/{$windowId}/slots/{$slotId}/zones", [
                'zone_id' => $this->zoneCovered,
                'warehouse_id' => $this->warehouse->id,
            ])->assertSuccessful();

        return [$windowId, $slotId];
    }

    /** @return array<string, mixed> */
    private function awaitingGroup(string $windowId, ?string $warehouseId = null): array
    {
        $query = $warehouseId === null ? '' : '?warehouse_id='.$warehouseId;

        return $this->actingAs($this->systemUser())
            ->getJson(self::DISTRIBUTION."/windows/{$windowId}/awaiting-group".$query)
            ->assertOk()
            ->json('data');
    }
}
