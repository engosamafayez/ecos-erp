<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Models\User;
use BackedEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Logistics\Drivers\Domain\Models\Driver;
use Modules\Operations\Loading\Application\Actions\LoadProductAction;
use Modules\Operations\Loading\Domain\Enums\LoadingSessionStatus;
use Modules\Operations\Loading\Domain\Enums\LoadingTaskStatus;
use Modules\Operations\Loading\Domain\Enums\SessionType;
use Modules\Operations\Loading\Domain\Enums\VehicleAssignmentStatus;
use Modules\Operations\Loading\Domain\Models\LoadingSession;
use Modules\Operations\Loading\Domain\Models\LoadingTask;
use Modules\Operations\Loading\Domain\Models\VehicleAssignment;
use Modules\Operations\Loading\Domain\Models\VehicleInventoryItem;
use Modules\Organization\Companies\Domain\Models\Company;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-DRIVER-WAVE-1-GROUP-LOADING-IMPLEMENTATION-001 (Option 1).
 *
 * The Group-grain driver loading contract: the existing LoadProductAction now
 * accepts NULL pool_entry_id / preparation_wave_id and still moves the ACTUAL
 * loaded quantity into the existing vehicle inventory. Pool-based loading is
 * unchanged. Plus the driver loading endpoint's fail-closed ownership + empty state.
 */
final class GroupGrainDriverLoadingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->user = User::factory()->create(['company_id' => $this->company->id]);
    }

    private function makeAssignment(?string $vehicleId = null): VehicleAssignment
    {
        $suffix = substr(md5(uniqid('', true)), 0, 6);

        $session = LoadingSession::create([
            'company_id' => $this->company->id,
            'warehouse_id' => (string) Str::uuid(),
            'session_number' => 'LS-'.$suffix,
            'operational_date' => '2026-08-25',
            'status' => LoadingSessionStatus::Loading->value,
            'session_type' => SessionType::Standard->value,
            'created_by' => (string) $this->user->id,
            'updated_by' => (string) $this->user->id,
        ]);

        return VehicleAssignment::create([
            'company_id' => $this->company->id,
            'loading_session_id' => $session->id,
            'vehicle_id' => $vehicleId ?? (string) Str::uuid(),
            'vehicle_registration_snapshot' => 'REG-'.$suffix,
            'vehicle_type_snapshot' => 'van',
            'capacity_weight_kg_snapshot' => 1000,
            'capacity_volume_m3_snapshot' => 10,
            'assignment_number' => 'VA-'.$suffix,
            'status' => VehicleAssignmentStatus::Loading->value,
            'created_by' => (string) $this->user->id,
            'updated_by' => (string) $this->user->id,
        ]);
    }

    private function loadGroupGrain(VehicleAssignment $a, string $productId, float $planned, float $loaded): void
    {
        app(LoadProductAction::class)->execute(
            assignment: $a,
            poolEntryId: null,        // Group grain — no pool provenance
            productId: $productId,
            skuSnapshot: 'SKU-X',
            nameSnapshot: 'Honey 500g',
            preparationWaveId: null,  // Group grain — no preparation wave
            quantityPlanned: $planned,
            quantityLoaded: $loaded,
            loadedBy: (string) $this->user->id,
        );
    }

    private function statusValue(mixed $status): string
    {
        return $status instanceof BackedEnum ? (string) $status->value : (string) $status;
    }

    public function test_group_grain_load_persists_with_null_pool_provenance(): void
    {
        $a = $this->makeAssignment();
        $pid = (string) Str::uuid();

        $this->loadGroupGrain($a, $pid, 20, 18);

        $task = LoadingTask::where('vehicle_assignment_id', $a->id)->where('product_id', $pid)->firstOrFail();

        $this->assertNull($task->pool_entry_id);
        $this->assertNull($task->preparation_wave_id);
        $this->assertSame(20.0, (float) $task->quantity_planned);
        $this->assertSame(18.0, (float) $task->quantity_loaded);
        $this->assertSame(2.0, (float) $task->quantity_short);
        $this->assertSame(LoadingTaskStatus::ShortLoaded->value, $this->statusValue($task->status));
    }

    public function test_actual_loaded_quantity_reaches_vehicle_inventory_not_required(): void
    {
        $a = $this->makeAssignment();
        $pid = (string) Str::uuid();

        $this->loadGroupGrain($a, $pid, 20, 18);

        $item = VehicleInventoryItem::where('vehicle_assignment_id', $a->id)->where('product_id', $pid)->firstOrFail();

        $this->assertSame(18.0, (float) $item->quantity_loaded); // the actual 18, never the required 20
        $this->assertNull($item->pool_entry_id);
    }

    public function test_vehicle_inventory_accumulates_across_cycles_without_reset(): void
    {
        $vehicle = (string) Str::uuid();
        $pid = (string) Str::uuid();

        // Cycle 1 (assignment A): 10 on the vehicle.
        $a1 = $this->makeAssignment($vehicle);
        $this->loadGroupGrain($a1, $pid, 10, 10);

        // Cycle 2 (a new assignment for the same vehicle): +18.
        $a2 = $this->makeAssignment($vehicle);
        $this->loadGroupGrain($a2, $pid, 18, 18);

        $total = (float) VehicleInventoryItem::where('vehicle_id', $vehicle)->where('product_id', $pid)->sum('quantity_loaded');
        $this->assertSame(28.0, $total); // 10 + 18 — the earlier cycle was not reset

        $cycle1 = (float) VehicleInventoryItem::where('vehicle_assignment_id', $a1->id)->where('product_id', $pid)->value('quantity_loaded');
        $this->assertSame(10.0, $cycle1);
    }

    public function test_over_loading_is_rejected_and_writes_nothing(): void
    {
        $a = $this->makeAssignment();
        $pid = (string) Str::uuid();

        try {
            $this->loadGroupGrain($a, $pid, 20, 21);
            $this->fail('Over-load (loaded > required) should have been refused.');
        } catch (RuntimeException) {
            // expected — fail closed
        }

        $this->assertSame(0, LoadingTask::where('vehicle_assignment_id', $a->id)->count());
        $this->assertSame(0, VehicleInventoryItem::where('vehicle_assignment_id', $a->id)->count());
    }

    public function test_pool_based_loading_still_records_its_provenance(): void
    {
        $a = $this->makeAssignment();
        $pid = (string) Str::uuid();
        $pool = (string) Str::uuid();
        $wave = (string) Str::uuid();

        app(LoadProductAction::class)->execute(
            assignment: $a,
            poolEntryId: $pool,
            productId: $pid,
            skuSnapshot: 'SKU-Y',
            nameSnapshot: 'Nuts',
            preparationWaveId: $wave,
            quantityPlanned: 10,
            quantityLoaded: 10,
            loadedBy: (string) $this->user->id,
        );

        $task = LoadingTask::where('vehicle_assignment_id', $a->id)->where('product_id', $pid)->firstOrFail();
        $this->assertSame($pool, $task->pool_entry_id);
        $this->assertSame($wave, $task->preparation_wave_id);
    }

    public function test_non_driver_cannot_read_the_loading_manifest(): void
    {
        // A real, permitted user who is NOT a logistics_driver must be refused.
        $this->actingAs($this->user)
            ->getJson('/api/driver/loading')
            ->assertStatus(403);
    }

    public function test_driver_with_no_shipment_gets_an_empty_manifest(): void
    {
        $this->actingAs($this->user);

        $suffix = substr(md5(uniqid('', true)), 0, 8);
        Driver::create([
            'user_id' => $this->user->id,
            'driver_code' => 'DRV-'.$suffix,
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Test Driver',
            'mobile' => '01'.substr($suffix, 0, 8),
            'national_id' => 'NID-'.$suffix,
            'status' => 'active',
        ]);

        $this->getJson('/api/driver/loading')
            ->assertOk()
            ->assertJsonPath('data.shipment', null)
            ->assertJsonPath('data.items', []);
    }
}
