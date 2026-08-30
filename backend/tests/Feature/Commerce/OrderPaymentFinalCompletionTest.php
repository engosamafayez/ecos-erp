<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Admin\Configuration\Domain\Models\BrandPolicy;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\OrderImport\Application\Services\WooCommerceOrderImporter;
use Modules\Commerce\Orders\Application\Actions\ReevaluateOrderFulfillmentAction;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Enums\PaymentProofState;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderEvent;
use Modules\Commerce\Orders\Domain\Models\PaymentProof;
use Modules\Commerce\Orders\Domain\Services\PaymentFulfillmentGate;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-ORDERS-PAYMENT-CONFIRMATION-FULFILLMENT-FINAL-COMPLETION-001.
 *
 * Closes the gaps IMPLEMENTATION-002 left behind. Every case here is a way the D1-A control
 * could be satisfied, or escaped, at a moment nothing re-read it — not a restatement of the
 * two conditions themselves, which OrderPaymentContractImplementation002Test already covers.
 *
 *   S  supersession is a payment fact          (F1 — the control was undoable by `sales` alone)
 *   C  the channel is an input to the control  (F3 — the brand policy behind it decides)
 *   T  a proof belongs to exactly one tenant   (#15 — the gate had no company predicate)
 *   D  the reviewer is not the submitter       (SoD by identity, super-admin included)
 *   N  POST /api/orders                        (#10 — the path with no proven coverage)
 *   X  one trigger, one transition             (#14 — idempotence and the row lock)
 *
 * COD assertions are deliberately interleaved rather than grouped: each new trigger gets its own
 * `cod` counter-case, because the risk this suite guards against is a hardening that quietly
 * starts blocking the one method the business needs never to block.
 */
class OrderPaymentFinalCompletionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    private Product $product;

    /** Default brand policy applies: instapay/bank_transfer/mobile_wallet = 'required'. */
    private Channel $requiredChannel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->product = Product::factory()->create();
        $this->requiredChannel = Channel::factory()->create();
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Fixtures
    // ─────────────────────────────────────────────────────────────────────────────

    private function user(): User
    {
        return User::factory()->create(['company_id' => $this->company->id]);
    }

    /**
     * A channel whose brand explicitly declares instapay NON-blocking.
     *
     * This is the only honest way to test the channel trigger: with no stored policy every brand
     * falls back to the same defaults, so two default channels resolve identically and moving
     * between them would prove nothing. The row is written through the real policy store the gate
     * reads, then the config cache is flushed — `ConfigurationManager::getBrandPolicy()` memoises
     * per brand, so a policy created mid-test is otherwise invisible to the gate.
     */
    private function permissiveChannel(): Channel
    {
        $brand = Brand::factory()->create();

        $settings = BrandPolicy::defaultSettings('order');
        $settings['payment_proof_policy']['instapay'] = 'none';

        BrandPolicy::create([
            'brand_id' => $brand->id,
            'company_id' => $brand->company_id,
            'policy_group' => 'order',
            'settings' => $settings,
            'version' => 1,
            'is_active' => true,
        ]);

        Cache::flush();

        return Channel::factory()->create([
            'brand_id' => $brand->id,
            'company_id' => $brand->company_id,
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function createOrder(array $extra = [], float $unitPrice = 100): Order
    {
        $payload = array_merge([
            'customer_id' => $this->customer->id,
            'company_id' => $this->company->id,
            'channel_id' => $this->requiredChannel->id,
            'order_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $this->product->id,
                'quantity' => 1,
                'unit_price' => $unitPrice,
            ]],
        ], $extra);

        $response = $this->actingAs($this->user())->postJson('/api/orders/manual', $payload);

        self::assertContains(
            $response->status(),
            [200, 201],
            'Order creation failed: '.$response->getContent(),
        );

        return Order::query()->findOrFail($response->json('data.id') ?? $response->json('id'));
    }

    private function seedProof(Order $order, PaymentProofState $state, ?string $uploadedBy = null): PaymentProof
    {
        return PaymentProof::create([
            'company_id' => $order->company_id,
            'order_id' => $order->id,
            'state' => $state,
            'storage_disk' => 'local',
            'storage_path' => 'payment-proofs/'.$order->id.'/evidence.jpg',
            'original_filename' => 'evidence.jpg',
            'uploaded_by' => $uploadedBy,
            'uploaded_at' => now(),
            'verified_at' => $state === PaymentProofState::Verified ? now() : null,
        ]);
    }

    /** Reads the raw column, never the accessor — the stored value is the claim. */
    private function storedStatus(Order $order): string
    {
        return (string) DB::table('orders')->where('id', $order->id)->value('status');
    }

    private function setDeposit(Order $order, float $amount): void
    {
        DB::table('orders')->where('id', $order->id)->update(['deposit_amount' => $amount]);
    }

    /** @param array<string, mixed> $overrides */
    private function putOrder(Order $order, array $overrides): \Illuminate\Testing\TestResponse
    {
        $order->refresh();

        $payload = array_merge([
            'customer_id' => $order->customer_id,
            'channel_id' => $order->channel_id,
            'order_date' => $order->order_date instanceof DateTimeInterface
                ? $order->order_date->format('Y-m-d')
                : (string) $order->order_date,
            'status' => $order->status->value,
            'payment_method_manual' => $order->payment_method_manual,
            'lines' => [[
                'product_id' => $this->product->id,
                'quantity' => 1,
                'unit_price' => 100,
            ]],
        ], $overrides);

        return $this->actingAs($this->user())->putJson("/api/orders/{$order->id}", $payload);
    }

    /** Drives the order to a fulfilment-eligible status the way the contract sanctions. */
    private function clearedInstapayOrder(): Order
    {
        $order = $this->createOrder([
            'payment_method_manual' => 'instapay',
            'status' => OrderStatus::InProgress->value,
        ]);

        $this->setDeposit($order, 100);
        $this->seedProof($order, PaymentProofState::Verified);

        app(ReevaluateOrderFulfillmentAction::class)->execute($order->refresh());

        $order->refresh();

        self::assertContains(
            $order->status,
            OrderStatus::fulfilmentEligible(),
            'Fixture precondition failed: the order should be fulfilment-eligible before the escape is attempted.',
        );

        return $order;
    }

    private function uploadProof(Order $order): \Illuminate\Testing\TestResponse
    {
        Storage::fake('local');

        return $this->actingAs($this->user())->postJson(
            "/api/orders/{$order->id}/payment-proofs",
            ['file' => UploadedFile::fake()->image('replacement.jpg')],
        );
    }

    // ═════════════════════════════════════════════════════════════════════════════
    // S. Supersession is a payment fact (F1)
    // ═════════════════════════════════════════════════════════════════════════════

    /**
     * THE defect this task exists for.
     *
     * `sales` holds `proof_upload` and nothing else. Before the fix it could replace the verified
     * proof that cleared the gate — superseding it, so `hasVerifiedProof()` turned false — while
     * the order kept the fulfilment-eligible status that proof had bought. A control Finance had
     * to clear was undoable without Finance.
     */
    public function test_s1_superseding_the_verified_proof_returns_the_order_to_awaiting_payment(): void
    {
        $order = $this->clearedInstapayOrder();

        $this->uploadProof($order)->assertSuccessful();

        self::assertSame(OrderStatus::AwaitingPayment->value, $this->storedStatus($order));
    }

    /** The supersession is real, not just the status: no active verified proof survives. */
    public function test_s2_the_replaced_proof_is_superseded_and_no_verified_proof_remains_active(): void
    {
        $order = $this->clearedInstapayOrder();

        $this->uploadProof($order)->assertSuccessful();

        self::assertFalse(
            app(PaymentFulfillmentGate::class)->hasVerifiedProof($order->refresh()),
            'A superseded proof must not keep clearing the gate.',
        );
        self::assertSame(1, PaymentProof::query()->where('order_id', $order->id)->whereNotNull('superseded_at')->count());
    }

    /**
     * A FIRST upload is deliberately not a trigger, and this pins that down rather than leaving
     * it to the implementation. With no prior proof neither condition can have moved — the new
     * proof is created `uploaded`, never `verified` — so the order must be left exactly as it was.
     */
    public function test_s3_a_first_upload_does_not_transition_anything(): void
    {
        $order = $this->createOrder([
            'payment_method_manual' => 'instapay',
            'status' => OrderStatus::AwaitingPayment->value,
        ]);

        $this->uploadProof($order)->assertSuccessful();

        self::assertSame(OrderStatus::AwaitingPayment->value, $this->storedStatus($order));
        self::assertSame(0, PaymentProof::query()->where('order_id', $order->id)->whereNotNull('superseded_at')->count());
    }

    /** COD regression: the method needs no proof, so replacing one must not disturb the order. */
    public function test_s4_superseding_a_proof_on_a_cod_order_changes_nothing(): void
    {
        $order = $this->createOrder([
            'payment_method_manual' => 'cod',
            'status' => OrderStatus::InProgress->value,
        ]);

        $this->seedProof($order, PaymentProofState::Verified);
        $this->uploadProof($order)->assertSuccessful();

        self::assertSame(OrderStatus::InProgress->value, $this->storedStatus($order));
    }

    /** The return is the canonical workflow's, not a status write smuggled into the upload. */
    public function test_s5_the_return_is_performed_by_the_canonical_workflow(): void
    {
        $order = $this->clearedInstapayOrder();

        $this->uploadProof($order)->assertSuccessful();

        self::assertTrue(
            OrderEvent::query()
                ->where('order_id', $order->id)
                ->where('event_type', 'payment_proof_uploaded')
                ->exists(),
            'The upload itself must still be audited.',
        );
        self::assertSame(OrderStatus::AwaitingPayment->value, $this->storedStatus($order));
    }

    // ═════════════════════════════════════════════════════════════════════════════
    // C. The channel is an input to the control (F3)
    // ═════════════════════════════════════════════════════════════════════════════

    /**
     * Moving an order to a channel whose brand requires proof must re-apply the control.
     *
     * The payment method never changes here — only the policy that resolves it. Watching
     * `payment_method_manual` alone left this half of the same control unevaluated.
     */
    public function test_c1_moving_to_a_proof_requiring_channel_returns_the_order_to_awaiting_payment(): void
    {
        $permissive = $this->permissiveChannel();

        $order = $this->createOrder([
            'channel_id' => $permissive->id,
            'payment_method_manual' => 'instapay',
            'status' => OrderStatus::InProgress->value,
        ]);

        self::assertSame(OrderStatus::InProgress->value, $this->storedStatus($order));

        $this->putOrder($order, ['channel_id' => $this->requiredChannel->id])->assertSuccessful();

        self::assertSame(OrderStatus::AwaitingPayment->value, $this->storedStatus($order));
    }

    /** And the other direction: the trigger is not one-way. */
    public function test_c2_moving_to_a_permissive_channel_releases_a_parked_order(): void
    {
        $order = $this->createOrder([
            'payment_method_manual' => 'instapay',
            'status' => OrderStatus::AwaitingPayment->value,
        ]);

        self::assertSame(OrderStatus::AwaitingPayment->value, $this->storedStatus($order));

        $this->putOrder($order, ['channel_id' => $this->permissiveChannel()->id])->assertSuccessful();

        self::assertNotSame(
            OrderStatus::AwaitingPayment->value,
            $this->storedStatus($order),
            'A channel whose policy does not require proof must let the order out of awaiting_payment.',
        );
    }

    /** COD regression: cod resolves to 'none' on every brand, so no channel move can park it. */
    public function test_c3_a_channel_move_never_parks_a_cod_order(): void
    {
        $order = $this->createOrder([
            'channel_id' => $this->permissiveChannel()->id,
            'payment_method_manual' => 'cod',
            'status' => OrderStatus::InProgress->value,
        ]);

        $this->putOrder($order, ['channel_id' => $this->requiredChannel->id])->assertSuccessful();

        self::assertSame(OrderStatus::InProgress->value, $this->storedStatus($order));
    }

    // ═════════════════════════════════════════════════════════════════════════════
    // T. A proof belongs to exactly one tenant (#15)
    // ═════════════════════════════════════════════════════════════════════════════

    /**
     * The gate filtered on `order_id` alone, and `PaymentProof` carries no tenant global scope,
     * so the only tenant check on the lifecycle lived in the controller. A row carrying another
     * company's `company_id` would have cleared a financial control.
     */
    public function test_t1_a_proof_owned_by_another_company_does_not_satisfy_the_gate(): void
    {
        $order = $this->createOrder([
            'payment_method_manual' => 'instapay',
            'status' => OrderStatus::AwaitingPayment->value,
        ]);
        $this->setDeposit($order, 100);

        $foreign = Company::factory()->create();

        PaymentProof::create([
            'company_id' => $foreign->id,
            'order_id' => $order->id,
            'state' => PaymentProofState::Verified,
            'storage_disk' => 'local',
            'storage_path' => 'payment-proofs/foreign/evidence.jpg',
            'original_filename' => 'evidence.jpg',
            'uploaded_at' => now(),
            'verified_at' => now(),
        ]);

        $gate = app(PaymentFulfillmentGate::class);
        $order->refresh();

        self::assertFalse($gate->hasVerifiedProof($order));
        self::assertFalse($gate->permits($order));

        app(ReevaluateOrderFulfillmentAction::class)->execute($order);

        self::assertSame(OrderStatus::AwaitingPayment->value, $this->storedStatus($order));
    }

    /** The counter-case: the same proof, owned correctly, does clear it. */
    public function test_t2_a_proof_owned_by_the_orders_company_still_satisfies_the_gate(): void
    {
        $order = $this->createOrder([
            'payment_method_manual' => 'instapay',
            'status' => OrderStatus::AwaitingPayment->value,
        ]);
        $this->setDeposit($order, 100);
        $this->seedProof($order, PaymentProofState::Verified);

        self::assertTrue(app(PaymentFulfillmentGate::class)->permits($order->refresh()));
    }

    // ═════════════════════════════════════════════════════════════════════════════
    // D. The reviewer is not the submitter — SoD by identity
    // ═════════════════════════════════════════════════════════════════════════════

    /**
     * The role catalogue already keeps `proof_upload` and `proof_verify` in disjoint roles, but
     * that is configuration, not a control: it holds only while nobody is assigned one role from
     * each column, and `RequirePermissionMiddleware` passes any `is_system` role unconditionally.
     *
     * `actingAs()` grants exactly such a system role, so this test is also the super-admin case —
     * the request sails through the middleware and is refused by the action, which is the point.
     */
    public function test_d1_the_uploader_cannot_verify_their_own_proof(): void
    {
        $order = $this->createOrder(['payment_method_manual' => 'instapay']);
        $uploader = $this->user();
        $proof = $this->seedProof($order, PaymentProofState::Uploaded, (string) $uploader->id);

        $this->actingAs($uploader)
            ->postJson("/api/payment-proofs/{$proof->id}/verify")
            ->assertStatus(403);

        self::assertSame(
            PaymentProofState::Uploaded->value,
            (string) DB::table('payment_proofs')->where('id', $proof->id)->value('state'),
        );
    }

    /** Reject is the other half of one reviewer capability and is refused the same way. */
    public function test_d2_the_uploader_cannot_reject_their_own_proof(): void
    {
        $order = $this->createOrder(['payment_method_manual' => 'instapay']);
        $uploader = $this->user();
        $proof = $this->seedProof($order, PaymentProofState::Uploaded, (string) $uploader->id);

        $this->actingAs($uploader)
            ->postJson("/api/payment-proofs/{$proof->id}/reject", ['reason' => 'Illegible.'])
            ->assertStatus(403);

        self::assertSame(
            PaymentProofState::Uploaded->value,
            (string) DB::table('payment_proofs')->where('id', $proof->id)->value('state'),
        );
    }

    /** A different reviewer is unaffected — the rule separates people, it does not block review. */
    public function test_d3_a_different_reviewer_may_verify(): void
    {
        $order = $this->createOrder(['payment_method_manual' => 'instapay']);
        $uploader = $this->user();
        $proof = $this->seedProof($order, PaymentProofState::Uploaded, (string) $uploader->id);

        $this->actingAs($this->user())
            ->postJson("/api/payment-proofs/{$proof->id}/verify")
            ->assertSuccessful();

        self::assertSame(
            PaymentProofState::Verified->value,
            (string) DB::table('payment_proofs')->where('id', $proof->id)->value('state'),
        );
    }

    /**
     * An unattributed proof is reviewable. Documented, not accidental: the comparison needs two
     * identities, and the upload route sits behind `auth:sanctum`, so a NULL uploader can only
     * come from a console or test path where there is no submitter to be independent of.
     */
    public function test_d4_a_proof_with_no_recorded_uploader_may_still_be_reviewed(): void
    {
        $order = $this->createOrder(['payment_method_manual' => 'instapay']);
        $proof = $this->seedProof($order, PaymentProofState::Uploaded, null);

        $this->actingAs($this->user())
            ->postJson("/api/payment-proofs/{$proof->id}/verify")
            ->assertSuccessful();
    }

    // ═════════════════════════════════════════════════════════════════════════════
    // N. POST /api/orders (#10)
    // ═════════════════════════════════════════════════════════════════════════════

    /**
     * The creation path nothing had ever exercised against the control.
     *
     * `CreateOrderAction` consults `permitsAtCreation()`, but the guard is inert by construction:
     * `OrderDTO` declares no payment-method field, so a method supplied in the request is dropped
     * before the action sees it. This test pins the REASON the path is safe rather than the guard,
     * because the guard passing proves nothing while its input is always null. If the DTO ever
     * gains a payment method this test fails, which is exactly when the guard starts mattering.
     */
    public function test_n1_the_standard_creation_endpoint_cannot_store_a_proof_required_method(): void
    {
        $response = $this->actingAs($this->user())->postJson('/api/orders', [
            'customer_id' => $this->customer->id,
            'channel_id' => $this->requiredChannel->id,
            'order_date' => now()->toDateString(),
            'status' => OrderStatus::InProgress->value,
            'payment_method_manual' => 'instapay',
            'lines' => [[
                'product_id' => $this->product->id,
                'quantity' => 1,
                'unit_price' => 100,
            ]],
        ]);

        self::assertContains($response->status(), [200, 201], $response->getContent());

        $order = Order::query()->findOrFail($response->json('data.id') ?? $response->json('id'));

        self::assertNull($order->payment_method_manual);
        self::assertNull($order->payment_method);
        self::assertSame(
            '',
            app(PaymentFulfillmentGate::class)->methodOf($order),
            'With no method stored there is no proof requirement to bypass on this path.',
        );
        self::assertTrue(app(PaymentFulfillmentGate::class)->permits($order));
    }

    // ═════════════════════════════════════════════════════════════════════════════
    // X. One trigger, one transition (#14)
    // ═════════════════════════════════════════════════════════════════════════════

    /**
     * The row lock is the thing that makes concurrent triggers safe, so it is asserted directly
     * rather than inferred. Two triggers can fire at once — a payment write, a proof
     * verification, a supersession and a channel edit are independent user actions — and each
     * would otherwise read the same stale status, all pass, and all transition.
     *
     * NOTE ON SCOPE: this proves the lock is TAKEN, and the case below proves the decision is
     * idempotent. It does not execute two requests in parallel; PHPUnit runs the suite inside one
     * transaction, so a second connection could not see the fixture at all.
     */
    public function test_x1_re_evaluation_locks_the_order_row_for_the_decision(): void
    {
        $order = $this->createOrder([
            'payment_method_manual' => 'instapay',
            'status' => OrderStatus::AwaitingPayment->value,
        ]);

        $locking = [];
        DB::listen(function ($query) use (&$locking): void {
            if (stripos($query->sql, 'for update') !== false && stripos($query->sql, 'orders') !== false) {
                $locking[] = $query->sql;
            }
        });

        app(ReevaluateOrderFulfillmentAction::class)->execute($order->refresh());

        self::assertNotEmpty($locking, 'The decision must be made under a row lock on the order.');
    }

    /** Repeating a trigger must not transition twice or duplicate the audit trail. */
    public function test_x2_repeating_a_trigger_produces_exactly_one_transition(): void
    {
        $order = $this->clearedInstapayOrder();
        $statusAfterFirst = $this->storedStatus($order);

        $before = OrderEvent::query()->where('order_id', $order->id)->count();

        app(ReevaluateOrderFulfillmentAction::class)->execute($order->refresh());
        app(ReevaluateOrderFulfillmentAction::class)->execute($order->refresh());

        self::assertSame($statusAfterFirst, $this->storedStatus($order));
        self::assertSame(
            $before,
            OrderEvent::query()->where('order_id', $order->id)->count(),
            'A no-op re-evaluation must write no lifecycle event.',
        );
    }

    /** Repeating the SUPERSESSION trigger is likewise a no-op once the order is already parked. */
    public function test_x3_repeating_the_supersession_trigger_is_a_no_op(): void
    {
        $order = $this->clearedInstapayOrder();

        $this->uploadProof($order)->assertSuccessful();
        self::assertSame(OrderStatus::AwaitingPayment->value, $this->storedStatus($order));

        $this->uploadProof($order)->assertSuccessful();
        self::assertSame(OrderStatus::AwaitingPayment->value, $this->storedStatus($order));
    }

    // ═════════════════════════════════════════════════════════════════════════════
    // W. WooCommerce import — the third creation path (F2)
    // ═════════════════════════════════════════════════════════════════════════════

    /** @return array<string, mixed> */
    private function wooOrder(string $gateway, string $wooStatus = 'processing', int $wooId = 9001): array
    {
        return [
            'id' => $wooId,
            'number' => (string) $wooId,
            'status' => $wooStatus,
            'date_created' => '2026-08-23T10:00:00',
            'customer_note' => '',
            'total' => '100.00',
            'shipping_total' => '0',
            'discount_total' => '0',
            'total_tax' => '0',
            'billing' => [
                'first_name' => 'Ahmed',
                'last_name' => 'Ali',
                'email' => 'ahmed.import@example.com',
                'phone' => '01099998888',
                'country' => 'EG',
                'city' => 'Cairo',
                'address_1' => '1 Tahrir Square',
                'company' => '',
                'state' => '',
                'address_2' => '',
                'postcode' => '',
            ],
            'shipping' => [],
            'shipping_lines' => [],
            'payment_method' => $gateway,
            'payment_method_title' => $gateway,
            'transaction_id' => '',
            'date_paid' => '',
            'line_items' => [[
                'product_id' => 99,
                'sku' => $this->product->sku,
                'name' => 'Test Product',
                'quantity' => 1,
                'price' => '100.00',
                'subtotal' => '100.00',
                'total' => '100.00',
            ]],
            'fee_lines' => [],
            'coupon_lines' => [],
            'tax_lines' => [],
        ];
    }

    private function importChannel(): Channel
    {
        $brand = Brand::factory()->create(['company_id' => $this->company->id]);

        return Channel::factory()->create([
            'brand_id' => $brand->id,
            'company_id' => $this->company->id,
        ]);
    }

    private function importedOrder(string $gateway, string $wooStatus = 'processing'): Order
    {
        $channel = $this->importChannel();

        self::assertTrue(
            app(WooCommerceOrderImporter::class)->importSingle($channel, $this->wooOrder($gateway, $wooStatus)),
            'Import did not create an order.',
        );

        return Order::query()->where('external_order_id', '9001')->firstOrFail();
    }

    /**
     * Woo `processing` maps to `in_progress`, which is fulfilment-eligible. With a proof-required
     * gateway and no payment and no proof, that was a fully-formed D1-A bypass on an inbound,
     * unauthenticated path that nothing downstream ever re-evaluates.
     */
    public function test_w1_a_proof_required_gateway_is_imported_awaiting_payment(): void
    {
        self::assertSame(
            OrderStatus::AwaitingPayment->value,
            $this->importedOrder('instapay')->status->value,
        );
    }

    /** COD regression on the import path — the business's default method must import unchanged. */
    public function test_w2_a_cod_gateway_is_imported_unchanged(): void
    {
        self::assertSame(
            OrderStatus::InProgress->value,
            $this->importedOrder('cod')->status->value,
        );
    }

    /**
     * The RESIDUAL GAP, asserted rather than left implicit.
     *
     * `bacs` is a real WooCommerce bank-transfer gateway id, and it is not an ECOS policy key, so
     * the gate resolves it to `'none'` and the order imports fulfilment-eligible. That is the
     * certified key-miss contract, not a regression — but it means this change binds the control
     * only where a gateway id happens to equal a policy key. Pinning it here keeps the limit
     * visible: if someone later adds a gateway mapping, this test is where the decision surfaces.
     */
    public function test_w3_an_unmapped_gateway_id_is_not_covered_by_the_control(): void
    {
        $order = $this->importedOrder('bacs');

        self::assertSame(OrderStatus::InProgress->value, $order->status->value);
        self::assertSame(
            'none',
            app(PaymentFulfillmentGate::class)->requirementFor('bacs', (string) $order->channel_id, (string) $order->company_id),
        );
    }

    /** Tenancy: the imported order belongs to the company behind the channel's brand. */
    public function test_w4_an_imported_order_is_owned_by_the_channels_company(): void
    {
        $order = $this->importedOrder('cod');

        self::assertSame($this->company->id, $order->company_id);
    }

    /**
     * Fail-closed. A channel with no brand resolves to no company, and an order with no owner is
     * a row no tenant control can see — so the import is refused rather than written untenanted.
     */
    public function test_w5_a_channel_with_no_brand_refuses_to_import(): void
    {
        $channel = Channel::factory()->create([
            'brand_id' => null,
            'company_id' => $this->company->id,
        ]);

        $this->expectException(RuntimeException::class);

        app(WooCommerceOrderImporter::class)->importSingle($channel, $this->wooOrder('cod'));
    }
}
