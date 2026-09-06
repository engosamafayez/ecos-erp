<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Crm\Customers\Domain\Enums\CustomerStatus;
use Modules\Crm\Customers\Domain\Enums\CustomerType;
use Modules\Crm\Customers\Domain\Exceptions\CustomerException;
use Modules\Crm\Customers\Domain\Models\Customer;
use Modules\Crm\Customers\Domain\Services\Customer360Service;
use Modules\Crm\Customers\Domain\Services\CustomerMergeService;
use Modules\Crm\Customers\Domain\Services\CustomerSearchService;
use Modules\Crm\Customers\Domain\Services\CustomerService;
use Modules\Crm\Customers\Domain\Services\DuplicateDetectionService;
use Modules\Finance\Receivables\Domain\Enums\CustomerLedgerEntryType;
use Modules\Finance\Receivables\Domain\Models\CustomerLedgerEntry;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\CustomerBlock;
use Modules\Sales\Customers\Domain\Services\BlockedCustomerPolicy;
use Tests\TestCase;

/**
 * CRM & Customer Service OS — EPIC C1. Customer Foundation.
 *
 * Protects the single-source-of-truth guarantees: individuals & businesses,
 * multiple contacts with primary mirroring, tags/notes/documents/preferences,
 * duplicate detection, non-destructive merge, and the module's independence from
 * every operational module.
 */
class CustomerFoundationTest extends TestCase
{
    use DatabaseTransactions;

    private string $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyId = (string) Company::factory()->create()->id;
    }

    private function service(): CustomerService
    {
        return app(CustomerService::class);
    }

    // ═══ CREATE ════════════════════════════════════════════════════════════════

    public function test_create_individual_customer(): void
    {
        $customer = $this->service()->create($this->companyId, CustomerType::Individual, [
            'first_name' => 'Sara', 'last_name' => 'Nabil', 'phone' => '0100 123 4567', 'email' => 'sara@example.com',
        ]);

        $this->assertSame(CustomerType::Individual, $customer->customer_type);
        $this->assertSame('Sara Nabil', $customer->name);
        $this->assertNotEmpty($customer->code);
        $this->assertSame(CustomerStatus::Active, $customer->status);
        $this->assertTrue((bool) $customer->is_active);
        // Primary phone/email mirrored to the legacy columns.
        $this->assertSame('0100 123 4567', $customer->fresh()->phone);
        $this->assertSame('sara@example.com', $customer->fresh()->email);
    }

    public function test_business_customer_requires_a_business_name(): void
    {
        $this->expectException(CustomerException::class);
        $this->service()->create($this->companyId, CustomerType::Business, ['first_name' => 'X']);
    }

    // ═══ CONTACTS ══════════════════════════════════════════════════════════════

    public function test_multiple_phones_with_primary_mirroring(): void
    {
        $customer = $this->service()->create($this->companyId, CustomerType::Individual, ['first_name' => 'A', 'phone' => '111']);
        $this->service()->addPhone($customer, '222', 'work', true);

        $phones = $customer->fresh()->phones;
        $this->assertCount(2, $phones);
        $this->assertSame(1, $phones->where('is_primary', true)->count());
        $this->assertSame('222', $customer->fresh()->phone); // new primary mirrored
    }

    public function test_default_address_is_exclusive(): void
    {
        $customer = $this->service()->create($this->companyId, CustomerType::Individual, ['first_name' => 'A']);
        $this->service()->addAddress($customer, ['governorate' => 'Cairo', 'area' => 'Nasr City', 'city' => 'Cairo', 'address_line' => 'St 1'], true);
        $second = $this->service()->addAddress($customer, ['governorate' => 'Giza', 'area' => 'Dokki', 'city' => 'Giza', 'address_line' => 'St 2'], true);

        $defaults = $customer->fresh()->addresses->where('is_default', true);
        $this->assertCount(1, $defaults);
        $this->assertSame($second->id, $defaults->first()->id);
    }

    public function test_tags_notes_and_preferences(): void
    {
        $customer = $this->service()->create($this->companyId, CustomerType::Individual, ['first_name' => 'A']);
        $this->service()->assignTag($customer, 'VIP', '#gold');
        $this->service()->assignTag($customer, 'VIP'); // idempotent
        $this->service()->addNote($customer, 'Prefers WhatsApp', true, 1);
        $this->service()->setPreference($customer, 'marketing_opt_in', 'true');
        $this->service()->setPreference($customer, 'marketing_opt_in', 'false'); // upsert

        $this->assertSame(1, $customer->fresh()->tags()->count());
        $this->assertSame(1, $customer->fresh()->customerNotes()->count());
        $this->assertSame('false', $customer->fresh()->preferences()->where('key', 'marketing_opt_in')->value('value'));
    }

    // ═══ STATUS & ARCHIVE ══════════════════════════════════════════════════════

    public function test_status_change_syncs_the_legacy_flag(): void
    {
        $customer = $this->service()->create($this->companyId, CustomerType::Individual, ['first_name' => 'A']);
        $this->service()->setStatus($customer, CustomerStatus::Inactive);
        $this->assertFalse((bool) $customer->fresh()->is_active);
    }

    public function test_archive_is_non_destructive_and_guarded(): void
    {
        $customer = $this->service()->create($this->companyId, CustomerType::Individual, ['first_name' => 'A']);
        $this->service()->archive($customer, 1);

        $fresh = $customer->fresh();
        $this->assertSame(CustomerStatus::Archived, $fresh->status);
        $this->assertNotNull($fresh->archived_at);
        $this->assertNotNull(Customer::find($customer->id)); // not deleted

        $this->expectException(CustomerException::class);
        $this->service()->archive($fresh, 1);
    }

    // ═══ SEARCH & DUPLICATES ═══════════════════════════════════════════════════

    public function test_search_finds_by_secondary_phone(): void
    {
        $customer = $this->service()->create($this->companyId, CustomerType::Individual, ['first_name' => 'Reem', 'phone' => '5550001']);
        $this->service()->addPhone($customer, '5559999', 'work', false);

        $page = app(CustomerSearchService::class)->search($this->companyId, ['q' => '5559999']);
        $this->assertSame(1, $page->total());
    }

    public function test_duplicate_detection_matches_shared_phone(): void
    {
        $a = $this->service()->create($this->companyId, CustomerType::Individual, ['first_name' => 'A', 'phone' => '700100']);
        $b = $this->service()->create($this->companyId, CustomerType::Individual, ['first_name' => 'B', 'phone' => '700200']);
        // B also carries A's number as a secondary (no unique clash on the master).
        $this->service()->addPhone($b, '700100', 'other', false);

        $candidates = app(DuplicateDetectionService::class)->candidatesFor($a);
        $this->assertNotEmpty($candidates);
        $this->assertSame($b->id, $candidates[0]['customer_id']);
        $this->assertContains('phone', $candidates[0]['reasons']);
    }

    // ═══ MERGE ═════════════════════════════════════════════════════════════════

    public function test_merge_moves_data_and_archives_non_destructively(): void
    {
        $surviving = $this->service()->create($this->companyId, CustomerType::Individual, ['first_name' => 'Keep', 'phone' => '900100']);
        $merged = $this->service()->create($this->companyId, CustomerType::Individual, ['first_name' => 'Dup', 'phone' => '900200']);
        $this->service()->addPhone($merged, '900300', 'work', false);
        $this->service()->assignTag($merged, 'Wholesale');
        $this->service()->addNote($merged, 'note on dup', false, 1);

        app(CustomerMergeService::class)->merge($surviving, $merged, 1);

        $survivingFresh = $surviving->fresh();
        $this->assertGreaterThanOrEqual(2, $survivingFresh->phones()->count()); // its own + merged's moved
        $this->assertSame(1, $survivingFresh->tags()->count());
        $this->assertSame(1, $survivingFresh->customerNotes()->count());

        $mergedFresh = $merged->fresh();
        $this->assertSame(CustomerStatus::Archived, $mergedFresh->status);
        $this->assertSame($surviving->id, $mergedFresh->merged_into_id);
        $this->assertNotNull(Customer::find($merged->id)); // never deleted

        // Resolve follows the chain back to the survivor.
        $this->assertSame($surviving->id, app(CustomerMergeService::class)->resolve($mergedFresh)->id);
        $this->assertDatabaseHas('crm_customer_merges', ['surviving_customer_id' => $surviving->id, 'merged_customer_id' => $merged->id]);
    }

    public function test_merge_into_self_is_refused(): void
    {
        $c = $this->service()->create($this->companyId, CustomerType::Individual, ['first_name' => 'A']);
        $this->expectException(CustomerException::class);
        app(CustomerMergeService::class)->merge($c, $c, 1);
    }

    // ═══ 360 PROFILE ═══════════════════════════════════════════════════════════

    public function test_customer_360_profile_aggregates_owned_data(): void
    {
        $customer = $this->service()->create($this->companyId, CustomerType::Individual, ['first_name' => 'A', 'phone' => '1', 'email' => 'a@x.com']);
        $this->service()->assignTag($customer, 'VIP');

        $profile = app(Customer360Service::class)->profile($customer->fresh());
        foreach (['identity', 'phones', 'emails', 'addresses', 'tags', 'notes', 'documents', 'preferences'] as $key) {
            $this->assertArrayHasKey($key, $profile);
        }
        $this->assertSame('A', $profile['identity']['display_name']);
    }

    // ═══ 360 COMPOSITION — Gate B (Finance / blocked / engagement panels) ═══════
    //
    // TASK-ECOS-CRM-CONTINUATION-AND-CUSTOMER360-GATE-B-002. The blocked-customer
    // authority itself (BlockedCustomerPolicy/CustomerBlock) is NOT owned by CRM —
    // it stays on Sales\Customers, read here exactly as the real authority exposes
    // it (phone-first, company-scoped). See CustomerController's constructor note.

    public function test_profile_endpoint_composes_finance_and_engagement_panels(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $customer = $this->service()->create((string) $company->id, CustomerType::Individual, [
            'first_name' => 'Nadia', 'last_name' => 'Kamal', 'phone' => '01011119999',
        ]);

        CustomerLedgerEntry::create([
            'company_id' => $company->id, 'customer_id' => $customer->id,
            'entry_date' => now()->toDateString(), 'entry_type' => CustomerLedgerEntryType::Invoice,
            'amount' => 500, 'description' => 'Invoice #1',
        ]);
        CustomerLedgerEntry::create([
            'company_id' => $company->id, 'customer_id' => $customer->id,
            'entry_date' => now()->toDateString(), 'entry_type' => CustomerLedgerEntryType::Receipt,
            'amount' => -200, 'description' => 'Receipt #1',
        ]);

        DB::table('cep_conversations')->insert([
            'id' => (string) Str::uuid(), 'conversation_uuid' => (string) Str::uuid(),
            'provider' => 'whatsapp', 'customer_id' => $customer->id, 'company_id' => $company->id,
            'started_at' => now()->subHour(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson("/api/crm/customers/{$customer->id}/profile");

        $response->assertOk();
        $data = $response->json('data');

        $this->assertEquals(300.0, $data['finance']['balance']);
        $this->assertFalse($data['blocked']['is_blocked']);
        $this->assertNull($data['blocked']['reason']);
        $this->assertSame(1, $data['engagement']['conversations_count']);
        $this->assertNotNull($data['engagement']['last_conversation_at']);
    }

    public function test_profile_endpoint_reports_blocked_state_from_the_sales_authority(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $customer = $this->service()->create((string) $company->id, CustomerType::Individual, [
            'first_name' => 'Blocked', 'last_name' => 'One', 'phone' => '01055554444',
        ]);

        CustomerBlock::create([
            'company_id' => $company->id,
            'normalized_phone' => app(BlockedCustomerPolicy::class)->normalize('01055554444'),
            'is_active' => true,
            'block_reason' => 'Repeated non-payment',
            'blocked_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson("/api/crm/customers/{$customer->id}/profile");

        $response->assertOk();
        $data = $response->json('data');

        $this->assertTrue($data['blocked']['is_blocked']);
        $this->assertSame('Repeated non-payment', $data['blocked']['reason']);
        $this->assertNotNull($data['blocked']['blocked_at']);
    }

    public function test_profile_endpoint_blocked_state_is_tenant_scoped(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $userA = User::factory()->create(['company_id' => $companyA->id]);
        $customerA = $this->service()->create((string) $companyA->id, CustomerType::Individual, [
            'first_name' => 'A', 'phone' => '01000000001',
        ]);

        // Same normalized phone, blocked only for company B — must not leak into A's profile.
        CustomerBlock::create([
            'company_id' => $companyB->id,
            'normalized_phone' => app(BlockedCustomerPolicy::class)->normalize('01000000001'),
            'is_active' => true,
            'block_reason' => 'Different company',
            'blocked_at' => now(),
        ]);

        $response = $this->actingAs($userA)->getJson("/api/crm/customers/{$customerA->id}/profile");

        $response->assertOk();
        $this->assertFalse($response->json('data.blocked.is_blocked'));
    }

    public function test_profile_endpoint_handles_no_finance_or_engagement_data_gracefully(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $customer = $this->service()->create((string) $company->id, CustomerType::Individual, ['first_name' => 'Quiet']);

        $response = $this->actingAs($user)->getJson("/api/crm/customers/{$customer->id}/profile");

        $response->assertOk();
        $data = $response->json('data');

        $this->assertEquals(0.0, $data['finance']['balance']);
        $this->assertFalse($data['blocked']['is_blocked']);
        $this->assertSame(0, $data['engagement']['conversations_count']);
        $this->assertNull($data['engagement']['last_conversation_at']);
    }

    // ═══ SECURITY ══════════════════════════════════════════════════════════════

    public function test_customer_routes_require_authentication(): void
    {
        $this->getJson('/api/crm/customers')->assertUnauthorized();
        $this->postJson('/api/crm/customers', [])->assertUnauthorized();
    }

    // ═══ ARCHITECTURE / SOURCE SCAN ════════════════════════════════════════════

    public function test_customer_domain_imports_no_operational_module(): void
    {
        // Scoped to Domain, not the whole module: Customer360Service's own docblock
        // promises no operational-module import, but Presentation is documented and
        // expected to compose them (see CustomerController::profile()'s constructor
        // comment) — that's the whole point of a controller, not a violation. The
        // module-wide version of this check is tests/Architecture's still-unbuilt
        // "modules must not depend on each other's internal namespaces" (see its
        // README) — a different, not-yet-implemented fitness function.
        $dir = base_path('Modules/Crm/Customers/Domain');
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));

        foreach ($it as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            foreach (['use Modules\\Commerce', 'use Modules\\Finance', 'use Modules\\Logistics', 'use Modules\\Marketing', 'use Modules\\POS', 'use Modules\\Sales'] as $needle) {
                $this->assertStringNotContainsString(
                    $needle, $source,
                    basename($file->getPathname()).' must not depend on an operational module — Customer owns identity, others reference it.',
                );
            }
        }
    }
}
