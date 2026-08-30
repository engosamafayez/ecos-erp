<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Integration\Domain\Services\AccountRoleResolver;
use Modules\Finance\Ledger\Domain\Models\JournalLine;
use Modules\Finance\Payables\Domain\Models\SupplierLedgerEntry;
use Modules\Inventory\InventoryItems\Domain\Enums\GoodsInwardMode;
use Modules\Inventory\Products\Domain\Enums\InventoryClass;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Inventory\ReceiptLayers\Domain\Models\InventoryReceiptLayer;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Purchasing\GoodsReceipts\Application\Actions\PostGoodsReceiptAction;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceipt;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceiptLine;
use Modules\Purchasing\PurchaseOrders\Domain\Models\PurchaseOrder;
use Modules\Purchasing\PurchaseOrders\Domain\Models\PurchaseOrderLine;
use Modules\Purchasing\SupplierInvoices\Application\Services\PostSupplierInvoiceService;
use Modules\Purchasing\SupplierInvoices\Domain\Enums\SupplierInvoiceStatus;
use Modules\Purchasing\SupplierInvoices\Domain\Exceptions\InvoiceAnchorValidationException;
use Modules\Purchasing\SupplierInvoices\Domain\Models\SupplierInvoice;
use Modules\Purchasing\SupplierInvoices\Domain\Models\SupplierInvoiceLine;
use Modules\Purchasing\Suppliers\Domain\Models\Supplier;
use RuntimeException;
use Tests\Support\ProvisionsCompanyFinance;
use Tests\TestCase;
use Throwable;

/**
 * THE PROCUREMENT → FINANCE INBOUND CHAIN, END TO END.
 *
 * Everything here runs through the real {@see PostSupplierInvoiceService}. There is deliberately
 * no test that calls {@see \Modules\Finance\Payables\Domain\Services\AccountsPayableService}
 * directly: a payable that posts correctly when hand-fed proves nothing about whether Purchasing
 * feeds it correctly, and it is the feeding that this chain gets wrong when it breaks.
 *
 * MODE 1 (Goods Receipt is the inbound authority)
 *   Receipt:  Dr Inventory / Cr GRNI          at the receipt's stamped landed_unit_cost
 *   Invoice:  Dr GRNI / Dr-Cr PPV / Dr VAT / Cr AP
 *
 * MODE 3 (Supplier Invoice is the inbound authority)
 *   Invoice:  Dr Inventory / Dr VAT / Cr AP   at the invoice's stamped landed_unit_cost
 *   No GRNI, and no PPV — there is no second valuation to vary against.
 *
 * The valuation authority is always a STAMPED cost on the document that owns the inbound. FIFO
 * is never re-read, today's cost is never used, and no average is ever taken.
 */
final class SupplierInvoiceFinancialPostingTest extends TestCase
{
    use ProvisionsCompanyFinance;
    use RefreshDatabase;

    private Company $company;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->provisionFinance($this->company);
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
    }

    // ── fixtures ──────────────────────────────────────────────────────────────

    /** @return array{0: PurchaseOrder, 1: PurchaseOrderLine, 2: Product} */
    private function approvedPo(float $qty, float $unitPrice, ?Company $company = null): array
    {
        $co = $company ?? $this->company;
        $po = PurchaseOrder::factory()->approved()->create(['company_id' => $co->id]);
        $product = Product::factory()->create([
            'product_type' => InventoryClass::RawMaterial->value,
        ]);

        $poLine = PurchaseOrderLine::factory()->create([
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'quantity' => $qty,
            'received_qty' => 0,
            'unit_price' => $unitPrice,
        ]);

        return [$po, $poLine, $product];
    }

    /** A posted Goods Receipt, which is what raises the GRNI this suite then clears. */
    private function postedReceipt(
        PurchaseOrder $po,
        PurchaseOrderLine $poLine,
        float $qty,
        float $unitPrice,
        ?Warehouse $warehouse = null,
    ): GoodsReceiptLine {
        $receipt = GoodsReceipt::factory()->create([
            'purchase_order_id' => $po->id,
            'warehouse_id' => ($warehouse ?? $this->warehouse)->id,
        ]);

        $line = GoodsReceiptLine::factory()->create([
            'goods_receipt_id' => $receipt->id,
            'purchase_order_line_id' => $poLine->id,
            'product_id' => $poLine->product_id,
            'ordered_quantity' => (float) $poLine->quantity,
            'received_quantity' => $qty,
            'gross_received_quantity' => $qty,
            'net_received_quantity' => $qty,
            'variance_quantity' => $qty - (float) $poLine->quantity,
            'unit_price' => $unitPrice,
            'landed_unit_cost' => $unitPrice,
        ]);

        app(PostGoodsReceiptAction::class)->execute($receipt->id);

        return $line->refresh();
    }

    private function invoice(
        Product $product,
        float $qty,
        float $unitPrice,
        ?string $anchorId,
        ?string $supplierId = null,
        ?Company $company = null,
        ?Warehouse $warehouse = null,
        float $taxAmount = 0.0,
    ): SupplierInvoice {
        $co = $company ?? $this->company;
        $supplierId ??= (string) Supplier::factory()->create(['company_id' => $co->id])->id;

        $invoice = SupplierInvoice::query()->create([
            'invoice_number' => 'SI-'.uniqid(),
            'supplier_id' => $supplierId,
            'warehouse_id' => ($warehouse ?? $this->warehouse)->id,
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
            'goods_receipt_line_id' => $anchorId,
            'quantity' => $qty,
            'unit_price' => $unitPrice,
            'line_total' => $qty * $unitPrice,
            // Only signals that the invoice CARRIES tax. The amount posted is computed by the
            // configured tax code, never from this figure — asserting otherwise would let a
            // hardcoded rate pass.
            'tax_amount' => $taxAmount,
        ]);

        return $invoice->refresh();
    }

    private function postInvoice(SupplierInvoice $invoice): void
    {
        app(PostSupplierInvoiceService::class)->execute($invoice);
    }

    /**
     * The service REJECTS a second posting rather than silently no-op'ing it: an already-posted
     * invoice throws "cannot be posted (status: posted)". Asserting the rejection — not a
     * swallowed no-op — is the correct idempotency contract (task-mandated fixture correction).
     */
    private function assertSecondPostingRejected(SupplierInvoice $invoice): void
    {
        try {
            app(PostSupplierInvoiceService::class)->execute($invoice->refresh());
            self::fail('A second posting of an already-posted invoice must be rejected, not accepted.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('cannot be posted', $e->getMessage());
        }
    }

    private function useMode3(): void
    {
        DB::table('companies')
            ->where('id', $this->company->id)
            ->update(['goods_inward_mode' => GoodsInwardMode::SupplierInvoice->value]);
    }

    // ── assertions on the ledger ──────────────────────────────────────────────

    /**
     * Signed movement on the account a ROLE resolves to: debit positive, credit negative.
     *
     * Reading by role, not by account code, is the same indirection production uses — a test that
     * hardcoded 2120 would keep passing after the GRNI account was remapped.
     */
    private function net(string $role, ?Company $company = null): float
    {
        $companyId = (string) ($company ?? $this->company)->id;
        $accountId = app(AccountRoleResolver::class)->resolve($companyId, $role);

        $lines = JournalLine::query()
            ->where('company_id', $companyId)
            ->where('account_id', $accountId)
            ->get();

        return round(
            $lines->sum(fn (JournalLine $l): float => (float) $l->debit - (float) $l->credit),
            2,
        );
    }

    private function payableLedgerTotal(): float
    {
        return round((float) SupplierLedgerEntry::query()
            ->where('company_id', $this->company->id)
            ->sum('amount'), 2);
    }

    // ══ E2E CASE 1-3 — single receipt, the three variance directions ══════════

    /** CASE 1 — invoice agrees with the receipt: GRNI clears exactly, no PPV at all. */
    public function test_case1_equal_price_clears_grni_with_no_variance(): void
    {
        [$po, $poLine, $product] = $this->approvedPo(qty: 100.0, unitPrice: 500.0);
        $anchor = $this->postedReceipt($po, $poLine, qty: 40.0, unitPrice: 500.0);

        // The receipt accrued the liability: Cr GRNI 20,000 (negative under the signed convention).
        self::assertSame(-20_000.0, $this->net('grni'), 'The receipt did not accrue GRNI.');

        $this->postInvoice($this->invoice($product, 40.0, 500.0, (string) $anchor->id, (string) $po->supplier_id));

        self::assertSame(0.0, $this->net('grni'), 'GRNI residual left on a fully invoiced receipt.');
        self::assertSame(0.0, $this->net('purchase_price_variance'), 'PPV posted for an equal-price invoice.');
        self::assertSame(-20_000.0, $this->net('ap_control'), 'AP was not credited with the invoice value.');
    }

    /** CASE 2 — invoice above the receipt: the excess is an unfavourable PPV DEBIT. */
    public function test_case2_unfavourable_variance_debits_ppv(): void
    {
        [$po, $poLine, $product] = $this->approvedPo(qty: 100.0, unitPrice: 500.0);
        $anchor = $this->postedReceipt($po, $poLine, qty: 40.0, unitPrice: 500.0);

        $this->postInvoice($this->invoice($product, 40.0, 600.0, (string) $anchor->id, (string) $po->supplier_id));

        self::assertSame(0.0, $this->net('grni'), 'GRNI must clear at the RECEIPT value, not the invoice value.');
        self::assertSame(4_000.0, $this->net('purchase_price_variance'), 'Unfavourable variance is a PPV debit.');
        self::assertSame(-24_000.0, $this->net('ap_control'), 'AP owes the supplier the INVOICE value.');
    }

    /**
     * CASE 3 — invoice below the receipt: a favourable PPV CREDIT.
     *
     * This is the direction that was unrepresentable before the negative-net AP capability: the
     * variance leg carries a negative net, which posts as abs(net) on the opposite side.
     */
    public function test_case3_favourable_variance_credits_ppv(): void
    {
        [$po, $poLine, $product] = $this->approvedPo(qty: 100.0, unitPrice: 500.0);
        $anchor = $this->postedReceipt($po, $poLine, qty: 40.0, unitPrice: 500.0);

        $this->postInvoice($this->invoice($product, 40.0, 400.0, (string) $anchor->id, (string) $po->supplier_id));

        self::assertSame(0.0, $this->net('grni'));
        self::assertSame(-4_000.0, $this->net('purchase_price_variance'), 'The favourable variance was lost.');
        self::assertSame(-16_000.0, $this->net('ap_control'));
    }

    // ══ E2E CASE 4-5 — multi-receipt ══════════════════════════════════════════

    /**
     * CASE 4 — two receipts at different costs, both invoiced at the midpoint.
     *
     * The NET variance is zero, which is exactly the trap: an implementation that averaged the
     * two receipts before posting would also report zero and look correct. The line-level detail
     * is asserted separately to prove the two +400 / -400 movements really happened.
     */
    public function test_case4_multi_receipt_equal_totals_keep_line_level_variance(): void
    {
        [$po, $poLine, $product] = $this->approvedPo(qty: 200.0, unitPrice: 500.0);
        $supplier = (string) $po->supplier_id;

        $a = $this->postedReceipt($po, $poLine, qty: 40.0, unitPrice: 500.0);
        $b = $this->postedReceipt($po, $poLine, qty: 40.0, unitPrice: 520.0);

        self::assertSame(-40_800.0, $this->net('grni'), 'The two receipts accrued 20,000 + 20,800.');

        $this->postInvoice($this->invoice($product, 40.0, 510.0, (string) $a->id, $supplier));
        $this->postInvoice($this->invoice($product, 40.0, 510.0, (string) $b->id, $supplier));

        self::assertSame(0.0, $this->net('grni'), 'Both receipts must clear at their own value.');
        self::assertSame(0.0, $this->net('purchase_price_variance'), 'Net variance across the two is zero.');
        self::assertSame(-40_800.0, $this->net('ap_control'), 'Invoiced total is 20,400 + 20,400.');

        // Both movements exist and are opposite — not one blended zero.
        $ppvAccount = app(AccountRoleResolver::class)->resolve((string) $this->company->id, 'purchase_price_variance');
        $movements = JournalLine::query()
            ->where('company_id', $this->company->id)
            ->where('account_id', $ppvAccount)
            ->get()
            ->map(fn (JournalLine $l): float => round((float) $l->debit - (float) $l->credit, 2))
            ->sort()
            ->values()
            ->all();

        self::assertSame([-400.0, 400.0], $movements, 'The two receipts were blended before posting.');
    }

    /** CASE 5 — two receipts, opposite variances that do not cancel: net 800 favourable. */
    public function test_case5_multi_receipt_net_favourable_variance(): void
    {
        [$po, $poLine, $product] = $this->approvedPo(qty: 200.0, unitPrice: 500.0);
        $supplier = (string) $po->supplier_id;

        $a = $this->postedReceipt($po, $poLine, qty: 40.0, unitPrice: 500.0);
        $b = $this->postedReceipt($po, $poLine, qty: 40.0, unitPrice: 520.0);

        $this->postInvoice($this->invoice($product, 40.0, 450.0, (string) $a->id, $supplier));
        $this->postInvoice($this->invoice($product, 40.0, 550.0, (string) $b->id, $supplier));

        self::assertSame(0.0, $this->net('grni'));
        // Receipts 40,800 vs invoices 40,000 → 800 favourable, i.e. a net PPV credit.
        self::assertSame(-800.0, $this->net('purchase_price_variance'));
        self::assertSame(-40_000.0, $this->net('ap_control'));
    }

    // ══ D-A1 — the anchor is required, and never inferred ═════════════════════

    /**
     * An unanchored Mode 1 line is REFUSED, and the refusal rolls the whole document back.
     *
     * The invoice is left un-posted with nothing financial behind it. A "skip the payable but
     * mark the invoice posted" outcome would be far worse than the failure: the document would
     * look settled while owing the supplier nothing.
     */
    public function test_da1_unanchored_mode1_invoice_is_rejected_and_rolls_back_atomically(): void
    {
        [$po, $poLine, $product] = $this->approvedPo(qty: 100.0, unitPrice: 500.0);
        $this->postedReceipt($po, $poLine, qty: 40.0, unitPrice: 500.0);

        $grniAfterReceipt = $this->net('grni');
        $invoice = $this->invoice($product, 40.0, 500.0, anchorId: null, supplierId: (string) $po->supplier_id);

        $thrown = null;

        try {
            $this->postInvoice($invoice);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(InvoiceAnchorValidationException::class, $thrown);
        self::assertStringContainsString('no goods receipt anchor', strtolower((string) $thrown->getMessage()));

        // Nothing survived the rollback.
        self::assertSame(SupplierInvoiceStatus::Validated, $invoice->refresh()->status, 'The invoice was left posted.');
        self::assertSame($grniAfterReceipt, $this->net('grni'), 'GRNI moved on a rejected invoice.');
        self::assertSame(0.0, $this->net('purchase_price_variance'));
        self::assertSame(0.0, $this->net('ap_control'));
        self::assertSame(0.0, $this->payableLedgerTotal(), 'A supplier ledger entry survived the rollback.');
    }

    /** The anchor is never inferred: a perfectly matching receipt does not rescue a null anchor. */
    public function test_da1_anchor_is_never_inferred_from_a_matching_receipt(): void
    {
        [$po, $poLine, $product] = $this->approvedPo(qty: 100.0, unitPrice: 500.0);

        // Same supplier, same product, same quantity, same price, same day — every attribute a
        // fuzzy matcher would key on. It must still be refused.
        $this->postedReceipt($po, $poLine, qty: 40.0, unitPrice: 500.0);
        $invoice = $this->invoice($product, 40.0, 500.0, anchorId: null, supplierId: (string) $po->supplier_id);

        $this->expectException(InvoiceAnchorValidationException::class);
        $this->postInvoice($invoice);
    }

    // ══ Anchor validity — tenant, supplier, product, quantity ═════════════════

    /**
     * A foreign company's receipt line is NOT FOUND, and the refusal leaks nothing about it.
     *
     * Reported as not-found rather than as a supplier or product mismatch on purpose: the more
     * specific message would confirm the row exists and describe it.
     */
    public function test_tenant_isolation_foreign_anchor_is_not_found_and_leaks_nothing(): void
    {
        $other = Company::factory()->create();
        $this->provisionFinance($other);
        $otherWarehouse = Warehouse::factory()->create(['company_id' => $other->id]);

        [$otherPo, $otherPoLine] = $this->approvedPo(qty: 100.0, unitPrice: 500.0, company: $other);
        $foreign = $this->postedReceipt($otherPo, $otherPoLine, qty: 40.0, unitPrice: 500.0, warehouse: $otherWarehouse);

        [$po, , $product] = $this->approvedPo(qty: 100.0, unitPrice: 500.0);
        $invoice = $this->invoice($product, 40.0, 500.0, (string) $foreign->id, (string) $po->supplier_id);

        $thrown = null;

        try {
            $this->postInvoice($invoice);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(InvoiceAnchorValidationException::class, $thrown);

        $message = (string) $thrown->getMessage();
        self::assertStringNotContainsString((string) $otherPo->supplier_id, $message, 'The error leaked a foreign supplier id.');
        self::assertStringNotContainsString((string) $foreign->product_id, $message, 'The error leaked a foreign product id.');

        // The refusal must be the NOT-FOUND variant. A supplier-mismatch or quantity-exceeded
        // message would confirm the foreign row exists and describe an attribute of it — the
        // anchor id is not checked for stray digits here because it is a UUID the caller already
        // supplied, so it reveals nothing it did not already know.
        self::assertStringContainsString('was not found', $message);
        self::assertStringNotContainsString('different supplier', $message);
        self::assertStringNotContainsString('different product', $message);
        self::assertStringNotContainsString('exceeds', $message);

        self::assertSame(0.0, $this->net('ap_control'), 'A cross-tenant anchor produced a payable.');
    }

    /** A receipt belonging to a different supplier cannot settle this invoice. */
    public function test_cross_supplier_anchor_is_rejected(): void
    {
        [$po, $poLine, $product] = $this->approvedPo(qty: 100.0, unitPrice: 500.0);
        $anchor = $this->postedReceipt($po, $poLine, qty: 40.0, unitPrice: 500.0);

        // Same company, different supplier.
        $stranger = (string) Supplier::factory()->create(['company_id' => $this->company->id])->id;

        $this->expectException(InvoiceAnchorValidationException::class);
        $this->postInvoice($this->invoice($product, 40.0, 500.0, (string) $anchor->id, $stranger));
    }

    /** A receipt for a different product cannot settle this line. */
    public function test_cross_product_anchor_is_rejected(): void
    {
        [$po, $poLine] = $this->approvedPo(qty: 100.0, unitPrice: 500.0);
        $anchor = $this->postedReceipt($po, $poLine, qty: 40.0, unitPrice: 500.0);

        $otherProduct = Product::factory()->create(['product_type' => InventoryClass::RawMaterial->value]);

        $this->expectException(InvoiceAnchorValidationException::class);
        $this->postInvoice($this->invoice($otherProduct, 40.0, 500.0, (string) $anchor->id, (string) $po->supplier_id));
    }

    /** An invoice may never settle more than was physically received. */
    public function test_quantity_ceiling_is_the_received_quantity(): void
    {
        [$po, $poLine, $product] = $this->approvedPo(qty: 100.0, unitPrice: 500.0);
        $anchor = $this->postedReceipt($po, $poLine, qty: 40.0, unitPrice: 500.0);

        $this->expectException(InvoiceAnchorValidationException::class);
        $this->postInvoice($this->invoice($product, 41.0, 500.0, (string) $anchor->id, (string) $po->supplier_id));
    }

    /** Two invoices cannot clear the same physical quantity twice. */
    public function test_duplicate_financial_clearing_of_one_receipt_is_prevented(): void
    {
        [$po, $poLine, $product] = $this->approvedPo(qty: 100.0, unitPrice: 500.0);
        $anchor = $this->postedReceipt($po, $poLine, qty: 40.0, unitPrice: 500.0);
        $supplier = (string) $po->supplier_id;

        $this->postInvoice($this->invoice($product, 40.0, 500.0, (string) $anchor->id, $supplier));
        self::assertSame(0.0, $this->net('grni'));

        // The receipt is fully settled; a second invoice has nothing left to clear.
        $this->expectException(InvoiceAnchorValidationException::class);
        $this->postInvoice($this->invoice($product, 40.0, 500.0, (string) $anchor->id, $supplier));
    }

    /** Posting the same invoice twice must not double any leg. */
    public function test_repeated_posting_of_one_invoice_is_idempotent(): void
    {
        [$po, $poLine, $product] = $this->approvedPo(qty: 100.0, unitPrice: 500.0);
        $anchor = $this->postedReceipt($po, $poLine, qty: 40.0, unitPrice: 500.0);

        $invoice = $this->invoice($product, 40.0, 600.0, (string) $anchor->id, (string) $po->supplier_id);

        $this->postInvoice($invoice);
        $ap = $this->net('ap_control');
        $ppv = $this->net('purchase_price_variance');
        $ledger = $this->payableLedgerTotal();

        $this->assertSecondPostingRejected($invoice);

        self::assertSame($ap, $this->net('ap_control'), 'AP was posted twice.');
        self::assertSame($ppv, $this->net('purchase_price_variance'), 'PPV was posted twice.');
        self::assertSame(0.0, $this->net('grni'), 'GRNI was cleared twice.');
        self::assertSame($ledger, $this->payableLedgerTotal(), 'The supplier ledger was written twice.');
    }

    // ══ VAT — resolved through the configured tax code, never hardcoded ══════

    /**
     * Input VAT posts on the INVOICE value, at the rate the tax code carries.
     *
     * The expected figure is read from the company's own tax code rather than written as 14%: a
     * literal here would keep passing if the posting logic hardcoded a rate too, which is exactly
     * the defect the assertion exists to catch.
     */
    public function test_vat_input_posts_at_the_configured_rate_on_the_invoice_value(): void
    {
        [$po, $poLine, $product] = $this->approvedPo(qty: 100.0, unitPrice: 500.0);
        $anchor = $this->postedReceipt($po, $poLine, qty: 40.0, unitPrice: 500.0);

        $this->postInvoice($this->invoice(
            $product, 40.0, 500.0, (string) $anchor->id, (string) $po->supplier_id, taxAmount: 1.0,
        ));

        $rate = (float) DB::table('finance_tax_codes')
            ->where('company_id', $this->company->id)
            ->where('tax_type', 'vat')
            ->where('is_active', true)
            ->value('rate');

        self::assertGreaterThan(0.0, $rate, 'The company has no active VAT code to post through.');

        // Tax rides the GRNI leg, whose net is the receipt valuation; with no variance that is
        // also the invoice value.
        self::assertSame(round(20_000.0 * $rate / 100, 2), $this->net('vat_input'), 'Input VAT did not post.');
        self::assertSame(0.0, $this->net('grni'), 'Tagging the GRNI leg with VAT disturbed its clearing.');
    }

    /** Mode 3 taxes the inventory value the same way. */
    public function test_da2_mode3_vat_posts_on_the_inventory_value(): void
    {
        $this->useMode3();

        $product = Product::factory()->create(['product_type' => InventoryClass::RawMaterial->value]);
        $this->postInvoice($this->invoice($product, 40.0, 500.0, anchorId: null, taxAmount: 1.0));

        $rate = (float) DB::table('finance_tax_codes')
            ->where('company_id', $this->company->id)
            ->where('tax_type', 'vat')
            ->where('is_active', true)
            ->value('rate');

        self::assertSame(round(20_000.0 * $rate / 100, 2), $this->net('vat_input'));
        self::assertSame(20_000.0, $this->net('raw_materials'), 'VAT was folded into the inventory value.');
    }

    // ══ FIFO — the payable never rewrites inventory valuation ═════════════════

    /**
     * A price variance changes the PAYABLE, never the stock already on the shelf.
     *
     * The receipt layer keeps its own id, quantity and landed cost: FIFO consumption after an
     * invoice must cost exactly what it would have cost before one existed.
     */
    public function test_invoice_price_does_not_mutate_the_receipt_fifo_layer(): void
    {
        [$po, $poLine, $product] = $this->approvedPo(qty: 100.0, unitPrice: 500.0);
        $anchor = $this->postedReceipt($po, $poLine, qty: 40.0, unitPrice: 500.0);

        $before = InventoryReceiptLayer::query()
            ->where('product_id', $product->id)
            ->get(['id', 'received_qty', 'remaining_qty', 'landed_unit_cost'])
            ->map(fn ($l): array => [
                'id' => (string) $l->id,
                'received' => (float) $l->received_qty,
                'remaining' => (float) $l->remaining_qty,
                'cost' => (float) $l->landed_unit_cost,
            ])
            ->all();

        self::assertNotSame([], $before, 'The receipt created no FIFO layer to protect.');

        // Invoiced 20% above the receipt — the layer must not follow it.
        $this->postInvoice($this->invoice($product, 40.0, 600.0, (string) $anchor->id, (string) $po->supplier_id));

        $after = InventoryReceiptLayer::query()
            ->where('product_id', $product->id)
            ->get(['id', 'received_qty', 'remaining_qty', 'landed_unit_cost'])
            ->map(fn ($l): array => [
                'id' => (string) $l->id,
                'received' => (float) $l->received_qty,
                'remaining' => (float) $l->remaining_qty,
                'cost' => (float) $l->landed_unit_cost,
            ])
            ->all();

        self::assertSame($before, $after, 'The supplier invoice rewrote the FIFO layer.');
    }

    // ══ D-A2 — Mode 3, where the invoice is the inbound authority ═════════════

    /**
     * Mode 3 debits INVENTORY directly and raises NO GRNI.
     *
     * GRNI is the liability a Goods Receipt raises for stock taken in before an invoice existed.
     * Under Mode 3 no receipt posts, so a GRNI leg here would relieve an accrual that was never
     * made — an entry that balances and means nothing.
     */
    public function test_da2_mode3_debits_inventory_at_landed_cost_and_posts_no_grni(): void
    {
        $this->useMode3();

        $product = Product::factory()->create(['product_type' => InventoryClass::RawMaterial->value]);
        $invoice = $this->invoice($product, 40.0, 500.0, anchorId: null);

        $this->postInvoice($invoice);

        self::assertSame(SupplierInvoiceStatus::Posted, $invoice->refresh()->status);

        // The product is a raw material, so the debit lands on the raw-materials account — chosen
        // by the product's own class, never a generic inventory default.
        self::assertSame(20_000.0, $this->net('raw_materials'), 'Inventory was not debited at landed cost.');
        self::assertSame(0.0, $this->net('grni'), 'Mode 3 fabricated a GRNI clearing.');
        self::assertSame(0.0, $this->net('purchase_price_variance'), 'Mode 3 has no second valuation to vary against.');
        self::assertSame(-20_000.0, $this->net('ap_control'), 'AP was not credited.');
    }

    /** The payable reaches the supplier subledger, written by the one payable writer. */
    public function test_da2_mode3_payable_reaches_the_supplier_ledger(): void
    {
        $this->useMode3();

        $product = Product::factory()->create(['product_type' => InventoryClass::RawMaterial->value]);
        $this->postInvoice($this->invoice($product, 40.0, 500.0, anchorId: null));

        $entries = SupplierLedgerEntry::query()->where('company_id', $this->company->id)->get();

        self::assertCount(1, $entries, 'Mode 3 did not write exactly one supplier ledger entry.');
        self::assertSame(20_000.0, round((float) $entries->first()->amount, 2));
    }

    /** Mode 3 posting is idempotent too. */
    public function test_da2_mode3_repeated_posting_is_idempotent(): void
    {
        $this->useMode3();

        $product = Product::factory()->create(['product_type' => InventoryClass::RawMaterial->value]);
        $invoice = $this->invoice($product, 40.0, 500.0, anchorId: null);

        $this->postInvoice($invoice);
        $inventory = $this->net('raw_materials');
        $ap = $this->net('ap_control');

        $this->assertSecondPostingRejected($invoice);

        self::assertSame($inventory, $this->net('raw_materials'), 'Inventory was debited twice.');
        self::assertSame($ap, $this->net('ap_control'), 'AP was credited twice.');
        self::assertCount(1, SupplierLedgerEntry::query()->where('company_id', $this->company->id)->get());
    }
}
