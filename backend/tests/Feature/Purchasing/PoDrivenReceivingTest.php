<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\InventoryItems\Domain\Models\InventoryItem;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Purchasing\PurchaseOrders\Domain\Enums\PurchaseOrderStatus;
use Modules\Purchasing\PurchaseOrders\Domain\Models\PurchaseOrder;
use Modules\Purchasing\PurchaseOrders\Domain\Models\PurchaseOrderLine;
use Modules\Purchasing\SupplierInvoices\Domain\Models\SupplierInvoice;
use Tests\TestCase;

/**
 * TASK-PROCUREMENT-PO-DRIVEN-RECEIVING-CENTER-001 — the Receiving Center as a receivable-PO queue,
 * receiving through the certified Goods Receipt authority (Create + Post). No new inventory
 * authority; the Supplier Invoice remains untouched.
 */
class PoDrivenReceivingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Warehouse $warehouse;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $this->user = User::factory()->create(['company_id' => $this->company->id]);
    }

    /** @return array{0: PurchaseOrder, 1: PurchaseOrderLine, 2: Product} */
    private function makeApprovedPo(float $qty = 100.0, float $price = 10.0): array
    {
        $po = PurchaseOrder::factory()->approved()->create([
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
        ]);
        $product = Product::factory()->create();
        $line = PurchaseOrderLine::factory()->create([
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'quantity' => $qty,
            'received_qty' => 0,
            'unit_price' => $price,
        ]);

        return [$po, $line, $product];
    }

    /** @return array<int, array<string, mixed>> */
    private function receivePayload(PurchaseOrderLine $line, float $qty): array
    {
        return ['lines' => [['purchase_order_line_id' => $line->id, 'receive_now' => $qty]]];
    }

    // ── Queue ──────────────────────────────────────────────────────────────────

    public function test_queue_lists_receivable_po_with_canonical_aggregates(): void
    {
        [$po] = $this->makeApprovedPo(100.0);

        $this->actingAs($this->user)
            ->getJson('/api/receiving/queue?scope=active')
            ->assertOk()
            ->assertJsonPath('data.scope', 'active')
            ->assertJsonPath('data.kpis.awaiting', 1)
            ->assertJsonPath('data.items.0.id', $po->id)
            ->assertJsonPath('data.items.0.product_count', 1)
            ->assertJsonPath('data.items.0.expected_qty', fn ($v): bool => (float) $v === 100.0)
            ->assertJsonPath('data.items.0.received_qty', fn ($v): bool => (float) $v === 0.0)
            ->assertJsonPath('data.items.0.remaining_qty', fn ($v): bool => (float) $v === 100.0)
            ->assertJsonPath('data.items.0.status', PurchaseOrderStatus::Approved->value);
    }

    public function test_draft_po_is_not_receivable_and_absent_from_the_queue(): void
    {
        PurchaseOrder::factory()->create([
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrderStatus::Draft->value,
        ]);

        $this->actingAs($this->user)
            ->getJson('/api/receiving/queue?scope=active')
            ->assertOk()
            ->assertJsonCount(0, 'data.items')
            ->assertJsonPath('data.kpis.awaiting', 0);
    }

    // ── Supplier filter / tenancy ───────────────────────────────────────────────

    public function test_queue_supplier_filter_narrows_results_server_side(): void
    {
        [$poA] = $this->makeApprovedPo(100.0);
        [$poB] = $this->makeApprovedPo(100.0);

        // Unfiltered active queue lists both receivable POs (distinct suppliers via the factory).
        $this->actingAs($this->user)
            ->getJson('/api/receiving/queue?scope=active')
            ->assertOk()
            ->assertJsonCount(2, 'data.items');

        // Filtering by supplier narrows to that supplier's PO only — the canonical server-side
        // `supplier_id` where-clause, not client-side filtering.
        $this->actingAs($this->user)
            ->getJson("/api/receiving/queue?scope=active&supplier_id={$poA->supplier_id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $poA->id);

        // The other supplier yields only the other PO.
        $this->actingAs($this->user)
            ->getJson("/api/receiving/queue?scope=active&supplier_id={$poB->supplier_id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $poB->id);

        // Clearing the supplier filter restores the full queue.
        $this->actingAs($this->user)
            ->getJson('/api/receiving/queue?scope=active')
            ->assertOk()
            ->assertJsonCount(2, 'data.items');
    }

    public function test_queue_is_company_scoped_and_never_exposes_another_companys_orders(): void
    {
        [$mine] = $this->makeApprovedPo(100.0);

        $otherCompany = Company::factory()->create();
        $otherWarehouse = Warehouse::factory()->create(['company_id' => $otherCompany->id]);
        $otherPo = PurchaseOrder::factory()->approved()->create([
            'company_id' => $otherCompany->id,
            'warehouse_id' => $otherWarehouse->id,
        ]);

        // The acting user's queue shows only their own company's PO.
        $this->actingAs($this->user)
            ->getJson('/api/receiving/queue?scope=active')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $mine->id);

        // Even filtering by another company's supplier exposes nothing — the company boundary wins,
        // so a supplier_id from outside the tenant never leaks another company's purchase orders.
        $this->actingAs($this->user)
            ->getJson("/api/receiving/queue?scope=active&supplier_id={$otherPo->supplier_id}")
            ->assertOk()
            ->assertJsonCount(0, 'data.items');
    }

    // ── Partial / full receipt ─────────────────────────────────────────────────

    public function test_partial_receipt_uses_actual_quantity_and_keeps_po_receivable(): void
    {
        [$po, $line, $product] = $this->makeApprovedPo(100.0);

        $this->actingAs($this->user)
            ->postJson("/api/receiving/purchase-orders/{$po->id}/receive", $this->receivePayload($line, 70.0))
            ->assertOk();

        // Actual received advanced on the canonical PO line; PO moves to Partially Received.
        $this->assertSame('70.0000', $line->fresh()->received_qty);
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $po->fresh()->status);

        // Inventory increased by the ACTUAL received quantity (70), via the canonical action.
        $item = InventoryItem::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $product->id)
            ->firstOrFail();
        $this->assertSame('70.0000', $item->on_hand_qty);

        // The same PO remains in the active queue with the correct remaining quantity.
        $this->actingAs($this->user)
            ->getJson('/api/receiving/queue?scope=active')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $po->id)
            ->assertJsonPath('data.items.0.received_qty', fn ($v): bool => (float) $v === 70.0)
            ->assertJsonPath('data.items.0.remaining_qty', fn ($v): bool => (float) $v === 30.0);
    }

    public function test_full_receipt_leaves_active_and_appears_in_history(): void
    {
        [$po, $line] = $this->makeApprovedPo(100.0);

        $this->actingAs($this->user)
            ->postJson("/api/receiving/purchase-orders/{$po->id}/receive", $this->receivePayload($line, 100.0))
            ->assertOk();

        $this->assertSame(PurchaseOrderStatus::Received, $po->fresh()->status);

        $this->actingAs($this->user)->getJson('/api/receiving/queue?scope=active')
            ->assertOk()->assertJsonCount(0, 'data.items');

        $this->actingAs($this->user)->getJson('/api/receiving/queue?scope=history')
            ->assertOk()->assertJsonPath('data.items.0.id', $po->id);
    }

    // ── Guards ─────────────────────────────────────────────────────────────────

    public function test_over_receipt_is_rejected_by_the_canonical_ceiling(): void
    {
        [$po, $line, $product] = $this->makeApprovedPo(100.0);

        $this->actingAs($this->user)
            ->postJson("/api/receiving/purchase-orders/{$po->id}/receive", $this->receivePayload($line, 150.0))
            ->assertStatus(422);

        // Nothing posted — the whole receive rolled back (over-receipt ceiling in PostGoodsReceiptAction).
        $this->assertSame('0.0000', $line->fresh()->received_qty);
        $this->assertSame(PurchaseOrderStatus::Approved, $po->fresh()->status);
        $this->assertDatabaseMissing('inventory_items', [
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $product->id,
        ]);
    }

    public function test_receiving_never_creates_or_touches_a_supplier_invoice(): void
    {
        [$po, $line] = $this->makeApprovedPo(100.0);

        $this->actingAs($this->user)
            ->postJson("/api/receiving/purchase-orders/{$po->id}/receive", $this->receivePayload($line, 100.0))
            ->assertOk();

        // The receipt is the inventory authority; no Supplier Invoice is created or required.
        $this->assertSame(0, SupplierInvoice::query()->withoutGlobalScopes()->count());
    }

    public function test_unauthorized_user_cannot_receive(): void
    {
        [$po, $line] = $this->makeApprovedPo(100.0);

        $this->actingAsUnprivileged($this->user)
            ->postJson("/api/receiving/purchase-orders/{$po->id}/receive", $this->receivePayload($line, 50.0))
            ->assertForbidden();

        $this->assertSame('0.0000', $line->fresh()->received_qty);
    }
}
