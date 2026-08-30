<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Commerce\Orders\Application\Actions\ChangeOrderPaymentMethodAction;
use Modules\Commerce\Orders\Domain\Exceptions\PaymentMethodChangeRejectedException;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-DRIVER-APP-PHASE-4-PAYMENT-METHOD-CLOSURE-001 — the canonical change-method orchestration.
 *
 * Every proof here is EFFECT-based (no mocks): a real fulfilment transition can only be produced
 * by ReevaluateOrderFulfillmentAction, so observing one proves the change routed through the
 * canonical authority and did not bypass fulfilment/reservation evaluation (§6). The rejection
 * test proves the consistency invariant — not the wrapping transaction — is what stops a
 * proof-required method committing onto a still-fulfilling order (§7/§8), via rollback.
 */
final class ChangeOrderPaymentMethodActionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
    }

    private function order(string $status, ?string $method): Order
    {
        return Order::query()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-'.strtoupper(substr(uniqid(), -8)),
            'order_date' => now()->toDateString(),
            'assigned_warehouse_id' => $this->warehouse->id,
            'city' => 'Cairo', 'governorate' => 'Cairo', 'status' => $status,
            'subtotal' => 100, 'total' => 100, 'deposit_amount' => 0,
            'shipping_total' => 0, 'discount_total' => 0, 'tax_total' => 0,
            'payment_method_manual' => $method,
        ]);
    }

    private function method(Order $order): ?string
    {
        return DB::table('orders')->where('id', $order->id)->value('payment_method_manual');
    }

    private function orderStatus(Order $order): string
    {
        return (string) DB::table('orders')->where('id', $order->id)->value('status');
    }

    /**
     * §6 headline — InstaPay → COD on an order blocked at awaiting_payment (instapay needs proof)
     * routes through the canonical re-evaluation, which advances it out of the payment block. The
     * advance is a transition ONLY ReevaluateOrderFulfillmentAction can make → it proves the
     * change did not bypass fulfilment evaluation. (No order lines → nothing to reserve.)
     */
    public function test_instapay_to_cod_advances_a_blocked_awaiting_payment_order(): void
    {
        $order = $this->order('awaiting_payment', 'instapay');

        app(ChangeOrderPaymentMethodAction::class)->execute($order, 'cod');

        self::assertSame('cod', $this->method($order));
        self::assertNotSame('awaiting_payment', $this->orderStatus($order), 're-evaluation advanced the order out of the payment block');
    }

    /**
     * §6 return direction — COD → InstaPay on a fulfilment-eligible order routes through the
     * canonical re-evaluation, which DEMOTES it back to awaiting_payment because instapay's proof
     * requirement is unmet. Again a transition only the canonical action performs.
     */
    public function test_cod_to_instapay_demotes_a_fulfilment_eligible_order(): void
    {
        $order = $this->order('in_progress', 'cod');

        app(ChangeOrderPaymentMethodAction::class)->execute($order, 'instapay');

        self::assertSame('instapay', $this->method($order));
        self::assertSame('awaiting_payment', $this->orderStatus($order), 're-evaluation demoted the order to collect proof');
    }

    /**
     * §7/§8 — switching a fulfilling (out-for-delivery) order to a proof-required method it cannot
     * be demoted to collect proof for is REJECTED, and the write is rolled back. The demotion
     * workflow blocks out_for_delivery, so ReevaluateOrderFulfillmentAction returns a no-op
     * SUCCESS — proving the consistency invariant, not the transaction, is the protection.
     */
    public function test_switching_a_fulfilling_order_to_a_proof_required_method_is_rejected_and_rolled_back(): void
    {
        $order = $this->order('out_for_delivery', 'cod'); // unpaid, no verified proof

        try {
            app(ChangeOrderPaymentMethodAction::class)->execute($order, 'instapay');
            self::fail('Expected the proof-required change on a fulfilling order to be rejected.');
        } catch (PaymentMethodChangeRejectedException) {
            // expected
        }

        self::assertSame('cod', $this->method($order), 'the method write was rolled back');
        self::assertSame('out_for_delivery', $this->orderStatus($order), 'the order status was untouched');
    }

    /** §12 — an unchanged method is a no-op success with no transition and no side effect. */
    public function test_an_unchanged_method_is_a_no_op(): void
    {
        $order = $this->order('out_for_delivery', 'cod');

        $result = app(ChangeOrderPaymentMethodAction::class)->execute($order, 'cod');

        self::assertTrue($result->isSuccess());
        self::assertSame('cod', $this->method($order));
        self::assertSame('out_for_delivery', $this->orderStatus($order));
    }
}
