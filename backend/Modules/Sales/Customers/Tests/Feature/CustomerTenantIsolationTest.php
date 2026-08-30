<?php

declare(strict_types=1);

namespace Modules\Sales\Customers\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Crm\Engagement\Infrastructure\Timeline\OrderTimelineSource;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * Tenant boundary regression for the Sales Customers surface.
 *
 * Three read paths were unscoped before TASK-SALES-CUSTOMERS-POST-360-HARDENING-001:
 * the repository lookup, the phone search, and every method on the address controller.
 * These cases were argued statically at the time; this file makes them executable so a
 * future change cannot quietly reopen them.
 *
 * Canonical rule: customer.company_id == the authenticated user's company_id.
 * A null company context is the DOCUMENTED unrestricted case (super-admin), per
 * {@see \App\Core\Company\CurrentCompanyService} — it is deliberately NOT fail-closed.
 *
 * Cross-tenant access must answer 404, never 403 — a 403 would confirm the record exists.
 */
final class CustomerTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private string $companyA;

    private string $companyB;

    private User $userA;

    private string $customerA;

    private string $customerB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = (string) Company::factory()->create()->id;
        $this->companyB = (string) Company::factory()->create()->id;

        $this->userA = User::factory()->create(['company_id' => $this->companyA]);

        $this->customerA = $this->customer($this->companyA, '0500000001', 'Alpha Trading');
        $this->customerB = $this->customer($this->companyB, '0500000002', 'Bravo Holdings');
    }

    // ── show ─────────────────────────────────────────────────────────────────

    public function test_company_a_can_read_its_own_customer(): void
    {
        $this->actingAs($this->userA)
            ->getJson("/api/customers/{$this->customerA}")
            ->assertOk()
            ->assertJsonPath('data.id', $this->customerA);
    }

    public function test_company_a_cannot_read_company_b_customer(): void
    {
        $this->actingAs($this->userA)
            ->getJson("/api/customers/{$this->customerB}")
            ->assertNotFound();
    }

    public function test_company_b_cannot_read_company_a_customer(): void
    {
        $userB = User::factory()->create(['company_id' => $this->companyB]);

        $this->actingAs($userB)
            ->getJson("/api/customers/{$this->customerA}")
            ->assertNotFound();
    }

    public function test_cross_tenant_read_returns_404_not_403(): void
    {
        // 403 would leak the fact that the record exists. The boundary must be silent.
        $this->actingAs($this->userA)
            ->getJson("/api/customers/{$this->customerB}")
            ->assertStatus(404);
    }

    public function test_no_field_of_the_foreign_customer_leaks(): void
    {
        $response = $this->actingAs($this->userA)->getJson("/api/customers/{$this->customerB}");

        $body = $response->getContent() ?: '';

        // The 404 body echoes the id that was requested ("Customer [id] not found"), which
        // the caller already supplied — that is not a leak. What must never appear is any
        // ATTRIBUTE of the foreign record.
        $this->assertStringNotContainsString('0500000002', $body, 'foreign phone leaked');
        $this->assertStringNotContainsString('Bravo Holdings', $body, 'foreign name leaked');
    }

    // ── super-admin (documented unrestricted context) ─────────────────────────

    public function test_super_admin_without_company_is_unrestricted(): void
    {
        // CurrentCompanyService::id() returns null for a user with no company affiliation.
        // That is the documented unrestricted path and must keep working.
        $superAdmin = User::factory()->create(['company_id' => null]);

        $this->actingAs($superAdmin)
            ->getJson("/api/customers/{$this->customerA}")
            ->assertOk()
            ->assertJsonPath('data.id', $this->customerA);

        $this->actingAs($superAdmin)
            ->getJson("/api/customers/{$this->customerB}")
            ->assertOk()
            ->assertJsonPath('data.id', $this->customerB);
    }

    // ── phone search ─────────────────────────────────────────────────────────

    public function test_phone_search_finds_own_company_customer(): void
    {
        $this->actingAs($this->userA)
            ->getJson('/api/customers/search-by-phone?phone=0500000001')
            ->assertOk()
            // The action wraps the record: data.customer.*, not data.*
            ->assertJsonPath('data.customer.id', $this->customerA);
    }

    public function test_phone_search_does_not_cross_companies(): void
    {
        // Before the fix this returned company B's customer — with addresses and order
        // stats — to anyone who knew the phone number.
        $response = $this->actingAs($this->userA)
            ->getJson('/api/customers/search-by-phone?phone=0500000002')
            ->assertOk();

        $this->assertNull($response->json('data'));
        $this->assertStringNotContainsString($this->customerB, $response->getContent() ?: '');
    }

    // ── addresses ────────────────────────────────────────────────────────────

    public function test_addresses_readable_for_own_customer(): void
    {
        $this->actingAs($this->userA)
            ->getJson("/api/customers/{$this->customerA}/addresses")
            ->assertOk();
    }

    public function test_addresses_not_readable_across_companies(): void
    {
        $this->actingAs($this->userA)
            ->getJson("/api/customers/{$this->customerB}/addresses")
            ->assertNotFound();
    }

    public function test_addresses_not_writable_across_companies(): void
    {
        // The write path mattered most: store/update/destroy all resolved the customer
        // with a bare findOrFail, so another tenant's address book was editable.
        $this->actingAs($this->userA)
            ->postJson("/api/customers/{$this->customerB}/addresses", [
                'label' => 'Injected',
                'address_line' => 'should never persist',
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('customer_addresses', [
            'customer_id' => $this->customerB,
            'label' => 'Injected',
        ]);
    }

    // ── metrics ──────────────────────────────────────────────────────────────

    public function test_list_does_not_include_other_company_customers(): void
    {
        $response = $this->actingAs($this->userA)->getJson('/api/customers')->assertOk();

        $ids = array_column($response->json('data.items') ?? [], 'id');

        $this->assertContains($this->customerA, $ids);
        $this->assertNotContains($this->customerB, $ids);
    }

    // ── customer timeline (PART 15 item 11) ──────────────────────────────────

    public function test_customer_timeline_excludes_other_company_orders(): void
    {
        // OrderTimelineSource accepted $companyId but never applied it, so an order booked
        // under another tenant for the same customer id appeared on the customer's timeline.
        $this->order($this->customerA, $this->companyA, 'ORD-OWN');
        $this->order($this->customerA, $this->companyB, 'ORD-FOREIGN');

        $entries = app(OrderTimelineSource::class)->entries($this->companyA, $this->customerA);

        $titles = array_map(static fn ($e) => $e->title, $entries);

        $this->assertTrue((bool) preg_grep('/ORD-OWN/', $titles), 'own order missing');
        $this->assertEmpty(preg_grep('/ORD-FOREIGN/', $titles), 'foreign company order leaked onto the timeline');
    }

    public function test_customer_timeline_excludes_soft_deleted_orders(): void
    {
        $this->order($this->customerA, $this->companyA, 'ORD-LIVE');
        $this->order($this->customerA, $this->companyA, 'ORD-DELETED', deleted: true);

        $titles = array_map(
            static fn ($e) => $e->title,
            app(OrderTimelineSource::class)->entries($this->companyA, $this->customerA),
        );

        $this->assertTrue((bool) preg_grep('/ORD-LIVE/', $titles));
        $this->assertEmpty(preg_grep('/ORD-DELETED/', $titles), 'soft-deleted order shown on timeline');
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function order(string $customerId, string $companyId, string $number, bool $deleted = false): void
    {
        DB::table('orders')->insert([
            'id' => (string) Str::uuid7(),
            'company_id' => $companyId,
            'customer_id' => $customerId,
            'order_number' => $number,
            'status' => 'pending',
            'total' => 100,
            'order_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => $deleted ? now() : null,
        ]);
    }

    private function customer(string $companyId, string $phone, string $name): string
    {
        return (string) Customer::query()->create([
            'company_id' => $companyId,
            'code' => 'CUS-'.Str::upper(Str::random(8)),
            'name' => $name,
            'phone' => $phone,
            'is_active' => true,
        ])->id;
    }
}
