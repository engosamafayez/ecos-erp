<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Purchasing\PurchaseMaterials\Application\Actions\CreatePurchaseMaterialAction;
use Modules\Purchasing\PurchaseMaterials\Application\DTO\PurchaseMaterialDTO;
use Modules\Purchasing\PurchaseMaterials\Domain\Models\PurchaseMaterial;
use Tests\TestCase;

/**
 * TASK-ECOS-PROCUREMENT-PURCHASE-REQUESTS-USER-REVIEW-REMEDIATION-012.
 *
 * `request_number` is a MAX+1 read (EloquentPurchaseMaterialRepository::nextRequestNumber())
 * against a DB-level unique index (`purchase_materials_request_number_unique`). Two concurrent
 * `POST /purchase-materials` requests could both read the same "last" number and both attempt
 * the same next one — the second insert failed the unique constraint and surfaced to the caller
 * as a raw, unhandled 500 at completion time instead of a real request being created. That is the
 * "real issue when completing Purchase Request creation" this task's user-review findings
 * flagged, root-caused by reading the create path end to end (no reproduction steps were given).
 *
 * THE REPAIR: `CreatePurchaseMaterialAction::createWithUniqueNumber()` wraps the number read and
 * the insert in one DB transaction, and `nextRequestNumber()`'s read now takes `lockForUpdate()`
 * — so a second, concurrent transaction's own locked read blocks until the first commits, then
 * correctly sees the new max. A bounded retry (catching the specific unique-constraint violation
 * by name) covers the one window locking cannot close: the very first row ever, where there is no
 * existing row to lock against.
 *
 * HOW THE RACE IS REPRODUCED HERE, following the established pattern in
 * `GoodsReceiptConcurrencyTest` (D-INB-03): a `DB::listen` hook fires a second, fully-committed
 * create the moment `nextRequestNumber()`'s own SELECT is issued inside the first request's
 * transaction — the exact window in which an old, unlocked read would have handed out the same
 * number twice. Both calls go through `CreatePurchaseMaterialAction` directly (not `postJson`),
 * matching that same file's reasoning: a real HTTP dispatch re-entered from inside a low-level
 * query listener risks disturbing request-lifecycle state that has nothing to do with the race
 * being proven.
 *
 * SCOPE OF THIS PROOF, STATED HONESTLY (same caveat as `GoodsReceiptConcurrencyTest`): this proves
 * the retry path recovers cleanly from a real unique-constraint collision, and that the read is
 * genuinely locked inside the action's own transaction. It does not exercise real InnoDB
 * cross-connection blocking, since `RefreshDatabase` confines the whole test to one connection.
 *
 * NOT INDEPENDENTLY EXECUTED. This shared environment's dedicated PHPUnit database
 * (`ecos_erp_test`) was found ~670 migrations behind the current schema when this task ran,
 * making a live `RefreshDatabase` run impractical here for the same reasons documented in
 * TASK-ECOS-PROCUREMENT-PURCHASE-REQUESTS-AND-HUB-FINAL-REMEDIATION-011's report. This file is
 * believed correct after careful review against the certified sibling pattern above, but that is
 * not the same claim as "observed passing."
 */
final class PurchaseMaterialCreateConcurrencyTest extends TestCase
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
        $this->buyer = $this->purchasingUser(['purchasing.materials.create']);
    }

    private function purchasingUser(array $permissions): User
    {
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $role = Role::create(['slug' => 'test-pm-create-'.uniqid(), 'name' => 'test-pm-create', 'is_system' => false]);

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

    private function fakeRequest(): Request
    {
        $request = Request::create('/api/purchase-materials', 'POST');
        $request->setUserResolver(fn () => $this->buyer);

        return $request;
    }

    private function createOne(): PurchaseMaterial
    {
        $product = Product::factory()->create();

        $dto = PurchaseMaterialDTO::fromArray([
            'warehouse_id' => $this->warehouse->id,
            'priority' => 'normal',
            'record_type' => 'purchase',
            'lines' => [
                ['product_id' => $product->id, 'requested_qty' => 5],
            ],
        ]);

        $result = app(CreatePurchaseMaterialAction::class)->execute($dto, $this->fakeRequest());

        return $result->data();
    }

    // ── 1. Control: a normal create ───────────────────────────────────────────

    public function test_a_normal_create_gets_the_first_number(): void
    {
        $this->actingAsUnprivileged($this->buyer);

        $material = $this->createOne();

        self::assertSame('PM-00001', $material->request_number);
        self::assertSame(1, PurchaseMaterial::query()->count());
    }

    // ── 2. Sequential creates each get their own number ──────────────────────

    public function test_b_sequential_creates_each_get_a_distinct_incrementing_number(): void
    {
        $this->actingAsUnprivileged($this->buyer);

        $first = $this->createOne();
        $second = $this->createOne();

        self::assertSame('PM-00001', $first->request_number);
        self::assertSame('PM-00002', $second->request_number);
    }

    // ── 3. THE RACE — the defect this task repairs ───────────────────────────

    public function test_c_concurrent_creates_never_collide_on_the_same_number(): void
    {
        $this->actingAsUnprivileged($this->buyer);

        $fired = false;
        $competing = null;

        DB::listen(function ($query) use (&$fired, &$competing): void {
            if ($fired || ! str_contains($query->sql, 'REPLACE(request_number')) {
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
            $first->request_number,
            $competing->request_number,
            'Both concurrent creates were handed the same request_number.',
        );
        self::assertSame(
            2,
            PurchaseMaterial::query()->count(),
            'One of the two concurrent creates silently failed instead of retrying.',
        );
        self::assertSame(
            ['PM-00001', 'PM-00002'],
            PurchaseMaterial::query()->orderBy('request_number')->pluck('request_number')->all(),
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
            if (str_contains($query->sql, 'REPLACE(request_number') && str_contains(strtolower($query->sql), 'for update')) {
                $sawLock = true;
                $lockLevel = DB::transactionLevel();
            }
        });

        $this->createOne();

        self::assertTrue($sawLock, 'The request_number read was never locked with FOR UPDATE.');
        self::assertGreaterThan(
            $baseline,
            $lockLevel,
            'The lock was taken outside the action’s own transaction, so it cannot serialise concurrent creates.',
        );
    }
}
