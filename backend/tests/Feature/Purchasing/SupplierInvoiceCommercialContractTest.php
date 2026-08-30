<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Core\Documents\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Finance\Ledger\Domain\Enums\AccountType;
use Modules\Finance\Ledger\Domain\Services\ChartOfAccountsService;
use Modules\Finance\Payables\Domain\Models\PaymentAllocation;
use Modules\Finance\Payables\Domain\Models\SupplierBill;
use Modules\Finance\Payables\Domain\Models\SupplierPayment;
use Modules\Inventory\InventoryItems\Domain\Models\InventoryItem;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceipt;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceiptLine;
use Modules\Purchasing\PurchaseOrders\Domain\Models\PurchaseOrder;
use Modules\Purchasing\SupplierInvoices\Domain\Enums\SupplierInvoiceStatus;
use Modules\Purchasing\SupplierInvoices\Domain\Models\SupplierInvoice;
use Modules\Purchasing\Suppliers\Domain\Models\Supplier;
use Tests\TestCase;

/**
 * TASK-PROCUREMENT-SUPPLIER-INVOICE-COMMERCIAL-CONTRACT-001.
 *
 * The Supplier Invoice as the canonical commercial/financial document: header + attachment + line
 * calculation + totals + a DERIVED payment read-model (Paid/Remaining/Status from the canonical AP
 * allocation authority) + read-only PO→GR→Invoice linkage — WITHOUT becoming a physical-receiving or
 * inventory authority. Approval never moves stock and never creates a Goods Receipt.
 */
class SupplierInvoiceCommercialContractTest extends TestCase
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
        $this->supplier = Supplier::factory()->create();
        $this->product = Product::factory()->create();
    }

    /** A fresh, role-less user — actingAs() persists a system role, so a contaminated user cannot assert 403. */
    private function stranger(): User
    {
        return User::factory()->create(['company_id' => $this->company->id]);
    }

    /** @param array<string, mixed> $overrides */
    private function createInvoice(array $overrides = [], ?array $lines = null): SupplierInvoice
    {
        $payload = array_merge([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'invoice_date' => '2026-08-01',
            'due_date' => '2026-09-01',
            'supplier_invoice_ref' => 'SUP-REF-1',
            'freight_amount' => 100,
            'additional_costs' => 50,
            'lines' => $lines ?? [[
                'product_id' => $this->product->id,
                'quantity' => 10,
                'unit_price' => 20,
                'tax_rate' => 15,
            ]],
        ], $overrides);

        $response = $this->actingAs($this->user)->postJson('/api/supplier-invoices', $payload)->assertCreated();

        return SupplierInvoice::query()->findOrFail($response->json('data.id'));
    }

    // ── Header ───────────────────────────────────────────────────────────────────

    public function test_header_fields_persist_with_company_derived_from_the_warehouse(): void
    {
        $invoice = $this->createInvoice();

        $this->assertSame((string) $this->company->id, (string) $invoice->company_id);
        $this->assertSame((string) $this->warehouse->id, (string) $invoice->warehouse_id);
        $this->assertSame((string) $this->supplier->id, (string) $invoice->supplier_id);
        $this->assertSame('2026-08-01', $invoice->invoice_date->toDateString());
        $this->assertSame('2026-09-01', $invoice->due_date->toDateString());
        $this->assertSame('SUP-REF-1', $invoice->supplier_invoice_ref);
        $this->assertSame(SupplierInvoiceStatus::Draft, $invoice->status);
    }

    // ── Line calculation + totals (backend authoritative) ────────────────────────

    public function test_line_total_and_invoice_total_are_computed_by_the_backend(): void
    {
        // qty 10 × price 20 = 200 net; +15% tax = 30; line_total 230.
        // grand_total = subtotal(net 200) + tax 30 + freight 100 + additional 50 = 380.
        $invoice = $this->createInvoice();

        $this->actingAs($this->user)->getJson("/api/supplier-invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('data.subtotal', fn ($v): bool => (float) $v === 200.0)
            ->assertJsonPath('data.tax_total', fn ($v): bool => (float) $v === 30.0)
            ->assertJsonPath('data.grand_total', fn ($v): bool => (float) $v === 380.0)
            ->assertJsonPath('data.lines.0.line_total', fn ($v): bool => (float) $v === 230.0)
            ->assertJsonPath('data.lines.0.unit_price', fn ($v): bool => (float) $v === 20.0);
    }

    public function test_backend_recomputes_line_total_ignoring_any_client_supplied_value(): void
    {
        // §5 — the backend is authoritative. A client-sent line_total is not a declared field, so it
        // is stripped and the canonical qty×price(+tax) is what persists.
        $invoice = $this->createInvoice(lines: [[
            'product_id' => $this->product->id,
            'quantity' => 4,
            'unit_price' => 25,
            'tax_rate' => 0,
            'line_total' => 999999, // client attempt — must be ignored
        ]]);

        $line = $invoice->lines()->firstOrFail();
        $this->assertSame('100.0000', $line->line_total); // 4 × 25, tax 0
    }

    public function test_transport_and_additional_expenses_change_the_invoice_total(): void
    {
        $without = $this->createInvoice(['freight_amount' => 0, 'additional_costs' => 0]);
        $with = $this->createInvoice(['freight_amount' => 100, 'additional_costs' => 50]);

        // Same single line (net 200 + tax 30 = 230) in both; the two expenses add exactly 150.
        $this->assertSame(230.0, round((float) $without->grand_total, 2));
        $this->assertSame(380.0, round((float) $with->grand_total, 2));
    }

    // ── Payment read-model (derived from the canonical AP authority) ─────────────

    public function test_payment_summary_is_unpaid_when_no_ap_bill_exists(): void
    {
        $invoice = $this->createInvoice();

        $this->actingAs($this->user)->getJson("/api/supplier-invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('data.payment.paid', fn ($v): bool => (float) $v === 0.0)
            ->assertJsonPath('data.payment.remaining', fn ($v): bool => (float) $v === 380.0)
            ->assertJsonPath('data.payment.payment_status', 'unpaid')
            ->assertJsonPath('data.payment.billed', false);
    }

    public function test_payment_summary_derives_paid_remaining_and_status_from_canonical_allocations(): void
    {
        $invoice = $this->createInvoice(); // grand_total 380

        // The canonical AP settlement rows: one bill (number = the 'SI-<id>' convention its writer uses),
        // one payment funded from a real account, one immutable allocation of 100 against the bill.
        $fundingAccount = app(ChartOfAccountsService::class)->create([
            'company_id' => (string) $this->company->id,
            'code' => 'BANK-'.substr(md5((string) $invoice->id), 0, 6),
            'name' => 'Bank',
            'account_type' => AccountType::Asset,
            'is_postable' => true,
        ]);

        $bill = SupplierBill::query()->create([
            'company_id' => (string) $this->company->id,
            'supplier_id' => (string) $this->supplier->id,
            'document_type' => 'bill',
            'number' => 'SI-'.$invoice->id,
            'bill_date' => '2026-08-01',
            'total' => 380.0,
            'status' => 'posted',
        ]);

        $payment = SupplierPayment::query()->create([
            'company_id' => (string) $this->company->id,
            'supplier_id' => (string) $this->supplier->id,
            'number' => 'PAY-'.substr(md5((string) $invoice->id), 0, 6),
            'payment_date' => '2026-08-15',
            'amount' => 100.0,
            'funding_account_id' => $fundingAccount->id,
            'status' => 'posted',
        ]);

        PaymentAllocation::query()->create([
            'company_id' => (string) $this->company->id,
            'payment_id' => $payment->id,
            'supplier_bill_id' => $bill->id,
            'amount' => 100.0,
            'allocated_at' => now(),
        ]);

        $this->actingAs($this->user)->getJson("/api/supplier-invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('data.payment.paid', fn ($v): bool => (float) $v === 100.0)         // sum of canonical allocations
            ->assertJsonPath('data.payment.remaining', fn ($v): bool => (float) $v === 280.0)    // Invoice Total − Paid
            ->assertJsonPath('data.payment.payment_status', 'partially_paid')
            ->assertJsonPath('data.payment.billed', true);
    }

    public function test_payment_summary_exposes_total_due_and_canonical_payment_history(): void
    {
        // TASK-PROCUREMENT-SUPPLIER-INVOICE-AP-PAYMENT-INTEGRATION-001 — the invoice detail surfaces
        // Total / Due / a payment HISTORY derived from the canonical AP allocation authority. No writer.
        $invoice = $this->createInvoice(); // grand_total 380, due 2026-09-01

        $fundingAccount = app(ChartOfAccountsService::class)->create([
            'company_id' => (string) $this->company->id,
            'code' => 'BANK-'.substr(md5((string) $invoice->id), 0, 6),
            'name' => 'Bank',
            'account_type' => AccountType::Asset,
            'is_postable' => true,
        ]);

        $bill = SupplierBill::query()->create([
            'company_id' => (string) $this->company->id,
            'supplier_id' => (string) $this->supplier->id,
            'document_type' => 'bill',
            'number' => 'SI-'.$invoice->id,
            'bill_date' => '2026-08-01',
            'total' => 380.0,
            'status' => 'posted',
        ]);

        $payment = SupplierPayment::query()->create([
            'company_id' => (string) $this->company->id,
            'supplier_id' => (string) $this->supplier->id,
            'number' => 'PAY-HIST-1',
            'payment_date' => '2026-08-15',
            'amount' => 150.0,
            'funding_account_id' => $fundingAccount->id,
            'status' => 'posted',
        ]);

        PaymentAllocation::query()->create([
            'company_id' => (string) $this->company->id,
            'payment_id' => $payment->id,
            'supplier_bill_id' => $bill->id,
            'amount' => 150.0,
            'allocated_at' => now(),
        ]);

        $this->actingAs($this->user)->getJson("/api/supplier-invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('data.payment.total', fn ($v): bool => (float) $v === 380.0)
            ->assertJsonPath('data.payment.due_date', '2026-09-01')
            ->assertJsonPath('data.payment.paid', fn ($v): bool => (float) $v === 150.0)
            ->assertJsonPath('data.payment.history.0.payment_number', 'PAY-HIST-1')
            ->assertJsonPath('data.payment.history.0.payment_date', '2026-08-15')
            ->assertJsonPath('data.payment.history.0.amount', fn ($v): bool => (float) $v === 150.0)
            ->assertJsonPath('data.payment.history.0.payment_status', 'posted');
    }

    public function test_payment_history_is_empty_when_no_payable_has_been_established(): void
    {
        // A commercial invoice with no AP bill (the common Mode-1, unanchored case) has nothing to
        // pay against — the history is empty and Total/Due are still reported. Nothing fabricated.
        $invoice = $this->createInvoice();

        $this->actingAs($this->user)->getJson("/api/supplier-invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('data.payment.total', fn ($v): bool => (float) $v === 380.0)
            ->assertJsonPath('data.payment.due_date', '2026-09-01')
            ->assertJsonPath('data.payment.history', []);
    }

    // ── Inventory boundary (§18) ─────────────────────────────────────────────────

    public function test_invoice_validation_creates_no_goods_receipt_and_moves_no_inventory(): void
    {
        $invoice = $this->createInvoice();

        $this->actingAs($this->user)->postJson("/api/supplier-invoices/{$invoice->id}/validate")
            ->assertOk()
            ->assertJsonPath('data.status', SupplierInvoiceStatus::Validated->value);

        // Approval is a commercial state change only — no physical receipt, no stock movement.
        $this->assertSame(0, GoodsReceipt::query()->withoutGlobalScopes()->count());
        $this->assertFalse(
            InventoryItem::query()
                ->where('warehouse_id', $this->warehouse->id)
                ->where('product_id', $this->product->id)
                ->exists(),
        );
    }

    // ── PO → GR → Invoice linkage (§15–§17), read-only ───────────────────────────

    public function test_existing_goods_receipt_linkage_is_surfaced_as_distinct_ordered_received_invoiced(): void
    {
        $po = PurchaseOrder::factory()->approved()->create([
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->supplier->id,
        ]);
        $receipt = GoodsReceipt::factory()->create([
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_order_id' => $po->id,
        ]);
        $receiptLine = GoodsReceiptLine::factory()->create([
            'goods_receipt_id' => $receipt->id,
            'product_id' => $this->product->id,
            'ordered_quantity' => 100,
            'net_received_quantity' => 95,
        ]);

        // Invoice line settles that receipt line (V-5 anchor); invoiced 90 differs from received 95.
        $invoice = $this->createInvoice(lines: [[
            'product_id' => $this->product->id,
            'quantity' => 90,
            'unit_price' => 20,
            'tax_rate' => 0,
            'goods_receipt_line_id' => $receiptLine->id,
        ]]);

        $this->actingAs($this->user)->getJson("/api/supplier-invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('data.lines.0.goods_receipt_line_id', $receiptLine->id)
            ->assertJsonPath('data.receipt_links.0.receipt_number', $receipt->receipt_number)
            ->assertJsonPath('data.receipt_links.0.po_number', $po->po_number)
            ->assertJsonPath('data.receipt_links.0.ordered_qty', fn ($v): bool => (float) $v === 100.0)
            ->assertJsonPath('data.receipt_links.0.received_qty', fn ($v): bool => (float) $v === 95.0)
            ->assertJsonPath('data.receipt_links.0.invoiced_qty', fn ($v): bool => (float) $v === 90.0);
    }

    // ── Attachment (§3) ──────────────────────────────────────────────────────────

    public function test_attachment_uploads_to_the_private_disk_and_downloads(): void
    {
        Storage::fake('local');
        $invoice = $this->createInvoice();

        // A file upload is multipart — use post(), not postJson().
        $upload = $this->actingAs($this->user)->post(
            "/api/supplier-invoices/{$invoice->id}/documents",
            ['file' => UploadedFile::fake()->create('invoice.pdf', 120, 'application/pdf')],
        )->assertCreated();

        // Recorded against the canonical documents table, scoped to the invoice + its company.
        $this->assertSame(1, Document::query()
            ->where('subject_type', 'SupplierInvoice')
            ->where('subject_id', (string) $invoice->id)
            ->where('company_id', (string) $this->company->id)
            ->count());

        $documentId = $upload->json('data.id');
        $this->actingAs($this->user)
            ->get("/api/supplier-invoices/{$invoice->id}/documents/{$documentId}/download")
            ->assertOk();
    }

    public function test_attachment_upload_is_forbidden_without_permission(): void
    {
        $invoice = $this->createInvoice();

        $this->actingAsUnprivileged($this->stranger())->post(
            "/api/supplier-invoices/{$invoice->id}/documents",
            ['file' => UploadedFile::fake()->create('invoice.pdf', 120, 'application/pdf')],
        )->assertForbidden();
    }

    // ── RBAC (§22) ───────────────────────────────────────────────────────────────

    public function test_unauthorized_user_cannot_create_an_invoice(): void
    {
        $this->actingAsUnprivileged($this->stranger())->postJson('/api/supplier-invoices', [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'invoice_date' => '2026-08-01',
            'lines' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 10]],
        ])->assertForbidden();
    }

    public function test_unauthorized_user_cannot_edit_an_invoice(): void
    {
        $invoice = $this->createInvoice();

        $this->actingAsUnprivileged($this->stranger())->putJson("/api/supplier-invoices/{$invoice->id}", [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'invoice_date' => '2026-08-02',
            'lines' => [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 10]],
        ])->assertForbidden();
    }
}
