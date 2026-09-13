<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderEvent;
use Modules\Commerce\Synchronization\Application\Actions\AuditWooCustomerCompanyLinkageAction;
use Modules\Crm\Customers\Domain\Models\Customer;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-V1.1-WOO-02-TENANT-SAFE-CUSTOMER-IDENTITY-044 §11 — the 5 mandatory
 * historical cross-company linkage audit cases. Every scenario is built by
 * writing an Order directly with a `customer_id` pointing at a Customer in a
 * DIFFERENT company than the Order itself — simulating exactly what the
 * pre-WOO-02 unscoped matching could produce — rather than reproducing it via a
 * live import (which WOO-02 itself now prevents from happening again).
 */
final class WooCustomerLinkageAuditTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomer(string $companyId, array $attributes = []): Customer
    {
        return Customer::withoutEvents(fn (): Customer => Customer::query()->create(array_merge([
            'code' => 'CUS-AUDIT-'.substr((string) str_replace('-', '', (string) \Illuminate\Support\Str::uuid()), 0, 12),
            'company_id' => $companyId,
            'name' => 'Audit Test Customer',
            'is_active' => true,
        ], $attributes)));
    }

    private function makeOrder(string $companyId, string $customerId, string $externalId): Order
    {
        return Order::create([
            'company_id' => $companyId,
            'customer_id' => $customerId,
            'external_order_id' => $externalId,
            'order_number' => 'ORD-AUDIT-'.$externalId,
            'order_date' => now()->toDateString(),
            'status' => OrderStatus::InProgress->value,
            'subtotal' => 100,
            'total' => 100,
        ]);
    }

    public function test_safe_auto_relink_targets_an_existing_same_company_customer(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        $wrongCustomer = $this->makeCustomer($companyB->id, ['phone' => '201099990001']);
        $correctCustomer = $this->makeCustomer($companyA->id, ['phone' => '201099990001']);
        $order = $this->makeOrder($companyA->id, $wrongCustomer->id, 'AUDIT-F1');

        $result = app(AuditWooCustomerCompanyLinkageAction::class)->run(apply: true);

        $this->assertSame(1, $result['mismatched']);
        $this->assertSame(1, $result['safe_auto_relink']);
        $this->assertSame(0, $result['needs_review']);
        $this->assertSame(1, $result['relinked']);

        $case = $result['cases'][0];
        $this->assertSame('SAFE_AUTO_RELINK', $case['classification']);
        $this->assertSame($correctCustomer->id, $case['relinked_to_customer_id']);
        $this->assertFalse($case['relinked_customer_was_created']);

        $this->assertSame($correctCustomer->id, $order->fresh()->customer_id);
        // The mismatched customer is left completely alone — never merged, deleted, or moved.
        $this->assertNotNull($wrongCustomer->fresh());
        $this->assertSame($companyB->id, $wrongCustomer->fresh()->company_id);

        $this->assertSame(
            1,
            OrderEvent::query()->where('order_id', $order->id)->where('event_type', 'woo_customer_linkage_relinked')->count(),
        );
    }

    public function test_safe_auto_relink_creates_a_new_same_company_customer_when_no_match_exists(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        $wrongCustomer = $this->makeCustomer($companyB->id, ['email' => 'nomatch@example.test', 'name' => 'No Match']);
        $order = $this->makeOrder($companyA->id, $wrongCustomer->id, 'AUDIT-F2');

        $countBefore = Customer::query()->count();

        $result = app(AuditWooCustomerCompanyLinkageAction::class)->run(apply: true);

        $this->assertSame(1, $result['safe_auto_relink']);
        $case = $result['cases'][0];
        $this->assertSame('SAFE_AUTO_RELINK', $case['classification']);
        $this->assertTrue($case['relinked_customer_was_created']);

        $newCustomerId = $case['relinked_to_customer_id'];
        $this->assertNotSame($wrongCustomer->id, $newCustomerId);

        $newCustomer = Customer::find($newCustomerId);
        $this->assertNotNull($newCustomer);
        $this->assertSame($companyA->id, $newCustomer->company_id);
        $this->assertSame('nomatch@example.test', $newCustomer->email);

        $this->assertSame($newCustomerId, $order->fresh()->customer_id);
        $this->assertSame($countBefore + 1, Customer::query()->count());

        // The mismatched customer is left completely alone.
        $this->assertSame($companyB->id, $wrongCustomer->fresh()->company_id);
    }

    public function test_needs_review_is_never_auto_acted_on_even_with_apply(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        // No email, no phone, no mobile at all — nothing to key a repair on.
        $wrongCustomer = $this->makeCustomer($companyB->id, ['email' => null, 'phone' => null, 'mobile' => null]);
        $order = $this->makeOrder($companyA->id, $wrongCustomer->id, 'AUDIT-F3');

        $result = app(AuditWooCustomerCompanyLinkageAction::class)->run(apply: true);

        $this->assertSame(1, $result['mismatched']);
        $this->assertSame(0, $result['safe_auto_relink']);
        $this->assertSame(1, $result['needs_review']);
        $this->assertSame(0, $result['relinked']);

        $case = $result['cases'][0];
        $this->assertSame('NEEDS_REVIEW', $case['classification']);
        $this->assertNotNull($case['reason']);

        // Untouched — even under apply=true, NEEDS_REVIEW is never acted on.
        $this->assertSame($wrongCustomer->id, $order->fresh()->customer_id);
        $this->assertSame(
            0,
            OrderEvent::query()->where('order_id', $order->id)->where('event_type', 'woo_customer_linkage_relinked')->count(),
        );
    }

    public function test_dry_run_reports_but_makes_no_changes(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        $wrongCustomer = $this->makeCustomer($companyB->id, ['phone' => '201099990002']);
        $correctCustomer = $this->makeCustomer($companyA->id, ['phone' => '201099990002']);
        $order = $this->makeOrder($companyA->id, $wrongCustomer->id, 'AUDIT-F4');

        $countBefore = Customer::query()->count();

        $result = app(AuditWooCustomerCompanyLinkageAction::class)->run(apply: false);

        $this->assertSame(1, $result['mismatched']);
        $this->assertSame(1, $result['safe_auto_relink']);
        $this->assertSame(0, $result['relinked']);
        $this->assertArrayNotHasKey('relinked_to_customer_id', $result['cases'][0]);

        // Nothing written: same customer_id, no new customer, no OrderEvent.
        $this->assertSame($wrongCustomer->id, $order->fresh()->customer_id);
        $this->assertSame($countBefore, Customer::query()->count());
        $this->assertSame(0, OrderEvent::query()->where('order_id', $order->id)->count());

        // Both customers otherwise untouched.
        $this->assertSame($companyB->id, $wrongCustomer->fresh()->company_id);
        $this->assertSame($companyA->id, $correctCustomer->fresh()->company_id);
    }

    public function test_apply_is_idempotent_on_rerun(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        $wrongCustomer = $this->makeCustomer($companyB->id, ['phone' => '201099990003']);
        $correctCustomer = $this->makeCustomer($companyA->id, ['phone' => '201099990003']);
        $order = $this->makeOrder($companyA->id, $wrongCustomer->id, 'AUDIT-F5');

        $action = app(AuditWooCustomerCompanyLinkageAction::class);

        $first = $action->run(apply: true);
        $this->assertSame(1, $first['relinked']);
        $this->assertSame($correctCustomer->id, $order->fresh()->customer_id);

        $second = $action->run(apply: true);

        $this->assertSame(0, $second['mismatched']);
        $this->assertSame(0, $second['safe_auto_relink']);
        $this->assertSame(0, $second['needs_review']);
        $this->assertSame(0, $second['relinked']);
        $this->assertSame([], $second['cases']);

        // Still exactly one relink event — the rerun found nothing left to do,
        // not a second (duplicate) remediation of the same order.
        $this->assertSame(
            1,
            OrderEvent::query()->where('order_id', $order->id)->where('event_type', 'woo_customer_linkage_relinked')->count(),
        );
    }
}
