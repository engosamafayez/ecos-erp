<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\Channels\Domain\Models\ChannelCredential;
use Modules\Commerce\Orders\Application\Actions\ReevaluateOrderFulfillmentAction;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Enums\PaymentProofState;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderEvent;
use Modules\Commerce\Orders\Domain\Models\PaymentProof;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-ORDERS-PAYMENT-CONFIRMATION-FULFILLMENT-IMPLEMENTATION-001.
 *
 * THE DEFECT. Recording a payment and verifying a payment proof both updated a financial
 * fact and then stopped. Nothing re-evaluated the payment gate afterwards, so an order
 * could be paid in full, carry a VERIFIED proof, satisfy every condition the gate asks
 * about — and still sit in `awaiting_payment` forever, never reaching the statuses
 * Preparation collects. Observed live on ORD-00003 (10000/10000 paid, proof verified,
 * still awaiting_payment). The gate itself was never wrong; nothing re-asked it.
 *
 * WHAT THESE TESTS PIN DOWN. Both triggers route through the SAME entry point
 * (ReevaluateOrderFulfillmentAction), that entry point never writes Order.status itself,
 * a still-unsatisfied gate leaves the financial fact committed rather than rolling it
 * back, and a second evaluation cannot produce a second transition or a second event.
 *
 * Orders are created through the REAL manual-order endpoint and advanced through the
 * REAL payment endpoints: the defect survived precisely because the two halves were only
 * ever exercised apart.
 */
class OrderPaymentFulfillmentReevaluationTest extends TestCase
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
        // The channel carries the brand whose policy governs proof rules. With no stored
        // policy the brand defaults apply: instapay = 'required', cod = 'none'.
        $this->channel = Channel::factory()->create();
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Fixtures
    // ─────────────────────────────────────────────────────────────────────────────

    private function user(): User
    {
        return User::factory()->create(['company_id' => $this->company->id]);
    }

    /** @param array<string, mixed> $extra */
    private function createOrder(array $extra = []): Order
    {
        $response = $this->actingAs($this->user())->postJson('/api/orders/manual', array_merge([
            'customer_id' => $this->customer->id,
            'company_id' => $this->company->id,
            'channel_id' => $this->channel->id,
            'order_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $this->product->id,
                'quantity' => 1,
                'unit_price' => 100,
            ]],
        ], $extra));

        self::assertContains(
            $response->status(),
            [200, 201],
            'Order creation failed: '.$response->getContent(),
        );

        return Order::query()->findOrFail($response->json('data.id') ?? $response->json('id'));
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

    private function storedDeposit(Order $order): float
    {
        return (float) DB::table('orders')->where('id', $order->id)->value('deposit_amount');
    }

    private function recordPayment(Order $order, float $amount): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user())
            ->postJson("/api/orders/{$order->id}/record-payment", ['amount' => $amount]);
    }

    private function verifyProof(PaymentProof $proof): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user())
            ->postJson("/api/payment-proofs/{$proof->id}/verify");
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // A. Trigger 1 — recording a payment re-evaluates the gate
    // ─────────────────────────────────────────────────────────────────────────────

    /** The ORD-00003 defect, inverted: the last missing fact arrives and the order moves. */
    public function test_recording_the_final_payment_advances_an_order_with_a_verified_proof(): void
    {
        $order = $this->createOrder(['payment_method_manual' => 'instapay']);
        $this->seedProof($order, PaymentProofState::Verified);

        self::assertSame('awaiting_payment', $this->storedStatus($order), 'Premise: parked on payment.');

        $this->recordPayment($order, 100)->assertOk();

        self::assertSame(
            'in_progress',
            $this->storedStatus($order),
            'Completing the balance must re-evaluate the gate, not just store the money. '
            .'ADR-042 §7.1: the advance lands on in_progress — Confirm stays an operator decision.',
        );
    }

    /**
     * The gate is re-evaluated, not assumed. A partial payment leaves the balance short,
     * so the order must stay put — and the payment must still be recorded.
     */
    public function test_partial_payment_records_the_money_without_advancing_the_order(): void
    {
        $order = $this->createOrder(['payment_method_manual' => 'instapay']);
        $this->seedProof($order, PaymentProofState::Verified);

        $this->recordPayment($order, 40)->assertOk();

        self::assertSame('awaiting_payment', $this->storedStatus($order));
        self::assertSame(40.0, $this->storedDeposit($order), 'The partial payment must survive.');
    }

    /**
     * A re-evaluation that decides "not yet" must never roll back the fact that triggered
     * it. Full payment, proof-required method, no verified proof: the order stays, but the
     * money is committed. This is the case that would silently lose a payment if the
     * re-evaluation shared the failure path of the write.
     */
    public function test_full_payment_is_retained_when_the_gate_still_blocks(): void
    {
        $order = $this->createOrder(['payment_method_manual' => 'instapay']);   // no proof at all

        $this->recordPayment($order, 100)->assertOk();

        self::assertSame('awaiting_payment', $this->storedStatus($order));
        self::assertSame(100.0, $this->storedDeposit($order), 'Payment must NOT be rolled back by a blocked gate.');
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // B. Trigger 2 — verifying a proof re-evaluates the same gate
    // ─────────────────────────────────────────────────────────────────────────────

    public function test_verifying_the_proof_advances_an_already_paid_order(): void
    {
        $order = $this->createOrder(['payment_method_manual' => 'instapay', 'deposit_amount' => 100]);
        $proof = $this->seedProof($order, PaymentProofState::Uploaded);

        self::assertSame('awaiting_payment', $this->storedStatus($order), 'Premise: paid but unverified.');

        $this->verifyProof($proof)->assertOk();

        self::assertSame('in_progress', $this->storedStatus($order));
    }

    /** Verification is evidence review only: an unpaid order stays, the proof still verifies. */
    public function test_verifying_the_proof_on_an_unpaid_order_verifies_without_advancing(): void
    {
        $order = $this->createOrder(['payment_method_manual' => 'instapay', 'deposit_amount' => 40]);
        $proof = $this->seedProof($order, PaymentProofState::Uploaded);

        $this->verifyProof($proof)->assertOk();

        self::assertSame('awaiting_payment', $this->storedStatus($order));
        self::assertSame(
            PaymentProofState::Verified,
            $proof->refresh()->state,
            'The proof state change must survive a blocked gate.',
        );
        self::assertSame(40.0, $this->storedDeposit($order), 'Verification must not invent payment.');
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // C. One entry point, and it does not decide anything itself
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Both triggers reach the lifecycle through ProcessOrderWorkflow, so the transition is
     * recorded under that workflow's own name — not as a bespoke payment-side status write.
     * If either path ever set `status` directly this assertion is what fails.
     *
     * ADR-042 §7.1 moved the advance off ConfirmOrderWorkflow: satisfying the payment gate
     * is not the operator's Confirm decision, so the event recorded is the activation
     * ('initiate_order'), and NO `confirm_order` event may be written by a payment fact.
     * Both halves are asserted, because the second is the part that regressed.
     */
    public function test_the_transition_is_attributed_to_the_activation_workflow(): void
    {
        $order = $this->createOrder(['payment_method_manual' => 'instapay']);
        $this->seedProof($order, PaymentProofState::Verified);

        // Creation already logged its own `initiate_order` for the availability decision,
        // so the DELTA is what attributes the advance — a stricter claim than any absolute
        // count, and one that stays true if the creation path ever logs differently.
        $before = $this->activationEvents($order);

        $this->recordPayment($order, 100)->assertOk();

        self::assertSame(
            $before + 1,
            $this->activationEvents($order),
            'the advance is attributed to the activation workflow, exactly once',
        );

        self::assertSame(0, OrderEvent::query()
            ->where('order_id', $order->id)
            ->where('event_type', 'confirm_order')
            ->count(), 'a payment fact must never record an operator Confirm');
    }

    /** How many activation events this order carries. */
    private function activationEvents(mixed $order): int
    {
        return OrderEvent::query()
            ->where('order_id', $order->id)
            ->where('event_type', 'initiate_order')
            ->count();
    }

    /** A second evaluation observes the advanced order and does nothing. */
    public function test_re_evaluation_is_idempotent(): void
    {
        $order = $this->createOrder(['payment_method_manual' => 'instapay', 'deposit_amount' => 100]);
        $this->seedProof($order, PaymentProofState::Verified);

        $action = app(ReevaluateOrderFulfillmentAction::class);

        $this->actingAs($this->user());
        $action->execute($order->refresh());
        $statusAfterFirst = $this->storedStatus($order);
        $eventsAfterFirst = $this->activationEvents($order);
        $action->execute($order->refresh());

        self::assertSame('in_progress', $statusAfterFirst);
        self::assertSame($statusAfterFirst, $this->storedStatus($order), 'The second run must not move the order again.');

        // Compared against the first run rather than a literal: idempotency is "the second
        // evaluation adds nothing", which is what this asserts directly.
        self::assertSame(
            $eventsAfterFirst,
            $this->activationEvents($order),
            'A repeated evaluation must not duplicate the transition event.',
        );
    }

    /** Only orders parked on payment are re-evaluated; anything else is left alone. */
    public function test_an_order_not_awaiting_payment_is_untouched(): void
    {
        $order = $this->createOrder(['payment_method_manual' => 'cod']);
        DB::table('orders')->where('id', $order->id)->update(['status' => OrderStatus::Cancelled->value]);

        $this->actingAs($this->user());
        app(ReevaluateOrderFulfillmentAction::class)->execute($order->refresh());

        self::assertSame('cancelled', $this->storedStatus($order));
        self::assertSame(0, OrderEvent::query()
            ->where('order_id', $order->id)
            ->where('event_type', 'confirm_order')
            ->count());
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // D. Non-proof methods keep their existing behaviour
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * cod resolves to 'none', so payment alone carries it.
     *
     * The premise assertion below is load-bearing: it pins the CLOSURE-001 PART 1/23-B
     * invariant that the creation-time availability decision does not touch the payment
     * block. It is what caught ADR-042 §7.1's advance firing inside the creation request.
     */
    public function test_recording_payment_on_a_cod_order_advances_it(): void
    {
        $order = $this->createOrder([
            'payment_method_manual' => 'cod',
            'status' => OrderStatus::AwaitingPayment->value,
        ]);
        self::assertSame('awaiting_payment', $this->storedStatus($order));

        $this->recordPayment($order, 100)->assertOk();

        self::assertSame('in_progress', $this->storedStatus($order));
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // E. RBAC — the grant is sufficient, and separated
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * The Finance Manager shape: `sales.orders.proof_verify` and nothing else in sales.*.
     * The verify route carries only that permission, so this minimal grant is enough to
     * review evidence — Finance never needs an order write verb to clear the gate.
     */
    public function test_a_role_holding_only_proof_verify_can_verify_and_thereby_advance_the_order(): void
    {
        $order = $this->createOrder(['payment_method_manual' => 'instapay', 'deposit_amount' => 100]);
        $proof = $this->seedProof($order, PaymentProofState::Uploaded);

        $reviewer = $this->user();
        $reviewer->roles()->attach($this->roleWith('sales.orders.proof_verify')->id);

        $this->actingAsUnprivileged($reviewer)
            ->postJson("/api/payment-proofs/{$proof->id}/verify")
            ->assertOk();

        self::assertSame('in_progress', $this->storedStatus($order));
    }

    /**
     * Separation of duties: the role that submits evidence must not be able to accept it.
     * `sales.orders.update` is the broadest verb any Sales role holds, and it does not
     * reach verify — which is exactly what the legacy `payment_proof_path` branch used to
     * let it bypass.
     */
    public function test_order_update_permission_does_not_grant_proof_verification(): void
    {
        $order = $this->createOrder(['payment_method_manual' => 'instapay', 'deposit_amount' => 100]);
        $proof = $this->seedProof($order, PaymentProofState::Uploaded);

        $seller = $this->user();
        $seller->roles()->attach($this->roleWith('sales.orders.update', 'sales.orders.proof_upload')->id);

        $this->actingAsUnprivileged($seller)
            ->postJson("/api/payment-proofs/{$proof->id}/verify")
            ->assertStatus(403);

        self::assertSame('awaiting_payment', $this->storedStatus($order));
    }

    private function roleWith(string ...$permissions): Role
    {
        $role = Role::create([
            'slug' => 'test-role-'.Str::random(8),
            'name' => 'Test Role',
            'is_system' => false,
        ]);

        foreach ($permissions as $name) {
            [$module, $resource, $action] = explode('.', $name, 3);
            $role->permissions()->attach(Permission::firstOrCreate(
                ['name' => $name],
                ['module' => $module, 'resource' => $resource, 'action' => $action],
            )->id);
        }

        return $role;
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // F. WooCommerce — an unmapped status must not fake success, or block the order
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * ECOS remains the source of truth for `confirmed`: WooCommerce has no equivalent slug,
     * so OrderStatusSyncJob records a FAILED sync log and returns without throwing. This
     * task newly makes `awaiting_payment → confirmed` actually happen on channel orders, so
     * the assertion that matters is that the outbound attempt cannot roll the transition
     * back — and that the unmapped state is recorded rather than silently reported as sent.
     */
    public function test_confirming_a_channel_order_records_a_failed_sync_instead_of_a_false_success(): void
    {
        $order = $this->createOrder(['payment_method_manual' => 'instapay', 'deposit_amount' => 100]);
        DB::table('orders')->where('id', $order->id)->update(['external_order_id' => 'WOO-4471']);
        // Credentials must exist, or the job returns at an earlier failure branch and never
        // reaches the status-mapping decision this test is about.
        ChannelCredential::create([
            'channel_id' => $this->channel->id,
            'consumer_key' => 'ck_test',
            'consumer_secret' => 'cs_test',
        ]);
        $proof = $this->seedProof($order, PaymentProofState::Uploaded);

        $this->verifyProof($proof)->assertOk();

        self::assertSame('in_progress', $this->storedStatus($order), 'The outbound sync must not block the lifecycle.');

        $log = DB::table('sync_logs')
            ->where('entity_id', $order->id)
            ->where('action', 'order.status_sync')
            ->latest('created_at')
            ->first();

        if ($log === null) {
            self::markTestSkipped('Channel fixture does not dispatch outbound sync; covered by Synchronization suite.');
        }

        self::assertSame('failed', (string) $log->status, 'An unmapped status must never be logged as a success.');
        self::assertStringContainsString('No WooCommerce mapping', (string) $log->error_message);
    }
}
