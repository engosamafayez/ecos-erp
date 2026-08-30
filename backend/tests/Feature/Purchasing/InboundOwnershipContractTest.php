<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\InventoryItems\Domain\Enums\GoodsInwardMode;
use Modules\Inventory\InventoryItems\Domain\Enums\LedgerMovementType;
use Modules\Inventory\InventoryItems\Domain\Models\InventoryItem;
use Modules\Inventory\InventoryItems\Domain\Models\StockLedgerEntry;
use Modules\Inventory\InventoryItems\Domain\Services\InboundPostingGuard;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Inventory\ReceiptLayers\Domain\Models\InventoryReceiptLayer;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Purchasing\GoodsReceipts\Application\Actions\PostGoodsReceiptAction;
use Modules\Purchasing\GoodsReceipts\Domain\Exceptions\GoodsReceiptAlreadyPostedException;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceipt;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceiptLine;
use Modules\Purchasing\PurchaseOrders\Domain\Models\PurchaseOrder;
use Modules\Purchasing\PurchaseOrders\Domain\Models\PurchaseOrderLine;
use Modules\Purchasing\SupplierInvoices\Application\Services\PostSupplierInvoiceService;
use Modules\Purchasing\SupplierInvoices\Domain\Enums\SupplierInvoiceStatus;
use Modules\Purchasing\SupplierInvoices\Domain\Models\SupplierInvoice;
use Modules\Purchasing\SupplierInvoices\Domain\Models\SupplierInvoiceLine;
use Modules\Purchasing\SupplierReturns\Application\Actions\ApproveSupplierReturnAction;
use Modules\Purchasing\SupplierReturns\Domain\Enums\SupplierReturnStatus;
use Modules\Purchasing\SupplierReturns\Domain\Models\SupplierReturn;
use Modules\Purchasing\SupplierReturns\Domain\Models\SupplierReturnLine;
use Modules\Purchasing\Suppliers\Domain\Models\Supplier;
use Tests\Support\ProvisionsCompanyFinance;
use Tests\TestCase;
use Throwable;

/**
 * P-7 GOODS-INWARD OWNERSHIP CONTRACT.
 *
 * Goods Receipt and Mode 3 Supplier Invoice are both valid inbound SOURCE documents, but the
 * physical delivery they describe must reach inventory EXACTLY ONCE — one quantity mutation,
 * one ledger entry, one FIFO layer, one cost propagation.
 *
 * Before the repair the two paths were blind to each other: `supplier_invoices.auto_receipt_id`
 * is a real FK to `goods_receipts`, yet invoice posting never consulted it, so a delivery with
 * both documents was posted twice.
 *
 * The idempotency key is the SHARED LEDGER REFERENCE: an invoice carrying `auto_receipt_id`
 * posts under that receipt's reference, so whichever document posts first wins.
 */
final class InboundOwnershipContractTest extends TestCase
{
    use ProvisionsCompanyFinance;
    use RefreshDatabase;

    private Company $company;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        // D-A1 makes Mode 1 invoices post a real payable, which resolves accounts by role.
        $this->provisionFinance($this->company);
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
    }

    // ── fixtures ──────────────────────────────────────────────────────────────

    /** @return array{0: PurchaseOrder, 1: PurchaseOrderLine, 2: Product} */
    private function approvedPo(float $qty = 100.0, float $unitPrice = 10.0): array
    {
        $po = PurchaseOrder::factory()->approved()->create(['company_id' => $this->company->id]);
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

    private function receipt(PurchaseOrder $po, PurchaseOrderLine $poLine, float $netQty): GoodsReceipt
    {
        $receipt = GoodsReceipt::factory()->create([
            'purchase_order_id' => $po->id,
            'warehouse_id' => $this->warehouse->id,
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
        // D-A1: a Mode 1 invoice may only post financially against a deterministic receipt
        // anchor, so Mode 1 fixtures now state the receipt line their invoice settles. This is
        // LINE level and is independent of `$autoReceiptId`, which is the HEADER auto-receipt
        // link and stays NULL wherever a test's contract says "unlinked".
        ?string $goodsReceiptLineId = null,
        // The anchor's supplier must match the invoice's, so a fixture that anchors also states
        // the receipt's supplier instead of minting an unrelated one.
        ?string $supplierId = null,
    ): SupplierInvoice {
        $wh = $warehouse ?? $this->warehouse;
        $co = $company ?? $this->company;
        $supplier = $supplierId !== null
            ? (object) ['id' => $supplierId]
            : Supplier::factory()->create(['company_id' => $co->id]);

        $invoice = SupplierInvoice::query()->create([
            'invoice_number' => 'SI-'.uniqid(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $wh->id,
            'company_id' => $co->id,
            'auto_receipt_id' => $autoReceiptId,
            'invoice_date' => now()->toDateString(),
            // Posting requires Validated (SupplierInvoiceStatus::canPost()). The fixture
            // starts there deliberately: this suite proves the INBOUND contract, not the
            // Draft -> Validated review step, which has its own controller path.
            'status' => SupplierInvoiceStatus::Validated,
            'subtotal' => $qty * $unitPrice,
            'freight_amount' => 0,
            'additional_costs' => 0,
            'total_amount' => $qty * $unitPrice,
        ]);

        SupplierInvoiceLine::query()->create([
            'supplier_invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'goods_receipt_line_id' => $goodsReceiptLineId,
            'quantity' => $qty,
            'unit_price' => $unitPrice,
            'line_total' => $qty * $unitPrice,
        ]);

        return $invoice->refresh();
    }

    private function onHand(Product $p): float
    {
        return (float) (InventoryItem::query()
            ->where('product_id', $p->id)
            ->where('warehouse_id', $this->warehouse->id)
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

    /**
     * Make the Supplier Invoice this company's goods-inward authority (ADR-011 Mode 3).
     *
     * The G-1 contract: exactly one document type posts inventory for a company. The schema
     * default is `goods_receipt`, so every invoice test that expects stock to move must say so
     * explicitly. This is a fixture expressing the approved contract — not a relaxed
     * assertion; the quantity, ledger and layer expectations below are unchanged.
     */
    private function useMode3(?Company $company = null): void
    {
        DB::table('companies')
            ->where('id', ($company ?? $this->company)->id)
            ->update(['goods_inward_mode' => GoodsInwardMode::SupplierInvoice->value]);
    }

    private function postInvoice(SupplierInvoice $invoice): void
    {
        app(PostSupplierInvoiceService::class)->execute($invoice);
    }

    // ── A — Goods Receipt inbound posts once ──────────────────────────────────

    public function test_a_goods_receipt_inbound_posts_once(): void
    {
        [$po, $poLine, $product] = $this->approvedPo();
        $receipt = $this->receipt($po, $poLine, netQty: 9.0);

        app(PostGoodsReceiptAction::class)->execute($receipt->id);

        self::assertSame(9.0, $this->onHand($product));      // F
        self::assertSame(1, $this->inboundLedgerCount($product)); // G
        self::assertSame(1, $this->layerCount($product));     // H
    }

    // ── B — Mode 3 Supplier Invoice inbound posts once ────────────────────────

    public function test_b_mode3_supplier_invoice_inbound_posts_once(): void
    {
        $this->useMode3();
        $product = Product::factory()->create();
        $invoice = $this->invoice($product, qty: 7.0, unitPrice: 12.0);

        $this->postInvoice($invoice);

        self::assertSame(SupplierInvoiceStatus::Posted, $invoice->refresh()->status);
        self::assertSame(7.0, $this->onHand($product));
        self::assertSame(1, $this->inboundLedgerCount($product));
        self::assertSame(1, $this->layerCount($product));
    }

    // ── C — the pair cannot double-post, in BOTH orders ───────────────────────

    public function test_c1_receipt_then_linked_invoice_does_not_double_post(): void
    {
        [$po, $poLine, $product] = $this->approvedPo();
        $receipt = $this->receipt($po, $poLine, netQty: 9.0);

        app(PostGoodsReceiptAction::class)->execute($receipt->id);
        $invoice = $this->invoice(
            $product,
            qty: 9.0,
            autoReceiptId: $receipt->id,
            goodsReceiptLineId: (string) $receipt->lines()->value('id'),
            supplierId: (string) $po->supplier_id,
        );
        $this->postInvoice($invoice);

        // Stock, ledger and FIFO all reflect ONE physical delivery.
        self::assertSame(9.0, $this->onHand($product), 'Inventory was posted twice.');
        self::assertSame(1, $this->inboundLedgerCount($product), 'Ledger recorded the inbound twice.');
        self::assertSame(1, $this->layerCount($product), 'Two FIFO layers for one delivery.');

        // The invoice still completes as a FINANCIAL document.
        self::assertSame(SupplierInvoiceStatus::Posted, $invoice->refresh()->status);
    }

    /**
     * SUPERSEDED MECHANISM, PRESERVED REQUIREMENT.
     *
     * This previously asserted that the receipt found its own inbound already in the ledger
     * and threw `GoodsReceiptAlreadyPostedException`. That collision could only happen when
     * the invoice carried `auto_receipt_id`, and the closure audit proved no production code
     * path ever sets it — so the assertion described a state the application cannot reach.
     *
     * Kept, with the fixture corrected to declare Mode 3. The assertion itself was right for
     * a LINKED invoice and is preserved unweakened: the link makes both documents share one
     * ledger reference, so the guard still refuses the receipt. What the closure audit proved
     * is that production never creates that link — which is why the G-1 authority tests below
     * cover the unlinked case, the only one the application can actually produce.
     */
    public function test_c2_invoice_then_receipt_does_not_double_post_in_mode3(): void
    {
        $this->useMode3();

        [$po, $poLine, $product] = $this->approvedPo();
        $receipt = $this->receipt($po, $poLine, netQty: 9.0);

        $invoice = $this->invoice($product, qty: 9.0, autoReceiptId: $receipt->id);
        $this->postInvoice($invoice);

        self::assertSame(9.0, $this->onHand($product));

        // BELT AND BRACES. Because this invoice DOES carry the link, it posted under the
        // receipt's own ledger reference — so the pre-existing guard finds the inbound and
        // refuses the receipt explicitly, exactly as it did before this repair. Authority is
        // what covers the unlinked case (see the G-1 tests below), which is every case the
        // application can actually produce; the two mechanisms are complementary, and this
        // asserts the stronger of the two outcomes rather than merely "nothing moved".
        $this->expectException(GoodsReceiptAlreadyPostedException::class);
        app(PostGoodsReceiptAction::class)->execute($receipt->id);
    }

    public function test_c2b_state_after_the_second_document_is_still_single_posted(): void
    {
        $this->useMode3();

        [$po, $poLine, $product] = $this->approvedPo();
        $receipt = $this->receipt($po, $poLine, netQty: 9.0);

        $invoice = $this->invoice($product, qty: 9.0, autoReceiptId: $receipt->id);
        $this->postInvoice($invoice);

        try {
            app(PostGoodsReceiptAction::class)->execute($receipt->id);
        } catch (GoodsReceiptAlreadyPostedException) {
            // Acceptable either way: what matters is that nothing moved twice.
        }

        self::assertSame(9.0, $this->onHand($product));
        self::assertSame(1, $this->inboundLedgerCount($product));
        self::assertSame(1, $this->layerCount($product));
    }

    // ── G-1 — the case the old mechanism could never cover: NO link at all ────

    /**
     * THE DEFECT THIS TASK EXISTS TO CLOSE. A goods receipt and a supplier invoice for the
     * same physical delivery, created independently the way the application actually creates
     * them — `auto_receipt_id` is deliberately NULL, because no production code path can set
     * it. Before the repair both posted: 18 units on hand for a 9-unit delivery, two ledger
     * rows, two FIFO layers.
     *
     * No matching, no timing, no operator discipline: the receipt is the authority under the
     * default mode, so the invoice never moves stock whatever order they are raised in.
     */
    public function test_g1_unlinked_receipt_and_invoice_post_once_in_receipt_mode(): void
    {
        [$po, $poLine, $product] = $this->approvedPo();
        $receipt = $this->receipt($po, $poLine, netQty: 9.0);

        app(PostGoodsReceiptAction::class)->execute($receipt->id);

        // NOT linked at HEADER level (`auto_receipt_id` stays NULL — that is this test's
        // contract). The LINE anchor is D-A1: a Mode 1 invoice must state the receipt line it
        // settles before it may post financially. Inventory is still the receipt's alone.
        $invoice = $this->invoice(
            $product,
            qty: 9.0,
            goodsReceiptLineId: (string) $receipt->lines()->value('id'),
            supplierId: (string) $po->supplier_id,
        );
        self::assertNull($invoice->auto_receipt_id, 'The fixture no longer mirrors production.');
        $this->postInvoice($invoice);

        self::assertSame(9.0, $this->onHand($product), 'One delivery posted twice.');
        self::assertSame(1, $this->inboundLedgerCount($product));
        self::assertSame(1, $this->layerCount($product));
        self::assertSame(SupplierInvoiceStatus::Posted, $invoice->refresh()->status);
    }

    /** The mirror image: the invoice owns inventory, and an unlinked receipt moves nothing. */
    public function test_g1_unlinked_invoice_and_receipt_post_once_in_mode3(): void
    {
        $this->useMode3();

        [$po, $poLine, $product] = $this->approvedPo();
        $receipt = $this->receipt($po, $poLine, netQty: 9.0);

        $invoice = $this->invoice($product, qty: 9.0);          // NOT linked
        $this->postInvoice($invoice);
        app(PostGoodsReceiptAction::class)->execute($receipt->id);

        self::assertSame(9.0, $this->onHand($product), 'One delivery posted twice.');
        self::assertSame(1, $this->inboundLedgerCount($product));
        self::assertSame(1, $this->layerCount($product));
    }

    /** Reverse order, same guarantee — order of operations is not part of the contract. */
    public function test_g1_order_of_the_two_documents_does_not_matter(): void
    {
        [$po, $poLine, $product] = $this->approvedPo();
        $receipt = $this->receipt($po, $poLine, netQty: 9.0);

        // Anchored to a receipt line that has NOT posted yet. The anchor is a reference to a
        // physical receipt LINE, not a claim that its inventory already moved — which is exactly
        // what "order does not matter" means. GRNI simply goes temporarily negative here and
        // nets to zero when the receipt posts below.
        $invoice = $this->invoice(
            $product,
            qty: 9.0,
            goodsReceiptLineId: (string) $receipt->lines()->value('id'),
            supplierId: (string) $po->supplier_id,
        );
        $this->postInvoice($invoice);                            // not the authority — no stock
        self::assertSame(0.0, $this->onHand($product));

        app(PostGoodsReceiptAction::class)->execute($receipt->id);

        self::assertSame(9.0, $this->onHand($product));
        self::assertSame(1, $this->inboundLedgerCount($product));
        self::assertSame(1, $this->layerCount($product));
    }

    // ── D / E — repeated posting of the same document ─────────────────────────

    public function test_d_repeated_invoice_posting_is_idempotent(): void
    {
        $this->useMode3();
        $product = Product::factory()->create();
        $invoice = $this->invoice($product, qty: 5.0);

        $this->postInvoice($invoice);

        // A second attempt is refused by the status contract; nothing further is posted.
        try {
            $this->postInvoice($invoice->refresh());
        } catch (Throwable) {
            // Posted invoices cannot be re-posted — either outcome is acceptable,
            // provided inventory did not move again.
        }

        self::assertSame(5.0, $this->onHand($product));
        self::assertSame(1, $this->inboundLedgerCount($product));
        self::assertSame(1, $this->layerCount($product));
    }

    public function test_e_repeated_receipt_posting_is_idempotent(): void
    {
        [$po, $poLine, $product] = $this->approvedPo();
        $receipt = $this->receipt($po, $poLine, netQty: 4.0);

        app(PostGoodsReceiptAction::class)->execute($receipt->id);

        try {
            app(PostGoodsReceiptAction::class)->execute($receipt->id);
        } catch (GoodsReceiptAlreadyPostedException) {
            // expected
        }

        self::assertSame(4.0, $this->onHand($product));
        self::assertSame(1, $this->inboundLedgerCount($product));
        self::assertSame(1, $this->layerCount($product));
    }

    // ── I — cost consistency with the canonical inbound source ────────────────

    public function test_i_cost_is_propagated_by_the_canonical_inbound(): void
    {
        $this->useMode3();
        $product = Product::factory()->create();
        $invoice = $this->invoice($product, qty: 10.0, unitPrice: 15.0);

        $this->postInvoice($invoice);

        $layer = InventoryReceiptLayer::query()->where('product_id', $product->id)->firstOrFail();

        self::assertSame(15.0, (float) $layer->landed_unit_cost);
        self::assertSame(15.0, (float) $product->refresh()->last_purchase_cost);
    }

    public function test_i2_invoice_layer_is_attributed_to_its_linked_receipt(): void
    {
        $this->useMode3();
        [$po, $poLine, $product] = $this->approvedPo();
        $receipt = $this->receipt($po, $poLine, netQty: 6.0);

        $invoice = $this->invoice($product, qty: 6.0, autoReceiptId: $receipt->id);
        $this->postInvoice($invoice);

        $layer = InventoryReceiptLayer::query()->where('product_id', $product->id)->firstOrFail();

        // The old path hard-coded goods_receipt_id => null, orphaning every invoice layer.
        self::assertSame($receipt->id, $layer->goods_receipt_id);
    }

    // ── J — tenant isolation ──────────────────────────────────────────────────

    public function test_j_inbound_is_scoped_to_the_owning_company(): void
    {
        $other = Company::factory()->create();
        $otherWarehouse = Warehouse::factory()->create(['company_id' => $other->id]);
        $this->useMode3($other);   // the invoice must be that company's inbound authority

        $product = Product::factory()->create();
        $invoice = $this->invoice(
            $product,
            qty: 3.0,
            warehouse: $otherWarehouse,
            company: $other,
        );

        $this->postInvoice($invoice);

        // Stock landed in the OTHER company's warehouse, not this one.
        self::assertSame(0.0, $this->onHand($product));

        $item = InventoryItem::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $otherWarehouse->id)
            ->firstOrFail();

        self::assertSame(3.0, (float) $item->on_hand_qty);
        self::assertSame($other->id, $item->company_id);
    }

    // ── the guard itself ──────────────────────────────────────────────────────

    public function test_guard_resolves_the_shared_reference_correctly(): void
    {
        $guard = app(InboundPostingGuard::class);

        self::assertSame(
            [InboundPostingGuard::REF_GOODS_RECEIPT, 'gr-1'],
            $guard->referenceForInvoice('gr-1', 'inv-1'),
            'A linked invoice must post under the RECEIPT reference — that is what makes the pair idempotent.',
        );

        self::assertSame(
            [InboundPostingGuard::REF_SUPPLIER_INVOICE, 'inv-1'],
            $guard->referenceForInvoice(null, 'inv-1'),
            'An unlinked Mode 3 invoice is an inbound in its own right.',
        );
    }

    // ── TEST 11 — the certified Supplier Return valuation still holds downstream ──

    /**
     * PART 9 regression: the inbound path feeds Supplier Returns, so a change to inbound
     * posting can silently corrupt a valuation that its own suite would still call green.
     * This walks the whole chain in one test — Goods Receipt inbound → FIFO layer →
     * receipt-scoped return — and checks the return is valued at the cost the INBOUND
     * actually recorded, not at any product-level cost.
     *
     * `TASK-SUPPLIER-RETURN-VALUATION-001` is CERTIFIED and is not re-designed here; this
     * only proves it remains correct on top of the inbound contract.
     */
    public function test_11_supplier_return_valuation_remains_correct_after_inbound(): void
    {
        [$po, $poLine, $product] = $this->approvedPo(qty: 20.0, unitPrice: 12.5);
        $receipt = $this->receipt($po, $poLine, netQty: 20.0);

        app(PostGoodsReceiptAction::class)->execute($receipt->id);

        self::assertSame(20.0, $this->onHand($product));
        self::assertSame(1, $this->layerCount($product));

        $receiptLine = GoodsReceiptLine::query()->where('goods_receipt_id', $receipt->id)->firstOrFail();

        // The return must be addressed to the supplier that actually delivered — the SR-1
        // guard resolves it through the receipt's purchase order.
        $return = SupplierReturn::query()->create([
            'return_number' => 'SR-'.uniqid(),
            'supplier_id' => $po->supplier_id,
            'warehouse_id' => $this->warehouse->id,
            'status' => SupplierReturnStatus::WaitingApproval,
            'return_date' => now()->toDateString(),
            'inventory_restocked' => false,
        ]);

        SupplierReturnLine::query()->create([
            'supplier_return_id' => $return->id,
            'product_id' => $product->id,
            'goods_receipt_line_id' => $receiptLine->id,
            'return_quantity' => 8.0,
            'unit_cost' => 0,
            'total_cost' => 0,
        ]);

        $approved = app(ApproveSupplierReturnAction::class)
            ->execute($return->refresh(), (string) User::factory()->create(['company_id' => $this->company->id])->id);

        self::assertSame(12.0, $this->onHand($product), 'The return did not reduce inbound stock correctly.');

        $line = $approved->lines()->first();
        self::assertSame(12.5, (float) $line->unit_cost, 'The return was not valued at the inbound FIFO cost.');
        self::assertSame(100.0, (float) $line->total_cost);

        // The inbound layer was consumed, not a new one created.
        self::assertSame(1, $this->layerCount($product));
        self::assertSame(
            12.0,
            (float) InventoryReceiptLayer::query()->where('product_id', $product->id)->value('remaining_qty'),
        );
    }
}
