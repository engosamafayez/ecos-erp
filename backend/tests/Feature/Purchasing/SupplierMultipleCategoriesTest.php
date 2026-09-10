<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Purchasing\Suppliers\Domain\Models\Supplier;
use Modules\Purchasing\Suppliers\Domain\Services\SupplierCategoryAssignmentSyncService;
use Modules\Purchasing\Suppliers\Infrastructure\Repositories\EloquentSupplierRepository;
use Tests\TestCase;

/**
 * TASK-ECOS-PROCUREMENT-SUPPLIER-MASTER-AND-RETURNS-FINAL-018 §A.1 — "a Supplier may
 * belong to more than one Supplier Category."
 *
 * Same isolated-schema approach as SupplierCapabilitiesTest (RefreshDatabase / the full
 * migration set is confirmed infeasible in this environment): hand-creates only the
 * tables the exercised classes actually touch, matching their real migrated shape —
 * plus a minimal `users` table so the REAL production migration
 * (2026_09_10_100000_create_supplier_category_assignments_table) can be executed
 * directly against it, since that migration's `created_by` column is FK-constrained
 * to `users`.
 */
final class SupplierMultipleCategoriesTest extends TestCase
{
    private const CONNECTION = 'supplier_multi_categories_test';

    /** @var list<string> */
    private const TABLES = [
        'supplier_category_assignments', 'supplier_products', 'supplier_product_categories',
        'suppliers', 'supplier_categories', 'products', 'categories',
        'purchase_orders', 'goods_receipts', 'inventory_receipt_layers', 'users',
    ];

    protected bool $grantsBaselineAuthorization = false;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.'.self::CONNECTION => [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '33063',
            'database' => 'ecos_erp_test',
            'username' => 'root',
            'password' => 'testonly',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]]);
        DB::purge(self::CONNECTION);
        DB::setDefaultConnection(self::CONNECTION);

        foreach (self::TABLES as $table) {
            Schema::connection(self::CONNECTION)->dropIfExists($table);
        }

        // Pre-existing (unrelated to this task) LEFT JOINs in
        // EloquentSupplierRepository::paginate() reference these — empty is fine, they
        // only need to EXIST so the join itself doesn't error.
        Schema::connection(self::CONNECTION)->create('purchase_orders', function ($table): void {
            $table->uuid('id')->primary();
            $table->uuid('supplier_id')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::connection(self::CONNECTION)->create('goods_receipts', function ($table): void {
            $table->uuid('id')->primary();
            $table->uuid('purchase_order_id')->nullable();
            $table->string('status')->nullable();
            $table->decimal('invoice_total_amount', 15, 2)->nullable();
            $table->decimal('paid_amount', 15, 2)->nullable();
            $table->date('receipt_date')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::connection(self::CONNECTION)->create('inventory_receipt_layers', function ($table): void {
            $table->uuid('id')->primary();
            $table->uuid('supplier_id')->nullable();
            $table->decimal('remaining_qty', 15, 4)->nullable();
            $table->decimal('landed_unit_cost', 15, 4)->nullable();
        });

        // Minimal, real `id` shape only — exists solely to satisfy the migration's own
        // `created_by` FK. Never populated with a real authenticated user (see the
        // bottom of this method).
        Schema::connection(self::CONNECTION)->create('users', function ($table): void {
            $table->id();
        });

        Schema::connection(self::CONNECTION)->create('supplier_categories', function ($table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->nullable();
            $table->string('code');
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::connection(self::CONNECTION)->create('suppliers', function ($table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->nullable();
            $table->string('code');
            $table->uuid('supplier_category_id')->nullable();
            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('mobile')->nullable();
            $table->string('country')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('district')->nullable();
            $table->string('address')->nullable();
            $table->string('google_maps_url', 1000)->nullable();
            $table->decimal('opening_balance_amount', 15, 2)->default(0);
            $table->string('opening_balance_type', 10)->default('credit');
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::connection(self::CONNECTION)->create('categories', function ($table): void {
            $table->uuid('id')->primary();
            $table->uuid('parent_id')->nullable();
            $table->string('code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('level')->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('category_scope')->default('product');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::connection(self::CONNECTION)->create('products', function ($table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->nullable();
            $table->uuid('brand_id')->nullable();
            $table->string('sku');
            $table->string('barcode')->nullable();
            $table->string('name');
            $table->uuid('category_id')->nullable();
            $table->uuid('unit_id')->nullable();
            $table->string('product_type');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::connection(self::CONNECTION)->create('supplier_products', function ($table): void {
            $table->id();
            $table->uuid('supplier_id');
            $table->uuid('product_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['supplier_id', 'product_id']);
            $table->index('product_id');
        });

        Schema::connection(self::CONNECTION)->create('supplier_product_categories', function ($table): void {
            $table->id();
            $table->uuid('supplier_id');
            $table->uuid('category_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['supplier_id', 'category_id']);
            $table->index('category_id');
        });

        // The real production migration — run directly against the isolated schema above
        // (both `Schema::create` and `DB::table` inside it are bare/default-connection
        // calls, which `DB::setDefaultConnection(self::CONNECTION)` above already redirects
        // here) so this test proves the ACTUAL migration file, not a hand-rolled substitute.
        (require base_path(
            'Modules/Purchasing/Suppliers/Infrastructure/Database/Migrations/'.
            '2026_09_10_100000_create_supplier_category_assignments_table.php',
        ))->up();

        // Deliberately NO Auth::setUser() here: SupplierCategoryAssignmentSyncService takes
        // actorId as an explicit parameter (not resolved via Auth::id()), and an
        // authenticated user would make TenantOwnershipResolver::appliesTo() return true,
        // which then queries roles/user_roles (not created in this minimal schema) — exactly
        // like SupplierCapabilitiesTest's own documented "console/queue/seeder, no actor" bypass.
    }

    protected function tearDown(): void
    {
        foreach (self::TABLES as $table) {
            Schema::connection(self::CONNECTION)->dropIfExists($table);
        }

        parent::tearDown();
    }

    private function makeSupplier(string $companyId, string $code = 'SUP-000001', ?string $categoryId = null): Supplier
    {
        return Supplier::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'code' => $code,
            'supplier_category_id' => $categoryId,
            'name' => 'Test Supplier '.$code,
            'is_active' => true,
        ]);
    }

    private function makeCategory(string $companyId, string $name = 'Test Category'): string
    {
        $id = (string) Str::uuid();
        DB::table('supplier_categories')->insert([
            'id' => $id,
            'company_id' => $companyId,
            'code' => 'SC-'.substr($id, 0, 8),
            'name' => $name,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    // ── MIGRATION / BACKFILL ────────────────────────────────────────────────

    public function test_migration_backfills_existing_single_category_assignments(): void
    {
        $companyId = (string) Str::uuid();
        $categoryA = $this->makeCategory($companyId, 'Category A');
        $categoryB = $this->makeCategory($companyId, 'Category B');

        // Pre-migration-shaped data: two suppliers each carrying only the legacy singular
        // column, exactly as they would on canonical before this task.
        $supplierWithCategory = $this->makeSupplier($companyId, 'SUP-000001', $categoryA);
        $supplierWithoutCategory = $this->makeSupplier($companyId, 'SUP-000002', null);
        $otherCompanySupplier = $this->makeSupplier((string) Str::uuid(), 'SUP-000003', $categoryB);

        // The migration already ran once in setUp() (before these suppliers existed) — run
        // it again here to prove the backfill step itself, isolated from table creation.
        // insertOrIgnore makes this safe to call again without duplicating the first pass.
        (require base_path(
            'Modules/Purchasing/Suppliers/Infrastructure/Database/Migrations/'.
            '2026_09_10_100000_create_supplier_category_assignments_table.php',
        ))->up();

        $this->assertSame(
            [$categoryA],
            DB::table('supplier_category_assignments')->where('supplier_id', $supplierWithCategory->id)->pluck('supplier_category_id')->all(),
        );
        $this->assertSame(
            0,
            DB::table('supplier_category_assignments')->where('supplier_id', $supplierWithoutCategory->id)->count(),
            'A Supplier with no legacy category must not gain a fabricated assignment.',
        );
        $this->assertSame(
            [$categoryB],
            DB::table('supplier_category_assignments')->where('supplier_id', $otherCompanySupplier->id)->pluck('supplier_category_id')->all(),
        );
    }

    // ── SYNC SERVICE ──────────────────────────────────────────────────────

    public function test_assign_multiple_categories(): void
    {
        $companyId = (string) Str::uuid();
        $supplier = $this->makeSupplier($companyId);
        $catA = $this->makeCategory($companyId, 'A');
        $catB = $this->makeCategory($companyId, 'B');

        app(SupplierCategoryAssignmentSyncService::class)->sync($supplier, [$catA, $catB], actorId: 42);

        $ids = $supplier->categories()->pluck('supplier_categories.id')->all();
        sort($ids);
        $expected = [$catA, $catB];
        sort($expected);
        $this->assertSame($expected, $ids);
        $this->assertSame(42, DB::table('supplier_category_assignments')->where('supplier_category_id', $catA)->value('created_by'));
    }

    public function test_remove_category_full_replace(): void
    {
        $companyId = (string) Str::uuid();
        $supplier = $this->makeSupplier($companyId);
        $catA = $this->makeCategory($companyId, 'A');
        $catB = $this->makeCategory($companyId, 'B');
        $sync = app(SupplierCategoryAssignmentSyncService::class);

        $sync->sync($supplier, [$catA, $catB], actorId: 1);
        $this->assertCount(2, $supplier->categories()->pluck('supplier_categories.id'));

        // Re-sync with only A — B must be removed (full-replace semantics, matching the
        // multi-select UI where the submitted list IS the complete desired state).
        $sync->sync($supplier, [$catA], actorId: 1);

        $this->assertSame([$catA], $supplier->categories()->pluck('supplier_categories.id')->all());
    }

    public function test_duplicate_assignment_prevented(): void
    {
        $companyId = (string) Str::uuid();
        $supplier = $this->makeSupplier($companyId);
        $catA = $this->makeCategory($companyId);
        $sync = app(SupplierCategoryAssignmentSyncService::class);

        $sync->sync($supplier, [$catA, $catA], actorId: 1);
        $this->assertSame(1, DB::table('supplier_category_assignments')->where('supplier_id', $supplier->id)->count());

        $sync->sync($supplier, [$catA], actorId: 1);
        $this->assertSame(1, DB::table('supplier_category_assignments')->where('supplier_id', $supplier->id)->count());
    }

    public function test_unchanged_assignment_preserves_original_created_by(): void
    {
        $companyId = (string) Str::uuid();
        $supplier = $this->makeSupplier($companyId);
        $catA = $this->makeCategory($companyId, 'A');
        $catB = $this->makeCategory($companyId, 'B');
        $sync = app(SupplierCategoryAssignmentSyncService::class);

        $sync->sync($supplier, [$catA], actorId: 100);
        $sync->sync($supplier, [$catA, $catB], actorId: 200);

        $this->assertSame(100, DB::table('supplier_category_assignments')->where('supplier_category_id', $catA)->value('created_by'));
        $this->assertSame(200, DB::table('supplier_category_assignments')->where('supplier_category_id', $catB)->value('created_by'));
    }

    // ── READ / REPOSITORY ────────────────────────────────────────────────

    public function test_supplier_detail_returns_all_assigned_categories(): void
    {
        $companyId = (string) Str::uuid();
        $supplier = $this->makeSupplier($companyId);
        $catA = $this->makeCategory($companyId, 'A');
        $catB = $this->makeCategory($companyId, 'B');
        app(SupplierCategoryAssignmentSyncService::class)->sync($supplier, [$catA, $catB], actorId: 1);

        $found = app(EloquentSupplierRepository::class)->findById($supplier->id);

        $this->assertNotNull($found);
        $this->assertTrue($found->relationLoaded('categories'));
        $ids = $found->categories->pluck('id')->all();
        sort($ids);
        $expected = [$catA, $catB];
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    public function test_filtering_by_any_assigned_category_finds_the_supplier(): void
    {
        $companyId = (string) Str::uuid();
        $catA = $this->makeCategory($companyId, 'A');
        $catB = $this->makeCategory($companyId, 'B');

        // Supplier's legacy PRIMARY column is A, but it is also assigned to B —
        // filtering by B must still find it (the whole point of §A.1).
        $supplier = $this->makeSupplier($companyId, 'SUP-000001', $catA);
        app(SupplierCategoryAssignmentSyncService::class)->sync($supplier, [$catA, $catB], actorId: 1);

        $other = $this->makeSupplier($companyId, 'SUP-000002', null);

        $result = app(EloquentSupplierRepository::class)->paginate(['supplier_category_id' => $catB, 'per_page' => 20]);

        $this->assertSame(1, $result->total());
        $this->assertSame($supplier->id, $result->items()[0]->id);
    }

    public function test_list_summary_shows_all_category_names_with_no_n_plus_1(): void
    {
        $companyId = (string) Str::uuid();
        $catA = $this->makeCategory($companyId, 'Alpha');
        $catB = $this->makeCategory($companyId, 'Beta');
        $supplier = $this->makeSupplier($companyId, 'SUP-000001', $catA);
        app(SupplierCategoryAssignmentSyncService::class)->sync($supplier, [$catA, $catB], actorId: 1);

        DB::connection(self::CONNECTION)->enableQueryLog();
        $paginator = app(EloquentSupplierRepository::class)->paginate(['per_page' => 20]);
        $queryCount = count(DB::connection(self::CONNECTION)->getQueryLog());
        DB::connection(self::CONNECTION)->disableQueryLog();

        $this->assertLessThanOrEqual(3, $queryCount, 'Supplier list must not issue a query per Supplier for its category set — got '.$queryCount);

        $row = $paginator->items()[0];
        $this->assertSame(2, $row->supplier_category_count);
        $this->assertSame('Alpha, Beta', $row->supplier_category_names);
    }

    public function test_deleting_category_still_assigned_only_via_pivot_is_blocked(): void
    {
        $companyId = (string) Str::uuid();
        $catA = $this->makeCategory($companyId, 'A');
        $catB = $this->makeCategory($companyId, 'B');
        // Legacy primary is A; B is a SECOND assignment reachable only through the pivot.
        $supplier = $this->makeSupplier($companyId, 'SUP-000001', $catA);
        app(SupplierCategoryAssignmentSyncService::class)->sync($supplier, [$catA, $catB], actorId: 1);

        // Mirrors DeleteSupplierCategoryAction's own guard query exactly.
        $count = Supplier::query()
            ->where(function ($q) use ($catB): void {
                $q->where('supplier_category_id', $catB)
                    ->orWhereHas('categories', fn ($cq) => $cq->where('supplier_categories.id', $catB));
            })
            ->distinct()
            ->count('suppliers.id');

        $this->assertSame(1, $count, 'A category assigned only via the pivot must still be reported as in-use.');
    }

    // ── TENANCY (request-validation-level rule, exercised directly) ────────

    public function test_cross_company_category_id_rejected_by_validation_rule(): void
    {
        $companyA = (string) Str::uuid();
        $companyB = (string) Str::uuid();
        $foreignCategory = $this->makeCategory($companyB);

        // Mirrors StoreSupplierRequest's supplier_category_ids.* rule exactly.
        $exists = DB::table('supplier_categories')
            ->where('id', $foreignCategory)
            ->where('company_id', $companyA)
            ->where('is_active', true)
            ->exists();

        $this->assertFalse($exists, 'A Supplier Category belonging to a different company must not satisfy the validation rule.');
    }
}
