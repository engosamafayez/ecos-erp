<?php

declare(strict_types=1);

namespace Modules\Sales\Customers\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Domain\Services\CustomerOrderMetricsService;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * preferred_governorate — the metric moved out of React into
 * {@see CustomerOrderMetricsService::preferredGovernorateForCustomers()}.
 *
 * Covers, in order:
 *   1. Most frequent governorate wins (A×3 vs B×1 → A).
 *   2. A tie resolves deterministically by the documented platform convention
 *      (stable ascending tiebreaker on the natural key → governorate name ASC).
 *   3. A company never sees another company's orders in the metric.
 *   4. The unrestricted (super-admin) context resolves correctly per company.
 *   5. A customer with no orders yields NULL — no value is invented.
 *   6. Orders with a NULL/empty governorate never count toward the frequency.
 */
final class CustomerPreferredGovernorateTest extends TestCase
{
    use RefreshDatabase;

    private CustomerOrderMetricsService $metrics;

    private string $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->metrics = app(CustomerOrderMetricsService::class);

        $company = Company::factory()->create();
        $this->companyId = (string) $company->id;

        $this->actingAs(User::factory()->create(['company_id' => $this->companyId]));
    }

    public function test_most_frequent_governorate_wins(): void
    {
        $customer = $this->customer($this->companyId);

        $this->order($customer, $this->companyId, 'Cairo');
        $this->order($customer, $this->companyId, 'Cairo');
        $this->order($customer, $this->companyId, 'Cairo');
        $this->order($customer, $this->companyId, 'Giza');

        $result = $this->metrics->preferredGovernorateForCustomers([$customer], $this->companyId);

        $this->assertSame('Cairo', $result[$customer] ?? null);
    }

    /**
     * A tie must not depend on insertion order, which is what the client-side version
     * silently relied on. The platform convention is a stable ascending tiebreaker on the
     * natural key, so 'Alexandria' beats 'Cairo' at equal counts — and does so every run.
     */
    public function test_tie_resolves_deterministically_by_name_ascending(): void
    {
        $customer = $this->customer($this->companyId);

        // Inserted Cairo-first on purpose: insertion order must NOT decide the winner.
        $this->order($customer, $this->companyId, 'Cairo');
        $this->order($customer, $this->companyId, 'Cairo');
        $this->order($customer, $this->companyId, 'Alexandria');
        $this->order($customer, $this->companyId, 'Alexandria');

        for ($i = 0; $i < 3; $i++) {
            $result = $this->metrics->preferredGovernorateForCustomers([$customer], $this->companyId);
            $this->assertSame('Alexandria', $result[$customer] ?? null, "run {$i} disagreed");
        }
    }

    public function test_company_a_never_sees_company_b_orders(): void
    {
        $otherCompany = (string) Company::factory()->create()->id;

        $customer = $this->customer($this->companyId);

        // One order in the caller's company, three in another company for the SAME customer id.
        $this->order($customer, $this->companyId, 'Giza');
        $this->order($customer, $otherCompany, 'Aswan');
        $this->order($customer, $otherCompany, 'Aswan');
        $this->order($customer, $otherCompany, 'Aswan');

        $result = $this->metrics->preferredGovernorateForCustomers([$customer], $this->companyId);

        // Aswan is more frequent overall, but it belongs to another tenant.
        $this->assertSame('Giza', $result[$customer] ?? null);
    }

    public function test_unrestricted_context_resolves_each_company_separately(): void
    {
        $companyB = (string) Company::factory()->create()->id;

        $customerA = $this->customer($this->companyId);
        $customerB = $this->customer($companyB);

        $this->order($customerA, $this->companyId, 'Cairo');
        $this->order($customerB, $companyB, 'Luxor');

        // Mirrors how the controller groups by the customer's OWN company when
        // CurrentCompanyService::id() is null.
        $a = $this->metrics->preferredGovernorateForCustomers([$customerA], $this->companyId);
        $b = $this->metrics->preferredGovernorateForCustomers([$customerB], $companyB);

        $this->assertSame('Cairo', $a[$customerA] ?? null);
        $this->assertSame('Luxor', $b[$customerB] ?? null);
    }

    public function test_customer_without_orders_yields_null(): void
    {
        $customer = $this->customer($this->companyId);

        $result = $this->metrics->preferredGovernorateForCustomers([$customer], $this->companyId);

        $this->assertArrayNotHasKey($customer, $result);
        $this->assertNull($result[$customer] ?? null);
    }

    public function test_orders_without_a_governorate_do_not_count(): void
    {
        $customer = $this->customer($this->companyId);

        // NULL and empty must never win, even though they are the majority here.
        $this->order($customer, $this->companyId, null);
        $this->order($customer, $this->companyId, null);
        $this->order($customer, $this->companyId, '');
        $this->order($customer, $this->companyId, 'Damietta');

        $result = $this->metrics->preferredGovernorateForCustomers([$customer], $this->companyId);

        $this->assertSame('Damietta', $result[$customer] ?? null);
    }

    public function test_customer_with_only_blank_governorates_yields_null(): void
    {
        $customer = $this->customer($this->companyId);

        $this->order($customer, $this->companyId, null);
        $this->order($customer, $this->companyId, '');

        $result = $this->metrics->preferredGovernorateForCustomers([$customer], $this->companyId);

        $this->assertNull($result[$customer] ?? null);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function customer(string $companyId): string
    {
        return (string) Customer::query()->create([
            'company_id' => $companyId,
            'code' => 'CUS-'.Str::upper(Str::random(8)),
            'name' => 'Test Customer',
            'is_active' => true,
        ])->id;
    }

    private function order(string $customerId, string $companyId, ?string $governorate): void
    {
        DB::table('orders')->insert([
            'id' => (string) Str::uuid7(),
            'company_id' => $companyId,
            'customer_id' => $customerId,
            'order_number' => 'ORD-'.Str::upper(Str::random(10)),
            'status' => 'pending',
            'total' => 100,
            'governorate' => $governorate,
            'order_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
