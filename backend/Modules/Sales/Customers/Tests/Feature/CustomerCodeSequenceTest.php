<?php

declare(strict_types=1);

namespace Modules\Sales\Customers\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Modules\Sales\Customers\Infrastructure\Repositories\EloquentCustomerRepository;
use Tests\TestCase;

/**
 * TASK-ECOS-COMMERCE-CUSTOMERS-BATCH-02-TASK-2-REMEDIATION-007-R1.
 *
 * Proves EloquentCustomerRepository::nextCodeNumber() against every case the CTO
 * required (§4 of the remediation task spec) for the customer_code_sequences-backed
 * design that replaced the original count()+1+lockForUpdate() implementation.
 *
 * NOT EXECUTED in this environment — no MySQL server is reachable on the test
 * connection here (see the remediation report §12 "Test Database Safety"). Written
 * to run, unmodified, the moment a reachable ecos_erp_test database is available; do
 * not treat this file's presence as proof the assertions have passed.
 */
final class CustomerCodeSequenceTest extends TestCase
{
    use RefreshDatabase;

    private EloquentCustomerRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new EloquentCustomerRepository;
    }

    // ── CASE E — first customer in a company ───────────────────────────────────

    public function test_first_customer_in_a_company_gets_number_one(): void
    {
        $companyId = $this->makeCompany();

        $number = $this->repository->nextCodeNumber($companyId);

        $this->assertSame(1, $number);
    }

    // ── CASE A — contiguous existing codes ──────────────────────────────────────

    public function test_contiguous_codes_produce_the_next_sequential_number(): void
    {
        $companyId = $this->makeCompany();
        $this->seedRawCode($companyId, 'CUST-000001');
        $this->seedRawCode($companyId, 'CUST-000002');

        $number = $this->repository->nextCodeNumber($companyId);

        $this->assertSame(3, $number);
    }

    // ── CASE B — non-contiguous historical codes ────────────────────────────────

    public function test_gapped_codes_do_not_regenerate_an_existing_code(): void
    {
        $companyId = $this->makeCompany();
        $this->seedRawCode($companyId, 'CUST-000001');
        $this->seedRawCode($companyId, 'CUST-000003'); // CUST-000002 deliberately missing

        $number = $this->repository->nextCodeNumber($companyId);

        // The old count()+1 design would return 3 here (2 rows -> collides with the
        // existing CUST-000003). The fix bootstraps from the highest EXISTING suffix
        // (3), so the next number must continue past it, never land on it.
        $this->assertSame(4, $number);
        $this->assertNotSame(3, $number, 'must not regenerate the already-taken CUST-000003');
    }

    // ── CASE C — manually-entered legacy codes ──────────────────────────────────

    public function test_legacy_non_canonical_codes_do_not_corrupt_the_sequence(): void
    {
        $companyId = $this->makeCompany();
        $this->seedRawCode($companyId, 'SUPPLIER-A');
        $this->seedRawCode($companyId, 'OLD-CUSTOMER');
        $this->seedRawCode($companyId, 'CUST-000010');

        $number = $this->repository->nextCodeNumber($companyId);

        // Row count here is 3 (the old design would return 4 -> CUST-000004, which
        // silently drifts below the real canonical maximum and eventually collides with
        // CUST-000010 a few creates later). The fix ignores non-canonical codes entirely
        // and bootstraps strictly from the highest CUST-NNNNNN suffix in use (10).
        $this->assertSame(11, $number);
    }

    // ── CASE D — soft-deleted customers ─────────────────────────────────────────

    public function test_soft_deleted_customer_code_is_never_reissued(): void
    {
        $companyId = $this->makeCompany();
        $trashedId = $this->seedRawCode($companyId, 'CUST-000005');
        Customer::query()->whereKey($trashedId)->delete(); // soft delete

        $number = $this->repository->nextCodeNumber($companyId);

        $this->assertSame(6, $number, 'a trashed row\'s code must still count toward the bootstrap maximum');

        // The unique index itself is the final guarantee: even a direct attempt to
        // reuse the trashed row's code must still be rejected at the DB level.
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('customers')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'company_id' => $companyId,
            'code' => 'CUST-000005',
            'name' => 'Duplicate Attempt',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ── CASE E/F — two sequential creates for the same company ─────────────────

    public function test_two_sequential_creates_for_the_same_company_get_distinct_numbers(): void
    {
        // Proves the sequence step itself is correct across repeated calls. This is
        // NOT a substitute for the true-concurrency proof below (two calls in one
        // PHP process/connection are never actually racing each other) — see
        // test_concurrent_first_creates_for_the_same_company_do_not_collide().
        $companyId = $this->makeCompany();

        $first = $this->repository->nextCodeNumber($companyId);
        $second = $this->repository->nextCodeNumber($companyId);

        $this->assertSame(1, $first);
        $this->assertSame(2, $second);
    }

    // ── CASE G — concurrent creates for different companies ────────────────────

    public function test_different_companies_get_independent_sequences(): void
    {
        $companyA = $this->makeCompany();
        $companyB = $this->makeCompany();
        $this->seedRawCode($companyA, 'CUST-000001');
        $this->seedRawCode($companyA, 'CUST-000002');

        // Company A is already at 2 existing codes; Company B has none. Company B's
        // sequence must be entirely independent — its own row, keyed on its own
        // company_id (the sequence table's primary key) — not shared or offset by A's.
        $numberB = $this->repository->nextCodeNumber($companyB);

        $this->assertSame(1, $numberB);
    }

    // ── CASE F — the critical concurrency case ──────────────────────────────────

    /**
     * Directly proves the InnoDB locking claim behind ensureCodeSequenceRow(): a second
     * transaction's INSERT IGNORE of the SAME (already primary-keyed) company_id must
     * BLOCK behind a first transaction's still-uncommitted insert of that same row,
     * rather than racing past it the way the old lockForUpdate()-over-zero-rows design
     * did (see the remediation report's Case F analysis).
     *
     * Uses two independent DB connections against the same test database (two real
     * MySQL sessions, not two PHP threads/processes — sufficient to exercise genuine
     * InnoDB cross-transaction lock contention). Connection B sets a 1-second
     * innodb_lock_wait_timeout so the test fails fast and deterministically instead of
     * hanging if the blocking behavior is ever absent (e.g. a future regression back to
     * a non-blocking approach) — this is the test's actual pass condition: it MUST
     * observe a lock-wait timeout, not a silent duplicate/race.
     */
    public function test_concurrent_first_creates_for_the_same_company_do_not_collide(): void
    {
        $companyId = $this->makeCompany();

        config(['database.connections.mysql_secondary' => config('database.connections.mysql')]);
        $connB = DB::connection('mysql_secondary');
        $connB->statement('SET SESSION innodb_lock_wait_timeout = 1');

        DB::beginTransaction();
        DB::table('customer_code_sequences')->insertOrIgnore([
            'company_id' => $companyId,
            'next_number' => 0,
        ]);

        // Connection A holds an uncommitted insert of this company's sequence row.
        // Connection B racing the identical insert for the SAME company must block and
        // time out — proving the row is genuinely contended, not silently duplicated.
        $blocked = false;

        try {
            $connB->beginTransaction();
            $connB->table('customer_code_sequences')->insertOrIgnore([
                'company_id' => $companyId,
                'next_number' => 0,
            ]);
            $connB->commit();
        } catch (\Illuminate\Database\QueryException $e) {
            $blocked = str_contains($e->getMessage(), 'Lock wait timeout exceeded');
            $connB->rollBack();
        }

        DB::commit();

        $this->assertTrue(
            $blocked,
            'connection B must block on connection A\'s uncommitted insert of the same '.
            'company_id, not race past it — a lock-wait timeout is the expected, correct '.
            'outcome here, proving no two concurrent "first create" requests can silently '.
            'produce the same bootstrap value.',
        );

        // Once A commits, a normal (non-racing) caller for the same company must see the
        // row already exists and proceed straight to a correct, non-colliding increment.
        $this->assertSame(1, $this->repository->nextCodeNumber($companyId));
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function makeCompany(): string
    {
        $company = Company::factory()->create();
        User::factory()->create(['company_id' => (string) $company->id]);

        return (string) $company->id;
    }

    /**
     * Inserts a Customer row with an explicit code, bypassing the generator entirely —
     * simulating historical data (manual entry, a prior algorithm, or a migrated legacy
     * record) that nextCodeNumber() must be safe against.
     */
    private function seedRawCode(string $companyId, string $code): string
    {
        $customer = Customer::factory()->withCompany($companyId)->create(['code' => $code]);

        return (string) $customer->id;
    }
}
