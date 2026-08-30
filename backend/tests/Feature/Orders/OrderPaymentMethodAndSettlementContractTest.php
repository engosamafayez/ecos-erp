<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderEvent;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-ORDERS-PAYMENT-TIME-SHIPPING-REMAINING-001 — Part A + Part E.
 *
 * Locks the two money-adjacent quick paths the Orders grid now exposes:
 *
 *   1. Inline payment-METHOD edit (A3) — PATCH …/quick-update {payment_method_manual}.
 *      Constrained to the five-value catalogue the create/update gates enforce. The edit
 *      itself never writes Order.status — it cannot smuggle an order PAST the confirm gate.
 *
 *      SUPERSEDED 2026-08-23 — the original wording called this a "LABEL-only change" that
 *      must NEVER move Order.status. That predates owner decision D1-A and ADR-042 §3.1 as
 *      amended, which make the proof requirement a function of the order's CURRENT payment
 *      method: switching an unpaid, unproven order onto a proof-required method must return
 *      it to `awaiting_payment` via `return_to_payment`, or the control would be advisory.
 *      The certified contract is asserted in
 *      OrderPaymentContractImplementation002Test::test_d3_* / test_d6_*.
 *
 *      The real invariant survives and is still tested below: the edit is not itself a
 *      lifecycle command, and any movement is the canonical re-evaluation's, never a direct
 *      status write. A method whose requirement is not `required` still moves nothing.
 *
 *   2. Payment SETTLEMENT (A4) — POST …/record-payment {amount}. deposit_amount is the
 *      cumulative SSOT; payment_state is DERIVED (deposit vs total) — a deposit is
 *      PARTIALLY PAID, never PAID — and the action never writes Order.status (P9).
 *
 * Financial consequences of DELIVERY shortage (Part D) are FROZEN and out of scope here.
 */
final class OrderPaymentMethodAndSettlementContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{User, Order, float, string} [owner, order, total, initialStatus]
     */
    private function seedOrder(int $qty = 1, float $unitPrice = 100.0): array
    {
        $company = Company::factory()->create();
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        $response = $this->actingAs($user)->postJson('/api/orders/manual', [
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'order_date' => now()->toDateString(),
            'lines' => [['product_id' => $product->id, 'quantity' => $qty, 'unit_price' => $unitPrice]],
        ]);

        self::assertContains($response->status(), [200, 201], $response->getContent());

        $id = $response->json('data.id') ?? $response->json('id');
        $order = Order::findOrFail($id);
        $total = (float) $response->json('data.total');
        $status = (string) $response->json('data.status');

        return [$user, $order, $total, $status];
    }

    // ── A3 — inline payment-method edit via quick-update ────────────────────────

    /**
     * Switching onto a PROOF-REQUIRED method re-opens the payment control (D1-A).
     *
     * Rewritten 2026-08-23: this previously asserted the status was untouched, which was the
     * pre-D1-A contract. `instapay` resolves to `required`, and the seeded order is unpaid with
     * no `payment_proofs` row, so neither condition of the control holds — leaving it
     * fulfilment-eligible is exactly the escape the control exists to close. The method edit
     * still writes no status itself; the movement comes from `return_to_payment`, which is
     * asserted rather than assumed.
     */
    public function test_quick_update_onto_a_proof_required_method_returns_the_order_to_awaiting_payment(): void
    {
        [$user, $order, , $initialStatus] = $this->seedOrder();

        self::assertSame(OrderStatus::InProgress->value, $initialStatus, 'Fixture precondition.');

        $response = $this->actingAs($user)->patchJson("/api/orders/{$order->id}/quick-update", [
            'payment_method_manual' => 'instapay',
        ]);

        $response->assertOk();
        self::assertSame('instapay', $response->json('data.payment_method_manual'));
        self::assertSame('instapay', DB::table('orders')->where('id', $order->id)->value('payment_method_manual'));

        self::assertSame(
            OrderStatus::AwaitingPayment->value,
            (string) DB::table('orders')->where('id', $order->id)->value('status'),
        );
        self::assertSame(
            1,
            OrderEvent::query()->where('order_id', $order->id)->where('event_type', 'return_to_payment')->count(),
            'The movement must come from ReturnToPaymentWorkflow, never a direct status write in the edit.',
        );
    }

    /**
     * The invariant the superseded assertion was reaching for, on a method where it holds.
     *
     * `cod` resolves to `none` on every scope, so the control has nothing to say and the edit
     * is genuinely label-only. This is the case that proves the edit is not itself a lifecycle
     * command — and it is the COD regression guard for this endpoint.
     */
    public function test_quick_update_onto_a_non_proof_required_method_leaves_status_untouched(): void
    {
        [$user, $order, , $initialStatus] = $this->seedOrder();

        $response = $this->actingAs($user)->patchJson("/api/orders/{$order->id}/quick-update", [
            'payment_method_manual' => 'cod',
        ]);

        $response->assertOk();
        self::assertSame('cod', DB::table('orders')->where('id', $order->id)->value('payment_method_manual'));
        self::assertSame($initialStatus, (string) DB::table('orders')->where('id', $order->id)->value('status'));
    }

    public function test_quick_update_rejects_a_method_outside_the_catalogue(): void
    {
        [$user, $order] = $this->seedOrder();

        $response = $this->actingAs($user)->patchJson("/api/orders/{$order->id}/quick-update", [
            'payment_method_manual' => 'paypal', // not one of the five canonical methods
        ]);

        $response->assertStatus(422);
        self::assertNull(DB::table('orders')->where('id', $order->id)->value('payment_method_manual'));
    }

    public function test_quick_update_payment_method_is_tenant_scoped(): void
    {
        [, $order] = $this->seedOrder();

        // A user from a DIFFERENT company must not reach this order at all.
        $intruderCompany = Company::factory()->create();
        $intruder = User::factory()->create(['company_id' => $intruderCompany->id]);

        $response = $this->actingAs($intruder)->patchJson("/api/orders/{$order->id}/quick-update", [
            'payment_method_manual' => 'cod',
        ]);

        $response->assertNotFound();
        self::assertNull(DB::table('orders')->where('id', $order->id)->value('payment_method_manual'));
    }

    public function test_quick_update_requires_the_orders_update_permission(): void
    {
        [, $order] = $this->seedOrder();

        $company = Company::factory()->create();
        $unprivileged = User::factory()->create(['company_id' => $company->id]);

        $response = $this->actingAsUnprivileged($unprivileged)->patchJson("/api/orders/{$order->id}/quick-update", [
            'payment_method_manual' => 'cod',
        ]);

        $response->assertForbidden();
    }

    // ── A4 — payment settlement via record-payment ──────────────────────────────

    public function test_record_payment_partial_marks_partially_paid_without_touching_status(): void
    {
        [$user, $order, $total, $initialStatus] = $this->seedOrder(1, 100.0);
        $part = round($total / 2, 2);

        $response = $this->actingAs($user)->postJson("/api/orders/{$order->id}/record-payment", [
            'amount' => $part,
        ]);

        $response->assertOk();
        self::assertSame('partially_paid', $response->json('data.payment_state'));
        self::assertEqualsWithDelta($part, (float) $response->json('data.paid_amount'), 0.01);
        self::assertEqualsWithDelta($part, (float) DB::table('orders')->where('id', $order->id)->value('deposit_amount'), 0.01);
        self::assertSame($initialStatus, $response->json('data.status'));
    }

    public function test_record_payment_full_marks_paid_without_touching_status(): void
    {
        [$user, $order, $total, $initialStatus] = $this->seedOrder(1, 100.0);

        $response = $this->actingAs($user)->postJson("/api/orders/{$order->id}/record-payment", [
            'amount' => $total,
        ]);

        $response->assertOk();
        self::assertSame('paid', $response->json('data.payment_state'));
        self::assertEqualsWithDelta(0.0, (float) $response->json('data.outstanding_amount'), 0.01);
        self::assertSame($initialStatus, $response->json('data.status'));
    }

    public function test_record_payment_rejects_overpayment_beyond_the_outstanding_balance(): void
    {
        [$user, $order, $total] = $this->seedOrder(1, 100.0);

        $response = $this->actingAs($user)->postJson("/api/orders/{$order->id}/record-payment", [
            'amount' => $total + 50.0,
        ]);

        $response->assertStatus(422);
        self::assertEqualsWithDelta(0.0, (float) DB::table('orders')->where('id', $order->id)->value('deposit_amount'), 0.01);
    }

    public function test_record_payment_is_tenant_scoped(): void
    {
        [, $order, $total] = $this->seedOrder(1, 100.0);

        $intruderCompany = Company::factory()->create();
        $intruder = User::factory()->create(['company_id' => $intruderCompany->id]);

        $response = $this->actingAs($intruder)->postJson("/api/orders/{$order->id}/record-payment", [
            'amount' => $total,
        ]);

        $response->assertNotFound();
        self::assertEqualsWithDelta(0.0, (float) DB::table('orders')->where('id', $order->id)->value('deposit_amount'), 0.01);
    }
}
