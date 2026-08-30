<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Enums\PaymentProofState;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\PaymentProof;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-ORDERS-INVENTORY-MANUAL-REMEDIATION-001 — Finding 06.
 *
 * "Confirm Data" (and any confirm path) must not move an order out of
 * `awaiting_payment` while payment is still outstanding. The gate lives in
 * ConfirmOrderWorkflow::guard().
 *
 * CONTRACT AS OF TASK-ORDERS-PAYMENT-CONFIRMATION-FULFILLMENT-IMPLEMENTATION-001
 * (Decisions 1 + 3) — the three conditions used to be OR-ed; for proof-required
 * methods the first two are now AND-ed:
 *
 *   method requires no proof (cod/cash → 'none', credit_card → 'optional')
 *       → unchanged, does not block
 *   method REQUIRES proof (instapay/bank_transfer/mobile_wallet)
 *       → paid in full AND an active VERIFIED row in `payment_proofs`
 *
 * Two tests below previously asserted the OR behaviour and now assert the AND
 * behaviour; they are kept (not deleted) because the scenarios they cover — paid
 * without proof, and a bare `payment_proof_path` string — are precisely the two
 * ways the gate used to be cleared without evidence. No new payment status is
 * introduced and no non-proof method is tightened.
 *
 * Every order here is created through the REAL manual-order endpoint and pushed
 * through the REAL confirm endpoints — the bug reached production precisely
 * because the guard was never exercised end-to-end.
 */
class OrderPaymentConfirmationGateTest extends TestCase
{
    use RefreshDatabase;

    private const CREATE = '/api/orders/manual';

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
        // A channel carries the brand whose policy governs payment-proof rules.
        // With no stored policy the brand defaults apply: instapay/bank_transfer =
        // 'required', cod = 'none', credit_card = 'optional'.
        $this->channel = Channel::factory()->create();
    }

    private function user(): User
    {
        return User::factory()->create(['company_id' => $this->company->id]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(array $extra = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id,
            'company_id' => $this->company->id,
            'channel_id' => $this->channel->id,
            'order_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $this->product->id,
                'quantity' => 1,
                'unit_price' => 100,
            ]],
        ], $extra);
    }

    /** @param array<string, mixed> $extra */
    private function createOrder(array $extra = []): Order
    {
        $response = $this->actingAs($this->user())->postJson(self::CREATE, $this->payload($extra));

        self::assertContains(
            $response->status(),
            [200, 201],
            'Order creation failed: '.$response->getContent(),
        );

        $id = $response->json('data.id') ?? $response->json('id');

        return Order::query()->findOrFail($id);
    }

    private function storedStatus(Order $order): string
    {
        return (string) DB::table('orders')->where('id', $order->id)->value('status');
    }

    private function confirmData(Order $order): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user())->postJson("/api/orders/{$order->id}/confirm-customer", [
            'communication_method' => 'phone',
            'result' => 'confirmed',
        ]);
    }

    // ── 1. Unpaid + proof-required method → Confirm Data is BLOCKED (the bug) ────

    public function test_unpaid_proof_required_order_cannot_be_confirmed_by_confirm_data(): void
    {
        // instapay requires proof; none supplied → creation gate parks it in awaiting_payment.
        $order = $this->createOrder(['payment_method_manual' => 'instapay']);
        self::assertSame('awaiting_payment', $this->storedStatus($order), 'Premise: order must start awaiting_payment.');

        $response = $this->confirmData($order);

        $response->assertStatus(422);
        self::assertSame(
            'awaiting_payment',
            $this->storedStatus($order),
            'Confirm Data must NOT move an unpaid, proof-required order to confirmed.',
        );
    }

    // ── 2. COD → Confirm is allowed (proof requirement 'none') ──────────────────

    public function test_cod_order_can_be_confirmed_from_awaiting_payment(): void
    {
        $order = $this->createOrder([
            'payment_method_manual' => 'cod',
            'status' => OrderStatus::AwaitingPayment->value,
        ]);
        self::assertSame('awaiting_payment', $this->storedStatus($order), 'Premise: order must start awaiting_payment.');

        $this->confirmData($order)->assertOk();

        self::assertSame('confirmed', $this->storedStatus($order));
    }

    // ── 3. Paid in full but NO verified proof → still BLOCKED (contract change) ────

    /**
     * This test asserted the OPPOSITE before Decision 1: a fully-paid instapay order
     * confirmed with no proof at all, because "paid in full" was evaluated first and
     * short-circuited the required-proof policy. Money alone can no longer satisfy a
     * REQUIRED-proof method — payment says the amount arrived, the verified proof says
     * it arrived from this customer for this order.
     */
    public function test_paid_order_is_blocked_when_method_requires_proof_and_none_is_verified(): void
    {
        $order = $this->createOrder([
            'payment_method_manual' => 'instapay',
            'deposit_amount' => 100,
        ]);
        self::assertSame('awaiting_payment', $this->storedStatus($order), 'Premise: order must start awaiting_payment.');

        $this->confirmData($order)->assertStatus(422);

        self::assertSame(
            'awaiting_payment',
            $this->storedStatus($order),
            'Full payment must NOT by itself clear a REQUIRED-proof method.',
        );
    }

    // ── 4. Legacy payment_proof_path alone → BLOCKED (contract change) ───────────

    /**
     * Also inverted by Decision 1. `orders.payment_proof_path` is an unvalidated free-text
     * column (`nullable|string|max:500`) with no storage, tenant, existence or MIME check,
     * so any non-empty value used to clear a REQUIRED-proof gate with zero money and zero
     * evidence — needing only `sales.orders.update`, never `sales.orders.proof_verify`.
     * `payment_proofs` is now the only source of proof truth.
     */
    public function test_legacy_payment_proof_path_no_longer_satisfies_a_required_proof_method(): void
    {
        $order = $this->createOrder(['payment_method_manual' => 'instapay']);
        self::assertSame('awaiting_payment', $this->storedStatus($order));

        DB::table('orders')->where('id', $order->id)->update(['payment_proof_path' => 'proofs/receipt.jpg']);

        $this->confirmData($order->refresh())->assertStatus(422);

        self::assertSame(
            'awaiting_payment',
            $this->storedStatus($order),
            'A bare path string must not constitute an accepted payment proof.',
        );
    }

    // ── 5. Paid in full AND verified proof → confirms (the positive path) ─────────

    public function test_paid_order_with_verified_proof_can_be_confirmed(): void
    {
        $order = $this->createOrder([
            'payment_method_manual' => 'instapay',
            'deposit_amount' => 100,
        ]);
        $this->seedProof($order, PaymentProofState::Verified);

        $this->confirmData($order->refresh())->assertOk();

        self::assertSame('confirmed', $this->storedStatus($order));
    }

    // ── 6. Uploaded-but-unverified proof → BLOCKED ──────────────────────────────

    /** Evidence submitted is not evidence accepted; only VERIFIED clears the gate. */
    public function test_uploaded_but_unverified_proof_does_not_clear_the_gate(): void
    {
        $order = $this->createOrder([
            'payment_method_manual' => 'instapay',
            'deposit_amount' => 100,
        ]);
        $this->seedProof($order, PaymentProofState::Uploaded);

        $this->confirmData($order->refresh())->assertStatus(422);

        self::assertSame('awaiting_payment', $this->storedStatus($order));
    }

    // ── 7. Rejected proof → BLOCKED ───────────────────────────────────────────────

    public function test_rejected_proof_does_not_clear_the_gate(): void
    {
        $order = $this->createOrder([
            'payment_method_manual' => 'instapay',
            'deposit_amount' => 100,
        ]);
        $this->seedProof($order, PaymentProofState::Rejected);

        $this->confirmData($order->refresh())->assertStatus(422);

        self::assertSame('awaiting_payment', $this->storedStatus($order));
    }

    // ── 8. Verified but SUPERSEDED proof → BLOCKED ───────────────────────────────

    /**
     * Uploading a replacement supersedes the previous proof even when that proof was
     * already verified, so the order must fall back behind the gate until the new
     * evidence is verified in its own right.
     */
    public function test_superseded_verified_proof_does_not_clear_the_gate(): void
    {
        $order = $this->createOrder([
            'payment_method_manual' => 'instapay',
            'deposit_amount' => 100,
        ]);
        $this->seedProof($order, PaymentProofState::Verified, supersededAt: now());

        $this->confirmData($order->refresh())->assertStatus(422);

        self::assertSame('awaiting_payment', $this->storedStatus($order));
    }

    // ── 9. Verified proof but UNPAID → BLOCKED (both conditions are required) ─────

    public function test_verified_proof_without_full_payment_does_not_clear_the_gate(): void
    {
        $order = $this->createOrder([
            'payment_method_manual' => 'instapay',
            'deposit_amount' => 40,   // 40 of 100
        ]);
        $this->seedProof($order, PaymentProofState::Verified);

        $this->confirmData($order->refresh())->assertStatus(422);

        self::assertSame(
            'awaiting_payment',
            $this->storedStatus($order),
            'A verified proof must not substitute for the outstanding balance.',
        );
    }

    // ── 10. Non-required methods are NOT tightened ───────────────────────────────

    /**
     * Explicit regression guard for the half of the contract that deliberately did not
     * change: credit_card resolves to 'optional', so it confirms with no proof and no
     * payment exactly as it did before. Test 2 above covers cod → 'none'.
     */
    public function test_optional_proof_method_still_confirms_without_payment_or_proof(): void
    {
        $order = $this->createOrder([
            'payment_method_manual' => 'credit_card',
            'status' => OrderStatus::AwaitingPayment->value,
        ]);
        self::assertSame('awaiting_payment', $this->storedStatus($order));

        $this->confirmData($order)->assertOk();

        self::assertSame('confirmed', $this->storedStatus($order));
    }

    /**
     * Seeds a proof row directly. The proof is the INPUT to the guard here, not the unit
     * under test — the upload/verify endpoints have their own coverage in
     * PaymentProofLifecycleTest and in the re-evaluation suite.
     */
    private function seedProof(Order $order, PaymentProofState $state, ?\Illuminate\Support\Carbon $supersededAt = null): PaymentProof
    {
        return PaymentProof::create([
            'company_id' => $order->company_id,
            'order_id' => $order->id,
            'state' => $state,
            'storage_disk' => 'public',
            'storage_path' => 'payment-proofs/'.$order->id.'/evidence.jpg',
            'original_filename' => 'evidence.jpg',
            'uploaded_at' => now(),
            'superseded_at' => $supersededAt,
        ]);
    }
}
