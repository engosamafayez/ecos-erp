<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Operations\DemandAnalysis\Application\Services\MaterialDemandCalculator;
use Modules\Operations\Preparation\Domain\Models\PreparationWave;
use Modules\Organization\Companies\Domain\Models\Company;
use ReflectionMethod;
use Tests\TestCase;

/**
 * TASK-ECOS-POST-DRIVER-RETURN-WAREHOUSE-RETURNS-FINAL-IMPLEMENTATION-002 §26.
 *
 * Pins MaterialDemandCalculator::expectedDriverReturns() — the "Expected
 * Driver Returns" figure (§12/§14) — in isolation via Reflection, rather than
 * through the full calculate() pipeline (which additionally requires a
 * populated wave_product_demand + an active BOM/recipe fixture per §the
 * calculator's own docblock, orthogonal to what this task changed). This
 * keeps the fixture to exactly the tables this task's change reads:
 * `vehicle_inventory_items` (+ its `vehicle_assignments`/`loading_sessions`
 * parents) and `inventory_items` — proving the two figures come from
 * genuinely disjoint sources and so can never double-count (§16).
 *
 * NOT RUN as part of this task (Validation Freeze, §27) — reviewed for
 * correctness, not executed.
 */
final class ExpectedDriverReturnsShortageProjectionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_expected_driver_returns_sums_outstanding_vehicle_custody_at_the_waves_warehouse(): void
    {
        $company = Company::factory()->create();
        $warehouse = Warehouse::factory()->create(['company_id' => $company->id]);
        $productId = (string) Str::uuid();

        $wave = $this->makeWave($company->id, $warehouse->id);

        // Physical Available's OWN source: on-hand stock actually in the warehouse ledger.
        // This must stay completely unaffected by anything on a vehicle (§16).
        $this->insertInventoryItem($company->id, $warehouse->id, $productId, onHand: 10.0);

        // Two open vehicle assignments both still carrying this product — simulating
        // exactly §16's scenario: Trip 1's undelivered units (still with the old driver)
        // plus, deliberately, a second unrelated vehicle also carrying stock — proving the
        // sum is warehouse-wide, not scoped to any one order's Trip (§12).
        $this->insertOutstandingVehicleCustody($company->id, $warehouse->id, $productId, onHand: 4.0);
        $this->insertOutstandingVehicleCustody($company->id, $warehouse->id, $productId, onHand: 1.5);

        // A vehicle item already fully reconciled (on_hand = 0) must NOT be counted —
        // it is no longer outstanding custody, whether via delivery or prior receipt.
        $this->insertOutstandingVehicleCustody($company->id, $warehouse->id, $productId, onHand: 0.0);

        $calculator = app(MaterialDemandCalculator::class);
        $method = new ReflectionMethod($calculator, 'expectedDriverReturns');
        $method->setAccessible(true);

        /** @var array<string, float> $result */
        $result = $method->invoke($calculator, $wave, [$productId]);

        self::assertSame(5.5, $result[$productId] ?? null, '4.0 + 1.5 outstanding; the zeroed-out item contributes nothing');
    }

    public function test_expected_driver_returns_is_scoped_to_the_waves_own_warehouse(): void
    {
        $company = Company::factory()->create();
        $thisWarehouse = Warehouse::factory()->create(['company_id' => $company->id]);
        $otherWarehouse = Warehouse::factory()->create(['company_id' => $company->id]);
        $productId = (string) Str::uuid();

        $wave = $this->makeWave($company->id, $thisWarehouse->id);

        // Outstanding custody at a DIFFERENT warehouse must not leak into this wave's figure.
        $this->insertOutstandingVehicleCustody($company->id, $otherWarehouse->id, $productId, onHand: 7.0);

        $calculator = app(MaterialDemandCalculator::class);
        $method = new ReflectionMethod($calculator, 'expectedDriverReturns');
        $method->setAccessible(true);

        $result = $method->invoke($calculator, $wave, [$productId]);

        self::assertArrayNotHasKey($productId, $result, 'no outstanding custody at THIS warehouse — nothing to report');
    }

    // ── Fixture ───────────────────────────────────────────────────────────────

    private function makeWave(string $companyId, string $warehouseId): PreparationWave
    {
        $waveId = (string) Str::uuid();

        DB::table('preparation_waves')->insert([
            'id' => $waveId,
            'company_id' => $companyId,
            'warehouse_id' => $warehouseId,
            'wave_number' => 'WAVE-'.substr(md5($waveId), 0, 8),
            'planning_date' => now()->toDateString(),
            'status' => 'preparing',
            'created_by' => (string) Str::uuid(),
            'updated_by' => (string) Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return PreparationWave::query()->findOrFail($waveId);
    }

    private function insertInventoryItem(string $companyId, string $warehouseId, string $productId, float $onHand): void
    {
        DB::table('inventory_items')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'on_hand_qty' => $onHand,
            'reserved_qty' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** A vehicle assignment at $warehouseId still carrying $onHand units of $productId. */
    private function insertOutstandingVehicleCustody(
        string $companyId,
        string $warehouseId,
        string $productId,
        float $onHand,
    ): void {
        $sessionId = (string) Str::uuid();
        DB::table('loading_sessions')->insert([
            'id' => $sessionId,
            'company_id' => $companyId,
            'warehouse_id' => $warehouseId,
            'session_number' => 'LS-'.substr(md5($sessionId), 0, 8),
            'operational_date' => now()->toDateString(),
            'status' => 'completed',
            'created_by' => (string) Str::uuid(),
            'updated_by' => (string) Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $assignmentId = (string) Str::uuid();
        DB::table('vehicle_assignments')->insert([
            'id' => $assignmentId,
            'company_id' => $companyId,
            'loading_session_id' => $sessionId,
            'vehicle_id' => (string) Str::uuid(),
            'vehicle_registration_snapshot' => 'PL-TEST',
            'vehicle_type_snapshot' => 'van',
            'assignment_number' => 'VA-'.substr(md5($assignmentId), 0, 8),
            'status' => 'dispatched',
            'created_by' => (string) Str::uuid(),
            'updated_by' => (string) Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('vehicle_inventory_items')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'vehicle_assignment_id' => $assignmentId,
            'vehicle_id' => (string) Str::uuid(),
            'product_id' => $productId,
            'sku_snapshot' => 'SKU-EDR',
            'name_snapshot' => 'Expected Driver Returns Test Product',
            'operational_date' => now()->toDateString(),
            'quantity_loaded' => $onHand + 1,
            'quantity_allocated' => 0,
            'quantity_delivered' => 1,
            'quantity_returned' => 0,
            'quantity_on_hand' => $onHand,
            'quantity_unallocated' => 0,
            'requires_refrigeration' => false,
            'status' => $onHand > 0 ? 'active' : 'depleted',
            'created_by' => (string) Str::uuid(),
            'updated_by' => (string) Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
