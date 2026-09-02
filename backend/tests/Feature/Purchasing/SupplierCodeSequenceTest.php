<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Purchasing\Suppliers\Domain\Models\Supplier;
use Modules\Purchasing\Suppliers\Domain\Services\SupplierCodeSequenceService;
use PDO;
use PDOException;
use Tests\TestCase;

/**
 * TASK-ECOS-PROCUREMENT-SUPPLIERS-BATCH-01-MASTER-DATA-002-R1.
 *
 * Proves the replacement Supplier Code sequence (supplier_code_sequences,
 * atomic upsert) is correct for gapped/legacy/soft-deleted codes and, via two
 * genuinely separate MySQL connections with a tuned lock-wait-timeout, that
 * concurrent creates are truly serialized — the exact property the prior
 * count()+lockForUpdate() generator lacked on a company's first Supplier.
 *
 * Runs against a fully isolated, disposable MySQL 8.4 container (see the
 * Engineering Report §15), NOT canonical DEV. Deliberately does NOT use
 * RefreshDatabase / the project's full ~730-migration set — confirmed
 * infeasible in this environment (a sibling remediation task measured
 * 41/731 migrations in 10 minutes) — and instead hand-creates only the two
 * tables this service touches, matching their real migrated schema exactly.
 */
final class SupplierCodeSequenceTest extends TestCase
{
    private const CONNECTION = 'supplier_code_seq_test';

    protected bool $grantsBaselineAuthorization = false;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.'.self::CONNECTION => [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '33062',
            'database' => 'supplier_code_test',
            'username' => 'root',
            'password' => 'testonly',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]]);
        DB::purge(self::CONNECTION);
        DB::setDefaultConnection(self::CONNECTION);

        Schema::connection(self::CONNECTION)->dropIfExists('suppliers');
        Schema::connection(self::CONNECTION)->dropIfExists('supplier_code_sequences');

        // Matches create_suppliers_table + the location/opening-balance +
        // Task-2 category-column migrations exactly — no FK constraints (no
        // companies table here; this test only needs company_id to compare
        // equal/unequal across rows, not to reference a real company).
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

        // Matches the new create_supplier_code_sequences_table migration exactly.
        Schema::connection(self::CONNECTION)->create('supplier_code_sequences', function ($table): void {
            $table->uuid('company_id')->primary();
            $table->unsignedInteger('current_number');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::connection(self::CONNECTION)->dropIfExists('suppliers');
        Schema::connection(self::CONNECTION)->dropIfExists('supplier_code_sequences');

        parent::tearDown();
    }

    private function makeSupplier(string $companyId, string $code, bool $trashed = false): Supplier
    {
        $supplier = Supplier::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'code' => $code,
            'name' => 'Test Supplier '.$code,
            'is_active' => true,
        ]);

        if ($trashed) {
            $supplier->delete();
        }

        return $supplier;
    }

    // ── 1. Contiguous codes ──────────────────────────────────────────────
    public function test_next_code_after_contiguous_codes(): void
    {
        $companyId = (string) Str::uuid();
        $this->makeSupplier($companyId, 'SUP-000001');
        $this->makeSupplier($companyId, 'SUP-000002');

        $next = app(SupplierCodeSequenceService::class)->next($companyId);

        $this->assertSame('SUP-000003', $next);
    }

    // ── 2. Gapped codes ──────────────────────────────────────────────────
    public function test_next_code_after_gapped_codes(): void
    {
        $companyId = (string) Str::uuid();
        $this->makeSupplier($companyId, 'SUP-000001');
        $this->makeSupplier($companyId, 'SUP-000003');
        $this->makeSupplier($companyId, 'SUP-000010');

        $next = app(SupplierCodeSequenceService::class)->next($companyId);

        $this->assertSame('SUP-000011', $next, 'A gapped set (…01, …03, …10) must bootstrap from the highest suffix (10), never collide with an existing code.');
    }

    // ── 3. Legacy/manual codes must not distort the canonical sequence ──
    public function test_legacy_codes_are_ignored_for_bootstrap(): void
    {
        $companyId = (string) Str::uuid();
        $this->makeSupplier($companyId, 'OLD-SUPPLIER-X');
        $this->makeSupplier($companyId, 'SUPPLIER-A');
        $this->makeSupplier($companyId, 'SUP-000005');

        $next = app(SupplierCodeSequenceService::class)->next($companyId);

        $this->assertSame('SUP-000006', $next, 'Non-canonical legacy codes must be ignored entirely, not counted or parsed.');
    }

    // ── 4. Soft-deleted Supplier codes must never be reissued ───────────
    public function test_soft_deleted_supplier_code_is_not_reissued(): void
    {
        $companyId = (string) Str::uuid();
        $this->makeSupplier($companyId, 'SUP-000001');
        $this->makeSupplier($companyId, 'SUP-000007', trashed: true);

        $next = app(SupplierCodeSequenceService::class)->next($companyId);

        $this->assertSame('SUP-000008', $next, 'A soft-deleted Supplier code (…07) must still count toward the bootstrap max.');
    }

    // ── 5. First Supplier for a company ──────────────────────────────────
    public function test_first_supplier_for_a_company(): void
    {
        $companyId = (string) Str::uuid();

        $next = app(SupplierCodeSequenceService::class)->next($companyId);

        $this->assertSame('SUP-000001', $next);
    }

    // ── 9. Bootstrap uses the highest canonical suffix, never row count ─
    public function test_bootstrap_uses_highest_suffix_not_row_count(): void
    {
        $companyId = (string) Str::uuid();
        // 1 canonical row at a HIGH suffix + 1 legacy row: a COUNT-based
        // generator would wrongly derive "2" (or "3"); the correct answer
        // depends only on the highest canonical suffix (10), not row count.
        $this->makeSupplier($companyId, 'SUP-000010');
        $this->makeSupplier($companyId, 'LEGACY-001');

        $next = app(SupplierCodeSequenceService::class)->next($companyId);

        $this->assertSame('SUP-000011', $next);
    }

    /**
     * A genuinely separate MySQL session (not the same connection Laravel's
     * query builder is using), with a short lock-wait-timeout so a real
     * block is observed quickly and deterministically rather than hanging.
     */
    private function rawConnection(): PDO
    {
        $pdo = new PDO(
            'mysql:host=127.0.0.1;port=33062;dbname=supplier_code_test;charset=utf8mb4',
            'root',
            'testonly',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 2');

        return $pdo;
    }

    /** Verbatim-identical to SupplierCodeSequenceService::next()'s upsert. */
    private function upsertSql(): string
    {
        return 'INSERT INTO supplier_code_sequences (company_id, current_number, created_at, updated_at) '
            .'VALUES (:company_id, :bootstrap, NOW(), NOW()) '
            .'ON DUPLICATE KEY UPDATE current_number = current_number + 1, updated_at = NOW()';
    }

    // ── 6. Two concurrent FIRST creates, SAME company — the exact failure
    // class the prior generator had (a zero-row SELECT ... FOR UPDATE locks
    // nothing). Proven with two real, separate connections: A's uncommitted
    // upsert must genuinely block B's upsert for the SAME company.
    public function test_concurrent_first_creates_same_company_are_serialized(): void
    {
        $companyId = (string) Str::uuid();

        $connA = $this->rawConnection();
        $connB = $this->rawConnection();

        $connA->beginTransaction();
        $connA->prepare($this->upsertSql())->execute(['company_id' => $companyId, 'bootstrap' => 1]);
        // Deliberately NOT committed yet — A holds the lock on this row.

        $blocked = false;
        try {
            $connB->beginTransaction();
            $connB->prepare($this->upsertSql())->execute(['company_id' => $companyId, 'bootstrap' => 1]);
        } catch (PDOException $e) {
            $blocked = str_contains($e->getMessage(), 'Lock wait timeout exceeded');
            $connB->rollBack();
        }

        $this->assertTrue(
            $blocked,
            'Expected B to block on A\'s uncommitted first-row upsert (lock wait timeout). '
            .'If this fails, two concurrent first-creates are NOT serialized — the original bug.',
        );

        $connA->commit();

        // Retry B now that A has committed — must succeed and correctly
        // increment off A's committed value (1 -> 2), never collide.
        $connB->beginTransaction();
        $connB->prepare($this->upsertSql())->execute(['company_id' => $companyId, 'bootstrap' => 1]);
        $connB->commit();

        $final = (int) DB::table('supplier_code_sequences')->where('company_id', $companyId)->value('current_number');
        $this->assertSame(2, $final, 'A and B must have produced distinct sequence values (1 then 2), not a duplicate.');
    }

    // ── 7. Concurrent creates for an EXISTING company row must also serialize.
    public function test_concurrent_creates_existing_company_are_serialized(): void
    {
        $companyId = (string) Str::uuid();
        DB::table('supplier_code_sequences')->insert([
            'company_id' => $companyId,
            'current_number' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $connA = $this->rawConnection();
        $connB = $this->rawConnection();

        $connA->beginTransaction();
        $connA->prepare($this->upsertSql())->execute(['company_id' => $companyId, 'bootstrap' => 1]);

        $blocked = false;
        try {
            $connB->beginTransaction();
            $connB->prepare($this->upsertSql())->execute(['company_id' => $companyId, 'bootstrap' => 1]);
        } catch (PDOException $e) {
            $blocked = str_contains($e->getMessage(), 'Lock wait timeout exceeded');
            $connB->rollBack();
        }
        $this->assertTrue($blocked);

        $connA->commit();

        $connB->beginTransaction();
        $connB->prepare($this->upsertSql())->execute(['company_id' => $companyId, 'bootstrap' => 1]);
        $connB->commit();

        $final = (int) DB::table('supplier_code_sequences')->where('company_id', $companyId)->value('current_number');
        $this->assertSame(12, $final, '10 -> 11 (A) -> 12 (B), distinct and correctly incremented.');
    }

    // ── 8. Different companies must NOT block each other. ────────────────
    public function test_concurrent_creates_different_companies_do_not_block(): void
    {
        $companyX = (string) Str::uuid();
        $companyY = (string) Str::uuid();

        $connA = $this->rawConnection();
        $connB = $this->rawConnection();

        $connA->beginTransaction();
        $connA->prepare($this->upsertSql())->execute(['company_id' => $companyX, 'bootstrap' => 1]);
        // Deliberately uncommitted — if B blocked here, that would be a
        // (wrong) cross-company lock rather than genuine isolation.

        $connB->beginTransaction();
        // Must succeed immediately — different company, different key, no timeout expected.
        $connB->prepare($this->upsertSql())->execute(['company_id' => $companyY, 'bootstrap' => 1]);
        $connB->commit();

        $connA->commit();

        $this->assertSame(1, (int) DB::table('supplier_code_sequences')->where('company_id', $companyX)->value('current_number'));
        $this->assertSame(1, (int) DB::table('supplier_code_sequences')->where('company_id', $companyY)->value('current_number'));
    }
}
