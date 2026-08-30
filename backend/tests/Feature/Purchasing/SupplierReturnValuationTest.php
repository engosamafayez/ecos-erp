<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Inventory\InventoryItems\Domain\Enums\LedgerMovementType;
use Modules\Inventory\InventoryItems\Domain\Models\InventoryItem;
use Modules\Inventory\InventoryItems\Domain\Models\StockLedgerEntry;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Inventory\ReceiptLayers\Domain\Models\InventoryReceiptLayer;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Purchasing\GoodsReceipts\Application\Actions\PostGoodsReceiptAction;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceipt;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceiptLine;
use Modules\Purchasing\PurchaseOrders\Domain\Models\PurchaseOrder;
use Modules\Purchasing\PurchaseOrders\Domain\Models\PurchaseOrderLine;
use Modules\Purchasing\SupplierReturns\Application\Actions\ApproveSupplierReturnAction;
use Modules\Purchasing\SupplierReturns\Domain\Enums\SupplierReturnStatus;
use Modules\Purchasing\SupplierReturns\Domain\Exceptions\SupplierReturnValidationException;
use Modules\Purchasing\SupplierReturns\Domain\Models\SupplierReturn;
use Modules\Purchasing\SupplierReturns\Domain\Models\SupplierReturnLine;
use Modules\Purchasing\Suppliers\Domain\Models\Supplier;
use Tests\TestCase;

/**
 * SR-1 / SR-2 / SR-3 — Supplier Return valuation, ceiling and atomicity.
 *
 *   SR-1  FIFO consumption is RECEIPT-SCOPED: a return consumes only layers created by its
 *         own goods receipt line, so Supplier A's return can never consume Supplier B's cost.
 *   SR-2  Returnable = received − previously approved returned, per goods_receipt_line_id.
 *   SR-3  No AP mutation in V1. The atomic operation is validate → FIFO → inventory → ledger
 *         → approve → commit, and it rolls back entirely on any failure.
 */
final class SupplierReturnValuationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Warehouse $warehouse;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
        // supplier_returns.approved_by is `bigint unsigned` — a real user id, not a label.
        $this->approver = User::factory()->create(['company_id' => $this->company->id]);
    }

    // ── fixtures ──────────────────────────────────────────────────────────────

    /**
     * A posted goods receipt line — real inbound, so a real FIFO layer exists.
     *
     * @return array{0: GoodsReceiptLine, 1: Product}
     */
    private function postedReceiptLine(
        float $qty,
        float $unitPrice,
        ?Product $product = null,
        ?Supplier $supplier = null,
        ?Company $company = null,
        ?Warehouse $warehouse = null,
    ): array {
        $co = $company ?? $this->company;
        $wh = $warehouse ?? $this->warehouse;
        $product ??= Product::factory()->create();
        $supplier ??= Supplier::factory()->create(['company_id' => $co->id]);

        $po = PurchaseOrder::factory()->approved()->create([
            'company_id' => $co->id,
            'supplier_id' => $supplier->id,
        ]);

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

        $line = GoodsReceiptLine::factory()->create([
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

        app(PostGoodsReceiptAction::class)->execute($receipt->id);

        return [$line->refresh(), $product];
    }

    /**
     * A return anchored to $receiptLine.
     *
     * TWO FIXTURE CHOICES HERE ARE LOAD-BEARING, both corrections of earlier versions that
     * made the suite pass while the implementation was wrong:
     *
     *  1. `supplier_id` is DERIVED from the anchored receipt line, not minted fresh. The
     *     first version created an unrelated supplier for every return, so every test was a
     *     supplier-mismatched document — and all of them were approved. That made the SR-1
     *     test prove only anchor-scoping while the cross-supplier case it claimed to cover
     *     went entirely unexercised. `$supplierId` overrides it so the mismatch can be
     *     tested deliberately.
     *
     *  2. `company_id` is left NULL by default, because that is what production does:
     *     `SupplierReturnController::store()` persists `$request->safe()->except('lines')`
     *     and `company_id` is not a validated key, so no API-created return ever has one.
     *     The first version set it explicitly, which was the only reason the SR-2 ceiling
     *     appeared to work.
     */
    private function makeReturn(
        GoodsReceiptLine $receiptLine,
        Product $product,
        float $qty,
        ?Company $company = null,
        ?Warehouse $warehouse = null,
        bool $withReceiptLine = true,
        ?string $supplierId = null,
        bool $withCompanyId = false,
    ): SupplierReturn {
        $co = $company ?? $this->company;
        $wh = $warehouse ?? $this->warehouse;

        $receiptLine->loadMissing('goodsReceipt.purchaseOrder');
        $supplierId ??= (string) $receiptLine->goodsReceipt?->purchaseOrder?->supplier_id;

        $return = SupplierReturn::query()->create([
            'company_id' => $withCompanyId ? $co->id : null,
            'return_number' => 'SR-'.Str::random(8),
            'supplier_id' => $supplierId,
            'warehouse_id' => $wh->id,
            'status' => SupplierReturnStatus::WaitingApproval,
            'return_date' => now()->toDateString(),
            'inventory_restocked' => false,
        ]);

        SupplierReturnLine::query()->create([
            'supplier_return_id' => $return->id,
            'product_id' => $product->id,
            'goods_receipt_line_id' => $withReceiptLine ? $receiptLine->id : null,
            'return_quantity' => $qty,
            'unit_cost' => 0,
            'total_cost' => 0,
        ]);

        return $return->refresh();
    }

    private function approve(SupplierReturn $r): SupplierReturn
    {
        return app(ApproveSupplierReturnAction::class)->execute($r, (string) $this->approver->id);
    }

    private function onHand(Product $p, ?Warehouse $w = null): float
    {
        return (float) (InventoryItem::query()
            ->where('product_id', $p->id)
            ->where('warehouse_id', ($w ?? $this->warehouse)->id)
            ->value('on_hand_qty') ?? 0.0);
    }

    private function layerRemaining(GoodsReceiptLine $line): float
    {
        return (float) (InventoryReceiptLayer::query()
            ->where('goods_receipt_line_id', $line->id)
            ->value('remaining_qty') ?? 0.0);
    }

    // ── 1 / 8 — receipt-scoped FIFO and its valuation ─────────────────────────

    public function test_1_return_consumes_the_layer_of_its_own_receipt_line(): void
    {
        [$lineA, $product] = $this->postedReceiptLine(qty: 10, unitPrice: 100);

        $return = $this->makeReturn($lineA, $product, qty: 6);
        $approved = $this->approve($return);

        self::assertSame(4.0, $this->onHand($product));
        self::assertSame(4.0, $this->layerRemaining($lineA), 'FIFO layer was not consumed.');

        // Valuation comes from the consumed layer, not material_cost or latest price.
        $returnLine = $approved->lines()->first();
        self::assertSame(100.0, (float) $returnLine->unit_cost);
        self::assertSame(600.0, (float) $returnLine->total_cost);
    }

    /**
     * Anchor-scoping, isolated. Two receipt lines for the SAME product from the SAME supplier
     * at different costs; a return anchored to B must consume B's layer and leave A's
     * untouched, even though A is older and platform-wide FIFO would have taken it first.
     *
     * One supplier deliberately: it removes supplier identity from the experiment, so the
     * anchor is the only thing that can be producing the result. The cross-SUPPLIER half of
     * SR-1 is test_14.
     */
    public function test_2_return_never_consumes_another_receipt_lines_layer(): void
    {
        $supplier = Supplier::factory()->create(['company_id' => $this->company->id]);

        [$lineA, $product] = $this->postedReceiptLine(qty: 10, unitPrice: 100, supplier: $supplier);
        [$lineB] = $this->postedReceiptLine(qty: 10, unitPrice: 120, product: $product, supplier: $supplier);

        self::assertSame(20.0, $this->onHand($product));

        $return = $this->makeReturn($lineB, $product, qty: 6);
        $approved = $this->approve($return);

        self::assertSame(10.0, $this->layerRemaining($lineA), "The older supplier's layer was consumed.");
        self::assertSame(4.0, $this->layerRemaining($lineB));

        // Valued at B's cost (120), NOT the older A cost (100).
        self::assertSame(120.0, (float) $approved->lines()->first()->unit_cost);
    }

    // ── 3 / 4 / 6 — partial, repeated and full returns ────────────────────────

    public function test_3_partial_then_full_return_exhausts_the_ceiling(): void
    {
        [$line, $product] = $this->postedReceiptLine(qty: 100, unitPrice: 10);

        $this->approve($this->makeReturn($line, $product, qty: 20));
        $this->approve($this->makeReturn($line, $product, qty: 30));
        $this->approve($this->makeReturn($line, $product, qty: 50));

        self::assertSame(0.0, $this->onHand($product));
        self::assertSame(0.0, $this->layerRemaining($line));

        // Returnable is now 0 — a further return of any size is refused.
        $this->expectException(SupplierReturnValidationException::class);
        $this->approve($this->makeReturn($line, $product, qty: 1));
    }

    // ── 5 — over-return rejection, before any mutation ────────────────────────

    public function test_5_over_return_is_rejected_before_any_mutation(): void
    {
        [$line, $product] = $this->postedReceiptLine(qty: 100, unitPrice: 10);

        $return = $this->makeReturn($line, $product, qty: 101);

        try {
            $this->approve($return);
            self::fail('An over-return was accepted.');
        } catch (SupplierReturnValidationException) {
            // expected
        }

        // Nothing moved.
        self::assertSame(100.0, $this->onHand($product));
        self::assertSame(100.0, $this->layerRemaining($line));
        self::assertSame(SupplierReturnStatus::WaitingApproval, $return->refresh()->status);
        self::assertFalse((bool) $return->inventory_restocked);
    }

    public function test_5b_over_return_after_a_previous_return_is_rejected(): void
    {
        [$line, $product] = $this->postedReceiptLine(qty: 100, unitPrice: 10);

        $this->approve($this->makeReturn($line, $product, qty: 20)); // returnable now 80

        $this->expectException(SupplierReturnValidationException::class);
        $this->approve($this->makeReturn($line, $product, qty: 81));
    }

    public function test_5c_exactly_the_remaining_returnable_quantity_is_allowed(): void
    {
        [$line, $product] = $this->postedReceiptLine(qty: 100, unitPrice: 10);

        $this->approve($this->makeReturn($line, $product, qty: 20));
        $approved = $this->approve($this->makeReturn($line, $product, qty: 80)); // exactly 80

        self::assertSame(SupplierReturnStatus::Approved, $approved->status);
        self::assertSame(0.0, $this->onHand($product));
    }

    // ── 9 / 10 — atomicity and rollback ───────────────────────────────────────

    /**
     * Adversarial: the FIRST line is valid, the SECOND exceeds its ceiling. The whole
     * approval must roll back — the valid line's stock, layer and ledger must be untouched,
     * and the return must remain unapproved.
     */
    public function test_10_failure_on_a_later_line_rolls_back_the_earlier_one(): void
    {
        // Both lines from ONE supplier, so the second line fails on its CEILING — the
        // condition under test — and not on the supplier guard, which would abort earlier
        // and leave the rollback path unexercised.
        $supplier = Supplier::factory()->create(['company_id' => $this->company->id]);

        [$lineA, $productA] = $this->postedReceiptLine(qty: 10, unitPrice: 100, supplier: $supplier);
        [$lineB, $productB] = $this->postedReceiptLine(qty: 10, unitPrice: 100, supplier: $supplier);

        $return = $this->makeReturn($lineA, $productA, qty: 5);
        SupplierReturnLine::query()->create([
            'supplier_return_id' => $return->id,
            'product_id' => $productB->id,
            'goods_receipt_line_id' => $lineB->id,
            'return_quantity' => 999,   // over-return — fails after line A succeeded
            'unit_cost' => 0,
            'total_cost' => 0,
        ]);

        $ledgerBefore = StockLedgerEntry::query()->count();

        try {
            $this->approve($return->refresh());
            self::fail('The invalid line did not abort the approval.');
        } catch (SupplierReturnValidationException) {
            // expected
        }

        self::assertSame(10.0, $this->onHand($productA), 'Line A inventory was not rolled back.');
        self::assertSame(10.0, $this->layerRemaining($lineA), 'Line A FIFO layer was not rolled back.');
        self::assertSame($ledgerBefore, StockLedgerEntry::query()->count(), 'Ledger entries survived the rollback.');
        self::assertSame(SupplierReturnStatus::WaitingApproval, $return->refresh()->status);
        self::assertFalse((bool) $return->inventory_restocked);
    }

    public function test_10b_retry_after_the_failure_succeeds_exactly_once(): void
    {
        [$line, $product] = $this->postedReceiptLine(qty: 10, unitPrice: 100);

        $bad = $this->makeReturn($line, $product, qty: 99);
        try {
            $this->approve($bad);
        } catch (SupplierReturnValidationException) {
            // expected
        }

        $good = $this->makeReturn($line, $product, qty: 4);
        $this->approve($good);

        self::assertSame(6.0, $this->onHand($product));
        self::assertSame(6.0, $this->layerRemaining($line));
    }

    // ── 11 — idempotency ──────────────────────────────────────────────────────

    public function test_11_repeated_approval_does_not_mutate_twice(): void
    {
        [$line, $product] = $this->postedReceiptLine(qty: 10, unitPrice: 100);

        $return = $this->makeReturn($line, $product, qty: 4);
        $this->approve($return);

        $ledgerAfterFirst = StockLedgerEntry::query()
            ->where('reference_type', 'supplier_return')
            ->where('reference_id', $return->id)
            ->count();

        // Second attempt — via the same action, as a retry would.
        $this->approve($return->refresh());

        self::assertSame(6.0, $this->onHand($product), 'Inventory was reduced twice.');
        self::assertSame(6.0, $this->layerRemaining($line), 'FIFO was consumed twice.');
        self::assertSame(
            $ledgerAfterFirst,
            StockLedgerEntry::query()
                ->where('reference_type', 'supplier_return')
                ->where('reference_id', $return->id)
                ->count(),
            'Duplicate ledger entries were created.',
        );
    }

    // ── 12 — tenant isolation ─────────────────────────────────────────────────

    public function test_12_cannot_return_against_another_companys_receipt_line(): void
    {
        $other = Company::factory()->create();
        $otherWarehouse = Warehouse::factory()->create(['company_id' => $other->id]);

        [$foreignLine, $product] = $this->postedReceiptLine(
            qty: 10,
            unitPrice: 100,
            company: $other,
            warehouse: $otherWarehouse,
        );

        // A return owned by THIS company pointing at the other company's receipt line.
        $return = $this->makeReturn($foreignLine, $product, qty: 5);

        try {
            $this->approve($return);
            self::fail('A cross-company return was accepted.');
        } catch (SupplierReturnValidationException $e) {
            self::assertStringContainsString('another company', $e->getMessage());
        }

        self::assertSame(10.0, $this->layerRemaining($foreignLine), "Another company's layer was consumed.");
    }

    // ── 13 / 14 — receipt-line identity is required, never guessed ────────────

    public function test_13_return_without_receipt_line_identity_is_refused_not_guessed(): void
    {
        [$line, $product] = $this->postedReceiptLine(qty: 10, unitPrice: 100);

        $return = $this->makeReturn($line, $product, qty: 5, withReceiptLine: false);

        try {
            $this->approve($return);
            self::fail('A return with no receipt-line identity was approved.');
        } catch (SupplierReturnValidationException $e) {
            self::assertStringContainsString('will not be guessed', $e->getMessage());
        }

        // The historical row is left exactly as it was — nothing backfilled.
        self::assertNull($return->refresh()->lines()->first()->goods_receipt_line_id);
        self::assertSame(10.0, $this->onHand($product));
    }

    // ── 7 — product identity must match the receipt line ──────────────────────

    public function test_7_return_line_product_must_match_the_receipt_line(): void
    {
        [$line] = $this->postedReceiptLine(qty: 10, unitPrice: 100);
        $otherProduct = Product::factory()->create();

        $return = $this->makeReturn($line, $otherProduct, qty: 1);

        $this->expectException(SupplierReturnValidationException::class);
        $this->approve($return);
    }

    // ── ledger correctness ────────────────────────────────────────────────────

    public function test_ledger_records_the_return_once_with_the_right_reference(): void
    {
        [$line, $product] = $this->postedReceiptLine(qty: 10, unitPrice: 100);

        $return = $this->makeReturn($line, $product, qty: 3);
        $this->approve($return);

        $entries = StockLedgerEntry::query()
            ->where('product_id', $product->id)
            ->where('reference_type', 'supplier_return')
            ->where('reference_id', $return->id)
            ->get();

        self::assertCount(1, $entries);

        // Asserted POSITIVELY, and against the ENUM CASE — `movement_type` is cast, so it
        // arrives as a LedgerMovementType, not a string. The previous
        // `assertNotSame(PurchaseReceipt->value, ...)` compared a string with an enum
        // instance: never identical, so it passed unconditionally and proved nothing about
        // the movement actually written.
        self::assertSame(
            LedgerMovementType::AdjustmentOut,
            $entries->first()->movement_type,
            'A supplier return must be recorded as an outbound adjustment.',
        );
        self::assertSame(3.0, (float) $entries->first()->quantity);
    }

    // ── 17 — the ceiling must accumulate across lines of ONE return ───────────────

    /**
     * `returnable()` excludes the return being approved, so it cannot see the lines this same
     * loop just processed. Two lines anchored to the same receipt line each measured against
     * the full untouched allowance and both passed. The 100-unit receipt below is claimed
     * 60 + 60; the ceiling must refuse the second line itself, rather than leaving it to the
     * FIFO layer to run dry and report "insufficient stock" for an invalid document.
     */
    public function test_17_two_lines_of_one_return_share_a_single_receipt_line_ceiling(): void
    {
        [$line, $product] = $this->postedReceiptLine(qty: 100, unitPrice: 10);

        $return = $this->makeReturn($line, $product, qty: 60);
        SupplierReturnLine::query()->create([
            'supplier_return_id' => $return->id,
            'product_id' => $product->id,
            'goods_receipt_line_id' => $line->id,
            'return_quantity' => 60,
            'unit_cost' => 0,
            'total_cost' => 0,
        ]);

        try {
            $this->approve($return->refresh());
            self::fail('120 units were returned against a 100-unit receipt line.');
        } catch (SupplierReturnValidationException $e) {
            self::assertStringContainsString('exceeds the remaining returnable quantity', $e->getMessage());
        }

        self::assertSame(100.0, $this->onHand($product), 'The rejected return still moved stock.');
        self::assertSame(100.0, $this->layerRemaining($line));
    }

    // ── 18 — a cancelled-after-approval return still consumed its allowance ───────

    /**
     * `Approved -> Cancelled` is legal, and no restock path exists — the stock stays gone.
     * If cancellation handed the allowance back, the same units could be returned twice.
     */
    public function test_18_cancelling_an_approved_return_does_not_restore_the_allowance(): void
    {
        [$line, $product] = $this->postedReceiptLine(qty: 10, unitPrice: 100);

        $first = $this->makeReturn($line, $product, qty: 10);
        $approved = $this->approve($first);

        self::assertTrue($approved->status->canTransitionTo(SupplierReturnStatus::Cancelled));
        $approved->status = SupplierReturnStatus::Cancelled;
        $approved->save();

        // Stock was never given back, so the allowance must not be either.
        self::assertSame(0.0, $this->onHand($product));

        $this->expectException(SupplierReturnValidationException::class);
        $this->approve($this->makeReturn($line, $product, qty: 10));
    }

    // ── 19 — which column is authoritative for "received" ────────────────────────

    /**
     * `effectiveReceivedQty()` is `net_received_quantity ?? received_quantity`. Every other
     * fixture sets the two equal, so nothing pinned which one governs. Here they differ: a
     * 100-unit gross receipt with 90 net (10 rejected on inspection) may have at most 90
     * returned, because only 90 were ever stocked.
     */
    public function test_19_the_ceiling_follows_net_received_not_gross(): void
    {
        [$line, $product] = $this->postedReceiptLine(qty: 100, unitPrice: 10);

        $line->forceFill(['net_received_quantity' => 90, 'variance_quantity' => 10])->save();

        $this->expectException(SupplierReturnValidationException::class);
        $this->approve($this->makeReturn($line->refresh(), $product, qty: 91));
    }

    // ── 14 — SR-1 stated literally: supplier A must not reach supplier B's layer ───

    /**
     * The case SR-1 is actually worded about. Two suppliers deliver the SAME product into
     * the SAME warehouse at different costs; a return addressed to Supplier A names Supplier
     * B's receipt line. Anchoring alone would happily consume B's layer at B's cost and burn
     * down B's returnable allowance, so the anchor must be proven to belong to the supplier
     * the document is addressed to.
     */
    public function test_14_return_anchored_to_another_suppliers_receipt_line_is_refused(): void
    {
        $supplierA = Supplier::factory()->create(['company_id' => $this->company->id]);
        $product = Product::factory()->create();

        [$lineA] = $this->postedReceiptLine(qty: 10, unitPrice: 100, product: $product, supplier: $supplierA);
        [$lineB] = $this->postedReceiptLine(qty: 10, unitPrice: 60, product: $product);

        // Addressed to A, but anchored to B's receipt line.
        $return = $this->makeReturn($lineB, $product, qty: 5, supplierId: (string) $supplierA->id);

        try {
            $this->approve($return);
            self::fail("A return addressed to one supplier consumed another supplier's receipt line.");
        } catch (SupplierReturnValidationException $e) {
            self::assertStringContainsString('was not supplied by supplier', $e->getMessage());
        }

        self::assertSame(10.0, $this->layerRemaining($lineB), "Supplier B's layer was consumed.");
        self::assertSame(10.0, $this->layerRemaining($lineA), "Supplier A's layer was consumed.");
        self::assertSame(20.0, $this->onHand($product), 'Stock moved on a refused return.');
    }

    // ── 15 — the ceiling must hold on the shape production actually creates ───────

    /**
     * Regression for a false green. The ceiling is company-scoped, but no API-created return
     * carries a `company_id`, so scoping on that column made the "previously returned" term
     * always 0 and the ceiling degenerate to `Returnable = Received`. Every ceiling test
     * still passed, because their fixtures set the column the create path never sets.
     *
     * Here the rows are NULL exactly as `store()` leaves them, and the ceiling must still
     * subtract. Without the warehouse-derived scoping the second approval is accepted.
     */
    public function test_15_ceiling_subtracts_prior_returns_when_company_id_is_null(): void
    {
        [$line, $product] = $this->postedReceiptLine(qty: 100, unitPrice: 10);

        $first = $this->makeReturn($line, $product, qty: 60);
        self::assertNull($first->company_id, 'Fixture no longer mirrors the production create path.');
        $this->approve($first);

        // 40 remain. 60 must now be refused BY THE CEILING — not incidentally by the layer
        // running dry, which is a different invariant reporting a different error.
        $second = $this->makeReturn($line, $product, qty: 60);

        try {
            $this->approve($second);
            self::fail('The SR-2 ceiling did not subtract the previous return.');
        } catch (SupplierReturnValidationException $e) {
            self::assertStringContainsString('exceeds the remaining returnable quantity', $e->getMessage());
        }

        self::assertSame(40.0, $this->onHand($product));
    }

    /** Backfilled rows — which DO carry company_id — must keep working. */
    public function test_15b_ceiling_also_holds_for_rows_that_carry_company_id(): void
    {
        [$line, $product] = $this->postedReceiptLine(qty: 100, unitPrice: 10);

        $this->approve($this->makeReturn($line, $product, qty: 60, withCompanyId: true));

        $this->expectException(SupplierReturnValidationException::class);
        $this->approve($this->makeReturn($line, $product, qty: 60, withCompanyId: true));
    }

    // ── 16 — idempotency must not be check-then-act ───────────────────────────────

    /**
     * The pre-transaction guard reads a possibly-stale in-memory model and holds no lock, so
     * a rival approval that loaded the return BEFORE the winner committed still passes it.
     * `$stale` is exactly that model. Only the locked re-read inside the transaction can
     * catch it; without that, the second call consumes the layer a second time and one
     * 4-unit return removes 8 units.
     */
    public function test_16_a_stale_approval_cannot_post_a_second_time(): void
    {
        [$line, $product] = $this->postedReceiptLine(qty: 10, unitPrice: 100);

        $return = $this->makeReturn($line, $product, qty: 4);
        $stale = SupplierReturn::query()->find($return->id);   // loaded pre-approval
        self::assertFalse((bool) $stale->inventory_restocked);

        $this->approve($return);
        $this->approve($stale);

        self::assertSame(6.0, $this->onHand($product), 'Stock was reduced twice.');
        self::assertSame(6.0, $this->layerRemaining($line), 'The FIFO layer was consumed twice.');
        self::assertCount(
            1,
            StockLedgerEntry::query()
                ->where('reference_type', 'supplier_return')
                ->where('reference_id', $return->id)
                ->get(),
            'A duplicate ledger entry was written.',
        );
    }
}
