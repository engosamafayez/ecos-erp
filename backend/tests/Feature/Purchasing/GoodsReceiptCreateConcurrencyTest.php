<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Purchasing\GoodsReceipts\Application\Actions\CreateGoodsReceiptAction;
use Modules\Purchasing\GoodsReceipts\Application\DTO\GoodsReceiptDTO;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceipt;
use Modules\Purchasing\PurchaseOrders\Domain\Models\PurchaseOrder;
use Tests\TestCase;

/**
 * TASK-ECOS-PROCUREMENT-PURCHASE-REQUESTS-FINAL-CLOSURE-013.
 *
 * `receipt_number` is a MAX+1 read (EloquentGoodsReceiptRepository::nextReceiptNumber())
 * against a DB-level unique index (`goods_receipts_receipt_number_unique`) — the identical
 * unlocked-read shape found and fixed for Purchase Materials' `nextRequestNumber()`
 * (TASK-...-012/012-A) and Purchase Orders' `nextPoNumber()` (this task), discovered here while
 * root-causing this task's own race/concurrency blocker — not previously flagged in any prior
 * report. `GoodsReceipt` is directly reachable from the Purchase Request work queue (the
 * receiving tab posts through this exact action), so this is squarely part of "no lost updates,
 * no over-ordering due to concurrent commits" for Purchase Requests, not a separate module's
 * problem.
 *
 * A SECOND, DISTINCT bug in the same method (also fixed here): `GoodsReceipt` carries a tenant
 * global scope, but `receipt_number`'s DB index has no `company_id` — the unlocked read was ALSO
 * scanning only the current tenant's rows, so two different companies each creating their first
 * receipt would both compute "no last receipt" and both pick `GR-00001`. Fixed by adding
 * `withoutGlobalScope('tenant')` alongside the lock, exactly as `nextRequestNumber()` already
 * does for `PurchaseMaterial`. This test suite proves the concurrency half within one tenant;
 * the cross-tenant numbering half is not separately reproduced here (it does not need
 * concurrency to manifest — see the source comment on `nextReceiptNumber()` for the reasoning).
 *
 * THE REPAIR mirrors `CreatePurchaseMaterialAction::createWithUniqueNumber()` exactly:
 * `CreateGoodsReceiptAction` now wraps the number read and the insert in one DB transaction
 * (previously the number was read eagerly, outside any lock, before the multi-anchor resolution
 * logic even ran), and `nextReceiptNumber()`'s read takes `lockForUpdate()`. A bounded retry
 * covers the one window locking cannot close: the very first row ever.
 *
 * HOW THE RACE IS REPRODUCED HERE: identical `DB::listen`-injection technique to
 * `PurchaseMaterialCreateConcurrencyTest` / `PurchaseOrderCreateConcurrencyTest` /
 * `GoodsReceiptConcurrencyTest` (D-INB-03) — a hook fires a second, fully-committed create the
 * moment `nextReceiptNumber()`'s own SELECT is issued inside the first request's transaction.
 * Uses the simplest (legacy PO-anchored) branch of `CreateGoodsReceiptAction` — the race is in
 * the shared number-generation step, not anchor-specific, so this proves it once rather than
 * once per anchor type.
 *
 * SCOPE OF THIS PROOF, STATED HONESTLY (same caveat as its siblings): proves the retry path
 * recovers cleanly from a real unique-constraint collision, and that the read is genuinely
 * locked inside the action's own transaction. Does not exercise real InnoDB cross-connection
 * blocking — `RefreshDatabase` confines the whole test to one connection.
 */
final class GoodsReceiptCreateConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected bool $grantsBaselineAuthorization = false;

    private Company $company;

    private Warehouse $warehouse;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $this->buyer = $this->purchasingUser(['purchasing.goods_receipts.create']);
    }

    private function purchasingUser(array $permissions): User
    {
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $role = Role::create(['slug' => 'test-gr-create-'.uniqid(), 'name' => 'test-gr-create', 'is_system' => false]);

        foreach ($permissions as $name) {
            [$module, $resource, $action] = explode('.', $name);
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                ['module' => $module, 'resource' => $resource, 'action' => $action],
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user->roles()->attach($role->id);
        $user->unsetRelation('roles');

        return $user;
    }

    private function createOne(?Company $company = null, ?Warehouse $warehouse = null): GoodsReceipt
    {
        $company ??= $this->company;
        $warehouse ??= $this->warehouse;

        $product = Product::factory()->create();
        $po = PurchaseOrder::factory()->approved()->create(['company_id' => $company->id]);
        $line = $po->lines()->create([
            'product_id' => $product->id,
            'quantity' => 10,
            'unit_price' => 5,
            'line_total' => 50,
        ]);

        $dto = GoodsReceiptDTO::fromArray([
            'purchase_order_id' => $po->id,
            'warehouse_id' => $warehouse->id,
            'receipt_date' => now()->toDateString(),
            'lines' => [[
                'purchase_order_line_id' => $line->id,
                'product_id' => $product->id,
                'ordered_quantity' => 10,
                'net_received_quantity' => 10,
                'gross_received_quantity' => 10,
                'unit_price' => 5,
            ]],
        ]);

        $result = app(CreateGoodsReceiptAction::class)->execute($dto);

        return $result->data();
    }

    // ── 1. Control: a normal create ───────────────────────────────────────────

    public function test_a_normal_create_gets_the_first_number(): void
    {
        $this->actingAsUnprivileged($this->buyer);

        $receipt = $this->createOne();

        self::assertSame('GR-00001', $receipt->receipt_number);
        self::assertSame(1, GoodsReceipt::query()->count());
    }

    // ── 2. Sequential creates each get their own number ──────────────────────

    public function test_b_sequential_creates_each_get_a_distinct_incrementing_number(): void
    {
        $this->actingAsUnprivileged($this->buyer);

        $first = $this->createOne();
        $second = $this->createOne();

        self::assertSame('GR-00001', $first->receipt_number);
        self::assertSame('GR-00002', $second->receipt_number);
    }

    // ── 3. THE RACE — the defect this task repairs ───────────────────────────

    public function test_c_concurrent_creates_never_collide_on_the_same_number(): void
    {
        $this->actingAsUnprivileged($this->buyer);

        $fired = false;
        $competing = null;

        DB::listen(function ($query) use (&$fired, &$competing): void {
            if ($fired || ! str_contains($query->sql, 'REPLACE(receipt_number')) {
                return;
            }

            // Set before re-entering so the competing create's own lookup does not recurse.
            $fired = true;
            $competing = $this->createOne();
        });

        $first = $this->createOne();

        self::assertTrue($fired, 'The competing create was never injected — the race window was not exercised.');
        self::assertNotNull($competing, 'The competing create never completed.');

        self::assertNotSame(
            $first->receipt_number,
            $competing->receipt_number,
            'Both concurrent creates were handed the same receipt_number.',
        );
        self::assertSame(
            2,
            GoodsReceipt::query()->count(),
            'One of the two concurrent creates silently failed instead of retrying.',
        );
        self::assertSame(
            ['GR-00001', 'GR-00002'],
            GoodsReceipt::query()->orderBy('receipt_number')->pluck('receipt_number')->all(),
        );
    }

    // ── 4. The lock is taken inside the action's own transaction ────────────

    public function test_d_the_locked_read_happens_inside_the_transaction(): void
    {
        $this->actingAsUnprivileged($this->buyer);

        $baseline = DB::transactionLevel();
        $sawLock = false;
        $lockLevel = null;

        DB::listen(function ($query) use (&$sawLock, &$lockLevel): void {
            if (str_contains($query->sql, 'REPLACE(receipt_number') && str_contains(strtolower($query->sql), 'for update')) {
                $sawLock = true;
                $lockLevel = DB::transactionLevel();
            }
        });

        $this->createOne();

        self::assertTrue($sawLock, 'The receipt_number read was never locked with FOR UPDATE.');
        self::assertGreaterThan(
            $baseline,
            $lockLevel,
            'The lock was taken outside the action’s own transaction, so it cannot serialise concurrent creates.',
        );
    }

    // ── 5. THE SECOND, DISTINCT BUG — cross-tenant numbering collision ───────

    public function test_e_two_different_companies_each_creating_their_first_receipt_get_different_numbers(): void
    {
        $companyB = Company::factory()->create();
        $warehouseB = Warehouse::factory()->create(['company_id' => $companyB->id]);
        $buyerB = $this->purchasingUser(['purchasing.goods_receipts.create']);
        $buyerB->update(['company_id' => $companyB->id]);

        $this->actingAsUnprivileged($this->buyer);
        $first = $this->createOne();

        $this->actingAsUnprivileged($buyerB);
        $second = $this->createOne($companyB, $warehouseB);

        // An unrestricted (is_system) actor is the only one whose read is not itself
        // tenant-scoped — needed here purely to observe both companies' rows at once.
        $auditorRole = Role::create(['slug' => 'test-gr-audit-'.uniqid(), 'name' => 'test-gr-audit', 'is_system' => true]);
        $auditor = User::factory()->create();
        $auditor->roles()->attach($auditorRole->id);
        $this->actingAsUnprivileged($auditor);

        self::assertSame(
            'GR-00001',
            $first->receipt_number,
            'Company A\'s first receipt should be GR-00001.',
        );
        self::assertNotSame(
            $first->receipt_number,
            $second->receipt_number,
            'Two different companies each creating their first receipt were both handed GR-00001 — '
            .'nextReceiptNumber() is scanning only the current tenant\'s rows instead of the global sequence.',
        );
        self::assertSame(
            2,
            GoodsReceipt::withoutGlobalScope('tenant')->count(),
            'One of the two cross-tenant creates silently failed instead of getting its own number.',
        );
    }
}
