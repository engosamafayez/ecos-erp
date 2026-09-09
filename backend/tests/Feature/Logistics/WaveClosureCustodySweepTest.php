<?php

declare(strict_types=1);

namespace Tests\Feature\Logistics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Logistics\Distribution\Domain\Enums\TripStatus;
use Modules\Logistics\Distribution\Domain\Models\DistributionWindow;
use Modules\Logistics\Distribution\Domain\Models\Trip;
use Modules\Logistics\Distribution\Domain\Models\VirtualCapacitySlot;
use Modules\Logistics\Distribution\Domain\Services\WaveClosureCustodyService;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Operations\Loading\Domain\Enums\VehicleAssignmentStatus;
use Modules\Operations\Preparation\Domain\Events\WaveClosed;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-ECOS-OPERATIONS-DISTRIBUTION-AND-LOADING-FINAL-022 §E/§F —
 * `WaveClosureCustodyService`, exercised through the REAL `WaveClosed` event so
 * the listener wiring (`CloseWaveLoadingCustodyListener`, registered directly in
 * `LogisticsDistributionServiceProvider::boot()` alongside the pre-existing
 * `CloseWaveDistributionGroupsListener`) is under test, not just the service.
 *
 * Fixtures build a Wave -> Group -> Trip -> VehicleAssignment -> TripOrder chain
 * directly (mirroring `DistributionWaveTriggersAndBoardIsolationTest`'s own
 * `wave()`/`slotFor()`/`closeWaveEvent()` helpers), since the sweep's whole
 * purpose is reacting to state no controller endpoint currently produces
 * end-to-end (a Trip loaded, or partly loaded, but never driver-accepted).
 */
final class WaveClosureCustodySweepTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_wave_closure_preserves_a_trip_whose_driver_accepted_custody(): void
    {
        $waveId = $this->wave();
        $windowId = $this->window();
        $group = $this->groupFor($windowId, $waveId);
        $order = $this->order();
        $trip = $this->tripFor($group, accepted: true);
        $this->tripOrder($trip, $order->id);
        $assignment = $this->vehicleAssignment($trip, VehicleAssignmentStatus::LoadingComplete, loadingStarted: true);

        $this->closeWaveEvent($waveId);

        $trip->refresh();
        self::assertSame(TripStatus::LoadingCompleted, $trip->status, 'An accepted Trip must be untouched by Wave closure.');

        self::assertSame(
            VehicleAssignmentStatus::LoadingComplete->value,
            DB::table('vehicle_assignments')->where('id', $assignment)->value('status'),
        );

        self::assertNull(
            DB::table('distribution_trip_orders')
                ->where('trip_id', $trip->id)->where('order_id', $order->id)
                ->value('superseded_at'),
            'An accepted Trip\'s Order must stay actively assigned.',
        );
    }

    public function test_wave_closure_cancels_a_loaded_but_unaccepted_trip_and_returns_the_order(): void
    {
        $waveId = $this->wave();
        $windowId = $this->window();
        $group = $this->groupFor($windowId, $waveId);
        $order = $this->order();
        $trip = $this->tripFor($group, accepted: false);
        $this->tripOrder($trip, $order->id);
        $assignmentId = $this->vehicleAssignment($trip, VehicleAssignmentStatus::Loading, loadingStarted: true);

        $this->closeWaveEvent($waveId);

        $trip->refresh();
        self::assertSame(TripStatus::Cancelled, $trip->status);

        $assignmentRow = DB::table('vehicle_assignments')->where('id', $assignmentId)->first();
        self::assertSame(VehicleAssignmentStatus::Cancelled->value, $assignmentRow->status);
        self::assertSame(WaveClosureCustodyService::REASON_LOADED_NOT_ACCEPTED, $assignmentRow->cancellation_reason);
        self::assertNotNull($assignmentRow->cancelled_at);

        $tripOrderRow = DB::table('distribution_trip_orders')
            ->where('trip_id', $trip->id)->where('order_id', $order->id)->first();
        self::assertNotNull($tripOrderRow->superseded_at, 'The Order must be released back to the pool.');
        self::assertSame(WaveClosureCustodyService::REASON_LOADED_NOT_ACCEPTED, $tripOrderRow->release_reason);

        // The Order itself is untouched — it was never anything but its own status.
        self::assertSame('in_progress', DB::table('orders')->where('id', $order->id)->value('status'));
    }

    public function test_wave_closure_cancels_a_never_loaded_trip_with_a_different_reason(): void
    {
        $waveId = $this->wave();
        $windowId = $this->window();
        $group = $this->groupFor($windowId, $waveId);
        $trip = $this->tripFor($group, accepted: false);
        $assignmentId = $this->vehicleAssignment($trip, VehicleAssignmentStatus::Pending, loadingStarted: false);

        $this->closeWaveEvent($waveId);

        $trip->refresh();
        self::assertSame(TripStatus::Cancelled, $trip->status);
        self::assertSame(
            WaveClosureCustodyService::REASON_NOT_LOADED,
            DB::table('vehicle_assignments')->where('id', $assignmentId)->value('cancellation_reason'),
        );
    }

    public function test_wave_closure_sweep_is_idempotent(): void
    {
        $waveId = $this->wave();
        $windowId = $this->window();
        $group = $this->groupFor($windowId, $waveId);
        $trip = $this->tripFor($group, accepted: false);
        $this->vehicleAssignment($trip, VehicleAssignmentStatus::Loading, loadingStarted: true);

        $this->closeWaveEvent($waveId);
        $trip->refresh();
        $firstCancelledAt = $trip->updated_at;

        // A replayed/duplicated WaveClosed must not re-touch an already-terminal Trip.
        $this->closeWaveEvent($waveId);
        $trip->refresh();

        self::assertSame(TripStatus::Cancelled, $trip->status);
        self::assertSame(
            $firstCancelledAt->toIso8601String(),
            $trip->updated_at->toIso8601String(),
            'A second sweep must not re-write an already-cancelled Trip.',
        );
    }

    public function test_wave_closure_does_not_touch_a_trip_under_a_different_wave(): void
    {
        $closingWave = $this->wave();
        $openWave = $this->wave();
        $windowId = $this->window();

        $closingGroup = $this->groupFor($windowId, $closingWave);
        $openGroup = $this->groupFor($windowId, $openWave);

        $untouchedTrip = $this->tripFor($openGroup, accepted: false);
        $this->vehicleAssignment($untouchedTrip, VehicleAssignmentStatus::Loading, loadingStarted: true);

        $closingTrip = $this->tripFor($closingGroup, accepted: false);
        $this->vehicleAssignment($closingTrip, VehicleAssignmentStatus::Loading, loadingStarted: true);

        $this->closeWaveEvent($closingWave);

        self::assertSame(TripStatus::Cancelled, $closingTrip->refresh()->status);
        self::assertSame(
            TripStatus::Loading,
            $untouchedTrip->refresh()->status,
            'A Trip under a Wave that is still open must never be touched by another Wave\'s closure.',
        );
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function wave(string $status = 'preparing'): string
    {
        $id = (string) Str::uuid();
        $date = now()->toDateString();

        DB::table('preparation_waves')->insert([
            'id' => $id,
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'wave_number' => 'W-'.substr(uniqid(), -8),
            'planning_date' => $date,
            'status' => $status,
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

    private function groupFor(string $windowId, string $waveId): VirtualCapacitySlot
    {
        return VirtualCapacitySlot::query()->create([
            'company_id' => $this->company->id,
            'distribution_window_id' => $windowId,
            'preparation_wave_id' => $waveId,
            'warehouse_id' => $this->warehouse->id,
            'code' => 'DG-'.substr(uniqid(), -6),
        ]);
    }

    private function tripFor(VirtualCapacitySlot $group, bool $accepted): Trip
    {
        return Trip::query()->create([
            'company_id' => $this->company->id,
            'virtual_slot_id' => $group->id,
            'name' => $group->code,
            'trip_number' => 'TRP-'.substr(uniqid(), -8),
            'status' => $accepted ? TripStatus::LoadingCompleted->value : TripStatus::Loading->value,
            'driver_accepted_products' => $accepted,
            'driver_accepted_custody' => $accepted,
            'driver_accepted_equipment' => $accepted,
        ]);
    }

    private function tripOrder(Trip $trip, string $orderId): void
    {
        $trip->tripOrders()->create([
            'order_id' => $orderId,
            'assignment_type' => 'manual',
            'assigned_at' => now(),
        ]);
    }

    private function vehicleAssignment(Trip $trip, VehicleAssignmentStatus $status, bool $loadingStarted): string
    {
        $id = (string) Str::uuid();

        DB::table('vehicle_assignments')->insert([
            'id' => $id,
            'company_id' => $this->company->id,
            'loading_session_id' => (string) Str::uuid(),
            'trip_id' => $trip->id,
            'vehicle_id' => (string) Str::uuid(),
            'vehicle_registration_snapshot' => 'TEST-1234',
            'vehicle_type_snapshot' => 'van',
            'capacity_weight_kg_snapshot' => 500,
            'capacity_volume_m3_snapshot' => 5,
            'refrigerated_snapshot' => false,
            'assignment_number' => 'VA-'.substr(uniqid(), -8),
            'status' => $status->value,
            'orders_count' => 1,
            'loading_weight_kg' => $loadingStarted ? 10 : 0,
            'loading_volume_m3' => $loadingStarted ? 1 : 0,
            'loading_started_at' => $loadingStarted ? now() : null,
            'created_by' => (string) Str::uuid(),
            'updated_by' => (string) Str::uuid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function order(): Order
    {
        return Order::query()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-WCC-'.uniqid(),
            'order_date' => now()->toDateString(),
            'assigned_warehouse_id' => $this->warehouse->id,
            'city' => 'Maadi',
            'governorate' => 'Cairo',
            'status' => 'in_progress',
            'subtotal' => 100, 'total' => 100, 'deposit_amount' => 0,
            'shipping_total' => 0, 'discount_total' => 0, 'tax_total' => 0,
        ]);
    }

    private function closeWaveEvent(string $waveId): void
    {
        event(new WaveClosed(
            waveId: $waveId,
            waveNumber: 'W-'.substr($waveId, 0, 6),
            companyId: (string) $this->company->id,
            warehouseId: (string) $this->warehouse->id,
            planningDate: now()->toDateString(),
            closedBy: (string) Str::uuid(),
            closedAt: now()->toIso8601String(),
        ));
    }
}
