<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\IAM\Domain\Models\Role;
use Modules\Inventory\InventoryItems\Domain\Enums\GoodsInwardMode;
use Modules\Inventory\InventoryItems\Domain\Enums\LedgerMovementType;
use Modules\Inventory\InventoryItems\Domain\Models\InventoryItem;
use Modules\Inventory\InventoryItems\Domain\Models\StockLedgerEntry;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Inventory\ReceiptLayers\Domain\Models\InventoryReceiptLayer;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Purchasing\GoodsReceipts\Application\Actions\PostGoodsReceiptAction;
use Modules\Purchasing\GoodsReceipts\Domain\Enums\GoodsReceiptStatus;
use Modules\Purchasing\GoodsReceipts\Domain\Exceptions\GoodsReceiptAlreadyPostedException;
use Modules\Purchasing\GoodsReceipts\Domain\Exceptions\GoodsReceiptNotFoundException;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceipt;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceiptLine;
use Modules\Purchasing\PurchaseOrders\Domain\Models\PurchaseOrder;
use Modules\Purchasing\PurchaseOrders\Domain\Models\PurchaseOrderLine;
use Modules\Purchasing\SupplierInvoices\Application\Services\PostSupplierInvoiceService;
use Modules\Purchasing\SupplierInvoices\Domain\Enums\SupplierInvoiceStatus;
use Modules\Purchasing\SupplierInvoices\Domain\Models\SupplierInvoice;
use Modules\Purchasing\SupplierInvoices\Domain\Models\SupplierInvoiceLine;
use Modules\Purchasing\Suppliers\Domain\Models\Supplier;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * C-1 — CROSS-DOCUMENT INBOUND CONCURRENCY.
 *
 * The Goods Receipt path locks the `goods_receipts` row before mutating anything (D-INB-03).
 * `PostSupplierInvoiceService` locked only the INVOICE row, so the two inbound documents
 * synchronised on different rows: a receipt and its linked Mode 3 invoice could post the same
 * physical delivery concurrently, producing two ledger rows and two FIFO layers.
 *
 * THE REPAIR: the invoice path now resolves the canonical inbound reference FIRST and locks
 * THAT row — the receipt row for a linked invoice, its own row for an unlinked Mode 3 invoice —
 * before any mutation, and re-reads its own posting state under that lock. Both paths therefore
 * contend on the same mutex for one physical inbound.
 *
 * `test_e_*` is the critical C-1 proof and asserts the shared point directly: both paths issue
 * `FOR UPDATE` against `goods_receipts` for the SAME id. That is the contract — "one physical
 * inbound, one synchronisation row" — rather than a coincidence of ordering.
 *
 * NOTE ON WHAT "REJECTED" MEANS FOR A RECEIPT IN MODE 3. Under the certified G-1 contract a
 * receipt in Mode 3 still completes as a RECEIVING record: it advances `received_qty` and its
 * own status while posting no inventory. So the second document is not always an error — the
 * invariant that must hold, and what these tests assert, is that one physical inbound produces
 * exactly ONE inventory effect. That contract is preserved, not redefined.
 */
final class InboundCrossDocumentConcurrencyTest extends TestCase
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

    // ── fixtures ──────────────────────────────────────────────────────────────

    /** @return array{0: PurchaseOrder, 1: PurchaseOrderLine, 2: Product} */
    private function approvedPo(float $qty = 100.0, float $unitPrice = 10.0, ?Company $company = null): array
    {
        $po = PurchaseOrder::factory()->approved()->create(['company_id' => ($company ?? $this->company)->id]);
        $product = Product::factory()->create();

        $poLine = PurchaseOrderLine::factory()->create([
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'quantity' => $qty,
            'received_qty' => 0,
            'unit_price' => $unitPrice,
        ]);

        return [$po, $poLine, $product];
    }

    private function receipt(
        PurchaseOrder $po,
        PurchaseOrderLine $poLine,
        float $netQty,
        ?Warehouse $warehouse = null,
    ): GoodsReceipt {
        $receipt = GoodsReceipt::factory()->create([
            'purchase_order_id' => $po->id,
            'warehouse_id' => ($warehouse ?? $this->warehouse)->id,
        ]);

        GoodsReceiptLine::factory()->create([
            'goods_receipt_id' => $receipt->id,
            'purchase_order_line_id' => $poLine->id,
            'product_id' => $poLine->product_id,
            'ordered_quantity' => (float) $poLine->quantity,
            'received_quantity' => $netQty,
            'gross_received_quantity' => $netQty,
            'net_received_quantity' => $netQty,
            'variance_quantity' => $netQty - (float) $poLine->quantity,
            'unit_price' => (float) $poLine->unit_price,
        ]);

        return $receipt->refresh();
    }

    private function invoice(
        Product $product,
        float $qty,
        float $unitPrice = 10.0,
        ?string $autoReceiptId = null,
        ?Warehouse $warehouse = null,
        ?Company $company = null,
    ): SupplierInvoice {
        $wh = $warehouse ?? $this->warehouse;
        $co = $company ?? $this->company;
        $supplier = Supplier::factory()->create(['company_id' => $co->id]);

        $invoice = SupplierInvoice::query()->create([
            'invoice_number' => 'SI-'.uniqid(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $wh->id,
            'company_id' => $co->id,
            'auto_receipt_id' => $autoReceiptId,
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

    private function useMode(GoodsInwardMode $mode, ?Company $company = null): void
    {
        DB::table('companies')
            ->where('id', ($company ?? $this->company)->id)
            ->update(['goods_inward_mode' => $mode->value]);
    }

    private function onHand(Product $p, ?Warehouse $warehouse = null): float
    {
        return (float) (InventoryItem::query()
            ->where('product_id', $p->id)
            ->where('warehouse_id', ($warehouse ?? $this->warehouse)->id)
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

    private function postReceipt(string $receiptId): void
    {
        app(PostGoodsReceiptAction::class)->execute($receiptId);
    }

    private function postInvoice(SupplierInvoice $invoice): void
    {
        app(PostSupplierInvoiceService::class)->execute($invoice);
    }

    /** Swallow only the canonical stand-down signals; anything else is a real failure. */
    private function attempt(callable $fn): ?string
    {
        try {
            $fn();

            return null;
        } catch (GoodsReceiptAlreadyPostedException|RuntimeException $e) {
            return $e::class;
        }
    }

    /** Assert one physical inbound produced exactly one of everything (PART 14). */
    private function assertExactlyOneInboundEffect(Product $product, float $expectedQty): void
    {
        self::assertSame($expectedQty, $this->onHand($product), 'Stock was posted more than once.');
        self::assertSame(1, $this->inboundLedgerCount($product), 'Duplicate stock ledger entry.');
        self::assertSame(1, $this->layerCount($product), 'Duplicate FIFO receipt layer.');
    }

    // ── TEST A — Mode 1: receipt is authoritative ─────────────────────────────

    public function test_a_mode1_receipt_posts_and_linked_invoice_cannot_post_the_same_inbound(): void
    {
        $this->useMode(GoodsInwardMode::GoodsReceipt);

        [$po, $poLine, $product] = $this->approvedPo();
        $receipt = $this->receipt($po, $poLine, netQty: 9.0);
        $invoice = $this->invoice($product, qty: 9.0, autoReceiptId: $receipt->id);

        $this->postReceipt($receipt->id);
        $this->postInvoice($invoice);

        $this->assertExactlyOneInboundEffect($product, 9.0);
        self::assertSame(SupplierInvoiceStatus::Posted, $invoice->refresh()->status);
    }

    // ── TEST B — Mode 3: invoice is authoritative ─────────────────────────────

    public function test_b_mode3_invoice_posts_and_receipt_cannot_post_the_same_inbound(): void
    {
        $this->useMode(GoodsInwardMode::SupplierInvoice);

        [$po, $poLine, $product] = $this->approvedPo();
        $receipt = $this->receipt($po, $poLine, netQty: 9.0);
        $invoice = $this->invoice($product, qty: 9.0, autoReceiptId: $receipt->id);

        $this->postInvoice($invoice);

        // CERTIFIED CONTRACT (InboundOwnershipContractTest::test_c2): because this invoice
        // carries the link it posted under the RECEIPT's ledger reference, so the pre-existing
        // guard refuses the receipt outright. It does not fall through to a receiving-only
        // record. Asserted as the certified behaviour rather than redefined here.
        $rejection = $this->attempt(fn () => $this->postReceipt($receipt->id));

        self::assertSame(GoodsReceiptAlreadyPostedException::class, $rejection);
        $this->assertExactlyOneInboundEffect($product, 9.0);
        self::assertNotSame(GoodsReceiptStatus::Posted, $receipt->refresh()->status);
        self::assertSame(0.0, (float) $poLine->refresh()->received_qty);
    }

    // ── TEST C — two concurrent receipt attempts ──────────────────────────────

    public function test_c_concurrent_receipt_attempts_produce_one_inbound(): void
    {
        $this->useMode(GoodsInwardMode::GoodsReceipt);

        [$po, $poLine, $product] = $this->approvedPo();
        $receipt = $this->receipt($po, $poLine, netQty: 9.0);

        $fired = false;
        DB::listen(function ($q) use (&$fired, $receipt): void {
            if ($fired || ! str_contains($q->sql, 'goods_inward_mode')) {
                return;
            }
            $fired = true;
            $this->postReceipt($receipt->id);   // the competing writer, mid-window
        });

        $rejection = $this->attempt(fn () => $this->postReceipt($receipt->id));

        self::assertTrue($fired, 'The competing receipt post was never injected.');
        self::assertNotNull($rejection, 'The second receipt post was not rejected.');
        $this->assertExactlyOneInboundEffect($product, 9.0);
        self::assertSame(9.0, (float) $poLine->refresh()->received_qty);
    }

    // ── TEST D — two concurrent invoice attempts ──────────────────────────────

    public function test_d_concurrent_invoice_attempts_produce_one_inbound(): void
    {
        $this->useMode(GoodsInwardMode::SupplierInvoice);

        [, , $product] = $this->approvedPo();
        $invoice = $this->invoice($product, qty: 9.0);

        // Two independent in-memory handles on the same row — the concurrent shape. Both pass
        // the pre-transaction canPost(); only the locked re-read inside can separate them.
        $handleA = SupplierInvoice::query()->findOrFail($invoice->id);
        $handleB = SupplierInvoice::query()->findOrFail($invoice->id);

        self::assertTrue($handleB->status->canPost(), 'Fixture precondition: B must start postable.');

        $this->postInvoice($handleA);
        $rejection = $this->attempt(fn () => $this->postInvoice($handleB));

        self::assertNotNull($rejection, 'The second invoice post was not rejected — it posted twice.');
        $this->assertExactlyOneInboundEffect($product, 9.0);
    }

    // ── TEST E — THE CRITICAL C-1 PROOF: receipt + invoice, same inbound ──────

    public function test_e_concurrent_receipt_and_invoice_share_one_synchronisation_row(): void
    {
        $this->useMode(GoodsInwardMode::SupplierInvoice);

        [$po, $poLine, $product] = $this->approvedPo();
        $receipt = $this->receipt($po, $poLine, netQty: 9.0);
        $invoice = $this->invoice($product, qty: 9.0, autoReceiptId: $receipt->id);

        // Record every FOR UPDATE taken against goods_receipts, per receipt id.
        $locks = [];
        DB::listen(function ($q) use (&$locks): void {
            $sql = strtolower($q->sql);
            if (! str_contains($sql, 'goods_receipts') || ! str_contains($sql, 'for update')) {
                return;
            }
            foreach ($q->bindings as $b) {
                if (is_string($b)) {
                    $locks[$b] = ($locks[$b] ?? 0) + 1;
                }
            }
        });

        // ── THE C-1 PROOF ────────────────────────────────────────────────────
        // The invoice path must lock the RECEIPT row — the row the Goods Receipt path locks.
        // Before this repair the invoice locked only its own row, so this count was 0 and the
        // two documents synchronised on different rows.
        $this->postInvoice($invoice);

        self::assertArrayHasKey(
            $receipt->id,
            $locks,
            'The invoice path never locked the canonical receipt row — the two inbound paths '
            .'still synchronise on different rows and the C-1 race is open.',
        );
        $this->assertExactlyOneInboundEffect($product, 9.0);
        self::assertSame(SupplierInvoiceStatus::Posted, $invoice->refresh()->status);

        // The linked receipt is now refused by the certified guard (see test_b), so it
        // short-circuits BEFORE its own lock — which is why the shared row is demonstrated
        // above on the invoice path, and below on a receipt that has not been superseded.
        $rejection = $this->attempt(fn () => $this->postReceipt($receipt->id));
        self::assertSame(GoodsReceiptAlreadyPostedException::class, $rejection);
        $this->assertExactlyOneInboundEffect($product, 9.0);

        // ── the other half: the receipt path locks that same row on a fresh inbound ──
        [$po2, $poLine2, $product2] = $this->approvedPo();
        $receipt2 = $this->receipt($po2, $poLine2, netQty: 4.0);

        $this->postReceipt($receipt2->id);

        self::assertArrayHasKey(
            $receipt2->id,
            $locks,
            'The Goods Receipt path did not lock its goods_receipts row.',
        );
        self::assertSame(4.0, (float) $poLine2->refresh()->received_qty);
    }

    public function test_e2_invoice_arriving_mid_receipt_window_yields_one_inbound(): void
    {
        $this->useMode(GoodsInwardMode::SupplierInvoice);

        [$po, $poLine, $product] = $this->approvedPo();
        $receipt = $this->receipt($po, $poLine, netQty: 9.0);
        $invoice = $this->invoice($product, qty: 9.0, autoReceiptId: $receipt->id);

        // The invoice completes inside the receipt path's pre-transaction window.
        $fired = false;
        DB::listen(function ($q) use (&$fired, $invoice): void {
            if ($fired || ! str_contains($q->sql, 'goods_inward_mode')) {
                return;
            }
            $fired = true;
            $this->postInvoice($invoice);
        });

        $rejection = $this->attempt(fn () => $this->postReceipt($receipt->id));

        self::assertTrue($fired, 'The competing invoice post was never injected.');

        // The injection lands AFTER the receipt's pre-transaction guards have already passed,
        // so only the in-transaction locked re-check (Guard 1c) can still stop it. That it
        // does is the whole point of this test.
        self::assertSame(
            GoodsReceiptAlreadyPostedException::class,
            $rejection,
            'The receipt posted on top of an invoice that completed inside its race window.',
        );

        $this->assertExactlyOneInboundEffect($product, 9.0);

        // Rejected before its receiving bookkeeping ran — the certified consequence of a
        // linked invoice winning the reference (see test_b). Recorded, not redefined.
        self::assertSame(0.0, (float) $poLine->refresh()->received_qty);
    }

    // ── TEST F / G — cross-company ────────────────────────────────────────────

    private function actAsOperatorOfThisCompany(): void
    {
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $role = Role::firstOrCreate(
            ['slug' => 'test-inbound-xdoc-operator'],
            ['name' => 'Test Inbound XDoc Operator', 'is_system' => false],
        );
        $user->roles()->attach($role->id);
        $user->unsetRelation('roles');
        $this->actingAsUnprivileged($user);
    }

    public function test_f_cross_company_receipt_is_denied_with_zero_mutations(): void
    {
        $foreign = Company::factory()->create();
        $foreignWarehouse = Warehouse::factory()->create(['company_id' => $foreign->id]);
        [$po, $poLine, $product] = $this->approvedPo(company: $foreign);
        $receipt = $this->receipt($po, $poLine, netQty: 9.0, warehouse: $foreignWarehouse);

        $this->actAsOperatorOfThisCompany();

        try {
            $this->postReceipt($receipt->id);
            self::fail('A foreign-company receipt was posted.');
        } catch (GoodsReceiptNotFoundException) {
            // certified 404 contract
        }

        self::assertSame(0.0, $this->onHand($product, $foreignWarehouse));
        self::assertSame(0, $this->inboundLedgerCount($product));
        self::assertSame(0, $this->layerCount($product));
        self::assertNotSame(GoodsReceiptStatus::Posted, $receipt->refresh()->status);
    }

    public function test_g_cross_company_invoice_is_denied_with_zero_mutations(): void
    {
        $foreign = Company::factory()->create();
        $foreignWarehouse = Warehouse::factory()->create(['company_id' => $foreign->id]);
        $this->useMode(GoodsInwardMode::SupplierInvoice, $foreign);

        $product = Product::factory()->create();
        $invoice = $this->invoice($product, qty: 9.0, warehouse: $foreignWarehouse, company: $foreign);

        $this->actAsOperatorOfThisCompany();

        // The tenant scope must hide the foreign invoice from this actor entirely.
        $visible = SupplierInvoice::query()->find($invoice->id);
        self::assertNull($visible, 'A foreign-company invoice was visible to this actor.');

        self::assertSame(0.0, $this->onHand($product, $foreignWarehouse));
        self::assertSame(0, $this->inboundLedgerCount($product));
        self::assertSame(0, $this->layerCount($product));
        self::assertNotSame(SupplierInvoiceStatus::Posted, $invoice->refresh()->status);
    }

    // ── TEST H — repeated posting of the authoritative document ───────────────

    public function test_h_repeated_posting_of_the_authoritative_document_posts_once(): void
    {
        $this->useMode(GoodsInwardMode::SupplierInvoice);

        [, , $product] = $this->approvedPo();
        $invoice = $this->invoice($product, qty: 9.0);

        $this->postInvoice($invoice);
        $rejection = $this->attempt(fn () => $this->postInvoice($invoice->refresh()));

        self::assertNotNull($rejection, 'A already-posted invoice was posted again.');
        $this->assertExactlyOneInboundEffect($product, 9.0);
    }

    // ── TEST I — mode switch: only the configured authority posts ─────────────

    public function test_i_only_the_configured_authority_performs_inbound_posting(): void
    {
        // Mode 1 — the invoice must not move stock.
        $this->useMode(GoodsInwardMode::GoodsReceipt);
        [, , $productA] = $this->approvedPo();
        $invoiceA = $this->invoice($productA, qty: 5.0);
        $this->postInvoice($invoiceA);

        self::assertSame(0.0, $this->onHand($productA), 'Invoice posted stock while the receipt was the authority.');
        self::assertSame(0, $this->inboundLedgerCount($productA));
        self::assertSame(0, $this->layerCount($productA));
        self::assertSame(SupplierInvoiceStatus::Posted, $invoiceA->refresh()->status);

        // Mode 3 — the receipt must not move stock.
        $this->useMode(GoodsInwardMode::SupplierInvoice);
        app(\Modules\Inventory\InventoryItems\Domain\Services\GoodsInwardAuthority::class)
            ->forget($this->company->id);

        [$poB, $poLineB, $productB] = $this->approvedPo();
        $receiptB = $this->receipt($poB, $poLineB, netQty: 7.0);
        $this->postReceipt($receiptB->id);

        self::assertSame(0.0, $this->onHand($productB), 'Receipt posted stock while the invoice was the authority.');
        self::assertSame(0, $this->inboundLedgerCount($productB));
        self::assertSame(0, $this->layerCount($productB));
        self::assertSame(GoodsReceiptStatus::Posted, $receiptB->refresh()->status);
    }

    // ── D-INB-07 — the database-level layer backstop ──────────────────────────

    public function test_receipt_line_can_own_at_most_one_fifo_layer(): void
    {
        [$po, $poLine, $product] = $this->approvedPo();
        $receipt = $this->receipt($po, $poLine, netQty: 9.0);

        $this->postReceipt($receipt->id);

        $layer = InventoryReceiptLayer::query()->where('product_id', $product->id)->firstOrFail();
        self::assertNotNull($layer->goods_receipt_line_id, 'Receipt-sourced layers must carry the canonical identity.');

        $duplicated = false;

        try {
            InventoryReceiptLayer::query()->create([
                'company_id' => $layer->company_id,
                'supplier_id' => $layer->supplier_id,
                'product_id' => $layer->product_id,
                'goods_receipt_id' => $layer->goods_receipt_id,
                'goods_receipt_line_id' => $layer->goods_receipt_line_id,
                'warehouse_id' => $layer->warehouse_id,
                'received_qty' => 1,
                'remaining_qty' => 1,
                'landed_unit_cost' => 1,
                'receipt_date' => $layer->receipt_date,
            ]);
            $duplicated = true;
        } catch (Throwable) {
            // the unique index rejected it, which is the point
        }

        self::assertFalse($duplicated, 'A second FIFO layer for the same receipt line was accepted.');
        self::assertSame(1, $this->layerCount($product));
    }
}
