<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Inventory\ReceiptLayers\Domain\Models\InventoryReceiptLayer;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Purchasing\GoodsReceipts\Application\Actions\PostGoodsReceiptAction;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceipt;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceiptLine;
use Modules\Purchasing\PurchaseOrders\Domain\Models\PurchaseOrder;
use Modules\Purchasing\PurchaseOrders\Domain\Models\PurchaseOrderLine;
use Modules\Purchasing\SupplierInvoices\Domain\Enums\SupplierInvoiceStatus;
use Modules\Purchasing\SupplierInvoices\Domain\Exceptions\InvoiceAnchorValidationException;
use Modules\Purchasing\SupplierInvoices\Domain\Models\SupplierInvoice;
use Modules\Purchasing\SupplierInvoices\Domain\Models\SupplierInvoiceLine;
use Modules\Purchasing\SupplierInvoices\Domain\Services\InvoiceReceiptAnchorService;
use Modules\Purchasing\Suppliers\Domain\Models\Supplier;
use Tests\TestCase;

/**
 * V-5 — the deterministic receipt anchor on supplier invoice lines.
 *
 * The blocker this closes: GRNI clearing and Purchase Price Variance both need the valuation the
 * PHYSICAL receipt committed to Inventory/FIFO, and without a link that number could only be
 * guessed. Every guess the contract forbids — supplier+product+date, timestamp, FIFO order,
 * nearest receipt — is absent by construction here: the service reads only the stated anchor.
 *
 * Receipts are POSTED through the real `PostGoodsReceiptAction` rather than hand-built, so
 * `landed_unit_cost` is the genuine stamped valuation and the FIFO invariant is checked against
 * layers the production path actually created.
 */
final class InvoiceReceiptAnchorTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
    }

    private function anchors(): InvoiceReceiptAnchorService
    {
        return app(InvoiceReceiptAnchorService::class);
    }

    /**
     * A POSTED receipt line: real inventory, real FIFO layer, real stamped landed cost.
     *
     * @return array{0: GoodsReceiptLine, 1: Product, 2: PurchaseOrder}
     */
    private function postedReceiptLine(float $qty, float $unitPrice, ?Company $company = null, ?Warehouse $warehouse = null): array
    {
        $co = $company ?? $this->company;
        $wh = $warehouse ?? $this->warehouse;

        $po = PurchaseOrder::factory()->approved()->create(['company_id' => $co->id]);
        $product = Product::factory()->create();

        $poLine = PurchaseOrderLine::factory()->create([
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'quantity' => max($qty, 1000),
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
            'ordered_quantity' => (float) $poLine->quantity,
            'received_quantity' => $qty,
            'gross_received_quantity' => $qty,
            'net_received_quantity' => $qty,
            'variance_quantity' => 0,
            'unit_price' => $unitPrice,
        ]);

        app(PostGoodsReceiptAction::class)->execute($receipt->id);

        $line = GoodsReceiptLine::query()->where('goods_receipt_id', $receipt->id)->firstOrFail();

        return [$line, $product, $po->refresh()];
    }

    private function invoiceFor(
        PurchaseOrder $po,
        array $lines,
        ?Company $company = null,
        ?Warehouse $warehouse = null,
        SupplierInvoiceStatus $status = SupplierInvoiceStatus::Validated,
    ): SupplierInvoice {
        $co = $company ?? $this->company;
        $wh = $warehouse ?? $this->warehouse;

        $invoice = SupplierInvoice::query()->create([
            'invoice_number' => 'SI-'.uniqid(),
            'supplier_id' => $po->supplier_id,
            'warehouse_id' => $wh->id,
            'company_id' => $co->id,
            'invoice_date' => now()->toDateString(),
            'status' => $status,
            'subtotal' => 0,
            'freight_amount' => 0,
            'additional_costs' => 0,
            'total_amount' => 0,
        ]);

        foreach ($lines as $l) {
            SupplierInvoiceLine::query()->create([
                'supplier_invoice_id' => $invoice->id,
                'product_id' => $l['product_id'],
                'goods_receipt_line_id' => $l['anchor'] ?? null,
                'quantity' => $l['qty'],
                'unit_price' => $l['price'],
                'line_total' => $l['qty'] * $l['price'],
            ]);
        }

        return $invoice->refresh();
    }

    // ── The anchor itself ─────────────────────────────────────────────────────

    public function test_a_valid_anchor_resolves(): void
    {
        [$anchor, $product, $po] = $this->postedReceiptLine(80, 500);
        $invoice = $this->invoiceFor($po, [['product_id' => $product->id, 'anchor' => $anchor->id, 'qty' => 80, 'price' => 500]]);

        $resolved = $this->anchors()->resolve($invoice, $invoice->lines->first());

        self::assertSame($anchor->id, $resolved->id);
        self::assertSame(500.0, (float) $resolved->landed_unit_cost, 'Receipt valuation must come from the stamped landed cost.');
    }

    public function test_b_missing_anchor_is_refused_never_guessed(): void
    {
        [, $product, $po] = $this->postedReceiptLine(80, 500);
        $invoice = $this->invoiceFor($po, [['product_id' => $product->id, 'anchor' => null, 'qty' => 80, 'price' => 500]]);

        $this->expectException(InvoiceAnchorValidationException::class);
        $this->anchors()->resolve($invoice, $invoice->lines->first());
    }

    public function test_c_cross_company_anchor_is_refused_and_leaks_nothing(): void
    {
        $foreign = Company::factory()->create();
        $foreignWarehouse = Warehouse::factory()->create(['company_id' => $foreign->id]);
        [$foreignAnchor, $foreignProduct, $foreignPo] = $this->postedReceiptLine(80, 500, $foreign, $foreignWarehouse);

        // This company's own invoice, pointed at the other company's receipt line.
        $ownPo = PurchaseOrder::factory()->approved()->create(['company_id' => $this->company->id]);
        $invoice = $this->invoiceFor($ownPo, [['product_id' => $foreignProduct->id, 'anchor' => $foreignAnchor->id, 'qty' => 80, 'price' => 500]]);

        try {
            $this->anchors()->resolve($invoice, $invoice->lines->first());
            self::fail('A cross-company receipt anchor was accepted.');
        } catch (InvoiceAnchorValidationException $e) {
            // Reported as unavailable — never as a supplier/product/quantity mismatch, which
            // would confirm facts about the other tenant's document.
            self::assertStringContainsString('not found or is not available', $e->getMessage());
            self::assertStringNotContainsString((string) $foreignPo->supplier_id, $e->getMessage());
        }
    }

    public function test_d_cross_supplier_anchor_is_refused(): void
    {
        [$anchor, $product, $po] = $this->postedReceiptLine(80, 500);
        $invoice = $this->invoiceFor($po, [['product_id' => $product->id, 'anchor' => $anchor->id, 'qty' => 80, 'price' => 500]]);

        // Same company, different supplier — the invoice is re-pointed, never the receipt.
        $invoice->update(['supplier_id' => Supplier::factory()->create(['company_id' => $this->company->id])->id]);

        $this->expectExceptionMessageMatches('/different supplier/');
        $this->anchors()->resolve($invoice->refresh(), $invoice->lines->first());
    }

    public function test_e_cross_product_anchor_is_refused(): void
    {
        [$anchor, , $po] = $this->postedReceiptLine(80, 500);
        $other = Product::factory()->create();
        $invoice = $this->invoiceFor($po, [['product_id' => $other->id, 'anchor' => $anchor->id, 'qty' => 80, 'price' => 500]]);

        $this->expectExceptionMessageMatches('/different product/');
        $this->anchors()->resolve($invoice, $invoice->lines->first());
    }

    // ── The ceiling: one physical receipt cannot be cleared twice ─────────────

    public function test_f_quantity_above_the_receipt_is_refused(): void
    {
        [$anchor, $product, $po] = $this->postedReceiptLine(80, 500);
        $invoice = $this->invoiceFor($po, [['product_id' => $product->id, 'anchor' => $anchor->id, 'qty' => 100, 'price' => 500]]);

        $this->expectExceptionMessageMatches('/exceeds the quantity still invoiceable/');
        $this->anchors()->resolve($invoice, $invoice->lines->first());
    }

    public function test_g_the_same_receipt_cannot_be_financially_cleared_twice(): void
    {
        [$anchor, $product, $po] = $this->postedReceiptLine(80, 500);

        $first = $this->invoiceFor($po, [['product_id' => $product->id, 'anchor' => $anchor->id, 'qty' => 80, 'price' => 500]]);
        $first->update(['status' => SupplierInvoiceStatus::Posted]);

        self::assertSame(0.0, $this->anchors()->invoiceable($anchor), 'A fully invoiced receipt line has nothing left to settle.');

        $second = $this->invoiceFor($po, [['product_id' => $product->id, 'anchor' => $anchor->id, 'qty' => 80, 'price' => 500]]);

        $this->expectExceptionMessageMatches('/exceeds the quantity still invoiceable/');
        $this->anchors()->resolve($second, $second->lines->first());
    }

    public function test_h_a_draft_invoice_reserves_nothing(): void
    {
        [$anchor, $product, $po] = $this->postedReceiptLine(80, 500);

        $draft = $this->invoiceFor($po, [['product_id' => $product->id, 'anchor' => $anchor->id, 'qty' => 80, 'price' => 500]]);
        $draft->update(['status' => SupplierInvoiceStatus::Draft]);

        // Paperwork that may never post must not block the receipt.
        self::assertSame(80.0, $this->anchors()->invoiceable($anchor));
    }

    // ── The GRNI / PPV basis ─────────────────────────────────────────────────

    public function test_i_equal_price_produces_zero_variance(): void
    {
        [$anchor, $product, $po] = $this->postedReceiptLine(80, 500);
        $invoice = $this->invoiceFor($po, [['product_id' => $product->id, 'anchor' => $anchor->id, 'qty' => 80, 'price' => 500]]);

        $basis = $this->anchors()->basisFor($invoice);

        self::assertSame(40000.0, $basis['receiptValuation']);
        self::assertSame(40000.0, $basis['invoiceNet']);
        self::assertSame(0.0, $basis['variance'], 'Equal price must produce no variance at all.');
    }

    public function test_j_lower_invoice_price_produces_a_favourable_variance(): void
    {
        [$anchor, $product, $po] = $this->postedReceiptLine(80, 500);
        $invoice = $this->invoiceFor($po, [['product_id' => $product->id, 'anchor' => $anchor->id, 'qty' => 80, 'price' => 450]]);

        $basis = $this->anchors()->basisFor($invoice);

        self::assertSame(40000.0, $basis['receiptValuation'], 'GRNI basis is the receipt valuation, not the invoice.');
        self::assertSame(36000.0, $basis['invoiceNet']);
        self::assertSame(-4000.0, $basis['variance'], 'Invoice below receipt = favourable.');
    }

    public function test_k_higher_invoice_price_produces_an_unfavourable_variance(): void
    {
        [$anchor, $product, $po] = $this->postedReceiptLine(80, 500);
        $invoice = $this->invoiceFor($po, [['product_id' => $product->id, 'anchor' => $anchor->id, 'qty' => 80, 'price' => 550]]);

        $basis = $this->anchors()->basisFor($invoice);

        self::assertSame(40000.0, $basis['receiptValuation']);
        self::assertSame(44000.0, $basis['invoiceNet']);
        self::assertSame(4000.0, $basis['variance'], 'Invoice above receipt = unfavourable.');
    }

    public function test_l_partial_receipt_settles_only_what_arrived(): void
    {
        // Ordered 100, received 80 — the invoice may settle 80 and no more.
        [$anchor, $product, $po] = $this->postedReceiptLine(80, 500);
        $invoice = $this->invoiceFor($po, [['product_id' => $product->id, 'anchor' => $anchor->id, 'qty' => 80, 'price' => 450]]);

        $basis = $this->anchors()->basisFor($invoice);

        self::assertSame(40000.0, $basis['receiptValuation']);
        self::assertSame(36000.0, $basis['invoiceNet']);
        self::assertSame(-4000.0, $basis['variance']);
        self::assertSame(80.0, $this->anchors()->received($anchor), 'The unreceived 20 must never become inventory.');
    }

    // ── Multiple receipts — deterministic, never allocated ────────────────────

    public function test_m_multi_receipt_invoice_is_deterministic_per_line(): void
    {
        [$a1, $p1, $po1] = $this->postedReceiptLine(40, 500);   // 20,000
        [$a2, $p2] = $this->postedReceiptLine(40, 520);          // 20,800

        // Each line names its own receipt; nothing is allocated or averaged.
        $invoice = $this->invoiceFor($po1, [
            ['product_id' => $p1->id, 'anchor' => $a1->id, 'qty' => 40, 'price' => 510],
            ['product_id' => $p2->id, 'anchor' => $a2->id, 'qty' => 40, 'price' => 510],
        ]);
        // Both receipts must be the invoice's supplier for a single invoice to settle them.
        $this->realignSupplier($invoice, $a1, $a2);

        $basis = $this->anchors()->basisFor($invoice->refresh());

        self::assertSame(40800.0, $basis['receiptValuation'], '20,000 + 20,800 from the two anchors.');
        self::assertSame(40800.0, $basis['invoiceNet'], '40×510 + 40×510.');
        self::assertSame(0.0, $basis['variance']);

        // Line-level costs survive: the two lines differ even though the totals net to zero.
        // Keyed by anchor rather than by index — line ordering is not part of the contract.
        $byAnchor = collect($basis['lines'])->keyBy('anchor_id');

        // Receipt 1 cost 500 and is invoiced at 510 → paying ABOVE the receipt = unfavourable.
        self::assertSame(400.0, $byAnchor[$a1->id]['variance']);
        // Receipt 2 cost 520 and is invoiced at 510 → paying BELOW the receipt = favourable.
        self::assertSame(-400.0, $byAnchor[$a2->id]['variance']);
    }

    public function test_n_multi_receipt_variance_is_summed_from_each_anchor(): void
    {
        [$a1, $p1, $po1] = $this->postedReceiptLine(40, 500);   // 20,000
        [$a2, $p2] = $this->postedReceiptLine(40, 520);          // 20,800

        $invoice = $this->invoiceFor($po1, [
            ['product_id' => $p1->id, 'anchor' => $a1->id, 'qty' => 40, 'price' => 450],  // 18,000
            ['product_id' => $p2->id, 'anchor' => $a2->id, 'qty' => 40, 'price' => 550],  // 22,000
        ]);
        $this->realignSupplier($invoice, $a1, $a2);

        $basis = $this->anchors()->basisFor($invoice->refresh());

        self::assertSame(40800.0, $basis['receiptValuation']);
        self::assertSame(40000.0, $basis['invoiceNet']);
        self::assertSame(-800.0, $basis['variance'], '800 favourable, summed per anchor — never a blended average.');
    }

    /** Point both receipts' POs at the invoice's supplier so one invoice may legitimately settle both. */
    private function realignSupplier(SupplierInvoice $invoice, GoodsReceiptLine ...$anchors): void
    {
        foreach ($anchors as $anchor) {
            PurchaseOrder::query()
                ->whereKey($anchor->goodsReceipt->purchase_order_id)
                ->update(['supplier_id' => $invoice->supplier_id]);
        }
    }

    // ── FIFO invariant ───────────────────────────────────────────────────────

    public function test_o_resolving_the_anchor_never_mutates_the_fifo_layer(): void
    {
        [$anchor, $product, $po] = $this->postedReceiptLine(80, 500);

        $before = InventoryReceiptLayer::query()->where('product_id', $product->id)->firstOrFail();
        $beforeCost = (float) $before->landed_unit_cost;
        $beforeQty = (float) $before->remaining_qty;

        $invoice = $this->invoiceFor($po, [['product_id' => $product->id, 'anchor' => $anchor->id, 'qty' => 80, 'price' => 450]]);
        $this->anchors()->basisFor($invoice);

        $after = InventoryReceiptLayer::query()->where('product_id', $product->id)->firstOrFail();

        self::assertSame($before->id, $after->id, 'No new layer may appear.');
        self::assertSame($beforeCost, (float) $after->landed_unit_cost, 'The 500 layer must not be rewritten to 450.');
        self::assertSame($beforeQty, (float) $after->remaining_qty);
        self::assertSame(1, InventoryReceiptLayer::query()->where('product_id', $product->id)->count());
    }
}
