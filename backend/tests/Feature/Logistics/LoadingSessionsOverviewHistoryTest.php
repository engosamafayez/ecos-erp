<?php

declare(strict_types=1);

namespace Tests\Feature\Logistics;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Logistics\Distribution\Domain\Enums\TripStatus;
use Modules\Logistics\Distribution\Domain\Models\DistributionWindow;
use Modules\Logistics\Distribution\Domain\Models\Trip;
use Modules\Logistics\Distribution\Domain\Models\VirtualCapacitySlot;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Operations\Loading\Domain\Enums\VehicleAssignmentStatus;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-ECOS-OPERATIONS-DISTRIBUTION-AND-LOADING-FINAL-022 §G —
 * `GET /api/loading/sessions-overview`'s "Completed/History" bucket.
 *
 *   1. A genuinely never-started (Draft, zero-activity) session must not appear
 *      in ANY bucket or count — the specific defect this task closes.
 *   2. A zero-activity session that DID reach a terminal state is still history.
 *   3. A history row for a real (cancelled) execution carries the audit detail
 *      §G asks for: Wave, Group, Orders, quantities, closure time, reason —
 *      alongside the Trip/Driver/Vehicle detail the endpoint already had.
 */
final class LoadingSessionsOverviewHistoryTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/loading';

    private Company $company;

    private Customer $customer;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
    }

    public function test_a_never_started_draft_session_is_excluded_from_every_bucket(): void
    {
        $sessionId = $this->loadingSession('draft');

        $data = $this->actingAs($this->user())
            ->getJson(self::BASE.'/sessions-overview?warehouse_id='.$this->warehouse->id)
            ->assertOk()
            ->json('data');

        self::assertFalse(
            collect($data['data'])->contains(fn (array $row): bool => $row['session_id'] === $sessionId),
            'A never-started Draft session with zero activity must not appear in the overview at all.',
        );

        self::assertSame(
            0,
            array_sum($data['counts']),
            'A never-started Draft session must not be counted in any bucket.',
        );
    }

    public function test_a_cancelled_zero_activity_session_still_appears_in_history(): void
    {
        $sessionId = $this->loadingSession('cancelled');

        $data = $this->actingAs($this->user())
            ->getJson(self::BASE.'/sessions-overview?warehouse_id='.$this->warehouse->id)
            ->assertOk()
            ->json('data');

        $row = collect($data['data'])->firstWhere('session_id', $sessionId);

        self::assertNotNull($row, 'A session that reached a terminal state must still appear, even with zero activity.');
        self::assertSame('completed_history', $row['bucket']);
        self::assertSame(['no_activity'], $row['reasons']);
    }

    public function test_history_row_includes_wave_group_orders_and_quantities(): void
    {
        $waveId = $this->wave();
        $windowId = $this->window();
        $group = $this->groupFor($windowId, $waveId, 'DG-HIST-1');
        $order = $this->order();

        $trip = Trip::query()->create([
            'company_id' => $this->company->id,
            'virtual_slot_id' => $group->id,
            'name' => $group->code,
            'trip_number' => 'TRP-'.substr(uniqid(), -8),
            'status' => TripStatus::Cancelled->value,
        ]);

        $trip->tripOrders()->create([
            'order_id' => $order->id,
            'assignment_type' => 'manual',
            'assigned_at' => now(),
            'superseded_at' => now(),
            'release_reason' => 'wave_closed_loaded_not_accepted',
        ]);

        $sessionId = $this->loadingSession('loading');

        $assignmentId = (string) Str::uuid();
        DB::table('vehicle_assignments')->insert([
            'id' => $assignmentId,
            'company_id' => $this->company->id,
            'loading_session_id' => $sessionId,
            'trip_id' => $trip->id,
            'vehicle_id' => (string) Str::uuid(),
            'vehicle_registration_snapshot' => 'HIST-1',
            'vehicle_type_snapshot' => 'van',
            'capacity_weight_kg_snapshot' => 500,
            'capacity_volume_m3_snapshot' => 5,
            'refrigerated_snapshot' => false,
            'assignment_number' => 'VA-'.substr(uniqid(), -8),
            'status' => VehicleAssignmentStatus::Cancelled->value,
            'orders_count' => 1,
            'loading_weight_kg' => 10,
            'loading_volume_m3' => 1,
            'loading_started_at' => now()->subMinutes(30),
            'cancelled_at' => now(),
            'cancellation_reason' => 'wave_closed_loaded_not_accepted',
            'created_by' => (string) Str::uuid(),
            'updated_by' => (string) Str::uuid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('loading_tasks')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $this->company->id,
            'loading_session_id' => $sessionId,
            'vehicle_assignment_id' => $assignmentId,
            'product_id' => (string) Str::uuid(),
            'sku_snapshot' => 'SKU-1',
            'name_snapshot' => 'Test Product',
            'quantity_planned' => 10,
            'quantity_loaded' => 10,
            'status' => 'loaded',
            'requires_refrigeration' => false,
            // Never driver-confirmed — this is the "loaded but not accepted" case.
            'driver_confirmed_loaded_qty' => null,
            'created_by' => (string) Str::uuid(),
            'updated_by' => (string) Str::uuid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $data = $this->actingAs($this->user())
            ->getJson(self::BASE.'/sessions-overview?warehouse_id='.$this->warehouse->id)
            ->assertOk()
            ->json('data');

        $row = collect($data['data'])->firstWhere('session_id', $sessionId);
        self::assertNotNull($row);

        $child = collect($row['assignments'])->firstWhere('vehicle_assignment_id', $assignmentId);
        self::assertNotNull($child);

        self::assertSame($group->id, $child['group']['id']);
        self::assertSame('DG-HIST-1', $child['group']['code']);
        self::assertSame($waveId, $child['wave']['id']);

        self::assertCount(1, $child['orders']);
        self::assertSame($order->id, $child['orders'][0]['order_id']);
        self::assertTrue($child['orders'][0]['released'], 'A released Order must be reported as released.');

        self::assertSame(10.0, $child['loaded_quantity']);
        self::assertSame(0.0, $child['accepted_quantity'], 'Never driver-confirmed must count as zero accepted.');
        self::assertSame(10.0, $child['unaccepted_returned_quantity']);
        self::assertNotNull($child['closed_at']);
        self::assertSame('wave_closed_loaded_not_accepted', $child['cancellation_reason']);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function loadingSession(string $status): string
    {
        $id = (string) Str::uuid();

        DB::table('loading_sessions')->insert([
            'id' => $id,
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'session_number' => 'LS-'.substr(uniqid(), -8),
            'operational_date' => now()->toDateString(),
            'status' => $status,
            'cancelled_at' => $status === 'cancelled' ? now() : null,
            'cancellation_reason' => $status === 'cancelled' ? 'test' : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function wave(): string
    {
        $id = (string) Str::uuid();
        $date = now()->toDateString();

        DB::table('preparation_waves')->insert([
            'id' => $id,
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'wave_number' => 'W-'.substr(uniqid(), -8),
            'planning_date' => $date,
            'status' => 'closed',
            'wave_type' => 'engine',
            'starts_at' => $date.' 00:00:00',
            'ends_at' => $date.' 23:59:59',
            'created_by' => (string) Str::uuid(),
            'updated_by' => (string) Str::uuid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function window(): string
    {
        $date = now()->toDateString();

        $existing = DistributionWindow::query()
            ->where('company_id', $this->company->id)
            ->whereDate('window_date', $date)
            ->first();

        if ($existing !== null) {
            return $existing->id;
        }

        $id = (string) Str::uuid();

        DB::table('distribution_windows')->insert([
            'id' => $id,
            'company_id' => $this->company->id,
            'window_date' => $date,
            'status' => 'open',
            'opens_at' => $date.' 00:00:00',
            'closes_at' => $date.' 23:59:59',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function groupFor(string $windowId, string $waveId, string $code): VirtualCapacitySlot
    {
        return VirtualCapacitySlot::query()->create([
            'company_id' => $this->company->id,
            'distribution_window_id' => $windowId,
            'preparation_wave_id' => $waveId,
            'warehouse_id' => $this->warehouse->id,
            'code' => $code,
            'closed_at' => now(),
            'closed_reason' => 'wave_ended',
        ]);
    }

    private function order(): Order
    {
        return Order::query()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-HIST-'.uniqid(),
            'order_date' => now()->toDateString(),
            'assigned_warehouse_id' => $this->warehouse->id,
            'city' => 'Maadi',
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
}
