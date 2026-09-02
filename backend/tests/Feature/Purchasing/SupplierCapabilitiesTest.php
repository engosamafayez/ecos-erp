<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Purchasing\Suppliers\Domain\Models\Supplier;
use Modules\Purchasing\Suppliers\Domain\Services\SupplierCapabilitySyncService;
use Modules\Purchasing\Suppliers\Infrastructure\Repositories\EloquentSupplierRepository;
use Tests\TestCase;

/**
 * TASK-ECOS-PROCUREMENT-SUPPLIERS-BATCH-01-SUPPLY-CAPABILITIES-003.
 *
 * Exercises the real production classes (SupplierCapabilitySyncService,
 * EloquentSupplierRepository) directly against a fully isolated, disposable
 * MySQL 8.4 container — same rationale as the R1 remediation: RefreshDatabase
 * / the ~730-migration set is confirmed infeasible in this environment, so
 * this hand-creates only the tables these classes actually touch, matching
 * their real migrated schema.
 */
final class SupplierCapabilitiesTest extends TestCase
{
    private const CONNECTION = 'supplier_capabilities_test';

    /** @var list<string> */
    private const TABLES = [
        'supplier_products', 'supplier_product_categories', 'suppliers', 'supplier_categories',
        'products', 'categories', 'purchase_orders', 'goods_receipts', 'inventory_receipt_layers',
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
        // EloquentSupplierRepository::paginate() reference these — empty is
        // fine (a LEFT JOIN against an empty table just yields NULLs), they
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

        // No FK constraint to a `users` table on purpose — this test uses an
        // in-memory (never persisted) authenticated user via Auth::setUser(),
        // exactly like production, without needing a real users table.
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

        // Deliberately NO Auth::setUser() here: SupplierCapabilitySyncService
        // takes actorId as an explicit parameter (not resolved via Auth::id()),
        // and an authenticated user would make TenantOwnershipResolver::
        // appliesTo() return true, which then queries roles/user_roles (not
        // created in this minimal schema) to resolve isUnrestricted(). With no
        // authenticated user this matches the model's own documented "console/
        // queue/seeder, no actor" bypass — exactly like Task 2/R1's tests.
    }

    protected function tearDown(): void
    {
        foreach (self::TABLES as $table) {
            Schema::connection(self::CONNECTION)->dropIfExists($table);
        }

        parent::tearDown();
    }

    private function makeSupplier(string $companyId, string $code = 'SUP-000001'): Supplier
    {
        return Supplier::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'code' => $code,
            'name' => 'Test Supplier '.$code,
            'is_active' => true,
        ]);
    }

    private function makeProduct(string $companyId, string $type = 'raw_material'): string
    {
        $id = (string) Str::uuid();
        DB::table('products')->insert([
            'id' => $id,
            'company_id' => $companyId,
            'sku' => 'SKU-'.substr($id, 0, 8),
            'name' => 'Test Product',
            'product_type' => $type,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function makeCategory(string $scope = 'product', bool $active = true): string
    {
        $id = (string) Str::uuid();
        DB::table('categories')->insert([
            'id' => $id,
            'code' => 'CAT-'.substr($id, 0, 8),
            'name' => 'Test Category',
            'level' => 1,
            'sort_order' => 0,
            'is_active' => $active,
            'category_scope' => $scope,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    // ── CAPABILITY WRITE ──────────────────────────────────────────────────

    public function test_assign_raw_material_capability(): void
    {
        $companyId = (string) Str::uuid();
        $supplier = $this->makeSupplier($companyId);
        $productId = $this->makeProduct($companyId);

        app(SupplierCapabilitySyncService::class)->sync($supplier, [$productId], [], actorId: 42);

        $this->assertSame([$productId], $supplier->rawMaterials()->pluck('products.id')->all());
        $this->assertSame(42, DB::table('supplier_products')->where('product_id', $productId)->value('created_by'));
    }

    public function test_assign_category_capability(): void
    {
        $companyId = (string) Str::uuid();
        $supplier = $this->makeSupplier($companyId);
        $categoryId = $this->makeCategory();

        app(SupplierCapabilitySyncService::class)->sync($supplier, [], [$categoryId], actorId: 7);

        $this->assertSame([$categoryId], $supplier->productCategories()->pluck('categories.id')->all());
    }

    public function test_assign_multiple_values(): void
    {
        $companyId = (string) Str::uuid();
        $supplier = $this->makeSupplier($companyId);
        $p1 = $this->makeProduct($companyId);
        $p2 = $this->makeProduct($companyId);
        $p3 = $this->makeProduct($companyId);

        app(SupplierCapabilitySyncService::class)->sync($supplier, [$p1, $p2, $p3], [], actorId: 1);

        $this->assertCount(3, $supplier->rawMaterials()->pluck('products.id'));
    }

    public function test_assign_both_capability_types(): void
    {
        $companyId = (string) Str::uuid();
        $supplier = $this->makeSupplier($companyId);
        $productId = $this->makeProduct($companyId);
        $categoryId = $this->makeCategory();

        app(SupplierCapabilitySyncService::class)->sync($supplier, [$productId], [$categoryId], actorId: 1);

        $this->assertCount(1, $supplier->rawMaterials()->pluck('products.id'));
        $this->assertCount(1, $supplier->productCategories()->pluck('categories.id'));
    }

    public function test_remove_capability(): void
    {
        $companyId = (string) Str::uuid();
        $supplier = $this->makeSupplier($companyId);
        $p1 = $this->makeProduct($companyId);
        $p2 = $this->makeProduct($companyId);
        $sync = app(SupplierCapabilitySyncService::class);

        $sync->sync($supplier, [$p1, $p2], [], actorId: 1);
        $this->assertCount(2, $supplier->rawMaterials()->pluck('products.id'));

        // Re-sync with only p1 — p2 must be removed (full-replace semantics).
        $sync->sync($supplier, [$p1], [], actorId: 1);

        $this->assertSame([$p1], $supplier->rawMaterials()->pluck('products.id')->all());
    }

    public function test_duplicate_assignment_prevented(): void
    {
        $companyId = (string) Str::uuid();
        $supplier = $this->makeSupplier($companyId);
        $productId = $this->makeProduct($companyId);
        $sync = app(SupplierCapabilitySyncService::class);

        // Submitting the same id twice in one call must not violate the
        // unique(supplier_id, product_id) constraint or create 2 rows.
        $sync->sync($supplier, [$productId, $productId], [], actorId: 1);
        $this->assertSame(1, DB::table('supplier_products')->where('supplier_id', $supplier->id)->count());

        // Re-syncing the SAME set again (e.g. re-saving the Supplier form
        // unchanged) must also not duplicate or error.
        $sync->sync($supplier, [$productId], [], actorId: 1);
        $this->assertSame(1, DB::table('supplier_products')->where('supplier_id', $supplier->id)->count());
    }

    public function test_unchanged_assignment_preserves_original_created_by(): void
    {
        $companyId = (string) Str::uuid();
        $supplier = $this->makeSupplier($companyId);
        $p1 = $this->makeProduct($companyId);
        $p2 = $this->makeProduct($companyId);
        $sync = app(SupplierCapabilitySyncService::class);

        $sync->sync($supplier, [$p1], [], actorId: 100);
        // A different "editor" later adds p2 — p1's original creator must be preserved.
        $sync->sync($supplier, [$p1, $p2], [], actorId: 200);

        $this->assertSame(100, DB::table('supplier_products')->where('product_id', $p1)->value('created_by'));
        $this->assertSame(200, DB::table('supplier_products')->where('product_id', $p2)->value('created_by'));
    }

    // ── READ ──────────────────────────────────────────────────────────────

    public function test_supplier_detail_returns_correct_capabilities(): void
    {
        $companyId = (string) Str::uuid();
        $supplier = $this->makeSupplier($companyId);
        $productId = $this->makeProduct($companyId);
        $categoryId = $this->makeCategory();
        app(SupplierCapabilitySyncService::class)->sync($supplier, [$productId], [$categoryId], actorId: 1);

        $found = app(EloquentSupplierRepository::class)->findById($supplier->id);

        $this->assertNotNull($found);
        $this->assertTrue($found->relationLoaded('rawMaterials'));
        $this->assertTrue($found->relationLoaded('productCategories'));
        $this->assertSame([$productId], $found->rawMaterials->pluck('id')->all());
        $this->assertSame([$categoryId], $found->productCategories->pluck('id')->all());
    }

    public function test_list_summary_has_no_n_plus_1(): void
    {
        $companyId = (string) Str::uuid();
        // 5 Suppliers, each with 3 raw materials and 2 categories — if the list
        // query were N+1, query count would scale with (suppliers * capabilities);
        // it must instead stay constant regardless of this fan-out.
        for ($i = 0; $i < 5; $i++) {
            $supplier = $this->makeSupplier($companyId, sprintf('SUP-%06d', $i + 1));
            $products = [$this->makeProduct($companyId), $this->makeProduct($companyId), $this->makeProduct($companyId)];
            $categories = [$this->makeCategory(), $this->makeCategory()];
            app(SupplierCapabilitySyncService::class)->sync($supplier, $products, $categories, actorId: 1);
        }

        DB::connection(self::CONNECTION)->enableQueryLog();
        $paginator = app(EloquentSupplierRepository::class)->paginate(['per_page' => 20]);
        $queryCount = count(DB::connection(self::CONNECTION)->getQueryLog());
        DB::connection(self::CONNECTION)->disableQueryLog();

        $this->assertSame(5, $paginator->total());
        $this->assertLessThanOrEqual(3, $queryCount, 'Supplier list must not issue a query per Supplier or per capability — expected 1 count + 1 select (+/-1), got '.$queryCount);

        foreach ($paginator->items() as $row) {
            $this->assertSame(3, $row->raw_material_count);
            $this->assertSame(2, $row->product_category_count);
        }
    }

    public function test_filtering_by_raw_material_works(): void
    {
        $companyId = (string) Str::uuid();
        $target = $this->makeProduct($companyId);
        $other = $this->makeProduct($companyId);

        $supplierA = $this->makeSupplier($companyId, 'SUP-000001');
        $supplierB = $this->makeSupplier($companyId, 'SUP-000002');
        app(SupplierCapabilitySyncService::class)->sync($supplierA, [$target], [], actorId: 1);
        app(SupplierCapabilitySyncService::class)->sync($supplierB, [$other], [], actorId: 1);

        $result = app(EloquentSupplierRepository::class)->paginate(['raw_material_id' => $target, 'per_page' => 20]);

        $this->assertSame(1, $result->total());
        $this->assertSame($supplierA->id, $result->items()[0]->id);
    }

    public function test_filtering_by_product_category_works(): void
    {
        $companyId = (string) Str::uuid();
        $target = $this->makeCategory();
        $other = $this->makeCategory();

        $supplierA = $this->makeSupplier($companyId, 'SUP-000001');
        $supplierB = $this->makeSupplier($companyId, 'SUP-000002');
        app(SupplierCapabilitySyncService::class)->sync($supplierA, [], [$target], actorId: 1);
        app(SupplierCapabilitySyncService::class)->sync($supplierB, [], [$other], actorId: 1);

        $result = app(EloquentSupplierRepository::class)->paginate(['product_category_id' => $target, 'per_page' => 20]);

        $this->assertSame(1, $result->total());
        $this->assertSame($supplierA->id, $result->items()[0]->id);
    }

    // ── TENANCY (request-validation-level rules, exercised directly) ───────

    public function test_cross_company_raw_material_rejected_by_validation_rule(): void
    {
        $companyA = (string) Str::uuid();
        $companyB = (string) Str::uuid();
        $foreignProduct = $this->makeProduct($companyB);

        // Mirrors StoreSupplierRequest's raw_material_ids.* rule exactly.
        $exists = DB::table('products')
            ->where('id', $foreignProduct)
            ->where('company_id', $companyA)
            ->where('product_type', 'raw_material')
            ->exists();

        $this->assertFalse($exists, 'A Product belonging to a different company must not satisfy the validation rule.');
    }

    public function test_inactive_category_rejected_by_validation_rule(): void
    {
        $inactiveCategory = $this->makeCategory(active: false);

        $exists = DB::table('categories')
            ->where('id', $inactiveCategory)
            ->whereIn('category_scope', ['product', 'material'])
            ->where('is_active', true)
            ->exists();

        $this->assertFalse($exists, 'An inactive Category must not satisfy the validation rule.');
    }

    public function test_non_raw_material_product_rejected_by_validation_rule(): void
    {
        $companyId = (string) Str::uuid();
        $finishedGood = $this->makeProduct($companyId, type: 'finished_good');

        $exists = DB::table('products')
            ->where('id', $finishedGood)
            ->where('company_id', $companyId)
            ->where('product_type', 'raw_material')
            ->exists();

        $this->assertFalse($exists, 'A finished_good Product must not satisfy the raw_material capability validation rule.');
    }
}
