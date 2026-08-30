<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Purchasing\PurchaseMaterials\Domain\Models\PurchaseMaterial;
use Modules\Purchasing\PurchaseMaterials\Infrastructure\Repositories\EloquentPurchaseMaterialRepository;
use Tests\TestCase;

/**
 * TASK-PROCUREMENT-PURCHASE-MATERIAL-MYSQL-CAST-REPAIR-001
 *
 * Regression cover for `nextRequestNumber()`, which used the PostgreSQL-only
 * `CAST(... AS BIGINT)`. On MySQL 8.4 that is a parse error (SQLSTATE 42000 / errno 1064),
 * so the method failed 100% of the time — including on an empty table, because the error is
 * raised at parse time and never reaches execution.
 *
 * The repair is `AS BIGINT` -> `AS UNSIGNED`, the same expression the structurally identical
 * `EloquentPurchaseOrderRepository::nextPoNumber()` and
 * `EloquentGoodsReceiptRepository` already use.
 *
 * These tests pin the *numeric* ordering semantics, not merely "the query runs" — a plain
 * lexicographic ORDER BY would also execute without error but would hand back a duplicate
 * number at every digit-width boundary.
 */
class PurchaseMaterialNumberGenerationTest extends TestCase
{
    use RefreshDatabase;

    private function repo(): EloquentPurchaseMaterialRepository
    {
        return app(EloquentPurchaseMaterialRepository::class);
    }

    /**
     * Seed a row carrying a specific request number. Deliberately not routed through the
     * repository: these fixtures exist to *set up* the ordering input, and generating them
     * with the code under test would make the assertions circular.
     */
    private function seedNumber(string $requestNumber, ?string $companyId = null): PurchaseMaterial
    {
        return PurchaseMaterial::query()->create([
            'request_number' => $requestNumber,
            'record_type' => 'material_request',
            'company_id' => $companyId,
            'warehouse_id' => Warehouse::factory()->create()->id,
            'status' => 'draft',
            'priority' => 'normal',
            'estimated_value' => 0,
            'approved_value' => 0,
            'purchased_value' => 0,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // The defect itself
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Guards against a false green: on SQLite `CAST(x AS BIGINT)` is perfectly legal, so the
     * whole suite would pass while production stayed broken. If this assertion ever fails the
     * remaining tests in this file prove nothing about the defect.
     */
    public function test_suite_runs_against_mysql_so_the_cast_is_actually_exercised(): void
    {
        $this->assertSame(
            'mysql',
            DB::connection()->getDriverName(),
            'These tests only prove the MySQL cast repair when run against MySQL.',
        );
    }

    public function test_next_request_number_executes_on_mysql_and_seeds_the_first_number(): void
    {
        // The pre-repair expression raised errno 1064 even here, with zero rows.
        $this->assertSame('PM-00001', $this->repo()->nextRequestNumber());
    }

    public function test_generated_sql_uses_a_mysql_compatible_cast(): void
    {
        DB::enableQueryLog();
        $this->repo()->nextRequestNumber();
        $sql = DB::getQueryLog()[0]['query'] ?? '';
        DB::disableQueryLog();

        $this->assertStringContainsString('AS UNSIGNED', $sql);
        $this->assertStringNotContainsString('AS BIGINT', $sql);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Numbering semantics — preserved, not merely "executes"
    // ──────────────────────────────────────────────────────────────────────────

    public function test_next_number_increments_the_highest_existing_number(): void
    {
        $this->seedNumber('PM-00009');

        $next = $this->repo()->nextRequestNumber();

        $this->assertSame('PM-00010', $next);
        $this->assertIsString($next);
    }

    /**
     * The reason the CAST exists at all. Lexicographically 'PM-00009' sorts above 'PM-00010',
     * so an uncast ORDER BY would return PM-00010 a second time and collide with the unique
     * index on request_number.
     */
    public function test_ordering_is_numeric_not_lexicographic(): void
    {
        $this->seedNumber('PM-00009');
        $this->seedNumber('PM-00010');

        $this->assertSame('PM-00011', $this->repo()->nextRequestNumber());
    }

    /**
     * The digit-width boundary, where lexicographic ordering fails hardest: 'PM-99999' sorts
     * above 'PM-100000' as text.
     */
    public function test_rollover_past_five_digits_keeps_counting(): void
    {
        $this->seedNumber('PM-99999');
        $this->assertSame('PM-100000', $this->repo()->nextRequestNumber());

        $this->seedNumber('PM-100000');
        $this->assertSame('PM-100001', $this->repo()->nextRequestNumber());
    }

    public function test_zero_padding_is_preserved_at_five_digits(): void
    {
        $this->seedNumber('PM-00001');

        $this->assertMatchesRegularExpression('/^PM-\d{5}$/', $this->repo()->nextRequestNumber());
    }

    /**
     * `withTrashed()` is part of the existing contract: a soft-deleted row still owns its
     * number, because the unique index on request_number ignores soft deletes.
     */
    public function test_soft_deleted_numbers_are_not_reissued(): void
    {
        $material = $this->seedNumber('PM-00042');
        $material->delete();

        $this->assertSoftDeleted('purchase_materials', ['request_number' => 'PM-00042']);
        $this->assertSame('PM-00043', $this->repo()->nextRequestNumber());
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Uniqueness
    // ──────────────────────────────────────────────────────────────────────────

    public function test_two_consecutive_creations_do_not_produce_the_same_number(): void
    {
        $warehouseId = Warehouse::factory()->create()->id;

        $base = [
            'record_type' => 'material_request',
            'warehouse_id' => $warehouseId,
            'status' => 'draft',
            'priority' => 'normal',
            'estimated_value' => 0,
            'approved_value' => 0,
            'purchased_value' => 0,
        ];

        $first = $this->repo()->create($base + ['request_number' => $this->repo()->nextRequestNumber()], []);
        $second = $this->repo()->create($base + ['request_number' => $this->repo()->nextRequestNumber()], []);

        $this->assertSame('PM-00001', $first->request_number);
        $this->assertSame('PM-00002', $second->request_number);
        $this->assertNotSame($first->request_number, $second->request_number);
        $this->assertSame(2, PurchaseMaterial::query()->count());
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Tenant contract — documented, deliberately unchanged by this repair
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Purchase Material numbering is GLOBAL, not per-company, and must stay that way: the
     * database enforces `purchase_materials_request_number_unique` on request_number ALONE,
     * so a per-company sequence would collide across tenants. The repair changes only the
     * cast keyword and leaves this contract untouched — this test pins it so a future change
     * cannot silently narrow the scan and start issuing duplicates.
     */
    public function test_numbering_is_global_across_companies_and_never_collides(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        $this->seedNumber('PM-00001', $companyA->id);

        // Company B's next number continues the global sequence rather than restarting at 1.
        $this->assertSame('PM-00002', $this->repo()->nextRequestNumber());

        $this->seedNumber('PM-00002', $companyB->id);

        $this->assertSame('PM-00003', $this->repo()->nextRequestNumber());
        $this->assertSame(2, PurchaseMaterial::query()->count());
    }
}
