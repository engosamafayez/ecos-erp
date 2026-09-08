<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Purchasing\GoodsReceipts\Application\Actions\ConfirmReceiptQuantitiesAction;
use Modules\Purchasing\GoodsReceipts\Application\Actions\PostGoodsReceiptAction;
use Modules\Purchasing\GoodsReceipts\Domain\Enums\GoodsReceiptStatus;
use Modules\Purchasing\GoodsReceipts\Domain\Exceptions\OverReceiptException;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceipt;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceiptLine;
use Modules\Purchasing\SupplierInvoices\Application\Services\InvoiceReceivingLinkService;
use Modules\Purchasing\SupplierInvoices\Domain\Exceptions\InvoiceAnchorValidationException;
use Modules\Purchasing\SupplierInvoices\Domain\Models\SupplierInvoice;
use Modules\Purchasing\SupplierInvoices\Domain\Models\SupplierInvoiceLine;
use Modules\Purchasing\SupplierInvoices\Domain\Services\InvoiceReceiptAnchorService;
use Modules\Purchasing\Suppliers\Domain\Models\Supplier;
use Tests\TestCase;

/**
 * TASK-ECOS-PROCUREMENT-INVOICE-FIRST-RECEIVING-FLOW-014.
 *
 * Covers the NEW invoice-first behaviours only. The pre-existing, manually-anchored V-5 flow
 * (an invoice line stating an already-POSTED receipt line it settles) is exercised, unedited, by
 * `InvoiceReceiptAnchorTest` / `SupplierInvoiceAnchorRealignmentTest` /
 * `SupplierInvoiceFinancialPostingTest` — none of those construct an anchor against an unposted
 * receipt (every fixture posts first), so the new "receipt must be posted" guard added to
 * `InvoiceReceiptAnchorService::resolve()` does not change their outcome.
 *
 * Company here always runs the default `goods_receipt` (Mode 1) inbound mode — this task's whole
 * scope. `InvoiceReceivingLinkService::sync()` is called directly (this is where
 * `SupplierInvoiceController::store()`/`update()` call it too) rather than through HTTP, to keep
 * this suite about the receiving contract itself, not request plumbing/permissions.
 */
final class SupplierInvoiceAutoReceivingTest extends TestCase
{
    use RefreshDatabase;

    protected bool $grantsBaselineAuthorization = false;

    private Company $company;

    private Warehouse $warehouse;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $this->supplier = Supplier::factory()->create(['company_id' => $this->company->id]);
    }

    /** @return array{0: SupplierInvoice, 1: SupplierInvoiceLine, 2: Product} */
    private function draftInvoice(float $qty, float $unitPrice): array
    {
        $product = Product::factory()->create();

        $invoice = SupplierInvoice::query()->create([
            'invoice_number' => 'INV-TEST-'.uniqid(),
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'company_id' => $this->company->id,
            'invoice_date' => now()->toDateString(),
            'status' => 'draft',
            'subtotal' => $qty * $unitPrice,
            'grand_total' => $qty * $unitPrice,
            'freight_amount' => 0,
            'additional_costs' => 0,
            'discount_amount' => 0,
        ]);

        $line = SupplierInvoiceLine::query()->create([
            'supplier_invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'quantity' => $qty,
            'unit_price' => $unitPrice,
            'line_total' => $qty * $unitPrice,
        ]);

        return [$invoice->refresh(), $line, $product];
    }

    private function sync(SupplierInvoice $invoice): SupplierInvoice
    {
        app(InvoiceReceivingLinkService::class)->sync($invoice->refresh());

        return $invoice->refresh();
    }

    // ── A/C — creation ─────────────────────────────────────────────────────────

    public function test_a_creating_an_invoice_creates_exactly_one_linked_receipt_with_expected_quantities(): void
    {
        [$invoice, $line] = $this->draftInvoice(qty: 100.0, unitPrice: 10.0);

        $invoice = $this->sync($invoice);

        self::assertNotNull($invoice->auto_receipt_id);
        self::assertSame(1, GoodsReceipt::query()->count());

        $receipt = GoodsReceipt::query()->with('lines')->findOrFail($invoice->auto_receipt_id);
        self::assertSame(GoodsReceiptStatus::Draft, $receipt->status);
        self::assertCount(1, $receipt->lines);

        $receiptLine = $receipt->lines->first();
        self::assertSame(100.0, (float) $receiptLine->ordered_quantity);
        self::assertSame(0.0, (float) $receiptLine->net_received_quantity, 'Nothing is marked received at creation (§6).');

        // C — the V-5 anchor is populated automatically, both directions.
        self::assertSame($receiptLine->id, $line->refresh()->goods_receipt_line_id);
        self::assertSame($line->id, $receiptLine->supplier_invoice_line_id);
    }

    // ── B — idempotency ──────────────────────────────────────────────────────────

    public function test_b_repeated_sync_never_creates_a_second_receipt(): void
    {
        [$invoice] = $this->draftInvoice(qty: 50.0, unitPrice: 5.0);

        $invoice = $this->sync($invoice);
        $firstReceiptId = $invoice->auto_receipt_id;

        // Simulates a retried save / duplicate request.
        $this->sync($invoice);
        $this->sync($invoice);

        self::assertSame(1, GoodsReceipt::query()->count());
        self::assertSame($firstReceiptId, $invoice->refresh()->auto_receipt_id);
    }

    public function test_b2_editing_the_invoice_before_receiving_starts_safely_resyncs_the_draft_lines(): void
    {
        [$invoice, $line, $product] = $this->draftInvoice(qty: 50.0, unitPrice: 5.0);
        $invoice = $this->sync($invoice);

        // Purchasing corrects the quantity before the warehouse has touched anything.
        $line->update(['quantity' => 75.0]);
        $invoice = $this->sync($invoice->refresh());

        self::assertSame(1, GoodsReceipt::query()->count());
        $receiptLine = GoodsReceiptLine::query()->where('goods_receipt_id', $invoice->auto_receipt_id)->sole();
        self::assertSame(75.0, (float) $receiptLine->ordered_quantity);
    }

    public function test_b3_once_receiving_has_recorded_a_quantity_editing_the_invoice_no_longer_rewrites_the_receipt(): void
    {
        [$invoice, $line] = $this->draftInvoice(qty: 50.0, unitPrice: 5.0);
        $invoice = $this->sync($invoice);

        $receipt = GoodsReceipt::query()->findOrFail($invoice->auto_receipt_id);
        $receiptLine = $receipt->lines()->sole();

        app(ConfirmReceiptQuantitiesAction::class)->execute($receipt->id, [
            ['line_id' => $receiptLine->id, 'accepted_qty' => 50.0],
        ]);

        // Purchasing tries to change the invoice AFTER the warehouse already recorded receipt.
        $line->update(['quantity' => 999.0]);
        $this->sync($invoice->refresh());

        self::assertSame(
            50.0,
            (float) $receiptLine->refresh()->ordered_quantity,
            'Accepted physical receipt history must never be silently rewritten (§6/§18).',
        );
    }

    // ── D/E/G/H — reconciliation ─────────────────────────────────────────────────

    public function test_d_warehouse_accepts_the_exact_quantity_and_the_invoice_reconciles(): void
    {
        [$invoice, $line] = $this->draftInvoice(qty: 100.0, unitPrice: 10.0);
        $invoice = $this->sync($invoice);
        $receipt = GoodsReceipt::query()->findOrFail($invoice->auto_receipt_id);
        $receiptLine = $receipt->lines()->sole();

        app(ConfirmReceiptQuantitiesAction::class)->execute($receipt->id, [
            ['line_id' => $receiptLine->id, 'accepted_qty' => 100.0],
        ]);
        app(PostGoodsReceiptAction::class)->execute($receipt->id);

        $reconciled = app(InvoiceReceiptAnchorService::class)->reconciledQuantity($line->refresh());
        self::assertSame(100.0, $reconciled);
    }

    public function test_e_warehouse_accepts_less_and_the_invoice_reconciles_to_the_accepted_amount(): void
    {
        [$invoice, $line] = $this->draftInvoice(qty: 100.0, unitPrice: 10.0);
        $invoice = $this->sync($invoice);
        $receipt = GoodsReceipt::query()->findOrFail($invoice->auto_receipt_id);
        $receiptLine = $receipt->lines()->sole();

        app(ConfirmReceiptQuantitiesAction::class)->execute($receipt->id, [
            ['line_id' => $receiptLine->id, 'accepted_qty' => 95.0],
        ]);
        app(PostGoodsReceiptAction::class)->execute($receipt->id);

        $reconciled = app(InvoiceReceiptAnchorService::class)->reconciledQuantity($line->refresh());

        self::assertSame(95.0, $reconciled, 'Reconciled quantity must be the ACCEPTED amount, never the originally invoiced one.');
        self::assertSame(100.0, (float) $line->quantity, 'The original supplier-declared quantity must never be deleted/overwritten (§8).');
    }

    public function test_g_posting_is_blocked_before_receiving_reconciles(): void
    {
        [$invoice, $line] = $this->draftInvoice(qty: 100.0, unitPrice: 10.0);
        $this->sync($invoice);

        $this->expectException(InvoiceAnchorValidationException::class);

        app(InvoiceReceiptAnchorService::class)->resolve($invoice, $line->refresh());
    }

    public function test_g2_posting_is_still_blocked_after_quantities_are_confirmed_but_before_the_receipt_posts(): void
    {
        [$invoice, $line] = $this->draftInvoice(qty: 100.0, unitPrice: 10.0);
        $invoice = $this->sync($invoice);
        $receipt = GoodsReceipt::query()->findOrFail($invoice->auto_receipt_id);
        $receiptLine = $receipt->lines()->sole();

        app(ConfirmReceiptQuantitiesAction::class)->execute($receipt->id, [
            ['line_id' => $receiptLine->id, 'accepted_qty' => 100.0],
        ]);

        // Confirmed, but NOT yet posted — landed_unit_cost is still null.
        $this->expectException(InvoiceAnchorValidationException::class);
        app(InvoiceReceiptAnchorService::class)->resolve($invoice, $line->refresh());
    }

    public function test_h_reconciled_invoice_becomes_posting_ready(): void
    {
        [$invoice, $line] = $this->draftInvoice(qty: 100.0, unitPrice: 10.0);
        $invoice = $this->sync($invoice);
        $receipt = GoodsReceipt::query()->findOrFail($invoice->auto_receipt_id);
        $receiptLine = $receipt->lines()->sole();

        app(ConfirmReceiptQuantitiesAction::class)->execute($receipt->id, [
            ['line_id' => $receiptLine->id, 'accepted_qty' => 100.0],
        ]);
        app(PostGoodsReceiptAction::class)->execute($receipt->id);

        // No exception — the anchor now resolves cleanly.
        $anchor = app(InvoiceReceiptAnchorService::class)->resolve($invoice, $line->refresh());
        self::assertSame($receiptLine->id, $anchor->id);
    }

    // ── over-receipt ceiling (§9) ─────────────────────────────────────────────────

    public function test_over_accepting_beyond_the_invoiced_quantity_is_rejected_at_post(): void
    {
        [$invoice] = $this->draftInvoice(qty: 100.0, unitPrice: 10.0);
        $invoice = $this->sync($invoice);
        $receipt = GoodsReceipt::query()->findOrFail($invoice->auto_receipt_id);
        $receiptLine = $receipt->lines()->sole();

        // ConfirmReceiptQuantitiesAction itself caps at ordered_quantity, so bypass it here to
        // prove PostGoodsReceiptAction's OWN ceiling (Guard 4) — the authoritative one — also
        // refuses an over-receipt on this branch, exactly as it already does for PO/PM lines.
        $receiptLine->update([
            'received_quantity' => 150.0,
            'gross_received_quantity' => 150.0,
            'net_received_quantity' => 150.0,
        ]);

        $this->expectException(OverReceiptException::class);
        app(PostGoodsReceiptAction::class)->execute($receipt->id);
    }

    // ── L — cross-tenant / mismatch guards untouched ─────────────────────────────

    public function test_l_a_foreign_company_cannot_resolve_another_companys_invoice_first_anchor(): void
    {
        [$invoice, $line] = $this->draftInvoice(qty: 10.0, unitPrice: 1.0);
        $invoice = $this->sync($invoice);
        $receipt = GoodsReceipt::query()->findOrFail($invoice->auto_receipt_id);
        $receiptLine = $receipt->lines()->sole();
        app(ConfirmReceiptQuantitiesAction::class)->execute($receipt->id, [
            ['line_id' => $receiptLine->id, 'accepted_qty' => 10.0],
        ]);
        app(PostGoodsReceiptAction::class)->execute($receipt->id);

        $foreignCompany = Company::factory()->create();
        $foreignWarehouse = Warehouse::factory()->create(['company_id' => $foreignCompany->id]);
        $foreignSupplier = Supplier::factory()->create(['company_id' => $foreignCompany->id]);
        $foreignInvoice = SupplierInvoice::query()->create([
            'invoice_number' => 'INV-TEST-'.uniqid(),
            'supplier_id' => $foreignSupplier->id,
            'warehouse_id' => $foreignWarehouse->id,
            'company_id' => $foreignCompany->id,
            'invoice_date' => now()->toDateString(),
            'status' => 'draft',
            'subtotal' => 0,
            'grand_total' => 0,
            'freight_amount' => 0,
            'additional_costs' => 0,
            'discount_amount' => 0,
        ]);

        $this->expectException(InvoiceAnchorValidationException::class);
        app(InvoiceReceiptAnchorService::class)->resolve($foreignInvoice, $line->refresh());
    }
}
