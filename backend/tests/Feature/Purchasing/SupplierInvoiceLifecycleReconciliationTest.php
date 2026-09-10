<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Finance\Payables\Domain\Models\SupplierBill;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Purchasing\GoodsReceipts\Application\Actions\ConfirmReceiptQuantitiesAction;
use Modules\Purchasing\GoodsReceipts\Application\Actions\PostGoodsReceiptAction;
use Modules\Purchasing\GoodsReceipts\Domain\Enums\GoodsReceiptStatus;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceipt;
use Modules\Purchasing\SupplierInvoices\Domain\Enums\SupplierInvoiceStatus;
use Modules\Purchasing\SupplierInvoices\Domain\Models\SupplierInvoice;
use Modules\Purchasing\Suppliers\Domain\Models\Supplier;
use Tests\TestCase;

/**
 * TASK-ECOS-PROCUREMENT-SUPPLIER-INVOICE-FINAL-LIFECYCLE-020.
 *
 * Covers exactly what this task changed or added — not a re-test of the whole module. The
 * underlying receiving/posting/AP/costing machinery (SelectLineSupplierAction-equivalent,
 * PostSupplierInvoiceService, MaterialCostService, InvoiceReceivingLinkService itself) is already
 * covered by SupplierInvoiceCommercialContractTest / SupplierInvoiceAutoReceivingTest and is
 * deliberately not duplicated here.
 *
 * §4/§7/§15 — commercial approval no longer requires the linked receipt to already be Posted
 * (the bug this task fixes), while final Post still does (the boundary this task leaves alone).
 * §5/§6 — display_status / available_actions are new, presentation-only fields.
 * §12/§13 — Warehouse Full Rejection is a brand-new action.
 * Also covers a second bug found while reconciling this lifecycle (not in the original spec,
 * but directly in its subject matter and severe enough to fix rather than only flag): editing
 * a Draft Mode-1 invoice's items used to throw a raw database error, because syncLines() hard-
 * deletes every existing line while the auto-linked receipt's lines still hold a RESTRICT-on-
 * delete FK back to them — see InvoiceReceivingLinkService::canRewriteLines()'s docblock.
 *
 * Same `actingAs($this->user)`-without-explicit-roles convention as
 * SupplierInvoiceCommercialContractTest (this module's own baseline authorization already covers
 * every endpoint exercised here).
 */
final class SupplierInvoiceLifecycleReconciliationTest extends TestCase
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
            'invoice_date' => '2026-09-01',
            'lines' => [[
                'product_id' => $this->product->id,
                'quantity' => 100,
                'unit_price' => 10,
            ]],
        ], $overrides);

        $response = $this->actingAs($this->user)->postJson('/api/supplier-invoices', $payload)->assertCreated();

        return SupplierInvoice::query()->findOrFail($response->json('data.id'));
    }

    private function validate(SupplierInvoice $invoice): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->postJson("/api/supplier-invoices/{$invoice->id}/validate");
    }

    // ── §4/§7/§15 — the actual bug fix ────────────────────────────────────────────

    public function test_commercial_approval_succeeds_before_any_physical_receiving_exists(): void
    {
        $invoice = $this->createInvoice();
        // The auto-linked receipt exists (TASK-...-014) but is still Draft/unposted — exactly
        // the state that used to make validate() throw "belongs to a different supplier"/
        // "has not been received and posted yet."
        self::assertNotNull($invoice->refresh()->auto_receipt_id);
        $receipt = GoodsReceipt::query()->findOrFail($invoice->auto_receipt_id);
        self::assertSame(GoodsReceiptStatus::Draft, $receipt->status);

        $this->validate($invoice)->assertOk()->assertJsonPath('data.status', 'validated');
    }

    public function test_final_posting_still_requires_the_linked_receipt_to_be_posted_first(): void
    {
        // The one guard this task must NOT weaken: Post still fully re-checks the anchor.
        $invoice = $this->createInvoice();
        $this->validate($invoice)->assertOk();

        $this->actingAs($this->user)->postJson("/api/supplier-invoices/{$invoice->id}/post")
            ->assertStatus(422);

        self::assertSame(SupplierInvoiceStatus::Validated, $invoice->refresh()->status, 'Post must not have silently succeeded.');
    }

    public function test_commercial_approval_still_requires_at_least_one_line(): void
    {
        $invoice = SupplierInvoice::query()->create([
            'invoice_number' => 'INV-TEST-'.uniqid(),
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'company_id' => $this->company->id,
            'invoice_date' => now()->toDateString(),
            'status' => 'draft',
            'subtotal' => 0,
            'grand_total' => 0,
        ]);

        $this->validate($invoice)->assertStatus(422);
        self::assertSame(SupplierInvoiceStatus::Draft, $invoice->refresh()->status);
    }

    // ── §5/§6 — display_status / available_actions ───────────────────────────────

    public function test_display_status_progresses_draft_then_commercially_approved(): void
    {
        $invoice = $this->createInvoice();

        $this->actingAs($this->user)->getJson("/api/supplier-invoices/{$invoice->id}")
            ->assertOk()->assertJsonPath('data.display_status', 'draft');

        $this->validate($invoice)->assertOk();

        $this->actingAs($this->user)->getJson("/api/supplier-invoices/{$invoice->id}")
            ->assertOk()->assertJsonPath('data.display_status', 'commercially_approved');
    }

    public function test_display_status_reflects_partial_then_fully_received(): void
    {
        $invoice = $this->createInvoice();
        $this->validate($invoice)->assertOk();
        $invoice->refresh();
        $receipt = GoodsReceipt::query()->with('lines')->findOrFail($invoice->auto_receipt_id);
        $line = $receipt->lines->first();

        app(ConfirmReceiptQuantitiesAction::class)->execute(
            (string) $receipt->id,
            [['line_id' => $line->id, 'accepted_qty' => 40.0]],
        );

        $this->actingAs($this->user)->getJson("/api/supplier-invoices/{$invoice->id}")
            ->assertOk()->assertJsonPath('data.display_status', 'partial_received');

        app(ConfirmReceiptQuantitiesAction::class)->execute(
            (string) $receipt->id,
            [['line_id' => $line->id, 'accepted_qty' => 100.0]],
        );
        app(PostGoodsReceiptAction::class)->execute((string) $receipt->id);

        $this->actingAs($this->user)->getJson("/api/supplier-invoices/{$invoice->id}")
            ->assertOk()->assertJsonPath('data.display_status', 'fully_received');
    }

    public function test_available_actions_matches_the_real_status_only_never_receiving_state(): void
    {
        $draft = $this->createInvoice();

        $this->actingAs($this->user)->getJson("/api/supplier-invoices/{$draft->id}")
            ->assertOk()
            ->assertJsonPath('data.available_actions', fn ($a): bool => in_array('edit', $a, true)
                && in_array('validate', $a, true) && in_array('cancel', $a, true) && in_array('delete', $a, true)
                && ! in_array('post', $a, true));

        $this->validate($draft)->assertOk();

        $this->actingAs($this->user)->getJson("/api/supplier-invoices/{$draft->id}")
            ->assertOk()
            ->assertJsonPath('data.available_actions', fn ($a): bool => in_array('post', $a, true)
                && in_array('cancel', $a, true)
                && ! in_array('edit', $a, true) && ! in_array('validate', $a, true) && ! in_array('delete', $a, true));
    }

    public function test_the_real_status_engine_is_never_touched_by_the_display_projection(): void
    {
        $invoice = $this->createInvoice();
        $this->validate($invoice)->assertOk();
        $invoice->refresh();

        // displayBucket() is a pure label — the real engine's own guards are unaffected.
        self::assertSame(SupplierInvoiceStatus::Validated, $invoice->status);
        self::assertTrue($invoice->status->canPost());
        self::assertTrue($invoice->status->canCancel());
        self::assertFalse($invoice->status->canValidate());
    }

    // ── §12/§13 — Warehouse Full Rejection ────────────────────────────────────────

    public function test_reject_receiving_requires_a_reason(): void
    {
        $invoice = $this->createInvoice();
        $this->validate($invoice)->assertOk();

        $this->actingAs($this->user)
            ->postJson("/api/supplier-invoices/{$invoice->id}/reject-receiving", ['reason' => ''])
            ->assertStatus(422);
    }

    public function test_reject_receiving_succeeds_before_anything_is_accepted(): void
    {
        $invoice = $this->createInvoice();
        $this->validate($invoice)->assertOk();
        $invoice->refresh();
        $receiptId = $invoice->auto_receipt_id;

        $this->actingAs($this->user)
            ->postJson("/api/supplier-invoices/{$invoice->id}/reject-receiving", ['reason' => 'Damaged pallet, supplier notified'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $invoice->refresh();
        self::assertSame(SupplierInvoiceStatus::Cancelled, $invoice->status);
        self::assertStringContainsString('Damaged pallet, supplier notified', (string) $invoice->internal_notes);

        // The receipt itself is never touched beyond its lines being confirmed at zero — it
        // stays Draft forever (GoodsReceiptStatus has no "rejected" case, §12's own design).
        $receipt = GoodsReceipt::query()->with('lines')->findOrFail($receiptId);
        self::assertSame(GoodsReceiptStatus::Draft, $receipt->status);
        foreach ($receipt->lines as $line) {
            self::assertSame(0.0, (float) $line->net_received_quantity);
        }
    }

    public function test_reject_receiving_is_blocked_once_any_quantity_has_been_accepted(): void
    {
        $invoice = $this->createInvoice();
        $this->validate($invoice)->assertOk();
        $invoice->refresh();
        $receipt = GoodsReceipt::query()->with('lines')->findOrFail($invoice->auto_receipt_id);

        app(ConfirmReceiptQuantitiesAction::class)->execute(
            (string) $receipt->id,
            [['line_id' => $receipt->lines->first()->id, 'accepted_qty' => 10.0]],
        );

        $this->actingAs($this->user)
            ->postJson("/api/supplier-invoices/{$invoice->id}/reject-receiving", ['reason' => 'Too late now'])
            ->assertStatus(422);

        self::assertSame(SupplierInvoiceStatus::Validated, $invoice->refresh()->status, 'A partial receipt must never become Cancelled (§13).');
    }

    public function test_reject_receiving_is_blocked_for_a_draft_invoice(): void
    {
        $invoice = $this->createInvoice();

        $this->actingAs($this->user)
            ->postJson("/api/supplier-invoices/{$invoice->id}/reject-receiving", ['reason' => 'Too early'])
            ->assertStatus(422);
    }

    public function test_reject_receiving_posts_no_ap_and_no_cost_update(): void
    {
        $invoice = $this->createInvoice();
        $this->validate($invoice)->assertOk();

        $this->actingAs($this->user)
            ->postJson("/api/supplier-invoices/{$invoice->id}/reject-receiving", ['reason' => 'Wrong items shipped'])
            ->assertOk();

        self::assertSame(0, SupplierBill::query()->where('company_id', $this->company->id)->count());
        self::assertNull($this->product->refresh()->last_purchase_cost);
    }

    // ── Regression: editing a Draft Mode-1 invoice no longer throws on the linked receipt's
    //    RESTRICT-on-delete FK (found and fixed while reconciling this lifecycle) ────────────

    public function test_editing_a_draft_invoice_with_a_freshly_linked_receipt_succeeds(): void
    {
        $invoice = $this->createInvoice();
        self::assertNotNull($invoice->refresh()->auto_receipt_id, 'Precondition: the auto-link must exist for this to be a real regression test.');

        $secondProduct = Product::factory()->create(['company_id' => $this->company->id]);

        $this->actingAs($this->user)->putJson("/api/supplier-invoices/{$invoice->id}", [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'invoice_date' => '2026-09-01',
            'lines' => [[
                'product_id' => $secondProduct->id,
                'quantity' => 55,
                'unit_price' => 12,
            ]],
        ])->assertOk();

        $invoice->refresh()->load('lines');
        self::assertCount(1, $invoice->lines);
        self::assertSame((string) $secondProduct->id, (string) $invoice->lines->first()->product_id);

        // The receipt was rebuilt from the new line, not left dangling or duplicated.
        $receipt = GoodsReceipt::query()->with('lines')->findOrFail($invoice->auto_receipt_id);
        self::assertCount(1, $receipt->lines);
        self::assertSame(55.0, (float) $receipt->lines->first()->ordered_quantity);
    }

    public function test_editing_is_refused_once_the_linked_receipt_has_accepted_any_quantity(): void
    {
        $invoice = $this->createInvoice();
        $invoice->refresh();
        $receipt = GoodsReceipt::query()->with('lines')->findOrFail($invoice->auto_receipt_id);

        app(ConfirmReceiptQuantitiesAction::class)->execute(
            (string) $receipt->id,
            [['line_id' => $receipt->lines->first()->id, 'accepted_qty' => 5.0]],
        );

        $this->actingAs($this->user)->putJson("/api/supplier-invoices/{$invoice->id}", [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'invoice_date' => '2026-09-01',
            'lines' => [['product_id' => $this->product->id, 'quantity' => 999, 'unit_price' => 1]],
        ])->assertStatus(422);

        // A clean refusal, not a crash — and nothing was actually changed.
        self::assertSame(100.0, (float) $invoice->fresh('lines')->lines->first()->quantity);
    }
}
