<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\Operations\Loading\Application\Actions\LoadProductAction;
use Modules\Operations\Loading\Application\Actions\RecordProductDeliveryAction;
use Modules\Operations\Loading\Domain\Enums\AllocationRecordStatus;
use Modules\Operations\Loading\Domain\Enums\DriverAssignmentStatus;
use Modules\Operations\Loading\Domain\Enums\LoadingSessionStatus;
use Modules\Operations\Loading\Domain\Enums\SessionType;
use Modules\Operations\Loading\Domain\Enums\VehicleAssignmentStatus;
use Modules\Operations\Loading\Domain\Models\AllocationRecord;
use Modules\Operations\Loading\Domain\Models\DriverAssignment;
use Modules\Operations\Loading\Domain\Models\LoadingSession;
use Modules\Operations\Loading\Domain\Models\VehicleAssignment;
use Modules\Operations\Loading\Domain\Models\VehicleInventoryItem;
use Modules\Operations\Loading\Domain\Models\VehicleShiftReconciliation;
use Modules\Operations\Loading\Domain\Services\VehicleShiftReconciliationService;
use Modules\Organization\Companies\Domain\Models\Company;
use RuntimeException;
use Tests\TestCase;

/**
 * T-09 — End-of-shift vehicle reconciliation (ADR-015 §6.4).
 *
 *   quantity_variance = loaded - delivered - returned   (must be 0)
 *
 * Every quantity in these tests is produced by its real writer:
 *
 *   loaded    LoadProductAction           → VehicleInventoryService::recordLoad()
 *   delivered RecordProductDeliveryAction → VehicleInventoryService::recordDelivery()
 *   returned  VehicleShiftReconciliationService::recordReturnedActual()
 *
 * Nothing writes quantity_delivered straight onto a row: a fixture that seeded it
 * would prove only that the arithmetic works on hand-placed numbers, which is the
 * exact failure this task exists to avoid.
 */
class VehicleShiftReconciliationTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->user = User::factory()->create(['company_id' => $this->company->id]);
    }

    // ── Fixtures — shift skeleton only; quantities always come from real writers ──

    private function makeShift(?Company $company = null): VehicleAssignment
    {
        $company ??= $this->company;
        $suffix = substr(md5(uniqid('', true)), 0, 8);

        $session = LoadingSession::create([
            'company_id' => $company->id,
            'warehouse_id' => (string) Str::uuid(),
            'session_number' => 'LS-'.$suffix,
            'operational_date' => '2026-08-18',
            'status' => LoadingSessionStatus::Loading->value,
            'session_type' => SessionType::Standard->value,
            'created_by' => (string) $this->user->id,
            'updated_by' => (string) $this->user->id,
        ]);

        $assignment = VehicleAssignment::create([
            'company_id' => $company->id,
            'loading_session_id' => $session->id,
            'vehicle_id' => (string) Str::uuid(),
            'vehicle_registration_snapshot' => 'REG-'.$suffix,
            'vehicle_type_snapshot' => 'van',
            'capacity_weight_kg_snapshot' => 1000,
            'capacity_volume_m3_snapshot' => 10,
            'assignment_number' => 'VA-'.$suffix,
            'status' => VehicleAssignmentStatus::Loading->value,
            'created_by' => (string) $this->user->id,
            'updated_by' => (string) $this->user->id,
        ]);

        DriverAssignment::create([
            'company_id' => $company->id,
            'vehicle_assignment_id' => $assignment->id,
            'loading_session_id' => $session->id,
            'vehicle_id' => $assignment->vehicle_id,
            'driver_id' => (string) Str::uuid(),
            'driver_name_snapshot' => 'Test Driver',
            'status' => DriverAssignmentStatus::Assigned->value,
            'assigned_by' => (string) $this->user->id,
            'created_by' => (string) $this->user->id,
            'updated_by' => (string) $this->user->id,
        ]);

        return $assignment->refresh();
    }

    /** Loads a product onto the shift through the real loading authority. */
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
            loadedBy: (string) $this->user->id,
        );

        return VehicleInventoryItem::where('vehicle_assignment_id', $assignment->id)
            ->where('product_id', $productId)
            ->firstOrFail();
    }

    /** The allocation bridge: order line ↔ product ↔ vehicle inventory item. */
    private function allocate(VehicleInventoryItem $item, float $quantity, ?string $orderId = null): AllocationRecord
    {
        return AllocationRecord::create([
            'company_id' => $item->company_id,
            'vehicle_assignment_id' => $item->vehicle_assignment_id,
            'loading_session_id' => VehicleAssignment::find($item->vehicle_assignment_id)?->loading_session_id,
            'vehicle_id' => $item->vehicle_id,
            'order_id' => $orderId ?? (string) Str::uuid(),
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
            'created_by' => (string) $this->user->id,
            'updated_by' => (string) $this->user->id,
        ]);
    }

    private function deliver(AllocationRecord $record, float $quantity): AllocationRecord
    {
        return app(RecordProductDeliveryAction::class)
            ->execute($record, $quantity, (string) $this->user->id);
    }

    private function service(): VehicleShiftReconciliationService
    {
        return app(VehicleShiftReconciliationService::class);
    }

    private function reconcile(VehicleAssignment $assignment): VehicleShiftReconciliation
    {
        return $this->service()->open($assignment, (string) $this->user->id);
    }

    // ── 1. Zero variance — everything delivered ──────────────────────────────

    public function test_full_delivery_reconciles_to_zero_variance(): void
    {
        $shift = $this->makeShift();
        $item = $this->load($shift, 100);
        $this->deliver($this->allocate($item, 100), 100);

        $reconciliation = $this->reconcile($shift);
        $line = $reconciliation->lines()->firstOrFail();

        $this->assertSame(100.0, (float) $line->quantity_loaded);
        $this->assertSame(100.0, (float) $line->quantity_delivered);
        $this->assertSame(0.0, (float) $line->quantity_returned_expected);
        $this->assertSame(0.0, (float) $line->variance);
        $this->assertFalse($reconciliation->has_variance);
        $this->assertTrue($this->service()->isReconciled($reconciliation));
    }

    // ── 2. Delivered + returned == loaded ────────────────────────────────────

    public function test_partial_delivery_with_matching_return_reconciles_to_zero(): void
    {
        $shift = $this->makeShift();
        $item = $this->load($shift, 100);
        $this->deliver($this->allocate($item, 100), 90);

        $reconciliation = $this->reconcile($shift);
        $line = $reconciliation->lines()->firstOrFail();

        // 100 loaded, 90 delivered → 10 expected back.
        $this->assertSame(90.0, (float) $line->quantity_delivered);
        $this->assertSame(10.0, (float) $line->quantity_returned_expected);
        $this->assertSame(10.0, (float) $line->variance, 'Nothing counted back yet, so 10 is outstanding.');

        $this->service()->recordReturnedActual($line, 10.0, null, (string) $this->user->id);

        $reconciliation->refresh();
        $this->assertSame(0.0, (float) $reconciliation->lines()->firstOrFail()->variance);
        $this->assertSame(0.0, (float) $reconciliation->total_variance);
        $this->assertFalse($reconciliation->has_variance);
    }

    // ── 3. Short return leaves a variance ────────────────────────────────────

    public function test_short_return_leaves_positive_variance(): void
    {
        $shift = $this->makeShift();
        $item = $this->load($shift, 100);
        $this->deliver($this->allocate($item, 100), 90);

        $reconciliation = $this->reconcile($shift);
        $this->service()->recordReturnedActual(
            $reconciliation->lines()->firstOrFail(),
            5.0,
            'Five units unaccounted for',
            (string) $this->user->id,
        );

        $reconciliation->refresh();

        // 100 - 90 - 5 = 5
        $this->assertSame(5.0, (float) $reconciliation->lines()->firstOrFail()->variance);
        $this->assertSame(5.0, (float) $reconciliation->total_variance);
        $this->assertTrue($reconciliation->has_variance);
        $this->assertFalse($this->service()->isReconciled($reconciliation));
    }

    // ── 4. Nothing delivered ─────────────────────────────────────────────────

    public function test_zero_delivery_returns_everything(): void
    {
        $shift = $this->makeShift();
        $item = $this->load($shift, 100);
        $record = $this->deliver($this->allocate($item, 100), 0);

        // Refusal semantics are undefined, so a zero confirmation must not
        // invent a terminal status.
        $this->assertSame(AllocationRecordStatus::Allocated, $record->status);
        $this->assertSame(0.0, (float) $record->quantity_delivered);

        $reconciliation = $this->reconcile($shift);
        $line = $reconciliation->lines()->firstOrFail();
        $this->assertSame(100.0, (float) $line->quantity_returned_expected);

        $this->service()->recordReturnedActual($line, 100.0, null, (string) $this->user->id);
        $this->assertSame(0.0, (float) $reconciliation->refresh()->total_variance);
    }

    // ── 5. Partial delivery sets the approved status and remaining ───────────

    public function test_partial_delivery_sets_partial_status_and_remaining(): void
    {
        $shift = $this->makeShift();
        $item = $this->load($shift, 100);
        $record = $this->deliver($this->allocate($item, 100), 90);

        $this->assertSame(AllocationRecordStatus::PartialDelivery, $record->status);
        $this->assertSame(90.0, (float) $record->quantity_delivered);
        // PRODUCT-ALLOCATION-ENGINE.md §6 — remaining = allocated - delivered.
        $this->assertSame(10.0, (float) $record->quantity_remaining);
    }

    public function test_full_delivery_sets_delivered_status(): void
    {
        $shift = $this->makeShift();
        $item = $this->load($shift, 100);
        $record = $this->deliver($this->allocate($item, 100), 100);

        $this->assertSame(AllocationRecordStatus::Delivered, $record->status);
        $this->assertSame(0.0, (float) $record->quantity_remaining);
    }

    // ── 6. Multiple products and order lines on one shift ────────────────────

    public function test_multiple_products_reconcile_independently(): void
    {
        $shift = $this->makeShift();

        $itemA = $this->load($shift, 100);
        $itemB = $this->load($shift, 40);

        $this->deliver($this->allocate($itemA, 100), 100);
        $this->deliver($this->allocate($itemB, 40), 30);

        $reconciliation = $this->reconcile($shift);
        $this->assertCount(2, $reconciliation->lines);

        $lineB = $reconciliation->lines()->where('product_id', $itemB->product_id)->firstOrFail();
        $this->service()->recordReturnedActual($lineB, 10.0, null, (string) $this->user->id);

        $reconciliation->refresh();
        $this->assertSame(140.0, (float) $reconciliation->total_quantity_loaded);
        $this->assertSame(130.0, (float) $reconciliation->total_quantity_delivered);
        $this->assertSame(10.0, (float) $reconciliation->total_quantity_returned);
        $this->assertSame(0.0, (float) $reconciliation->total_variance);
    }

    public function test_two_order_lines_of_the_same_product_accumulate_on_one_item(): void
    {
        $shift = $this->makeShift();
        $item = $this->load($shift, 100);

        $this->deliver($this->allocate($item, 60), 60);
        $this->deliver($this->allocate($item, 40), 25);

        $this->assertSame(85.0, (float) $item->refresh()->quantity_delivered);

        $line = $this->reconcile($shift)->lines()->firstOrFail();
        $this->assertSame(100.0, (float) $line->quantity_loaded);
        $this->assertSame(85.0, (float) $line->quantity_delivered);
        $this->assertSame(15.0, (float) $line->quantity_returned_expected);
    }

    // ── 7. Replay must not double-count ──────────────────────────────────────

    public function test_replaying_the_same_delivery_does_not_double_count(): void
    {
        $shift = $this->makeShift();
        $item = $this->load($shift, 100);
        $record = $this->allocate($item, 100);

        $this->deliver($record, 90);
        $this->deliver($record->refresh(), 90);
        $this->deliver($record->refresh(), 90);

        $this->assertSame(90.0, (float) $record->refresh()->quantity_delivered);
        $this->assertSame(90.0, (float) $item->refresh()->quantity_delivered, 'Vehicle aggregate must not accumulate on replay.');
        $this->assertSame(10.0, (float) $item->quantity_on_hand);
    }

    public function test_correcting_a_delivery_upwards_applies_only_the_difference(): void
    {
        $shift = $this->makeShift();
        $item = $this->load($shift, 100);
        $record = $this->allocate($item, 100);

        $this->deliver($record, 50);
        $this->deliver($record->refresh(), 80);

        $this->assertSame(80.0, (float) $record->refresh()->quantity_delivered);
        $this->assertSame(80.0, (float) $item->refresh()->quantity_delivered);
    }

    public function test_reopening_reconciliation_is_idempotent(): void
    {
        $shift = $this->makeShift();
        $item = $this->load($shift, 100);
        $this->deliver($this->allocate($item, 100), 90);

        $first = $this->reconcile($shift);
        $this->service()->recordReturnedActual($first->lines()->firstOrFail(), 10.0, null, (string) $this->user->id);

        $second = $this->reconcile($shift);

        $this->assertSame($first->id, $second->id, 'One reconciliation per shift.');
        $this->assertSame(1, $second->lines()->count(), 'Re-opening must not duplicate lines.');
        $this->assertSame(10.0, (float) $second->lines()->firstOrFail()->quantity_returned_actual, 'Counted return must survive a refresh.');
        $this->assertSame(0.0, (float) $second->total_variance);
    }

    // ── 8. Over-delivery has no approved contract → refused ──────────────────

    public function test_over_delivery_is_refused_because_no_contract_defines_it(): void
    {
        $shift = $this->makeShift();
        $item = $this->load($shift, 100);
        $record = $this->allocate($item, 100);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/exceeds the allocated quantity/');

        $this->deliver($record, 110);
    }

    public function test_negative_delivery_is_refused(): void
    {
        $shift = $this->makeShift();
        $item = $this->load($shift, 100);
        $record = $this->allocate($item, 100);

        $this->expectException(RuntimeException::class);

        $this->deliver($record, -5);
    }

    // ── 9. Shift and vehicle isolation ───────────────────────────────────────

    public function test_shift_a_reconciliation_never_consumes_shift_b_quantities(): void
    {
        $shiftA = $this->makeShift();
        $shiftB = $this->makeShift();

        $itemA = $this->load($shiftA, 100);
        $itemB = $this->load($shiftB, 250);

        $this->deliver($this->allocate($itemA, 100), 100);
        $this->deliver($this->allocate($itemB, 250), 200);

        $reconA = $this->reconcile($shiftA);
        $reconB = $this->reconcile($shiftB);

        $this->assertSame(100.0, (float) $reconA->total_quantity_loaded);
        $this->assertSame(100.0, (float) $reconA->total_quantity_delivered);

        $this->assertSame(250.0, (float) $reconB->total_quantity_loaded);
        $this->assertSame(200.0, (float) $reconB->total_quantity_delivered);

        $this->assertNotSame($reconA->id, $reconB->id);
        $this->assertSame(1, $reconA->lines()->count());
    }

    // ── 10. Tenant isolation ─────────────────────────────────────────────────

    public function test_reconciliation_is_scoped_to_the_owning_company(): void
    {
        $otherCompany = Company::factory()->create();

        $mine = $this->makeShift();
        $theirs = $this->makeShift($otherCompany);

        $this->deliver($this->allocate($this->load($mine, 100), 100), 100);
        $this->deliver($this->allocate($this->load($theirs, 70), 70), 70);

        $reconMine = $this->reconcile($mine);
        $reconTheirs = $this->reconcile($theirs);

        $this->assertSame($this->company->id, $reconMine->company_id);
        $this->assertSame($otherCompany->id, $reconTheirs->company_id);

        // No line of mine may carry another company's quantities.
        foreach ($reconMine->lines as $line) {
            $this->assertSame($this->company->id, $line->company_id);
        }

        $this->assertSame(100.0, (float) $reconMine->total_quantity_loaded);
        $this->assertSame(70.0, (float) $reconTheirs->total_quantity_loaded);
    }

    // ── 11. No duplicate inventory mutation ──────────────────────────────────

    public function test_delivery_records_one_movement_per_actual_change(): void
    {
        $shift = $this->makeShift();
        $item = $this->load($shift, 100);
        $record = $this->allocate($item, 100);

        $this->deliver($record, 40);
        $this->deliver($record->refresh(), 40); // replay — no movement
        $this->deliver($record->refresh(), 60); // +20

        $delivered = $item->movements()->where('movement_type', 'delivered')->get();

        $this->assertCount(2, $delivered, 'Replay must not append a movement.');
        $this->assertSame(60.0, (float) $item->refresh()->quantity_delivered);
    }

    // ── 12. Approved reconciliation is closed ────────────────────────────────

    public function test_an_approved_reconciliation_cannot_be_amended(): void
    {
        $shift = $this->makeShift();
        $item = $this->load($shift, 100);
        $this->deliver($this->allocate($item, 100), 100);

        $reconciliation = $this->reconcile($shift);
        $line = $reconciliation->lines()->firstOrFail();

        $reconciliation->update(['status' => \Modules\Operations\Loading\Domain\Enums\ReconciliationStatus::Approved->value]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/approved/');

        $this->service()->recordReturnedActual($line, 0.0, null, (string) $this->user->id);
    }
}
