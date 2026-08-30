<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\Inventory\InventoryItems\Domain\Enums\GoodsInwardMode;
use Modules\Inventory\InventoryItems\Domain\Enums\LedgerMovementType;
use Modules\Inventory\InventoryItems\Domain\Models\InventoryItem;
use Modules\Inventory\InventoryItems\Domain\Models\StockLedgerEntry;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Inventory\ReceiptLayers\Domain\Models\InventoryReceiptLayer;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceipt;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceiptLine;
use Modules\Purchasing\PurchaseOrders\Domain\Models\PurchaseOrder;
use Modules\Purchasing\PurchaseOrders\Domain\Models\PurchaseOrderLine;
use Modules\Purchasing\SupplierInvoices\Domain\Enums\SupplierInvoiceStatus;
use Modules\Purchasing\SupplierInvoices\Domain\Models\SupplierInvoice;
use Modules\Purchasing\SupplierInvoices\Domain\Models\SupplierInvoiceLine;
use Modules\Purchasing\Suppliers\Domain\Models\Supplier;
use Tests\TestCase;

/**
 * TASK-PROCUREMENT-INBOUND-OWNERSHIP-CLOSURE-001 — PART 11, the HTTP surface.
 *
 * `InboundOwnershipContractTest` proves the inbound contract by calling the actions and
 * services directly. PART 11 requires the real HTTP surface as well, because everything
 * between the route and the service — authentication, the permission middleware, route-model
 * binding and the controller's own error handling — is invisible to a service test and is
 * exactly where an inbound endpoint can be left unguarded.
 *
 * `$grantsBaselineAuthorization = false`: TestCase::actingAs() grants an is_system role to a
 * role-less user, and is_system bypasses the very checks under test here.
 */
final class InboundOwnershipHttpTest extends TestCase
{
    use RefreshDatabase;

    protected bool $grantsBaselineAuthorization = false;

    private Company $company;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
    }

    // ── actors ────────────────────────────────────────────────────────────────

    /**
     * A company-scoped actor holding exactly the named permissions and nothing else.
     *
     * @param  list<string>  $permissions
     */
    private function actor(Company $company, array $permissions = []): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);

        $slug = 'test-inbound-'.substr(md5(implode(',', $permissions).$company->id), 0, 12);
        $role = Role::firstOrCreate(
            ['slug' => $slug],
            ['name' => 'Test Inbound '.$slug, 'is_system' => false],
        );

        foreach ($permissions as $name) {
            [$module, $resource, $action] = explode('.', $name);
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                ['module' => $module, 'resource' => $resource, 'action' => $action],
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user->roles()->attach($role->id);
        $user->unsetRelation('roles');

        return $user;
    }

    // ── fixtures ──────────────────────────────────────────────────────────────

    /** @return array{0: GoodsReceipt, 1: Product} */
    private function pendingReceipt(float $qty = 100.0, float $unitPrice = 10.0, ?Company $company = null, ?Warehouse $warehouse = null): array
    {
        $co = $company ?? $this->company;
        $wh = $warehouse ?? $this->warehouse;

        $po = PurchaseOrder::factory()->approved()->create(['company_id' => $co->id]);
        $product = Product::factory()->create();

        $poLine = PurchaseOrderLine::factory()->create([
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'quantity' => $qty,
            'received_qty' => 0,
            'unit_price' => $unitPrice,
        ]);

        $receipt = GoodsReceipt::factory()->create([
            'purchase_order_id' => $po->id,
            'warehouse_id' => $wh->id,
        ]);

        GoodsReceiptLine::factory()->create([
            'goods_receipt_id' => $receipt->id,
            'purchase_order_line_id' => $poLine->id,
            'product_id' => $product->id,
            'ordered_quantity' => $qty,
            'received_quantity' => $qty,
            'gross_received_quantity' => $qty,
            'net_received_quantity' => $qty,
            'variance_quantity' => 0,
            'unit_price' => $unitPrice,
        ]);

        return [$receipt->refresh(), $product];
    }

    private function validatedInvoice(Product $product, float $qty = 40.0, float $unitPrice = 10.0, ?Company $company = null, ?Warehouse $warehouse = null): SupplierInvoice
    {
        $co = $company ?? $this->company;
        $wh = $warehouse ?? $this->warehouse;
        $supplier = Supplier::factory()->create(['company_id' => $co->id]);

        $invoice = SupplierInvoice::query()->create([
            'invoice_number' => 'SI-'.uniqid(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $wh->id,
            'company_id' => $co->id,
            'invoice_date' => now()->toDateString(),
            'status' => SupplierInvoiceStatus::Validated,
            'subtotal' => $qty * $unitPrice,
            'freight_amount' => 0,
            'additional_costs' => 0,
            'total_amount' => $qty * $unitPrice,
        ]);

        SupplierInvoiceLine::query()->create([
            'supplier_invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'quantity' => $qty,
            'unit_price' => $unitPrice,
            'line_total' => $qty * $unitPrice,
        ]);

        return $invoice->refresh();
    }

    /** Make the Supplier Invoice this company's goods-inward authority (G-1 / ADR-011 Mode 3). */
    private function useMode3(?Company $company = null): void
    {
        DB::table('companies')
            ->where('id', ($company ?? $this->company)->id)
            ->update(['goods_inward_mode' => GoodsInwardMode::SupplierInvoice->value]);
    }

    private function onHand(Product $p, ?Warehouse $w = null): float
    {
        return (float) (InventoryItem::query()
            ->where('product_id', $p->id)
            ->where('warehouse_id', ($w ?? $this->warehouse)->id)
            ->value('on_hand_qty') ?? 0.0);
    }

    private function inboundLedgerCount(Product $p): int
    {
        return StockLedgerEntry::query()
            ->where('product_id', $p->id)
            ->where('movement_type', LedgerMovementType::PurchaseReceipt->value)
            ->count();
    }

    private function layerCount(Product $p): int
    {
        return InventoryReceiptLayer::query()->where('product_id', $p->id)->count();
    }

    // ── TEST 13 — authentication ──────────────────────────────────────────────

    public function test_13a_posting_a_goods_receipt_requires_authentication(): void
    {
        [$receipt, $product] = $this->pendingReceipt();

        $this->postJson("/api/goods-receipts/{$receipt->id}/post")->assertUnauthorized();

        self::assertSame(0.0, $this->onHand($product), 'An unauthenticated request moved stock.');
        self::assertSame(0, $this->inboundLedgerCount($product));
    }

    public function test_13b_posting_a_supplier_invoice_requires_authentication(): void
    {
        [, $product] = $this->pendingReceipt();
        $invoice = $this->validatedInvoice($product);

        $this->postJson("/api/supplier-invoices/{$invoice->id}/post")->assertUnauthorized();

        self::assertSame(0.0, $this->onHand($product), 'An unauthenticated request moved stock.');
        self::assertSame(0, $this->inboundLedgerCount($product));
    }

    // ── TEST 13 — permission ──────────────────────────────────────────────────

    public function test_13c_posting_a_goods_receipt_requires_its_permission(): void
    {
        [$receipt, $product] = $this->pendingReceipt();

        // Authenticated, company-scoped, but holding an unrelated permission only.
        $this->actingAsUnprivileged($this->actor($this->company, ['purchasing.goods_receipts.view']));

        $this->postJson("/api/goods-receipts/{$receipt->id}/post")->assertForbidden();

        self::assertSame(0.0, $this->onHand($product), 'A forbidden request moved stock.');
        self::assertSame(0, $this->inboundLedgerCount($product));
    }

    public function test_13d_posting_a_supplier_invoice_requires_its_permission(): void
    {
        [, $product] = $this->pendingReceipt();
        $invoice = $this->validatedInvoice($product);

        $this->actingAsUnprivileged($this->actor($this->company, ['purchasing.supplier_invoices.view']));

        $this->postJson("/api/supplier-invoices/{$invoice->id}/post")->assertForbidden();

        self::assertSame(0.0, $this->onHand($product), 'A forbidden request moved stock.');
        self::assertSame(0, $this->inboundLedgerCount($product));
    }

    // ── TEST 1 + 3 over HTTP — receipt posts once, and only once ──────────────

    public function test_1_goods_receipt_posts_inventory_exactly_once_over_http(): void
    {
        [$receipt, $product] = $this->pendingReceipt(qty: 100, unitPrice: 10);

        $this->actingAsUnprivileged($this->actor($this->company, ['purchasing.goods_receipts.update']));

        $this->postJson("/api/goods-receipts/{$receipt->id}/post")->assertOk();

        self::assertSame(100.0, $this->onHand($product));
        self::assertSame(1, $this->inboundLedgerCount($product));
        self::assertSame(1, $this->layerCount($product), 'TEST 7 — exactly one FIFO layer.');
    }

    public function test_3_repeating_the_receipt_post_over_http_does_not_double_post(): void
    {
        [$receipt, $product] = $this->pendingReceipt(qty: 100, unitPrice: 10);

        $this->actingAsUnprivileged($this->actor($this->company, ['purchasing.goods_receipts.update']));

        $this->postJson("/api/goods-receipts/{$receipt->id}/post")->assertOk();
        $this->postJson("/api/goods-receipts/{$receipt->id}/post");

        self::assertSame(100.0, $this->onHand($product), 'Stock was posted twice.');
        self::assertSame(1, $this->inboundLedgerCount($product), 'A duplicate ledger entry was written.');
        self::assertSame(1, $this->layerCount($product), 'A duplicate FIFO layer was created.');
    }

    // ── TEST 2 + 4 over HTTP — Mode 3 invoice posts once, and only once ───────

    public function test_2_supplier_invoice_posts_inventory_exactly_once_over_http(): void
    {
        $this->useMode3();
        [, $product] = $this->pendingReceipt();
        $invoice = $this->validatedInvoice($product, qty: 40, unitPrice: 10);

        $this->actingAsUnprivileged($this->actor($this->company, ['purchasing.supplier_invoices.post']));

        $this->postJson("/api/supplier-invoices/{$invoice->id}/post")->assertOk();

        self::assertSame(40.0, $this->onHand($product));
        self::assertSame(1, $this->inboundLedgerCount($product));
        self::assertSame(1, $this->layerCount($product), 'TEST 7 — exactly one FIFO layer.');
        self::assertSame(SupplierInvoiceStatus::Posted, $invoice->refresh()->status);
    }

    public function test_4_repeating_the_invoice_post_over_http_does_not_double_post(): void
    {
        $this->useMode3();
        [, $product] = $this->pendingReceipt();
        $invoice = $this->validatedInvoice($product, qty: 40, unitPrice: 10);

        $this->actingAsUnprivileged($this->actor($this->company, ['purchasing.supplier_invoices.post']));

        $this->postJson("/api/supplier-invoices/{$invoice->id}/post")->assertOk();
        $this->postJson("/api/supplier-invoices/{$invoice->id}/post");

        self::assertSame(40.0, $this->onHand($product), 'Stock was posted twice.');
        self::assertSame(1, $this->inboundLedgerCount($product), 'A duplicate ledger entry was written.');
        self::assertSame(1, $this->layerCount($product), 'A duplicate FIFO layer was created.');
    }

    // ── TEST 12 — tenant isolation at the HTTP boundary ───────────────────────

    /**
     * B-2, repaired. An actor holding the correct permission but affiliated with a DIFFERENT
     * company must not be able to drive another company's inbound. The permission is
     * deliberately granted, which isolates the TENANT boundary from the permission gate.
     *
     * Before the repair this returned 200 and moved 50 units into the other company's
     * warehouse. `GoodsReceipt` now carries the same tenant global scope as the four models
     * that already had one, so the foreign row is invisible, the repository lookup returns
     * null, and the existing not-found exception yields the 404 the certified ECOS tenant
     * contract expects (`SupplierTenantIsolationTest` asserts the same shape for suppliers).
     */
    public function test_12_an_actor_cannot_post_another_companys_goods_receipt(): void
    {
        $other = Company::factory()->create();
        $otherWarehouse = Warehouse::factory()->create(['company_id' => $other->id]);

        [$foreignReceipt, $product] = $this->pendingReceipt(
            qty: 50, unitPrice: 10, company: $other, warehouse: $otherWarehouse,
        );

        $this->actingAsUnprivileged($this->actor($this->company, ['purchasing.goods_receipts.update']));

        $this->postJson("/api/goods-receipts/{$foreignReceipt->id}/post")->assertNotFound();

        // ZERO mutations — quantity, ledger and FIFO all untouched.
        self::assertSame(0.0, $this->onHand($product, $otherWarehouse), "Another company's stock was posted.");
        self::assertSame(0, $this->inboundLedgerCount($product), 'A ledger entry was written.');
        self::assertSame(0, $this->layerCount($product), 'A FIFO layer was created.');
    }

    /**
     * The same boundary on the invoice path. This previously returned 422 — no stock leaked,
     * but only because company degraded to '' and a NOT NULL/FK constraint rejected the
     * insert. A database constraint is not authorization, and it would stop protecting the
     * moment the column became resolvable, so the boundary is now explicit.
     */
    public function test_12b_an_actor_cannot_post_another_companys_supplier_invoice(): void
    {
        $other = Company::factory()->create();
        $otherWarehouse = Warehouse::factory()->create(['company_id' => $other->id]);
        $this->useMode3($other);

        $product = Product::factory()->create();
        $invoice = $this->validatedInvoice($product, qty: 25, unitPrice: 10, company: $other, warehouse: $otherWarehouse);

        $this->actingAsUnprivileged($this->actor($this->company, ['purchasing.supplier_invoices.post']));

        $this->postJson("/api/supplier-invoices/{$invoice->id}/post")->assertNotFound();

        self::assertSame(0.0, $this->onHand($product, $otherWarehouse), "Another company's stock was posted.");
        self::assertSame(0, $this->inboundLedgerCount($product), 'A ledger entry was written.');
        self::assertSame(0, $this->layerCount($product), 'A FIFO layer was created.');
    }

    /** Own-company posting must still work — the scope must not close the door on everyone. */
    public function test_12c_an_actor_can_still_post_its_own_companys_inbound(): void
    {
        [$receipt, $product] = $this->pendingReceipt(qty: 30, unitPrice: 10);

        $this->actingAsUnprivileged($this->actor($this->company, ['purchasing.goods_receipts.update']));

        $this->postJson("/api/goods-receipts/{$receipt->id}/post")->assertOk();

        self::assertSame(30.0, $this->onHand($product));
        self::assertSame(1, $this->inboundLedgerCount($product));
        self::assertSame(1, $this->layerCount($product));
    }
}
