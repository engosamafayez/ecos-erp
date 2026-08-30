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
use Modules\Purchasing\GoodsReceipts\Domain\Exceptions\OverReceiptException;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceipt;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceiptLine;
use Modules\Purchasing\PurchaseOrders\Domain\Models\PurchaseOrder;
use Modules\Purchasing\PurchaseOrders\Domain\Models\PurchaseOrderLine;
use Tests\TestCase;

/**
 * D-INB-03 — RECEIPT-LEVEL CONCURRENCY RACE.
 *
 * `PostGoodsReceiptAction` evaluated its duplicate-posting guards (Guard 1: receipt status;
 * Guard 1b: the inbound ledger reference) BEFORE opening its transaction and without taking any
 * lock, while the `Posted` stamp and every inventory mutation happened inside that transaction.
 * Two requests could therefore both observe an unposted receipt, both proceed, and both mutate —
 * two stock ledger entries, two FIFO layers and double `received_qty` for one physical delivery.
 *
 * The over-receipt guard did NOT cover this. It locks the PO line, but a duplicate post only
 * breaches the ordered quantity when a single receipt consumes more than half the order; the
 * 9-of-100 shape below passes it twice, which is exactly why the race was silent.
 *
 * THE REPAIR: re-read the receipt under `lockForUpdate()` as the first statement inside the
 * transaction and re-assert both guards there. The receipt row is the canonical authority for
 * its own posting, so it is what gets locked — no new lock table, no application-level lock, no
 * second idempotency mechanism.
 *
 * HOW THE RACE IS REPRODUCED HERE. `test_concurrent_double_post_...` injects a competing,
 * fully-committed post into the exact window: a `DB::listen` hook fires on the
 * `goods_inward_mode` lookup, which the action performs after both guards have passed and
 * before its transaction opens. The injection is asserted to have fired, so the test cannot
 * pass vacuously if that query ever moves.
 *
 * SCOPE OF THAT PROOF, STATED HONESTLY. It proves the check-then-act window is closed — the
 * decision is re-made from a fresh locked read inside the transaction rather than from the
 * pre-transaction read. It does not exercise InnoDB cross-connection lock blocking, because
 * RefreshDatabase confines the whole test to one connection inside one transaction. The
 * blocking half is demonstrated separately against two real MySQL sessions and recorded in the
 * task's engineering report.
 */
final class GoodsReceiptConcurrencyTest extends TestCase
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

    // ── fixtures (same shapes as the certified InboundOwnershipContractTest) ───

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

    /**
     * Run a competing, fully-committed post of the SAME receipt inside the race window.
     *
     * The hook fires on the `goods_inward_mode` lookup — issued after Guard 1 and Guard 1b have
     * passed and before the action's transaction opens. That is precisely the interval in which
     * a second request could commit under the old code.
     *
     * @return callable(): bool reports whether the injection actually fired
     */
    private function injectCompetingPostIntoTheRaceWindow(string $receiptId): callable
    {
        $fired = false;

        DB::listen(function ($query) use (&$fired, $receiptId): void {
            if ($fired || ! str_contains($query->sql, 'goods_inward_mode')) {
                return;
            }

            // Set before re-entering so the competing post's own lookup does not recurse.
            $fired = true;

            app(PostGoodsReceiptAction::class)->execute($receiptId);
        });

        // NOT an arrow function: `fn () => $fired` would capture $fired BY VALUE at creation
        // time and report false forever, making every race assertion below unreachable.
        return function () use (&$fired): bool {
            return $fired;
        };
    }

    // ── 1. Control: a normal post ─────────────────────────────────────────────

    public function test_a_normal_receipt_post_moves_stock_exactly_once(): void
    {
        [$po, $poLine, $product] = $this->approvedPo();
        $receipt = $this->receipt($po, $poLine, netQty: 9.0);

        $this->postReceipt($receipt->id);

        self::assertSame(9.0, $this->onHand($product));
        self::assertSame(1, $this->inboundLedgerCount($product));
        self::assertSame(1, $this->layerCount($product));
        self::assertSame(GoodsReceiptStatus::Posted, $receipt->refresh()->status);
        self::assertSame(9.0, (float) $poLine->refresh()->received_qty);
    }

    // ── 2. Sequential duplicate ───────────────────────────────────────────────

    public function test_b_duplicate_post_after_success_is_rejected_and_mutates_nothing(): void
    {
        [$po, $poLine, $product] = $this->approvedPo();
        $receipt = $this->receipt($po, $poLine, netQty: 9.0);

        $this->postReceipt($receipt->id);

        $this->expectException(GoodsReceiptAlreadyPostedException::class);

        try {
            $this->postReceipt($receipt->id);
        } finally {
            self::assertSame(9.0, $this->onHand($product));
            self::assertSame(1, $this->inboundLedgerCount($product));
            self::assertSame(1, $this->layerCount($product));
            self::assertSame(9.0, (float) $poLine->refresh()->received_qty);
        }
    }

    // ── 3. THE RACE — the defect this task repairs ────────────────────────────

    public function test_c_concurrent_double_post_produces_exactly_one_inventory_effect(): void
    {
        [$po, $poLine, $product] = $this->approvedPo(qty: 100.0);
        $receipt = $this->receipt($po, $poLine, netQty: 9.0);

        $didFire = $this->injectCompetingPostIntoTheRaceWindow($receipt->id);

        $rejected = false;

        try {
            // The competing post commits mid-flight; this one must stand down.
            $this->postReceipt($receipt->id);
        } catch (GoodsReceiptAlreadyPostedException) {
            $rejected = true;
        }

        // The test is only meaningful if the injection landed in the window.
        self::assertTrue($didFire(), 'The competing post was never injected — the race window was not exercised.');

        self::assertTrue($rejected, 'The second poster was not rejected: the check-then-act window is still open.');

        // Exactly one of everything, for one physical delivery.
        self::assertSame(9.0, $this->onHand($product), 'Quantity was posted twice.');
        self::assertSame(1, $this->inboundLedgerCount($product), 'Duplicate stock ledger entry.');
        self::assertSame(1, $this->layerCount($product), 'Duplicate FIFO receipt layer.');
        self::assertSame(9.0, (float) $poLine->refresh()->received_qty, 'received_qty was incremented twice.');
        self::assertSame(GoodsReceiptStatus::Posted, $receipt->refresh()->status);
    }

    public function test_d_the_locked_reread_happens_inside_the_transaction(): void
    {
        [$po, $poLine] = $this->approvedPo();
        $receipt = $this->receipt($po, $poLine, netQty: 9.0);

        // RefreshDatabase already holds a transaction, so "level > 0" would be vacuously true.
        // The lock must sit STRICTLY DEEPER than the ambient level — i.e. inside the
        // transaction the action itself opens.
        $baseline = DB::transactionLevel();

        $sawLock = false;
        $lockLevel = null;

        DB::listen(function ($query) use (&$sawLock, &$lockLevel): void {
            if (str_contains($query->sql, 'goods_receipts') && str_contains(strtolower($query->sql), 'for update')) {
                $sawLock = true;
                $lockLevel = DB::transactionLevel();
            }
        });

        $this->postReceipt($receipt->id);

        self::assertTrue($sawLock, 'The receipt row was never locked with FOR UPDATE.');
        self::assertGreaterThan(
            $baseline,
            $lockLevel,
            'The lock was taken outside the action’s own transaction, so it cannot serialise the mutation.',
        );
    }

    // ── 4. Rollback after the lock is held ────────────────────────────────────

    public function test_e_failure_after_the_lock_rolls_back_and_the_receipt_stays_retryable(): void
    {
        [$po, $poLine, $product] = $this->approvedPo(qty: 10.0);

        $first = $this->receipt($po, $poLine, netQty: 9.0);
        $this->postReceipt($first->id);

        // 9 + 9 = 18 > 10 ordered → OverReceiptException, thrown AFTER the receipt lock.
        $second = $this->receipt($po, $poLine, netQty: 9.0);

        try {
            $this->postReceipt($second->id);
            self::fail('Expected the over-receipt guard to reject this post.');
        } catch (OverReceiptException) {
            // expected
        }

        // Nothing from the failed attempt survived.
        self::assertSame(9.0, $this->onHand($product));
        self::assertSame(1, $this->inboundLedgerCount($product));
        self::assertSame(1, $this->layerCount($product));
        self::assertSame(9.0, (float) $poLine->refresh()->received_qty);

        // Crucially the document was NOT falsely marked posted, so it can be retried.
        self::assertNotSame(GoodsReceiptStatus::Posted, $second->refresh()->status);

        // A legitimate retry within the remaining quantity now succeeds.
        GoodsReceiptLine::query()
            ->where('goods_receipt_id', $second->id)
            ->update(['received_quantity' => 1.0, 'gross_received_quantity' => 1.0, 'net_received_quantity' => 1.0]);

        $this->postReceipt($second->id);

        self::assertSame(10.0, $this->onHand($product));
        self::assertSame(2, $this->inboundLedgerCount($product));
        self::assertSame(GoodsReceiptStatus::Posted, $second->refresh()->status);
    }

    // ── 5. Tenant isolation is preserved by the locked re-read ────────────────

    public function test_f_a_foreign_company_receipt_cannot_be_posted_or_locked(): void
    {
        $foreign = Company::factory()->create();
        $foreignWarehouse = Warehouse::factory()->create(['company_id' => $foreign->id]);

        $foreignPo = PurchaseOrder::factory()->approved()->create(['company_id' => $foreign->id]);
        $product = Product::factory()->create();
        $foreignPoLine = PurchaseOrderLine::factory()->create([
            'purchase_order_id' => $foreignPo->id,
            'product_id' => $product->id,
            'quantity' => 100.0,
            'received_qty' => 0,
            'unit_price' => 10.0,
        ]);

        $foreignReceipt = $this->receipt($foreignPo, $foreignPoLine, netQty: 9.0, warehouse: $foreignWarehouse);

        // A company-scoped actor holding no privileged role — is_system would legitimately
        // grant cross-company access, so it must not be present here.
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $role = Role::firstOrCreate(
            ['slug' => 'test-gr-concurrency-operator'],
            ['name' => 'Test GR Concurrency Operator', 'is_system' => false],
        );
        $user->roles()->attach($role->id);
        $user->unsetRelation('roles');
        $this->actingAsUnprivileged($user);

        try {
            $this->postReceipt($foreignReceipt->id);
            self::fail('A foreign-company receipt was posted.');
        } catch (GoodsReceiptNotFoundException) {
            // The tenant scope makes the row invisible — the certified 404 contract.
        }

        self::assertSame(0.0, $this->onHand($product, $foreignWarehouse));
        self::assertSame(0, $this->inboundLedgerCount($product));
        self::assertSame(0, $this->layerCount($product));
        self::assertNotSame(GoodsReceiptStatus::Posted, $foreignReceipt->refresh()->status);
    }

    // ── 6. Goods Inward Authority contract is untouched ───────────────────────

    public function test_g_mode3_receipt_still_posts_no_inventory_under_the_lock(): void
    {
        DB::table('companies')
            ->where('id', $this->company->id)
            ->update(['goods_inward_mode' => GoodsInwardMode::SupplierInvoice->value]);

        [$po, $poLine, $product] = $this->approvedPo();
        $receipt = $this->receipt($po, $poLine, netQty: 9.0);

        $this->postReceipt($receipt->id);

        // Mode 3: the Supplier Invoice owns inventory. Receiving bookkeeping still advances.
        self::assertSame(0.0, $this->onHand($product));
        self::assertSame(0, $this->inboundLedgerCount($product));
        self::assertSame(0, $this->layerCount($product));
        self::assertSame(GoodsReceiptStatus::Posted, $receipt->refresh()->status);
        self::assertSame(9.0, (float) $poLine->refresh()->received_qty);
    }

    public function test_h_mode3_receipt_is_still_protected_against_a_concurrent_duplicate(): void
    {
        DB::table('companies')
            ->where('id', $this->company->id)
            ->update(['goods_inward_mode' => GoodsInwardMode::SupplierInvoice->value]);

        [$po, $poLine, $product] = $this->approvedPo();
        $receipt = $this->receipt($po, $poLine, netQty: 9.0);

        $didFire = $this->injectCompetingPostIntoTheRaceWindow($receipt->id);

        $rejected = false;

        try {
            $this->postReceipt($receipt->id);
        } catch (GoodsReceiptAlreadyPostedException) {
            $rejected = true;
        }

        self::assertTrue($didFire(), 'The competing post was never injected.');
        self::assertTrue($rejected, 'The duplicate post was not rejected in Mode 3.');

        // No inventory either way, but received_qty must not be double-counted.
        self::assertSame(0, $this->inboundLedgerCount($product));
        self::assertSame(0, $this->layerCount($product));
        self::assertSame(9.0, (float) $poLine->refresh()->received_qty, 'received_qty was incremented twice.');
    }
}
