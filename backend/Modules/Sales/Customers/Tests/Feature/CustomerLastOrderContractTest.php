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
 * LAST_ORDER_DATE_CONTRACT — closed by TASK-CUSTOMER-DOMAIN-FINAL-CLOSURE-001.
 *
 * Last Order = MAX(orders.order_date), which is the Order domain's own definition
 * (OrderResource: "MIN(order_date) as first_order_date, MAX(order_date) as last_order_date").
 * `created_at` is row-insertion time and must NOT decide this value — otherwise an imported
 * or back-dated order reports the wrong answer.
 *
 * These tests deliberately make order_date and created_at disagree, so a regression back to
 * MAX(created_at) fails loudly instead of passing by coincidence.
 */
final class CustomerLastOrderContractTest extends TestCase
{
    use RefreshDatabase;

    private CustomerOrderMetricsService $metrics;

    private string $companyId;

    private string $customerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->metrics = app(CustomerOrderMetricsService::class);
        $this->companyId = (string) Company::factory()->create()->id;
        $this->actingAs(User::factory()->create(['company_id' => $this->companyId]));

        $this->customerId = (string) Customer::query()->create([
            'company_id' => $this->companyId,
            'code' => 'CUS-'.Str::upper(Str::random(8)),
            'name' => 'Contract Customer',
            'is_active' => true,
        ])->id;
    }

    public function test_last_order_uses_business_date_not_insertion_time(): void
    {
        // Inserted LAST but back-dated: created_at is newest, order_date is oldest.
        $this->order('2026-01-01', '2026-08-10 12:00:00');
        // Inserted FIRST but the real business date is the latest.
        $this->order('2026-03-01', '2026-08-01 12:00:00');

        $result = $this->metrics->forCustomer($this->customerId, $this->companyId);

        // MAX(created_at) would answer 2026-08-10 → the January order. The contract is the
        // business date, so March wins.
        $this->assertSame('2026-03-01', $this->dateOf($result['last_order_at']));
    }

    public function test_single_order_reports_its_own_order_date(): void
    {
        $this->order('2026-05-20', '2026-08-01 09:00:00');

        $result = $this->metrics->forCustomer($this->customerId, $this->companyId);

        $this->assertSame('2026-05-20', $this->dateOf($result['last_order_at']));
    }

    public function test_customer_without_orders_has_null_last_order(): void
    {
        $result = $this->metrics->forCustomer($this->customerId, $this->companyId);

        $this->assertNull($result['last_order_at']);
        $this->assertSame(0, $result['orders_count']);
    }

    public function test_last_order_is_company_scoped(): void
    {
        $otherCompany = (string) Company::factory()->create()->id;

        $this->order('2026-02-01', '2026-08-01 10:00:00');
        // A newer order for the same customer id, but in another tenant.
        $this->order('2026-12-31', '2026-08-02 10:00:00', $otherCompany);

        $result = $this->metrics->forCustomer($this->customerId, $this->companyId);

        $this->assertSame('2026-02-01', $this->dateOf($result['last_order_at']));
    }

    public function test_soft_deleted_orders_do_not_set_last_order(): void
    {
        $this->order('2026-04-01', '2026-08-01 10:00:00');
        $this->order('2026-09-09', '2026-08-02 10:00:00', null, deleted: true);

        $result = $this->metrics->forCustomer($this->customerId, $this->companyId);

        $this->assertSame('2026-04-01', $this->dateOf($result['last_order_at']));
    }

    /** `last_order_at` may come back as a date or datetime string depending on the driver. */
    private function dateOf(?string $value): ?string
    {
        return $value === null ? null : substr($value, 0, 10);
    }

    private function order(
        string $orderDate,
        string $createdAt,
        ?string $companyId = null,
        bool $deleted = false,
    ): void {
        DB::table('orders')->insert([
            'id' => (string) Str::uuid7(),
            'company_id' => $companyId ?? $this->companyId,
            'customer_id' => $this->customerId,
            'order_number' => 'ORD-'.Str::upper(Str::random(10)),
            'status' => 'pending',
            'total' => 100,
            'order_date' => $orderDate,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'deleted_at' => $deleted ? $createdAt : null,
        ]);
    }
}
