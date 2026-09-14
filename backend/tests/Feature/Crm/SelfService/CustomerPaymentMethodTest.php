<?php

declare(strict_types=1);

namespace Tests\Feature\Crm\SelfService;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Crm\Customers\Domain\Models\Customer;
use Modules\Crm\SelfService\Domain\Models\CustomerTrackingToken;
use Modules\Crm\SelfService\Domain\Services\CustomerTrackingTokenService;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-V1.1-CRM-04-BACKEND-SOURCE-CLOSURE-REMEDIATION-019R1 §7 — items 49-66. The
 * customer-facing payment-method change is a thin ownership/eligibility WRAPPER around the
 * canonical `ChangeOrderPaymentMethodAction`/`PaymentFulfillmentGate` (approved decision #4):
 * allowed only while AwaitingPayment or InProgress; every later or terminal status, and every
 * structurally locked order, is rejected; the allow-list is the real configured
 * `payment_proof_policy` (never a fabricated method list); and there is no payment-link surface.
 */
final class CustomerPaymentMethodTest extends TestCase
{
    use DatabaseTransactions;

    private function makeOrderWithCustomer(string $companyId, string $email, array $overrides = []): Order
    {
        $customer = Customer::create(['company_id' => $companyId, 'name' => 'Test Customer', 'email' => $email]);

        return Order::create(array_merge([
            'company_id' => $companyId,
            'customer_id' => $customer->id,
            'order_number' => 'ORD-'.strtoupper(Str::random(8)),
            'order_date' => now()->toDateString(),
            'status' => 'awaiting_payment',
            'subtotal' => 100,
            'total' => 100,
        ], $overrides));
    }

    private function tokenFor(Order $order): CustomerTrackingToken
    {
        return app(CustomerTrackingTokenService::class)->issue($order->customer, $order);
    }

    /** @return array<string, string> */
    private function bearer(CustomerTrackingToken $token): array
    {
        return ['Authorization' => 'Bearer '.$token->getAttribute('plain_text_token')];
    }

    // ── 49/50: the allow-list is the REAL configured policy, never fabricated ──────────

    public function test_options_returns_the_real_configured_method_allow_list(): void
    {
        $company = Company::factory()->create();
        $order = $this->makeOrderWithCustomer($company->id, 'mine@customer.test');
        $token = $this->tokenFor($order);

        $response = $this->withHeaders($this->bearer($token))->getJson('/api/track/order/payment-method');

        $response->assertOk();
        $methods = $response->json('data.methods');
        // The system-default payment_proof_policy (BrandPolicy::defaultSettings('order')) — the
        // one real, already-authoritative source; never a hand-invented gateway list.
        foreach (['cod', 'instapay', 'bank_transfer', 'mobile_wallet', 'credit_card'] as $expected) {
            $this->assertContains($expected, $methods);
        }
        $this->assertNotContains('paymob', $methods, 'must never surface a method the platform has no configured policy for');
    }

    // ── 51/52: eligible statuses (unlocked) may change ──────────────────────────────────

    public function test_awaiting_payment_order_can_change_to_an_allowed_method(): void
    {
        $company = Company::factory()->create();
        $order = $this->makeOrderWithCustomer($company->id, 'mine@customer.test', ['status' => 'awaiting_payment']);
        $token = $this->tokenFor($order);

        $response = $this->withHeaders($this->bearer($token))->postJson('/api/track/order/payment-method', ['payment_method' => 'cod']);

        $response->assertOk();
        $this->assertSame('cod', $order->fresh()->payment_method_manual);
    }

    public function test_in_progress_order_can_change_to_an_allowed_method(): void
    {
        $company = Company::factory()->create();
        $order = $this->makeOrderWithCustomer($company->id, 'mine@customer.test', ['status' => 'in_progress']);
        $token = $this->tokenFor($order);

        $response = $this->withHeaders($this->bearer($token))->postJson('/api/track/order/payment-method', ['payment_method' => 'cod']);

        $response->assertOk();
        $this->assertSame('cod', $order->fresh()->payment_method_manual);
    }

    // ── 53-59: every later/terminal/locked status is rejected ───────────────────────────

    /** @return array<string, array{0: string}> */
    public static function ineligibleStatuses(): array
    {
        return [
            'confirmed' => ['confirmed'],
            'ready_for_dispatch' => ['ready_for_dispatch'],
            'out_for_delivery' => ['out_for_delivery'],
            'delivered' => ['delivered'],
            'final_cash' => ['final_cash'],
            'cancelled' => ['cancelled'],
            'returned' => ['returned'],
        ];
    }

    /** @dataProvider ineligibleStatuses */
    public function test_ineligible_or_locked_statuses_reject_the_payment_method_change(string $status): void
    {
        $company = Company::factory()->create();
        $order = $this->makeOrderWithCustomer($company->id, 'mine@customer.test', ['status' => $status]);
        $token = $this->tokenFor($order);

        $response = $this->withHeaders($this->bearer($token))->postJson('/api/track/order/payment-method', ['payment_method' => 'cod']);

        $response->assertStatus(422);
        $this->assertNull($order->fresh()->payment_method_manual, "a {$status} order must never have its payment method changed via this surface");
    }

    // ── 60: unconfigured/unsupported methods are rejected ───────────────────────────────

    public function test_an_unconfigured_method_is_rejected(): void
    {
        $company = Company::factory()->create();
        $order = $this->makeOrderWithCustomer($company->id, 'mine@customer.test', ['status' => 'awaiting_payment']);
        $token = $this->tokenFor($order);

        $response = $this->withHeaders($this->bearer($token))->postJson('/api/track/order/payment-method', ['payment_method' => 'paymob']);

        $response->assertStatus(422);
    }

    // ── 61-63: cross-Customer/Company/Brand orders cannot be changed via injected ids ──

    public function test_a_token_cannot_change_a_different_companys_order_via_an_injected_order_id(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $orderA = $this->makeOrderWithCustomer($companyA->id, 'a@customer.test', ['status' => 'awaiting_payment']);
        $orderB = $this->makeOrderWithCustomer($companyB->id, 'b@customer.test', ['status' => 'awaiting_payment']);
        $token = $this->tokenFor($orderA);

        $this->withHeaders($this->bearer($token))->postJson('/api/track/order/payment-method', [
            'payment_method' => 'cod',
            'order_id' => $orderB->id,
            'customer_id' => $orderB->customer_id,
            'company_id' => $orderB->company_id,
        ])->assertOk();

        $this->assertSame('cod', $orderA->fresh()->payment_method_manual, 'the change must land on the TOKEN\'s own order');
        $this->assertNull($orderB->fresh()->payment_method_manual, 'a different company\'s order must never be reachable via an injected id');
    }

    public function test_a_token_cannot_change_a_different_brands_order_via_an_injected_brand_id(): void
    {
        $company = Company::factory()->create();
        $brandA = Brand::factory()->create(['company_id' => $company->id]);
        $brandB = Brand::factory()->create(['company_id' => $company->id]);
        $channelA = Channel::factory()->create(['brand_id' => $brandA->id]);
        $channelB = Channel::factory()->create(['brand_id' => $brandB->id]);
        $orderA = $this->makeOrderWithCustomer($company->id, 'a@customer.test', ['status' => 'awaiting_payment', 'channel_id' => $channelA->id]);
        $orderB = $this->makeOrderWithCustomer($company->id, 'b@customer.test', ['status' => 'awaiting_payment', 'channel_id' => $channelB->id]);
        $token = $this->tokenFor($orderA);
        $this->assertSame($brandA->id, $token->brand_id);

        $this->withHeaders($this->bearer($token))->postJson('/api/track/order/payment-method', [
            'payment_method' => 'cod',
            'brand_id' => $brandB->id,
        ])->assertOk();

        $this->assertSame('cod', $orderA->fresh()->payment_method_manual);
        $this->assertNull($orderB->fresh()->payment_method_manual);
    }

    // ── 64/65: PaymentFulfillmentGate remains the sole authority on the resulting state ──

    public function test_a_proof_required_method_leaves_an_unpaid_order_parked_at_awaiting_payment(): void
    {
        $company = Company::factory()->create();
        $order = $this->makeOrderWithCustomer($company->id, 'mine@customer.test', ['status' => 'awaiting_payment', 'deposit_amount' => 0]);
        $token = $this->tokenFor($order);

        $response = $this->withHeaders($this->bearer($token))->postJson('/api/track/order/payment-method', ['payment_method' => 'instapay']);

        // The gate's own consistency invariant (ChangeOrderPaymentMethodAction) — no proof yet,
        // so the order is correctly parked at awaiting_payment, not silently advanced.
        $response->assertOk();
        $fresh = $order->fresh();
        $this->assertSame('instapay', $fresh->payment_method_manual);
        $this->assertSame('awaiting_payment', $fresh->status->value);
    }

    public function test_a_cod_method_switch_succeeds_regardless_of_payment_state(): void
    {
        $company = Company::factory()->create();
        $order = $this->makeOrderWithCustomer($company->id, 'mine@customer.test', ['status' => 'awaiting_payment', 'deposit_amount' => 0]);
        $token = $this->tokenFor($order);

        $this->withHeaders($this->bearer($token))->postJson('/api/track/order/payment-method', ['payment_method' => 'cod'])->assertOk();

        $this->assertSame('cod', $order->fresh()->payment_method_manual);
    }

    // ── 66: no payment-link endpoint exists anywhere on this surface ───────────────────

    public function test_no_payment_link_endpoint_exists(): void
    {
        $company = Company::factory()->create();
        $order = $this->makeOrderWithCustomer($company->id, 'mine@customer.test');
        $token = $this->tokenFor($order);

        $this->withHeaders($this->bearer($token))->getJson('/api/track/order/payment-link')->assertNotFound();
        $this->withHeaders($this->bearer($token))->postJson('/api/track/order/payment-link', [])->assertNotFound();
    }
}
