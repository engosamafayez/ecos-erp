<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Purchasing\PurchaseOrders\Application\Actions\CreatePurchaseOrderAction;
use Modules\Purchasing\PurchaseOrders\Application\DTO\PurchaseOrderDTO;
use Modules\Purchasing\PurchaseOrders\Domain\Models\PurchaseOrder;
use Modules\Purchasing\Suppliers\Domain\Models\Supplier;
use Tests\TestCase;

/**
 * TASK-ECOS-PROCUREMENT-PURCHASE-REQUESTS-FINAL-CLOSURE-013.
 *
 * `po_number` is a MAX+1 read (EloquentPurchaseOrderRepository::nextPoNumber()) against a
 * DB-level unique index (`purchase_orders_po_number_unique`) — the identical unlocked-read
 * shape already found and fixed for Purchase Materials' `nextRequestNumber()`
 * (TASK-...-012/012-A), flagged there as a known-not-yet-fixed analog in a different module and
 * carried forward as this task's own root-caused blocker. Two concurrent
 * `POST /purchase-orders` requests could read the same "last" number and both attempt the same
 * next one, the second failing the unique constraint as a raw 500.
 *
 * THE REPAIR mirrors `CreatePurchaseMaterialAction::createWithUniqueNumber()` exactly:
 * `CreatePurchaseOrderAction` now wraps the number read and the insert in one DB transaction,
 * and `nextPoNumber()`'s read takes `lockForUpdate()` — a concurrent transaction's own locked
 * read blocks until the first commits, then correctly sees the new max. A bounded retry covers
 * the one window locking cannot close: the very first row ever.
 *
 * HOW THE RACE IS REPRODUCED HERE: identical `DB::listen`-injection technique to
 * `PurchaseMaterialCreateConcurrencyTest` / `GoodsReceiptConcurrencyTest` — a hook fires a
 * second, fully-committed create the moment `nextPoNumber()`'s own SELECT is issued inside the
 * first request's transaction, the exact window an old, unlocked read would have handed out the
 * same number twice.
 *
 * SCOPE OF THIS PROOF, STATED HONESTLY (same caveat as its siblings): proves the retry path
 * recovers cleanly from a real unique-constraint collision, and that the read is genuinely
 * locked inside the action's own transaction. Does not exercise real InnoDB cross-connection
 * blocking — `RefreshDatabase` confines the whole test to one connection.
 */
final class PurchaseOrderCreateConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected bool $grantsBaselineAuthorization = false;

    private Company $company;

    private Supplier $supplier;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->supplier = Supplier::factory()->create(['company_id' => $this->company->id]);
        $this->buyer = $this->purchasingUser(['purchasing.orders.create']);
    }

    private function purchasingUser(array $permissions): User
    {
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $role = Role::create(['slug' => 'test-po-create-'.uniqid(), 'name' => 'test-po-create', 'is_system' => false]);

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

    private function createOne(): PurchaseOrder
    {
        $product = Product::factory()->create();

        $dto = PurchaseOrderDTO::fromArray([
            'supplier_id' => $this->supplier->id,
            'order_date' => now()->toDateString(),
            'lines' => [
                ['product_id' => $product->id, 'quantity' => 5, 'unit_price' => 10],
            ],
        ]);

        $result = app(CreatePurchaseOrderAction::class)->execute($dto);

        return $result->data();
    }

    // ── 1. Control: a normal create ───────────────────────────────────────────

    public function test_a_normal_create_gets_the_first_number(): void
    {
        $this->actingAsUnprivileged($this->buyer);

        $order = $this->createOne();

        self::assertSame('PO-00001', $order->po_number);
        self::assertSame(1, PurchaseOrder::query()->count());
    }

    // ── 2. Sequential creates each get their own number ──────────────────────

    public function test_b_sequential_creates_each_get_a_distinct_incrementing_number(): void
    {
        $this->actingAsUnprivileged($this->buyer);

        $first = $this->createOne();
        $second = $this->createOne();

        self::assertSame('PO-00001', $first->po_number);
        self::assertSame('PO-00002', $second->po_number);
    }

    // ── 3. THE RACE — the defect this task repairs ───────────────────────────

    public function test_c_concurrent_creates_never_collide_on_the_same_number(): void
    {
        $this->actingAsUnprivileged($this->buyer);

        $fired = false;
        $competing = null;

        DB::listen(function ($query) use (&$fired, &$competing): void {
            if ($fired || ! str_contains($query->sql, 'REPLACE(po_number')) {
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
            $first->po_number,
            $competing->po_number,
            'Both concurrent creates were handed the same po_number.',
        );
        self::assertSame(
            2,
            PurchaseOrder::query()->count(),
            'One of the two concurrent creates silently failed instead of retrying.',
        );
        self::assertSame(
            ['PO-00001', 'PO-00002'],
            PurchaseOrder::query()->orderBy('po_number')->pluck('po_number')->all(),
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
            if (str_contains($query->sql, 'REPLACE(po_number') && str_contains(strtolower($query->sql), 'for update')) {
                $sawLock = true;
                $lockLevel = DB::transactionLevel();
            }
        });

        $this->createOne();

        self::assertTrue($sawLock, 'The po_number read was never locked with FOR UPDATE.');
        self::assertGreaterThan(
            $baseline,
            $lockLevel,
            'The lock was taken outside the action’s own transaction, so it cannot serialise concurrent creates.',
        );
    }
}
