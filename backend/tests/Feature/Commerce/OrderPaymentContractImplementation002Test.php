<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Enums\PaymentProofState;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderEvent;
use Modules\Commerce\Orders\Domain\Models\PaymentProof;
use Modules\Commerce\Orders\Domain\Services\PaymentFulfillmentGate;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-ORDERS-PAYMENT-CONFIRMATION-FULFILLMENT-IMPLEMENTATION-002.
 *
 * Owner decisions under test:
 *
 *   D1-A  MANDATORY FINANCIAL CONTROL. `payment_proof_policy: required` blocks fulfilment
 *         eligibility until sufficient payment AND an active VERIFIED `payment_proofs`
 *         record both exist — evaluated everywhere, creation included.
 *
 *   D2-B  `channel_id IS NULL` does not mean "no requirement". Policy resolution continues
 *         down the documented chain instead of hardcoding `'none'`.
 *
 * Everything goes through the REAL endpoints — creation via POST /api/orders/manual, method
 * changes via PATCH /api/orders/{id}/quick-update, payment and proof via their own routes.
 * The defects these cover all survived earlier suites precisely because the halves were only
 * ever exercised apart, and because channel-less fixtures made proof-required paths resolve
 * to `'none'` and pass for the wrong reason.
 */
class OrderPaymentContractImplementation002Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    private Product $product;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->product = Product::factory()->create();
        // With no stored brand policy the BrandPolicy defaults apply:
        // instapay / bank_transfer / mobile_wallet = 'required', cod / cash = 'none'.
        $this->channel = Channel::factory()->create();
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Fixtures
    // ─────────────────────────────────────────────────────────────────────────────

    private function user(): User
    {
        return User::factory()->create(['company_id' => $this->company->id]);
    }

    /**
     * Creates through the real endpoint. `$extra['channel_id'] = null` deliberately omits the
     * channel so the D2 chain is exercised.
     *
     * @param  array<string, mixed>  $extra
     */
    private function createOrder(array $extra = [], float $unitPrice = 100): Order
    {
        $payload = array_merge([
            'customer_id' => $this->customer->id,
            'company_id' => $this->company->id,
            'channel_id' => $this->channel->id,
            'order_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $this->product->id,
                'quantity' => 1,
                'unit_price' => $unitPrice,
            ]],
        ], $extra);

        if (array_key_exists('channel_id', $extra) && $extra['channel_id'] === null) {
            unset($payload['channel_id']);
        }

        $response = $this->actingAs($this->user())->postJson('/api/orders/manual', $payload);

        self::assertContains(
            $response->status(),
            [200, 201],
            'Order creation failed: '.$response->getContent(),
        );

        return Order::query()->findOrFail($response->json('data.id') ?? $response->json('id'));
    }

    private function seedVerifiedProof(Order $order): PaymentProof
    {
        return PaymentProof::create([
            'company_id' => $order->company_id,
            'order_id' => $order->id,
            'state' => PaymentProofState::Verified,
            'storage_disk' => 'public',
            'storage_path' => 'payment-proofs/'.$order->id.'/evidence.jpg',
            'original_filename' => 'evidence.jpg',
            'uploaded_at' => now(),
            'verified_at' => now(),
        ]);
    }

    private function seedProof(Order $order, PaymentProofState $state): PaymentProof
    {
        return PaymentProof::create([
            'company_id' => $order->company_id,
            'order_id' => $order->id,
            'state' => $state,
            'storage_disk' => 'public',
            'storage_path' => 'payment-proofs/'.$order->id.'/evidence.jpg',
            'original_filename' => 'evidence.jpg',
            'uploaded_at' => now(),
        ]);
    }

    /** Reads the raw column, never the model accessor — the stored value is the claim. */
    private function storedStatus(Order $order): string
    {
        return (string) DB::table('orders')->where('id', $order->id)->value('status');
    }

    /** Money is written straight to the column here — this suite tests the GATE, not payment. */
    private function setDeposit(Order $order, float $amount): void
    {
        DB::table('orders')->where('id', $order->id)->update(['deposit_amount' => $amount]);
    }

    private function changeMethod(Order $order, string $method): TestResponse
    {
        return $this->actingAs($this->user())
            ->patchJson("/api/orders/{$order->id}/quick-update", ['payment_method_manual' => $method]);
    }

    /**
     * A COD order that enters at `in_progress`.
     *
     * The explicit `status` matters and is not padding: with none submitted, resolution falls
     * through to `BrandPolicy::defaultSettings('order')['source_entry_policies']['manual']`,
     * which still reads `["pending","awaiting_payment","processing","confirmed"]` — pre-V3
     * vocabulary whose first CANONICAL member is `awaiting_payment`. So an unconfigured brand
     * parks every manual order on payment for a reason that has nothing to do with the payment
     * control under test. The real client always submits a status
     * (`order-form-schema.ts`: `status: values.status || 'in_progress'`), so this fixture
     * matches production rather than papering over the gap. Reported as a follow-up.
     */
    private function codOrder(): Order
    {
        return $this->createOrder([
            'payment_method_manual' => 'cod',
            'status' => OrderStatus::InProgress->value,
        ]);
    }

    private function gate(): PaymentFulfillmentGate
    {
        return app(PaymentFulfillmentGate::class);
    }

    // ═════════════════════════════════════════════════════════════════════════════
    // B. Creation path — D1-A
    // ═════════════════════════════════════════════════════════════════════════════

    public function test_b1_proof_required_with_insufficient_payment_is_created_awaiting_payment(): void
    {
        $order = $this->createOrder(['payment_method_manual' => 'instapay', 'deposit_amount' => 40]);

        self::assertSame(OrderStatus::AwaitingPayment->value, $this->storedStatus($order));
    }

    /**
     * The D1-A change with the sharpest edge. Before this task the creation path ignored
     * payment entirely and looked only at `payment_proof_path`; the confirmation gate, once
     * hardened, required payment AND a verified proof. Money alone must not buy entry.
     */
    public function test_b2_proof_required_paid_in_full_but_no_verified_proof_is_still_awaiting_payment(): void
    {
        $order = $this->createOrder([
            'payment_method_manual' => 'instapay',
            'deposit_amount' => 100,
            'status' => OrderStatus::InProgress->value,
        ]);

        self::assertSame(
            OrderStatus::AwaitingPayment->value,
            $this->storedStatus($order),
            'Paid in full is only ONE of the two required facts; a verified proof cannot exist at creation.',
        );
    }

    /**
     * The creation-time bypass this task closes. `payment_proof_path` is validated only as
     * `nullable|string|max:500` — no storage, tenant, existence or MIME check — and any
     * non-empty value used to skip `awaiting_payment` entirely, which meant the hardened
     * confirmation gate never ran for that order at all.
     */
    public function test_b3_arbitrary_payment_proof_path_does_not_satisfy_the_proof_requirement(): void
    {
        $order = $this->createOrder([
            'payment_method_manual' => 'instapay',
            'payment_proof_path' => 'x',
            'status' => OrderStatus::InProgress->value,
            'deposit_amount' => 100,
        ]);

        self::assertSame(OrderStatus::AwaitingPayment->value, $this->storedStatus($order));

        // The path is still persisted and still audited — it simply has no lifecycle authority.
        self::assertSame('x', (string) DB::table('orders')->where('id', $order->id)->value('payment_proof_path'));
        self::assertSame(
            0,
            PaymentProof::query()->where('order_id', $order->id)->count(),
            'A request string must never become a canonical proof record.',
        );
    }

    public function test_b4_creation_cannot_bypass_the_payment_gate_through_a_submitted_status(): void
    {
        foreach ([OrderStatus::InProgress->value, OrderStatus::Scheduled->value] as $submitted) {
            $order = $this->createOrder([
                'payment_method_manual' => 'bank_transfer',
                'status' => $submitted,
                'deposit_amount' => 100,
            ]);

            self::assertSame(
                OrderStatus::AwaitingPayment->value,
                $this->storedStatus($order),
                "Submitted entry status '{$submitted}' must not defeat the payment control.",
            );
        }
    }

    public function test_b5_the_entry_status_override_is_audited_and_reports_the_status_actually_stored(): void
    {
        $order = $this->createOrder([
            'payment_method_manual' => 'instapay',
            'status' => OrderStatus::InProgress->value,
        ]);

        $event = OrderEvent::query()
            ->where('order_id', $order->id)
            ->where('event_type', 'entry_status_overridden_by_payment_proof_policy')
            ->first();

        self::assertNotNull($event, 'ADR-042 §3.1 requires the override to be audited, never silent.');

        $payload = (array) $event->payload;
        self::assertSame(OrderStatus::InProgress->value, $payload['submitted_status'] ?? null);
        self::assertSame(
            $this->storedStatus($order),
            $payload['stored_status'] ?? null,
            'The audit record must report the status actually written to the row.',
        );
    }

    public function test_b6_a_non_proof_required_method_is_unaffected_by_the_creation_control(): void
    {
        $order = $this->codOrder();

        self::assertSame(OrderStatus::InProgress->value, $this->storedStatus($order));
    }

    /**
     * The full sanctioned route into fulfilment: park at creation, then upload → verify → pay.
     * Proves the control is a gate, not a wall.
     */
    public function test_b7_the_canonical_lifecycle_carries_a_proof_required_order_into_fulfilment(): void
    {
        $order = $this->createOrder(['payment_method_manual' => 'instapay']);
        self::assertSame(OrderStatus::AwaitingPayment->value, $this->storedStatus($order));

        $this->seedVerifiedProof($order);
        $this->actingAs($this->user())
            ->postJson("/api/orders/{$order->id}/record-payment", ['amount' => 100])
            ->assertOk();

        self::assertContains(
            $this->storedStatus($order),
            ['in_progress', 'confirmed'],
            'Payment + verified proof must carry the order into a fulfilment-eligible status.',
        );
    }

    // ═════════════════════════════════════════════════════════════════════════════
    // C. Policy resolution — D2-B
    // ═════════════════════════════════════════════════════════════════════════════

    public function test_c1_a_null_channel_resolves_the_requirement_through_the_documented_chain(): void
    {
        $gate = $this->gate();

        self::assertSame(
            'required',
            $gate->requirementFor('instapay', null, (string) $this->company->id),
            'NULL channel must fall through to the default policy, which marks instapay required.',
        );
        self::assertSame('required', $gate->requirementFor('bank_transfer', null, (string) $this->company->id));
        self::assertSame('required', $gate->requirementFor('mobile_wallet', null, (string) $this->company->id));
    }

    public function test_c2_null_channel_is_never_hardcoded_to_none(): void
    {
        // No company either — the chain must still reach the system default rather than
        // short-circuiting because a lookup key happened to be absent.
        self::assertSame('required', $this->gate()->requirementFor('instapay', null, null));
    }

    public function test_c3_cod_remains_none_on_every_scope(): void
    {
        $gate = $this->gate();

        self::assertSame('none', $gate->requirementFor('cod', null, null));
        self::assertSame('none', $gate->requirementFor('cod', null, (string) $this->company->id));
        self::assertSame('none', $gate->requirementFor('cod', (string) $this->channel->id, (string) $this->company->id));
        self::assertSame('none', $gate->requirementFor('cash', null, null));
    }

    public function test_c4_an_unrecognised_method_key_still_resolves_to_none(): void
    {
        // Key-miss behaviour is deliberately unchanged — it is what keeps WooCommerce
        // gateway ids such as `bacs` inert rather than silently proof-required.
        self::assertSame('none', $this->gate()->requirementFor('bacs', (string) $this->channel->id, (string) $this->company->id));
    }

    public function test_c5_a_channel_less_proof_required_order_is_parked_at_creation(): void
    {
        $order = $this->createOrder([
            'channel_id' => null,
            'payment_method_manual' => 'instapay',
            'status' => OrderStatus::InProgress->value,
        ]);

        self::assertNull($order->channel_id, 'Fixture premise: the order really has no channel.');
        self::assertSame(
            OrderStatus::AwaitingPayment->value,
            $this->storedStatus($order),
            'A missing channel is a missing configuration scope, not a missing requirement.',
        );
    }

    public function test_c6_a_channel_less_cod_order_is_unaffected(): void
    {
        $order = $this->createOrder(['channel_id' => null, 'payment_method_manual' => 'cod']);

        self::assertNull($order->channel_id);
        self::assertSame(OrderStatus::InProgress->value, $this->storedStatus($order));
    }

    // ═════════════════════════════════════════════════════════════════════════════
    // D. Payment-method change re-evaluation
    // ═════════════════════════════════════════════════════════════════════════════

    /** D-1 — the critical scenario, forward direction. */
    public function test_d1_instapay_to_cod_with_incomplete_payment_releases_the_order_into_fulfilment(): void
    {
        $order = $this->createOrder(['payment_method_manual' => 'instapay']);
        self::assertSame(OrderStatus::AwaitingPayment->value, $this->storedStatus($order));

        $this->changeMethod($order, 'cod')->assertOk();

        self::assertContains(
            $this->storedStatus($order),
            ['in_progress', 'confirmed'],
            'COD requires no proof, so the gate now passes and fulfilment continues.',
        );
    }

    /** D-2 — a stale proof must not manufacture a requirement COD does not have. */
    public function test_d2_instapay_to_cod_with_an_old_proof_does_not_create_a_false_cod_requirement(): void
    {
        $order = $this->createOrder(['payment_method_manual' => 'instapay']);
        $this->seedProof($order, PaymentProofState::Rejected);

        $this->changeMethod($order, 'cod')->assertOk();

        self::assertContains($this->storedStatus($order), ['in_progress', 'confirmed']);
    }

    /** D-3 / D-4 — the critical scenario, inverse direction. */
    public function test_d3_cod_to_instapay_returns_a_fulfilment_eligible_order_to_awaiting_payment(): void
    {
        $order = $this->codOrder();
        self::assertSame(OrderStatus::InProgress->value, $this->storedStatus($order));

        $this->changeMethod($order, 'instapay')->assertOk();

        self::assertSame(
            OrderStatus::AwaitingPayment->value,
            $this->storedStatus($order),
            'An unpaid, unproven instapay order must not remain fulfilment-eligible.',
        );
    }

    public function test_d4_the_return_transition_is_performed_by_the_canonical_workflow(): void
    {
        $order = $this->codOrder();
        $this->changeMethod($order, 'instapay')->assertOk();

        self::assertSame(
            1,
            OrderEvent::query()->where('order_id', $order->id)->where('event_type', 'return_to_payment')->count(),
            'The transition must come from ReturnToPaymentWorkflow, never a direct status write.',
        );
    }

    /**
     * D-5 — a proof-required method changing to ANOTHER proof-required method.
     *
     * The policy is re-resolved and re-applied; both resolve `required`, and the order already
     * satisfies payment + an active verified proof, so it stays eligible. NOTE: `payment_proofs`
     * carries no payment-method association, so this asserts the EXISTING order-scoped proof
     * semantics unchanged. Whether a proof raised for one rail should carry to another is an
     * open question reported as a STOP in the final report — it is deliberately not decided
     * here, and no schema relationship was invented.
     */
    public function test_d5_instapay_to_bank_transfer_re_resolves_the_policy_and_applies_it(): void
    {
        $order = $this->createOrder(['payment_method_manual' => 'instapay']);
        $this->seedVerifiedProof($order);
        $this->setDeposit($order, 100);

        // The method change is itself the re-evaluation trigger under test.
        $this->changeMethod($order, 'bank_transfer')->assertOk();

        self::assertSame('required', $this->gate()->requirementFor('bank_transfer', (string) $this->channel->id, (string) $this->company->id));
        self::assertContains($this->storedStatus($order), ['in_progress', 'confirmed']);
    }

    /** D-6 — the same control applies to an order that was already confirmed. */
    public function test_d6_a_confirmed_order_is_returned_when_its_new_method_is_unsatisfied(): void
    {
        $order = $this->codOrder();

        $this->actingAs($this->user())
            ->postJson("/api/fulfillment/orders/{$order->id}/confirm")
            ->assertOk();
        self::assertSame(OrderStatus::Confirmed->value, $this->storedStatus($order));

        $this->changeMethod($order, 'mobile_wallet')->assertOk();

        self::assertSame(
            OrderStatus::AwaitingPayment->value,
            $this->storedStatus($order),
            'Confirmed is fulfilment-eligible, so the control applies there too.',
        );
    }

    public function test_d7_re_evaluating_the_same_method_twice_is_a_no_op(): void
    {
        $order = $this->codOrder();
        $this->changeMethod($order, 'instapay')->assertOk();
        $this->changeMethod($order, 'instapay')->assertOk();

        self::assertSame(OrderStatus::AwaitingPayment->value, $this->storedStatus($order));
        self::assertSame(
            1,
            OrderEvent::query()->where('order_id', $order->id)->where('event_type', 'return_to_payment')->count(),
            'A repeated change to the same method must not produce a second transition.',
        );
    }

    // ═════════════════════════════════════════════════════════════════════════════
    // E. Proof lifecycle against the gate
    // ═════════════════════════════════════════════════════════════════════════════

    public function test_e1_an_uploaded_but_unverified_proof_does_not_satisfy_the_gate(): void
    {
        $order = $this->createOrder(['payment_method_manual' => 'instapay']);
        $this->seedProof($order, PaymentProofState::Uploaded);
        $this->setDeposit($order, 100);

        self::assertFalse($this->gate()->permits($order->refresh()));
    }

    public function test_e2_a_rejected_proof_does_not_satisfy_the_gate(): void
    {
        $order = $this->createOrder(['payment_method_manual' => 'instapay']);
        $this->seedProof($order, PaymentProofState::Rejected);
        $this->setDeposit($order, 100);

        self::assertFalse($this->gate()->permits($order->refresh()));
    }

    public function test_e3_an_active_verified_proof_with_full_payment_satisfies_the_gate(): void
    {
        $order = $this->createOrder(['payment_method_manual' => 'instapay']);
        $this->seedVerifiedProof($order);
        $this->setDeposit($order, 100);

        self::assertTrue($this->gate()->permits($order->refresh()));
    }

    public function test_e4_a_superseded_verified_proof_no_longer_satisfies_the_gate(): void
    {
        $order = $this->createOrder(['payment_method_manual' => 'instapay']);
        $proof = $this->seedVerifiedProof($order);
        $this->setDeposit($order, 100);

        $proof->update(['superseded_at' => now()]);

        self::assertFalse(
            $this->gate()->permits($order->refresh()),
            'History is retained but a replaced proof stops clearing the gate.',
        );
    }

    public function test_e5_a_verified_proof_without_full_payment_does_not_satisfy_the_gate(): void
    {
        $order = $this->createOrder(['payment_method_manual' => 'instapay']);
        $this->seedVerifiedProof($order);
        $this->setDeposit($order, 30);

        self::assertFalse(
            $this->gate()->permits($order->refresh()),
            'Both facts are required; a verified proof is not a payment.',
        );
    }
}
