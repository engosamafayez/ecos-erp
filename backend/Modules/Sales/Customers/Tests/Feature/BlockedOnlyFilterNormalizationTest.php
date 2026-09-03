<?php

declare(strict_types=1);

namespace Modules\Sales\Customers\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Modules\Sales\Customers\Domain\Models\CustomerBlock;
use Modules\Sales\Customers\Domain\Services\PhoneNormalizer;
use Modules\Sales\Customers\Infrastructure\Repositories\EloquentCustomerRepository;
use Tests\TestCase;

/**
 * TASK-ECOS-COMMERCE-CUSTOMERS-BATCH-02-BLOCKED-CUSTOMERS-009-R1 (§4).
 *
 * Real-MySQL proof for the `blocked_only` filter's phone-normalization fix —
 * see EloquentCustomerRepository::applyBlockedOnlyFilter(). The original
 * `whereColumn('customer_blocks.normalized_phone', 'customers.phone')` compared
 * an already-normalized digit string against the Customer's raw, unnormalized
 * saved phone, so a Customer whose saved number was a differently formatted
 * equivalent of a blocked number never matched.
 *
 * Own minimal schema (companies/brands/customers/customer_addresses/
 * customer_brands/customer_blocks only — the repository never touches `orders`
 * unless an aggregate-sort or repeat/product filter is used, none of which these
 * tests exercise), duplicated rather than shared with CustomerBlockingTest /
 * BlockedOrderFulfillmentTest for the same reason those two document: either
 * suite stays readable and runnable standalone.
 */
final class BlockedOnlyFilterNormalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function migrateFreshUsing()
    {
        return array_merge([
            '--drop-views' => $this->shouldDropViews(),
            '--drop-types' => $this->shouldDropTypes(),
            '--seed' => $this->shouldSeed(),
        ], [
            '--path' => [
                'Modules/Organization/Companies/Infrastructure/Database/Migrations',
                'Modules/Organization/Brands/Infrastructure/Database/Migrations/2026_07_05_140000_create_brands_table.php',
                // CustomerObserver (Commerce\Synchronization) fires unconditionally on
                // Customer::created and queries `channels` (is_active + sync_customers) —
                // a side effect, not something these tests assert on, but the table and
                // that column must still exist for the factory create() itself to succeed.
                'Modules/Commerce/Channels/Infrastructure/Database/Migrations/2026_06_23_170000_create_channels_table.php',
                'Modules/Commerce/Channels/Infrastructure/Database/Migrations/2026_06_23_600000_add_sync_customers_and_webhook_ids_to_channels.php',
                'Modules/Sales/Customers/Infrastructure/Database/Migrations/2026_06_23_160000_create_customers_table.php',
                'Modules/Sales/Customers/Infrastructure/Database/Migrations/2026_07_06_100001_create_customer_addresses_table.php',
                // Test-harness-only replica of the real add_company_id_to_customers_table
                // migration (same schema effect: column/index/FK), already established by
                // CustomerCodeSequenceTest — deliberately omits that migration's backfill
                // UPDATE (reads orders.company_id), which would otherwise pull the entire
                // Orders module's dependency chain into this suite's isolated schema for
                // no reason: zero pre-existing customer rows here means the backfill would
                // be a no-op regardless.
                'Modules/Sales/Customers/Tests/Support/Migrations/2026_07_08_910001_add_company_id_to_customers_table_test_only.php',
                'Modules/Sales/Customers/Infrastructure/Database/Migrations/2026_07_22_200000_create_customer_brands_table.php',
                'Modules/Sales/Customers/Infrastructure/Database/Migrations/2026_09_15_100000_create_customer_blocks_table.php',
            ],
        ]);
    }

    private function repository(): EloquentCustomerRepository
    {
        return new EloquentCustomerRepository;
    }

    /** Inserts an active block, normalizing $rawPhone through the real PhoneNormalizer — never a hand-computed digit string. */
    private function block(string $companyId, ?string $customerId, string $rawPhone): CustomerBlock
    {
        return CustomerBlock::create([
            'company_id' => $companyId,
            'customer_id' => $customerId,
            'normalized_phone' => (new PhoneNormalizer)->normalize($rawPhone),
            'is_active' => true,
            'block_reason' => 'R1 normalization test fixture',
            'blocked_at' => now(),
        ]);
    }

    /**
     * The exact pair named in the R1 task (§4): a Customer whose SAVED phone is
     * a spaced-out format, blocked under a compact digits-only format. Both
     * normalize to the same value through PhoneNormalizer::normalize() (a
     * leading local '0' at 10+ digits becomes country code '2') — the block is
     * never re-normalized by hand here, so this proves the real algorithm, not
     * an assumption about its output.
     */
    public function test_equivalent_phone_formats_match_in_the_blocked_only_filter(): void
    {
        $company = Company::factory()->create();
        $this->block($company->id, null, '01001234567');

        $customer = Customer::factory()->create([
            'company_id' => $company->id,
            'phone' => '0100 123 4567',
            'mobile' => null,
        ]);

        $page = $this->repository()->paginate(['company_id' => $company->id, 'blocked_only' => true]);

        self::assertTrue($page->getCollection()->pluck('id')->contains($customer->id));
    }

    public function test_customer_id_binding_still_works(): void
    {
        $company = Company::factory()->create();
        $customer = Customer::factory()->create(['company_id' => $company->id, 'phone' => null, 'mobile' => null]);
        $this->block($company->id, $customer->id, '201000000001');

        $page = $this->repository()->paginate(['company_id' => $company->id, 'blocked_only' => true]);

        self::assertTrue($page->getCollection()->pluck('id')->contains($customer->id));
    }

    public function test_phone_first_unbound_block_still_surfaces_the_customer(): void
    {
        $company = Company::factory()->create();
        // customer_id intentionally left null — this fix must not require or
        // perform opportunistic binding to work.
        $this->block($company->id, null, '0111 222 3333');

        $customer = Customer::factory()->create([
            'company_id' => $company->id,
            'phone' => '01112223333',
        ]);

        $page = $this->repository()->paginate(['company_id' => $company->id, 'blocked_only' => true]);

        self::assertTrue($page->getCollection()->pluck('id')->contains($customer->id));
        self::assertNull(
            CustomerBlock::query()->where('company_id', $company->id)->firstOrFail()->customer_id,
            'The block must remain unbound — this filter reads, it never writes.',
        );
    }

    public function test_unrelated_customer_does_not_appear(): void
    {
        $company = Company::factory()->create();
        $this->block($company->id, null, '01009999999');
        $unrelated = Customer::factory()->create(['company_id' => $company->id, 'phone' => '01005555555', 'mobile' => null]);

        $page = $this->repository()->paginate(['company_id' => $company->id, 'blocked_only' => true]);

        self::assertFalse($page->getCollection()->pluck('id')->contains($unrelated->id));
    }

    public function test_same_phone_in_another_company_does_not_leak(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $this->block($companyA->id, null, '01008888888');
        $customerB = Customer::factory()->create(['company_id' => $companyB->id, 'phone' => '01008888888', 'mobile' => null]);

        $page = $this->repository()->paginate(['company_id' => $companyB->id, 'blocked_only' => true]);

        self::assertFalse($page->getCollection()->pluck('id')->contains($customerB->id));
    }

    /**
     * The mandatory "no N+1" contract (§4/§9): the query count for the
     * blocked_only path must be a CONSTANT, never a function of how many
     * Customers are on the page. Proven by comparing the real query count
     * against two page sizes rather than asserting an arbitrary fixed number,
     * which would only prove "small today", not "does not scale".
     */
    public function test_blocked_only_filter_query_count_does_not_scale_with_customer_count(): void
    {
        $company = Company::factory()->create();
        $this->block($company->id, null, '01007777777');
        Customer::factory()->create(['company_id' => $company->id, 'phone' => '01007777777', 'mobile' => null]);

        DB::enableQueryLog();
        $this->repository()->paginate(['company_id' => $company->id, 'blocked_only' => true, 'per_page' => 50]);
        $fewCustomersQueryCount = count(DB::getQueryLog());
        DB::flushQueryLog();

        // Fixture setup, not the call under test — flushed again below so its own
        // inserts (and CustomerObserver's per-create side query) are never counted
        // as part of paginate()'s cost.
        Customer::factory()->count(20)->create(['company_id' => $company->id]);
        DB::flushQueryLog();

        $this->repository()->paginate(['company_id' => $company->id, 'blocked_only' => true, 'per_page' => 50]);
        $manyCustomersQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        self::assertSame(
            $fewCustomersQueryCount,
            $manyCustomersQueryCount,
            'Query count must not scale with the number of customers on the page — got '
            .$fewCustomersQueryCount.' for 1 customer vs '.$manyCustomersQueryCount.' for 21.',
        );
    }
}
