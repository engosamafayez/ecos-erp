<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Purchasing\GoodsReceipts\Application\Actions\CreateGoodsReceiptAction;
use Modules\Purchasing\GoodsReceipts\Application\Actions\PostGoodsReceiptAction;
use Modules\Purchasing\GoodsReceipts\Application\DTO\GoodsReceiptDTO;
use Modules\Purchasing\GoodsReceipts\Domain\Exceptions\PurchaseMaterialReceivingException;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceipt;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceiptLine;
use Modules\Purchasing\PurchaseMaterials\Domain\Models\PurchaseMaterial;
use Modules\Purchasing\PurchaseMaterials\Domain\Models\PurchaseMaterialLine;
use Modules\Purchasing\SupplierInvoices\Domain\Enums\SupplierInvoiceStatus;
use Modules\Purchasing\SupplierInvoices\Domain\Exceptions\InvoiceAnchorValidationException;
use Modules\Purchasing\SupplierInvoices\Domain\Models\SupplierInvoice;
use Modules\Purchasing\SupplierInvoices\Domain\Models\SupplierInvoiceLine;
use Modules\Purchasing\SupplierInvoices\Domain\Services\InvoiceReceiptAnchorService;
use Modules\Purchasing\Suppliers\Domain\Models\Supplier;
use Tests\TestCase;

/**
 * TASK-PROC-PURCHASING-PHASE2-SUPPLIER-INVOICE-ANCHOR-REALIGNMENT-001.
 *
 * PHASE A. `InvoiceReceiptAnchorService` read the anchor's supplier from the purchase order
 * alone. A Purchase-Material-anchored receipt carries no purchase order, so that read yielded ''
 * and EVERY such line was refused as a supplier mismatch — a Purchase Material could never be
 * invoiced at all. The Purchase Material line is the certified supplier authority for those
 * receipts (RD-1), and it now answers exactly as the warehouse already answered for company.
 *
 * PHASE B. `goods_receipt_line_id` was absent from the request rules while being mass-assigned
 * from RAW input into a fillable column — persisted with no existence check and no tenant scope.
 * It is now a declared, tenant-scoped field, and line synchronisation takes the VALIDATED set.
 *
 * These tests cover only the NEW behaviour. The pre-existing `InvoiceReceiptAnchorTest` covers the
 * legacy purchase-order path and is deliberately left untouched, so running it unchanged is the
 * proof that legacy resolution did not shift.
 */
final class SupplierInvoiceAnchorRealignmentTest extends TestCase
{
    use RefreshDatabase;

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

    private function anchors(): InvoiceReceiptAnchorService
    {
        return app(InvoiceReceiptAnchorService::class);
    }

    /** A Purchase Material line — the RD-1 supplier authority. */
    private function purchaseLine(
        ?string $supplierId = 'default',
        ?Company $company = null,
        ?Warehouse $warehouse = null,
    ): PurchaseMaterialLine {
        $company ??= $this->company;
        $warehouse ??= $this->warehouse;

        $pm = PurchaseMaterial::query()->create([
            'request_number' => 'PM-'.substr(md5(uniqid('', true)), 0, 8),
            'company_id' => $company->id,
            'warehouse_id' => $warehouse->id,
            'record_type' => 'purchase',
            'status' => 'approved',
            'priority' => 'normal',
        ]);

        return PurchaseMaterialLine::query()->create([
            'purchase_material_id' => $pm->id,
            'product_id' => Product::factory()->create()->id,
            'requested_qty' => 100.0,
            'agreed_price' => 25.0,
            'supplier_id' => $supplierId === 'default' ? $this->supplier->id : $supplierId,
        ]);
    }

    /**
     * A POSTED, purchase-material-anchored receipt line — built through the real create/post
     * actions so `purchase_order_id` is genuinely null and `landed_unit_cost` is genuinely stamped.
     */
    private function postedPmReceiptLine(PurchaseMaterialLine $line, float $qty = 40.0, ?Warehouse $warehouse = null): GoodsReceiptLine
    {
        $warehouse ??= $this->warehouse;

        $dto = GoodsReceiptDTO::fromArray([
            'purchase_order_id' => null,
            'warehouse_id' => $warehouse->id,
            'receipt_date' => now()->toDateString(),
            'lines' => [[
                'purchase_material_line_id' => $line->id,
                'product_id' => $line->product_id,
                'ordered_quantity' => (float) $line->requested_qty,
                'gross_received_quantity' => $qty,
                'net_received_quantity' => $qty,
                'unit_price' => (float) $line->agreed_price,
            ]],
        ]);

        /** @var GoodsReceipt $receipt */
        $receipt = app(CreateGoodsReceiptAction::class)->execute($dto)->data();
        app(PostGoodsReceiptAction::class)->execute($receipt->id);

        return GoodsReceiptLine::query()->where('goods_receipt_id', $receipt->id)->firstOrFail();
    }

    private function invoiceWithAnchor(
        GoodsReceiptLine $anchor,
        string $supplierId,
        float $qty,
        float $price,
        ?Company $company = null,
    ): SupplierInvoice {
        $company ??= $this->company;

        $invoice = SupplierInvoice::query()->create([
            'invoice_number' => 'SI-'.uniqid(),
            'supplier_id' => $supplierId,
            'warehouse_id' => $this->warehouse->id,
            'company_id' => $company->id,
            'invoice_date' => now()->toDateString(),
            'status' => SupplierInvoiceStatus::Validated,
            'subtotal' => 0,
            'freight_amount' => 0,
            'additional_costs' => 0,
            'total_amount' => 0,
        ]);

        SupplierInvoiceLine::query()->create([
            'supplier_invoice_id' => $invoice->id,
            'product_id' => $anchor->product_id,
            'goods_receipt_line_id' => $anchor->id,
            'quantity' => $qty,
            'unit_price' => $price,
            'line_total' => $qty * $price,
        ]);

        return $invoice->refresh();
    }

    // ── PHASE A — supplier identity ───────────────────────────────────────────

    public function test_a_pm_anchored_receipt_resolves_its_supplier_from_the_purchase_material_line(): void
    {
        $line = $this->purchaseLine();
        $anchor = $this->postedPmReceiptLine($line);

        // The whole point: before the realignment this threw supplierMismatch, because the
        // receipt has no purchase order to ask.
        $invoice = $this->invoiceWithAnchor($anchor, (string) $this->supplier->id, 40.0, 25.0);

        $resolved = $this->anchors()->resolve($invoice, $invoice->lines->first());

        self::assertSame((string) $anchor->id, (string) $resolved->id);
    }

    public function test_b_no_purchase_order_is_involved_in_the_pm_invoice_path(): void
    {
        $line = $this->purchaseLine();
        $anchor = $this->postedPmReceiptLine($line);

        // The anchor genuinely carries no purchase order at either level.
        self::assertNull($anchor->purchase_order_line_id);
        self::assertNull($anchor->goodsReceipt->purchase_order_id);

        $invoice = $this->invoiceWithAnchor($anchor, (string) $this->supplier->id, 40.0, 25.0);
        $basis = $this->anchors()->basisFor($invoice);

        self::assertCount(1, $basis['lines']);
        self::assertSame(1000.0, $basis['receiptValuation']); // 40 x 25.00 stamped by the receipt
        self::assertSame(1000.0, $basis['invoiceNet']);
        self::assertSame(0.0, $basis['variance']);
    }

    /**
     * A supplier-less Purchase Material line is refused ONE LAYER EARLIER than the anchor —
     * receiving itself will not create a receipt against it. Recorded because it means the
     * "anchor with no resolvable supplier" state is unreachable through the production path.
     */
    public function test_c1_a_purchase_material_line_without_a_supplier_cannot_even_be_received(): void
    {
        $line = $this->purchaseLine(supplierId: null);

        $this->expectException(PurchaseMaterialReceivingException::class);
        $this->postedPmReceiptLine($line);
    }

    public function test_c2_an_anchor_whose_supplier_became_unresolvable_is_still_refused(): void
    {
        $line = $this->purchaseLine();
        $anchor = $this->postedPmReceiptLine($line);
        $invoice = $this->invoiceWithAnchor($anchor, (string) $this->supplier->id, 40.0, 25.0);

        // The receipt exists and was legitimately created, then the PM line's supplier is
        // cleared. Only reachable by writing the column directly — precisely because
        // test_c1 shows the production path refuses it — and that is the point: this
        // exercises the fallback's fail-closed branch, proving it refuses rather than
        // guessing when the authority has gone silent.
        PurchaseMaterialLine::query()->whereKey($line->id)->update(['supplier_id' => null]);

        $this->expectException(InvoiceAnchorValidationException::class);
        $this->anchors()->resolve($invoice->fresh(['lines']), $invoice->lines->first());
    }

    public function test_d_a_pm_anchor_supplied_by_another_supplier_is_refused(): void
    {
        $other = Supplier::factory()->create(['company_id' => $this->company->id]);
        $line = $this->purchaseLine();                     // delivered by $this->supplier
        $anchor = $this->postedPmReceiptLine($line);

        $invoice = $this->invoiceWithAnchor($anchor, (string) $other->id, 40.0, 25.0);

        $this->expectException(InvoiceAnchorValidationException::class);
        $this->anchors()->resolve($invoice, $invoice->lines->first());
    }

    public function test_e_a_pm_anchor_from_another_company_is_refused(): void
    {
        $otherCompany = Company::factory()->create();
        $otherWarehouse = Warehouse::factory()->create(['company_id' => $otherCompany->id]);

        $foreignLine = $this->purchaseLine(company: $otherCompany, warehouse: $otherWarehouse);
        $foreignAnchor = $this->postedPmReceiptLine($foreignLine, warehouse: $otherWarehouse);

        // Invoice belongs to THIS company; the anchor does not.
        $invoice = $this->invoiceWithAnchor($foreignAnchor, (string) $foreignLine->supplier_id, 40.0, 25.0);

        $this->expectException(InvoiceAnchorValidationException::class);
        $this->anchors()->resolve($invoice, $invoice->lines->first());
    }

    // ── PHASE B — the request contract ────────────────────────────────────────

    /** @return array<string, mixed> */
    private function invoicePayload(?string $anchorId, string $productId): array
    {
        return [
            'supplier_id' => (string) $this->supplier->id,
            'warehouse_id' => (string) $this->warehouse->id,
            'invoice_date' => now()->toDateString(),
            'lines' => [array_filter([
                'product_id' => $productId,
                'goods_receipt_line_id' => $anchorId,
                'quantity' => 40,
                'unit_price' => 25.0,
            ], fn ($v) => $v !== null)],
        ];
    }

    public function test_f_a_valid_receipt_line_anchor_is_accepted_and_persisted(): void
    {
        $line = $this->purchaseLine();
        $anchor = $this->postedPmReceiptLine($line);

        $this->actingAs(User::factory()->create(['company_id' => $this->company->id]));

        $this->postJson('/api/supplier-invoices', $this->invoicePayload((string) $anchor->id, (string) $anchor->product_id))
            ->assertSuccessful();

        self::assertSame(
            (string) $anchor->id,
            (string) SupplierInvoiceLine::query()->latest('id')->firstOrFail()->goods_receipt_line_id,
        );
    }

    public function test_g_a_non_existent_receipt_line_anchor_is_rejected(): void
    {
        $line = $this->purchaseLine();
        $this->actingAs(User::factory()->create(['company_id' => $this->company->id]));

        $this->postJson('/api/supplier-invoices', $this->invoicePayload((string) Str::uuid(), (string) $line->product_id))
            ->assertStatus(422)
            ->assertJsonValidationErrors('lines.0.goods_receipt_line_id');
    }

    public function test_h_a_cross_tenant_receipt_line_anchor_is_rejected(): void
    {
        $otherCompany = Company::factory()->create();
        $otherWarehouse = Warehouse::factory()->create(['company_id' => $otherCompany->id]);
        $foreignLine = $this->purchaseLine(company: $otherCompany, warehouse: $otherWarehouse);
        $foreignAnchor = $this->postedPmReceiptLine($foreignLine, warehouse: $otherWarehouse);

        // Actor belongs to THIS company and names Company B's receipt line. Without the tenant
        // scope this would be accepted, and its `landed_unit_cost` would later be read back
        // through the GRNI and PPV legs.
        $this->actingAs(User::factory()->create(['company_id' => $this->company->id]));

        $this->postJson('/api/supplier-invoices', $this->invoicePayload((string) $foreignAnchor->id, (string) $foreignAnchor->product_id))
            ->assertStatus(422)
            ->assertJsonValidationErrors('lines.0.goods_receipt_line_id');

        self::assertSame(0, SupplierInvoiceLine::query()->count());
    }

    public function test_i_an_invoice_without_an_anchor_is_still_accepted(): void
    {
        // The anchor is optional at the contract boundary: a Mode 3 invoice IS the inbound and
        // anchors nothing. Where it is required, posting enforces it — not this request.
        $line = $this->purchaseLine();
        $this->actingAs(User::factory()->create(['company_id' => $this->company->id]));

        $this->postJson('/api/supplier-invoices', $this->invoicePayload(null, (string) $line->product_id))
            ->assertSuccessful();

        self::assertNull(SupplierInvoiceLine::query()->latest('id')->firstOrFail()->goods_receipt_line_id);
    }

    public function test_j_an_undeclared_line_field_can_no_longer_reach_a_column(): void
    {
        $line = $this->purchaseLine();
        $this->actingAs(User::factory()->create(['company_id' => $this->company->id]));

        // `landed_unit_cost` is fillable and was previously mass-assigned straight from raw
        // input. It is owned by the posting run's landed-cost allocation and must never be
        // client-settable.
        $payload = $this->invoicePayload(null, (string) $line->product_id);
        $payload['lines'][0]['landed_unit_cost'] = 999.99;

        $this->postJson('/api/supplier-invoices', $payload)->assertSuccessful();

        $persisted = SupplierInvoiceLine::query()->latest('id')->firstOrFail();
        self::assertNotEquals(999.99, (float) $persisted->landed_unit_cost);
    }
}
