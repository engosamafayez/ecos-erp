<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Domain\Enums\PaymentState;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-WAREHOUSE-BRAND-PAYMENT-IMPLEMENTATION-001 §B — payment state + record payment.
 *
 * Approved rule: a deposit is PARTIALLY PAID, never PAID; an order is PAID only when
 * the FULL order total has been received. Payment state is DERIVED, never a second
 * stored truth, and recording payment never writes Order status.
 */
final class OrderPaymentStateTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->customer = Customer::factory()->create(['company_id' => $this->company->id]);
    }

    private function user(?Company $company = null): User
    {
        return User::factory()->create(['company_id' => ($company ?? $this->company)->id]);
    }

    private function makeOrder(float $total, float $deposit = 0, array $extra = []): Order
    {
        return Order::query()->create(array_merge([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-PS-'.Str::random(6),
            'order_date' => now()->toDateString(),
            'status' => 'in_progress',
            'subtotal' => $total,
            'total' => $total,
            'shipping_total' => 0,
            'discount_total' => 0,
            'tax_total' => 0,
            'deposit_amount' => $deposit,
        ], $extra));
    }

    private function record(User $user, Order $order, float $amount): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)->postJson("/api/orders/{$order->id}/record-payment", ['amount' => $amount]);
    }

    // ── Pure derivation (B11.1-B11.3, B11.10) ────────────────────────────────────

    public function test_zero_payment_is_unpaid(): void
    {
        self::assertSame(PaymentState::Unpaid, PaymentState::fromAmounts(0, 10000));
    }

    public function test_partial_deposit_is_partially_paid(): void
    {
        self::assertSame(PaymentState::PartiallyPaid, PaymentState::fromAmounts(3000, 10000));
    }

    public function test_exact_full_payment_is_paid(): void
    {
        self::assertSame(PaymentState::Paid, PaymentState::fromAmounts(10000, 10000));
    }

    public function test_partial_payment_cannot_be_represented_as_paid(): void
    {
        self::assertNotSame(PaymentState::Paid, PaymentState::fromAmounts(9999.99, 10000));
        self::assertSame(PaymentState::PartiallyPaid, PaymentState::fromAmounts(9999, 10000));
    }

    // ── Resource exposes the derived state (B9) ──────────────────────────────────

    public function test_resource_exposes_derived_partially_paid_state(): void
    {
        $order = $this->makeOrder(10000, 3000);
        $this->actingAs($this->user())
            ->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.payment_state', 'partially_paid')
            ->assertJsonPath('data.paid_amount', 3000)
            ->assertJsonPath('data.outstanding_amount', 7000);
    }

    // ── record-payment endpoint (B11.2-B11.6, B2) ────────────────────────────────

    public function test_recording_a_partial_payment_yields_partially_paid(): void
    {
        $order = $this->makeOrder(10000);
        $this->record($this->user(), $order, 3000)->assertOk();
        $order->refresh();
        self::assertSame(3000.0, (float) $order->deposit_amount);
        self::assertSame(PaymentState::PartiallyPaid, PaymentState::fromAmounts((float) $order->deposit_amount, (float) $order->total));
    }

    public function test_recording_the_full_amount_yields_paid(): void
    {
        $order = $this->makeOrder(10000);
        $this->record($this->user(), $order, 10000)->assertOk();
        $order->refresh();
        self::assertSame(PaymentState::Paid, PaymentState::fromAmounts((float) $order->deposit_amount, (float) $order->total));
    }

    public function test_recording_remaining_after_a_deposit_yields_paid(): void
    {
        $order = $this->makeOrder(10000, 3000);
        $this->record($this->user(), $order, 7000)->assertOk();
        $order->refresh();
        self::assertSame(10000.0, (float) $order->deposit_amount);
        self::assertSame(PaymentState::Paid, PaymentState::fromAmounts((float) $order->deposit_amount, (float) $order->total));
    }

    // ── COD (B11.7, B11.8) ───────────────────────────────────────────────────────

    public function test_cod_order_with_zero_payment_is_unpaid(): void
    {
        $order = $this->makeOrder(10000, 0, ['payment_method_manual' => 'cod']);
        self::assertSame(PaymentState::Unpaid, PaymentState::fromAmounts((float) $order->deposit_amount, (float) $order->total));
    }

    public function test_cod_order_with_a_partial_deposit_is_partially_paid(): void
    {
        $order = $this->makeOrder(10000, 3000, ['payment_method_manual' => 'cod']);
        self::assertSame(PaymentState::PartiallyPaid, PaymentState::fromAmounts((float) $order->deposit_amount, (float) $order->total));
    }

    // ── Proof alone does not pay (B11.9) ─────────────────────────────────────────

    public function test_payment_proof_alone_does_not_make_an_order_paid(): void
    {
        $order = $this->makeOrder(10000, 0, ['payment_proof_path' => 'proofs/receipt.jpg']);
        self::assertSame(PaymentState::Unpaid, PaymentState::fromAmounts((float) $order->deposit_amount, (float) $order->total));
        $this->actingAs($this->user())
            ->getJson("/api/orders/{$order->id}")
            ->assertJsonPath('data.payment_state', 'unpaid');
    }

    // ── Overpayment guard (B11.11) ───────────────────────────────────────────────

    public function test_overpayment_is_rejected(): void
    {
        $order = $this->makeOrder(10000);
        $this->record($this->user(), $order, 15000)->assertStatus(422);
        self::assertSame(0.0, (float) $order->refresh()->deposit_amount);
    }

    public function test_recording_more_than_outstanding_after_a_deposit_is_rejected(): void
    {
        $order = $this->makeOrder(10000, 6000);
        $this->record($this->user(), $order, 5000)->assertStatus(422); // outstanding is 4000
    }

    public function test_paying_a_fully_paid_order_is_idempotent(): void
    {
        $order = $this->makeOrder(10000, 10000);
        $this->record($this->user(), $order, 1000)->assertOk(); // no-op, not an error
        self::assertSame(10000.0, (float) $order->refresh()->deposit_amount);
    }

    // ── Payment state never writes Order status (B11.12) ─────────────────────────

    public function test_recording_payment_does_not_change_order_status(): void
    {
        $order = $this->makeOrder(10000, 0, ['status' => 'in_progress']);
        $before = (string) $order->getRawOriginal('status');
        $this->record($this->user(), $order, 10000)->assertOk();
        self::assertSame($before, (string) $order->refresh()->getRawOriginal('status'), 'Recording payment must not change Order status.');
    }
}
