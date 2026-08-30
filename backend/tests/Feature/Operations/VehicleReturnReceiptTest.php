<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Inventory\InventoryItems\Domain\Models\InventoryItem;
use Modules\Inventory\InventoryItems\Domain\Models\StockLedgerEntry;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Inventory\ReceiptLayers\Domain\Models\InventoryReceiptLayer;
use Modules\Operations\Loading\Application\Actions\LoadProductAction;
use Modules\Operations\Loading\Application\Actions\ReceiveVehicleReturnAction;
use Modules\Operations\Loading\Application\Actions\RecordProductDeliveryAction;
use Modules\Operations\Loading\Domain\Enums\AllocationRecordStatus;
use Modules\Operations\Loading\Domain\Enums\DriverAssignmentStatus;
use Modules\Operations\Loading\Domain\Enums\LoadingSessionStatus;
use Modules\Operations\Loading\Domain\Enums\ReconciliationStatus;
use Modules\Operations\Loading\Domain\Enums\SessionType;
use Modules\Operations\Loading\Domain\Enums\VehicleAssignmentStatus;
use Modules\Operations\Loading\Domain\Models\AllocationRecord;
use Modules\Operations\Loading\Domain\Models\DriverAssignment;
use Modules\Operations\Loading\Domain\Models\LoadingSession;
use Modules\Operations\Loading\Domain\Models\VehicleAssignment;
use Modules\Operations\Loading\Domain\Models\VehicleInventoryItem;
use Modules\Operations\Loading\Domain\Models\VehicleInventoryMovement;
use Modules\Operations\Loading\Domain\Models\VehicleShiftReconciliation;
use Modules\Operations\Loading\Domain\Models\VehicleShiftReconciliationLine;
use Modules\Operations\Loading\Domain\Services\VehicleShiftReconciliationService;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-OPERATIONAL-FULFILLMENT-RETURNS-RECONCILIATION-001 — Warehouse Return Receipt.
 *
 * Every quantity is produced by its real writer:
 *   loaded    LoadProductAction           → VehicleInventoryService::recordLoad()
 *   delivered RecordProductDeliveryAction → VehicleInventoryService::recordDelivery()
 *   received  ReceiveVehicleReturnAction  → recordReturnedActual + reconcileReturn + AdjustmentIn
 *
 * The receipt is the ONLY inventory event for a vehicle return: accepted good stock goes
 * back to the warehouse via the canonical AdjustmentIn (+ FIFO layer); damaged is kept out
 * of good stock; shortage stays visible as the reconciliation variance; custody reconciles.
 */
class VehicleReturnReceiptTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Warehouse $warehouse;

    private string $actorId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $this->actorId = (string) Str::uuid();
    }

    // ═══════════════════ Expected-return derivation (§6/§7 of the task) ═══════════════════

    public function test_full_delivery_yields_zero_expected_return(): void
    {
        [$shift, $item] = $this->loadedShift(10.0);
        $this->deliver($this->allocate($item, 10.0), 10.0);

        $line = $this->open($shift)->lines()->firstOrFail();

        self::assertSame(10.0, (float) $line->quantity_loaded);
        self::assertSame(10.0, (float) $line->quantity_delivered);
        self::assertSame(0.0, (float) $line->quantity_returned_expected);
    }

    public function test_partial_delivery_yields_correct_expected_return(): void
    {
        [$shift, $item] = $this->loadedShift(10.0);
        $this->deliver($this->allocate($item, 10.0), 6.0);

        $line = $this->open($shift)->lines()->firstOrFail();

        self::assertSame(4.0, (float) $line->quantity_returned_expected, 'expected = loaded 10 − delivered 6');
    }

    public function test_failed_delivery_yields_full_expected_return(): void
    {
        [$shift, $item] = $this->loadedShift(10.0);
        // No delivery recorded at all.
        $line = $this->open($shift)->lines()->firstOrFail();

        self::assertSame(0.0, (float) $line->quantity_delivered);
        self::assertSame(10.0, (float) $line->quantity_returned_expected, 'nothing delivered → full load expected back');
    }

    // ═══════════════════ Warehouse actual receipt ═══════════════════

    public function test_warehouse_accepts_full_return_restocks_and_reconciles(): void
    {
        $product = $this->product();
        [$shift, $item] = $this->loadedShift(10.0, $product->id);
        $this->deliver($this->allocate($item, 10.0), 6.0); // expected return 4
        $line = $this->open($shift)->lines()->firstOrFail();

        app(ReceiveVehicleReturnAction::class)->execute($line, quantityAccepted: 4.0, quantityDamaged: 0.0, damageReason: null, actorId: $this->actorId);

        $line->refresh();
        self::assertSame(4.0, (float) $line->quantity_returned_actual);
        self::assertSame(4.0, (float) $line->quantity_accepted);
        self::assertSame(0.0, (float) $line->variance, 'balanced');
        self::assertNotNull($line->warehouse_receipt_at);

        // Accepted good stock reached the warehouse via AdjustmentIn (+ FIFO layer).
        self::assertSame(4.0, $this->warehouseOnHand($product->id), 'accepted return restocked to warehouse');
        self::assertSame(1, $this->adjustmentInCount($product->id, $line->id));
        self::assertSame(4.0, (float) InventoryReceiptLayer::where('product_id', $product->id)->sum('remaining_qty'));

        // Vehicle custody reconciled: returned 4, on_hand 0.
        $custody = VehicleInventoryItem::whereKey($item->id)->firstOrFail();
        self::assertSame(4.0, (float) $custody->quantity_returned);
        self::assertSame(0.0, (float) $custody->quantity_on_hand, 'loaded 10 − delivered 6 − returned 4 = 0');

        // Shift closed balanced.
        $header = VehicleShiftReconciliation::whereKey($line->reconciliation_id)->firstOrFail();
        self::assertSame(ReconciliationStatus::Completed, $header->status);
        self::assertFalse($header->has_variance);
    }

    public function test_partial_receipt_leaves_visible_shortage_and_disputes_the_shift(): void
    {
        $product = $this->product();
        [$shift, $item] = $this->loadedShift(10.0, $product->id);
        $this->deliver($this->allocate($item, 10.0), 6.0); // expected return 4
        $line = $this->open($shift)->lines()->firstOrFail();

        app(ReceiveVehicleReturnAction::class)->execute($line, quantityAccepted: 3.0, quantityDamaged: 0.0, damageReason: null, actorId: $this->actorId);

        $line->refresh();
        self::assertSame(3.0, (float) $line->quantity_returned_actual);
        self::assertSame(1.0, (float) $line->variance, 'shortage = expected 4 − actual 3 = 1 (visible)');

        self::assertSame(3.0, $this->warehouseOnHand($product->id), 'only the 3 accepted units restocked');

        $custody = VehicleInventoryItem::whereKey($item->id)->firstOrFail();
        self::assertSame(1.0, (float) $custody->quantity_on_hand, 'the shortage remains on custody, not forced to zero');

        $header = VehicleShiftReconciliation::whereKey($line->reconciliation_id)->firstOrFail();
        self::assertTrue($header->has_variance, 'shortage is visible');
        self::assertSame(ReconciliationStatus::Disputed, $header->status, 'unresolved shortage keeps the shift open/disputed');
    }

    public function test_damage_is_captured_and_kept_out_of_good_stock(): void
    {
        $product = $this->product();
        [$shift, $item] = $this->loadedShift(10.0, $product->id);
        $this->deliver($this->allocate($item, 10.0), 6.0); // expected return 4
        $line = $this->open($shift)->lines()->firstOrFail();

        app(ReceiveVehicleReturnAction::class)->execute($line, quantityAccepted: 3.0, quantityDamaged: 1.0, damageReason: 'crushed in transit', actorId: $this->actorId);

        $line->refresh();
        self::assertSame(4.0, (float) $line->quantity_returned_actual, 'accepted 3 + damaged 1 = 4 received');
        self::assertSame(3.0, (float) $line->quantity_accepted);
        self::assertSame(1.0, (float) $line->quantity_damaged);
        self::assertSame('crushed in transit', $line->damage_reason);
        self::assertSame(0.0, (float) $line->variance, 'all 4 received (3 good + 1 damaged) → no shortage');

        // Only the 3 accepted (good) units enter warehouse stock; the damaged unit never does.
        self::assertSame(3.0, $this->warehouseOnHand($product->id), 'damaged qty must NOT enter good warehouse stock');
        self::assertSame(3.0, (float) InventoryReceiptLayer::where('product_id', $product->id)->sum('remaining_qty'));

        // Custody reconciles the full received (accepted + damaged) off the vehicle.
        $custody = VehicleInventoryItem::whereKey($item->id)->firstOrFail();
        self::assertSame(4.0, (float) $custody->quantity_returned);
        self::assertSame(0.0, (float) $custody->quantity_on_hand);
    }

    // ═══════════════════ Idempotency & atomicity ═══════════════════

    public function test_duplicate_receipt_is_idempotent(): void
    {
        $product = $this->product();
        [$shift, $item] = $this->loadedShift(10.0, $product->id);
        $this->deliver($this->allocate($item, 10.0), 6.0);
        $line = $this->open($shift)->lines()->firstOrFail();

        $action = app(ReceiveVehicleReturnAction::class);
        $action->execute($line, 4.0, 0.0, null, $this->actorId);
        $action->execute($line->fresh(), 4.0, 0.0, null, $this->actorId); // repeat with same values

        // Warehouse credited once, one ledger movement, one FIFO layer, one custody return movement.
        self::assertSame(4.0, $this->warehouseOnHand($product->id), 'warehouse not double-credited');
        self::assertSame(1, $this->adjustmentInCount($product->id, $line->id), 'one adjustment_in ledger row');
        self::assertSame(1, InventoryReceiptLayer::where('product_id', $product->id)->count(), 'one FIFO layer');
        self::assertSame(
            1,
            VehicleInventoryMovement::where('vehicle_inventory_item_id', $item->id)->where('movement_type', 'returned')->count(),
            'one custody return movement',
        );
        self::assertSame(4.0, (float) VehicleInventoryItem::whereKey($item->id)->value('quantity_returned'), 'custody not double-returned');
    }

    public function test_conflicting_re_receipt_is_refused(): void
    {
        $product = $this->product();
        [$shift, $item] = $this->loadedShift(10.0, $product->id);
        $this->deliver($this->allocate($item, 10.0), 6.0);
        $line = $this->open($shift)->lines()->firstOrFail();

        app(ReceiveVehicleReturnAction::class)->execute($line, 4.0, 0.0, null, $this->actorId);

        $this->expectException(RuntimeException::class);
        app(ReceiveVehicleReturnAction::class)->execute($line->fresh(), 2.0, 0.0, null, $this->actorId);
    }

    public function test_transaction_rolls_back_when_the_warehouse_movement_fails(): void
    {
        $product = $this->product();
        // A shift whose session points at a non-existent warehouse: the canonical
        // AdjustmentIn's inventory_items insert then fails the warehouse FK mid-receipt,
        // proving the whole business transaction rolls back atomically (§8).
        $bogusWarehouseId = (string) Str::uuid();
        [$shift, $item] = $this->loadedShift(10.0, $product->id, warehouseId: $bogusWarehouseId);
        $this->deliver($this->allocate($item, 10.0), 6.0);
        $line = $this->open($shift)->lines()->firstOrFail();

        $threw = false;
        try {
            app(ReceiveVehicleReturnAction::class)->execute($line, 4.0, 0.0, null, $this->actorId);
        } catch (\Throwable $e) {
            $threw = true;
        }

        self::assertTrue($threw, 'the failed warehouse movement must propagate');

        // Nothing persisted: line not marked received, actual reverted, custody untouched, no layer.
        $line->refresh();
        self::assertNull($line->warehouse_receipt_at, 'receipt marker rolled back');
        self::assertSame(0.0, (float) $line->quantity_returned_actual, 'actual receipt rolled back');
        self::assertSame(0, InventoryReceiptLayer::where('product_id', $product->id)->count(), 'no FIFO layer created');
        self::assertSame(0.0, (float) VehicleInventoryItem::whereKey($item->id)->value('quantity_returned'), 'custody rolled back');
    }

    // ═══════════════════ Validation (§11 / §6) ═══════════════════

    public function test_over_receipt_beyond_expected_is_refused(): void
    {
        [$shift, $item] = $this->loadedShift(10.0);
        $this->deliver($this->allocate($item, 10.0), 6.0); // expected 4
        $line = $this->open($shift)->lines()->firstOrFail();

        $this->expectException(RuntimeException::class);
        app(ReceiveVehicleReturnAction::class)->execute($line, 5.0, 0.0, null, $this->actorId); // 5 > 4
    }

    public function test_negative_quantity_is_refused(): void
    {
        [$shift, $item] = $this->loadedShift(10.0);
        $this->deliver($this->allocate($item, 10.0), 6.0);
        $line = $this->open($shift)->lines()->firstOrFail();

        $this->expectException(RuntimeException::class);
        app(ReceiveVehicleReturnAction::class)->execute($line, -1.0, 0.0, null, $this->actorId);
    }

    // ═══════════════════ Fixtures (real writers only) ═══════════════════

    private function product(): Product
    {
        return Product::factory()->create([
            'company_id' => $this->company->id,
            'current_fifo_cost' => 12.5,
        ]);
    }

    /** @return array{0: VehicleAssignment, 1: VehicleInventoryItem} */
    private function loadedShift(float $quantity, ?string $productId = null, ?string $warehouseId = null): array
    {
        $shift = $this->makeShift($warehouseId);
        $item = $this->load($shift, $quantity, $productId);

        return [$shift, $item];
    }

    private function makeShift(?string $warehouseId = null): VehicleAssignment
    {
        $suffix = substr(md5(uniqid('', true)), 0, 8);

        $session = LoadingSession::create([
            'company_id' => $this->company->id,
            'warehouse_id' => $warehouseId ?? $this->warehouse->id,
            'session_number' => 'LS-'.$suffix,
            'operational_date' => '2026-08-18',
            'status' => LoadingSessionStatus::Loading->value,
            'session_type' => SessionType::Standard->value,
            'created_by' => $this->actorId,
            'updated_by' => $this->actorId,
        ]);

        $assignment = VehicleAssignment::create([
            'company_id' => $this->company->id,
            'loading_session_id' => $session->id,
            'vehicle_id' => (string) Str::uuid(),
            'vehicle_registration_snapshot' => 'REG-'.$suffix,
            'vehicle_type_snapshot' => 'van',
            'capacity_weight_kg_snapshot' => 1000,
            'capacity_volume_m3_snapshot' => 10,
            'assignment_number' => 'VA-'.$suffix,
            'status' => VehicleAssignmentStatus::Loading->value,
            'created_by' => $this->actorId,
            'updated_by' => $this->actorId,
        ]);

        DriverAssignment::create([
            'company_id' => $this->company->id,
            'vehicle_assignment_id' => $assignment->id,
            'loading_session_id' => $session->id,
            'vehicle_id' => $assignment->vehicle_id,
            'driver_id' => (string) Str::uuid(),
            'driver_name_snapshot' => 'Test Driver',
            'status' => DriverAssignmentStatus::Assigned->value,
            'assigned_by' => $this->actorId,
            'created_by' => $this->actorId,
            'updated_by' => $this->actorId,
        ]);

        return $assignment->refresh();
    }

    private function load(VehicleAssignment $assignment, float $quantity, ?string $productId = null): VehicleInventoryItem
    {
        $productId ??= (string) Str::uuid();

        app(LoadProductAction::class)->execute(
            assignment: $assignment,
            poolEntryId: (string) Str::uuid(),
            productId: $productId,
            skuSnapshot: 'SKU-'.substr(md5($productId), 0, 6),
            nameSnapshot: 'Test Product',
            preparationWaveId: (string) Str::uuid(),
            quantityPlanned: $quantity,
            quantityLoaded: $quantity,
            loadedBy: $this->actorId,
        );

        return VehicleInventoryItem::where('vehicle_assignment_id', $assignment->id)
            ->where('product_id', $productId)
            ->firstOrFail();
    }

    private function allocate(VehicleInventoryItem $item, float $quantity): AllocationRecord
    {
        return AllocationRecord::create([
            'company_id' => $item->company_id,
            'vehicle_assignment_id' => $item->vehicle_assignment_id,
            'loading_session_id' => VehicleAssignment::find($item->vehicle_assignment_id)?->loading_session_id,
            'vehicle_id' => $item->vehicle_id,
            'order_id' => (string) Str::uuid(),
            'order_line_id' => (string) Str::uuid(),
            'order_number_snapshot' => 'ORD-'.substr(md5(uniqid('', true)), 0, 6),
            'product_id' => $item->product_id,
            'sku_snapshot' => $item->sku_snapshot,
            'vehicle_inventory_item_id' => $item->id,
            'allocation_mode' => 'full_auto',
            'priority_rank' => 1,
            'quantity_requested' => $quantity,
            'quantity_allocated' => $quantity,
            'quantity_loaded' => 0.0,
            'quantity_delivered' => 0.0,
            'quantity_remaining' => $quantity,
            'is_partial' => false,
            'status' => AllocationRecordStatus::Allocated->value,
            'allocated_at' => now(),
            'allocated_by' => 'system',
            'created_by' => $this->actorId,
            'updated_by' => $this->actorId,
        ]);
    }

    private function deliver(AllocationRecord $record, float $quantity): AllocationRecord
    {
        return app(RecordProductDeliveryAction::class)->execute($record, $quantity, $this->actorId);
    }

    private function open(VehicleAssignment $assignment): VehicleShiftReconciliation
    {
        return app(VehicleShiftReconciliationService::class)->open($assignment, $this->actorId);
    }

    private function warehouseOnHand(string $productId): float
    {
        $item = InventoryItem::where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $productId)
            ->first();

        return $item ? (float) $item->on_hand_qty : 0.0;
    }

    private function adjustmentInCount(string $productId, string $lineId): int
    {
        return StockLedgerEntry::where('product_id', $productId)
            ->where('movement_type', 'adjustment_in')
            ->where('reference_type', 'vehicle_return')
            ->where('reference_id', $lineId)
            ->count();
    }
}
