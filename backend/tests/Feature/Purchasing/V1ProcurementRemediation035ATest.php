<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Products\Domain\Exceptions\ProductNotFoundException;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Purchasing\GoodsReceipts\Application\Actions\ConfirmReceiptQuantitiesAction;
use Modules\Purchasing\GoodsReceipts\Application\Actions\CreateGoodsReceiptAction;
use Modules\Purchasing\GoodsReceipts\Application\Actions\PostGoodsReceiptAction;
use Modules\Purchasing\GoodsReceipts\Application\DTO\GoodsReceiptDTO;
use Modules\Purchasing\GoodsReceipts\Application\DTO\GoodsReceiptLineDTO;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceipt;
use Modules\Purchasing\SupplierInvoices\Application\Services\InvoiceReceivingLinkService;
use Modules\Purchasing\SupplierInvoices\Application\Services\SupplierInvoiceReceivingSummary;
use Modules\Purchasing\SupplierInvoices\Domain\Models\SupplierInvoice;
use Modules\Purchasing\SupplierInvoices\Domain\Models\SupplierInvoiceLine;
use Modules\Purchasing\SupplierInvoices\Domain\Services\InvoiceReceiptAnchorService;
use Modules\Purchasing\Suppliers\Domain\Models\Supplier;
use Tests\TestCase;

/**
 * TASK-ECOS-V1-REMEDIATION-PROCUREMENT-035A.
 *
 * Covers items 2-5 of this task (item 1 — the supplier-list ambiguous-column 500 — has its own
 * dedicated regression in SupplierMultipleCategoriesTest, matching that file's isolated-schema
 * convention; item 6 — "unknown inventory class" — was investigated and confirmed a stale/
 * misattributed reference, not a real defect, so it has no fix and no test here).
 *
 * Same `actingAs($this->user)`-without-explicit-roles convention as
 * SupplierInvoiceCommercialContractTest / SupplierInvoiceLifecycleReconciliationTest (this
 * module's own baseline authorization already covers every endpoint exercised here).
 */
final class V1ProcurementRemediation035ATest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Warehouse $warehouse;

    private User $user;

    private Supplier $supplier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $this->user = User::factory()->create(['company_id' => $this->company->id]);
        $this->supplier = Supplier::factory()->create(['company_id' => $this->company->id]);
        $this->product = Product::factory()->create(['company_id' => $this->company->id]);
    }

    /** @param array<string, mixed> $overrides */
    private function createInvoice(array $overrides = []): SupplierInvoice
    {
        $payload = array_merge([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'invoice_date' => '2026-09-11',
            'lines' => [[
                'product_id' => $this->product->id,
                'quantity' => 100,
                'unit_price' => 10,
            ]],
        ], $overrides);

        $response = $this->actingAs($this->user)->postJson('/api/supplier-invoices', $payload)->assertCreated();

        return SupplierInvoice::query()->findOrFail($response->json('data.id'));
    }

    private function validate(SupplierInvoice $invoice): void
    {
        $this->actingAs($this->user)->postJson("/api/supplier-invoices/{$invoice->id}/validate")->assertOk();
    }

    // ── Part B — invoice-first supplier authority ─────────────────────────────────

    public function test_invoice_first_posting_succeeds_once_the_linked_receipt_is_posted(): void
    {
        $invoice = $this->createInvoice();
        $this->validate($invoice);
        $invoice->refresh();

        $receipt = GoodsReceipt::query()->with('lines')->findOrFail($invoice->auto_receipt_id);
        app(ConfirmReceiptQuantitiesAction::class)->execute(
            (string) $receipt->id,
            [['line_id' => $receipt->lines->first()->id, 'accepted_qty' => 100.0]],
        );
        app(PostGoodsReceiptAction::class)->execute((string) $receipt->id);

        // Before the fix: anchorSupplierId() always resolved '' for an invoice-first anchor
        // (no purchase order, no purchase-material line), so this threw supplierMismatch
        // unconditionally — every Mode-1 invoice-first posting failed, regardless of whether
        // the invoice's real supplier agreed with the receipt.
        $this->actingAs($this->user)->postJson("/api/supplier-invoices/{$invoice->id}/post")
            ->assertOk()
            ->assertJsonPath('data.status', 'posted');
    }

    public function test_anchor_supplier_id_resolves_the_invoice_first_path_directly(): void
    {
        $invoice = $this->createInvoice();
        $this->validate($invoice);
        $invoice->refresh()->load('lines');

        $receipt = GoodsReceipt::query()->with('lines')->findOrFail($invoice->auto_receipt_id);
        app(ConfirmReceiptQuantitiesAction::class)->execute(
            (string) $receipt->id,
            [['line_id' => $receipt->lines->first()->id, 'accepted_qty' => 100.0]],
        );
        app(PostGoodsReceiptAction::class)->execute((string) $receipt->id);

        $anchor = app(InvoiceReceiptAnchorService::class)->resolve($invoice, $invoice->lines->first());

        self::assertSame($receipt->lines->first()->id, $anchor->id);
    }

    // ── Part C — anchor preservation (never clobber a valid existing anchor) ──────

    public function test_creating_the_linked_receipt_never_touches_a_line_already_anchored_elsewhere(): void
    {
        // A pre-existing, foreign Goods Receipt line — standing in for a legacy manual anchor
        // that predates the auto-linking flow ever touching this invoice.
        $foreignReceipt = app(CreateGoodsReceiptAction::class)->execute(new GoodsReceiptDTO(
            purchase_order_id: null,
            warehouse_id: (string) $this->warehouse->id,
            receipt_date: '2026-09-01',
            notes: 'Pre-existing legacy receipt',
            lines: [new GoodsReceiptLineDTO(
                purchase_order_line_id: null,
                product_id: (string) $this->product->id,
                ordered_quantity: 50,
                received_quantity: 0,
                gross_received_quantity: 0,
                net_received_quantity: 0,
                unit_price: 10,
            )],
        ))->data();
        $foreignReceipt->loadMissing('lines');
        $foreignLine = $foreignReceipt->lines->first();

        $invoice = SupplierInvoice::query()->create([
            'invoice_number' => 'INV-TEST-'.uniqid(),
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'company_id' => $this->company->id,
            'invoice_date' => now()->toDateString(),
            'status' => 'draft',
            'subtotal' => 1500,
            'grand_total' => 1500,
        ]);
        $anchoredLine = SupplierInvoiceLine::query()->create([
            'supplier_invoice_id' => $invoice->id,
            'product_id' => $this->product->id,
            'quantity' => 50,
            'unit_price' => 10,
            'line_total' => 500,
            'goods_receipt_line_id' => $foreignLine->id,
        ]);
        $unanchoredLine = SupplierInvoiceLine::query()->create([
            'supplier_invoice_id' => $invoice->id,
            'product_id' => $this->product->id,
            'quantity' => 100,
            'unit_price' => 10,
            'line_total' => 1000,
        ]);

        app(InvoiceReceivingLinkService::class)->sync($invoice->refresh());

        // The pre-existing anchor must survive untouched — never overwritten with a pointer
        // into a brand-new auto-created receipt.
        self::assertSame($foreignLine->id, $anchoredLine->refresh()->goods_receipt_line_id);

        // The previously-unanchored line gets a fresh receipt line of its own.
        self::assertNotNull($unanchoredLine->refresh()->goods_receipt_line_id);
        self::assertNotSame($foreignLine->id, $unanchoredLine->goods_receipt_line_id);

        // The foreign receipt itself is untouched — still exactly its own original line.
        self::assertSame(1, $foreignReceipt->refresh()->lines()->count());
    }

    // ── Part D — physical (draft) partial receipt must not read as "nothing accepted" ──

    public function test_a_draft_but_confirmed_partial_quantity_shows_as_partially_received(): void
    {
        $invoice = $this->createInvoice();
        $this->validate($invoice);
        $invoice->refresh();

        $receipt = GoodsReceipt::query()->with('lines')->findOrFail($invoice->auto_receipt_id);
        app(ConfirmReceiptQuantitiesAction::class)->execute(
            (string) $receipt->id,
            [['line_id' => $receipt->lines->first()->id, 'accepted_qty' => 40.0]],
        );

        // Before the fix: reconciledQuantity() only counts POSTED receipt lines, so this
        // confirmed-but-still-Draft quantity was invisible and the summary reported "awaiting"
        // — as if nothing had been physically received at all.
        $summary = app(SupplierInvoiceReceivingSummary::class)->for($invoice->refresh());

        self::assertSame(SupplierInvoiceReceivingSummary::PARTIALLY_RECEIVED, $summary['status']);
        self::assertSame(40.0, $summary['lines'][0]['accepted_qty']);
    }

    public function test_reject_receiving_is_blocked_once_a_draft_but_unconfirmed_partial_quantity_exists(): void
    {
        $invoice = $this->createInvoice();
        $this->validate($invoice);
        $invoice->refresh();

        $receipt = GoodsReceipt::query()->with('lines')->findOrFail($invoice->auto_receipt_id);
        app(ConfirmReceiptQuantitiesAction::class)->execute(
            (string) $receipt->id,
            [['line_id' => $receipt->lines->first()->id, 'accepted_qty' => 40.0]],
        );

        // Before the fix: this succeeded — the reject guard read the same blind "awaiting"
        // status, silently zeroed the just-confirmed 40 units, and cancelled the whole
        // invoice even though 40 of 100 units were already, physically, on the way in.
        $this->actingAs($this->user)
            ->postJson("/api/supplier-invoices/{$invoice->id}/reject-receiving", ['reason' => 'Too late — already partially received'])
            ->assertStatus(422);

        self::assertSame('validated', $invoice->refresh()->status->value);
        // The confirmed quantity itself must also survive the refused rejection attempt.
        self::assertSame(40.0, (float) $receipt->refresh()->lines->first()->net_received_quantity);
    }

    // ── Part E — cross-company product references rejected deterministically ─────

    public function test_supplier_invoice_creation_rejects_a_cross_company_product_id(): void
    {
        $foreignProduct = Product::factory()->create(['company_id' => Company::factory()->create()->id]);

        $this->actingAs($this->user)->postJson('/api/supplier-invoices', [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'invoice_date' => '2026-09-11',
            'lines' => [['product_id' => $foreignProduct->id, 'quantity' => 10, 'unit_price' => 5]],
        ])->assertStatus(422);

        self::assertSame(0, SupplierInvoice::query()->count(), 'No invoice must be created from a rejected request.');
    }

    public function test_goods_receipt_creation_action_rejects_a_cross_company_product_id(): void
    {
        $foreignProduct = Product::factory()->create(['company_id' => Company::factory()->create()->id]);

        $this->expectException(ProductNotFoundException::class);

        app(CreateGoodsReceiptAction::class)->execute(new GoodsReceiptDTO(
            purchase_order_id: null,
            warehouse_id: (string) $this->warehouse->id,
            receipt_date: '2026-09-11',
            notes: null,
            lines: [new GoodsReceiptLineDTO(
                purchase_order_line_id: null,
                product_id: (string) $foreignProduct->id,
                ordered_quantity: 10,
                received_quantity: 0,
                gross_received_quantity: 0,
                net_received_quantity: 0,
                unit_price: 5,
            )],
        ));
    }
}
