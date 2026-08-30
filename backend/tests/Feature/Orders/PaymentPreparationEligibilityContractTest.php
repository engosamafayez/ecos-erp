<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Application\Actions\ReevaluateOrderFulfillmentAction;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Services\PaymentFulfillmentGate;
use Modules\Inventory\InventoryItems\Domain\Models\InventoryItem;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Operations\Fulfillment\Application\FulfillmentEngine;
use Modules\Operations\Fulfillment\Application\Workflows\CancelOrderWorkflow;
use Modules\Operations\Fulfillment\Application\Workflows\ConfirmOrderWorkflow;
use Modules\Operations\Fulfillment\Application\Workflows\ProcessOrderWorkflow;
use Modules\Operations\Fulfillment\Application\Workflows\SetEarlyStatusWorkflow;
use Modules\Operations\Preparation\Application\Services\WaveEngine\WaveMembershipService;
use Modules\Operations\Preparation\Domain\Models\PreparationWave;
use Modules\Operations\Preparation\Domain\Models\WaveEngineConfiguration;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;
use Throwable;

/**
 * TASK-ORDER-PAYMENT-PREPARATION — the approved contract, encoded.
 *
 * Owner decisions covered:
 *   Q1  ADR-042 amendments A/B/C — the payment trigger advances to `in_progress`, never
 *       automatically to `confirmed`.
 *   Q2/M1  The advance is gated INSIDE ProcessOrderWorkflow, and
 *       `OrderStatus::advancesToInProgressOnReservation()` still EXCLUDES AwaitingPayment —
 *       that exclusion is the financial backstop for the generic status-patch route
 *       (blocker BL-1).
 *   Q3  O1 + O3 — blanking the payment method is rejected at the edge, and the gate fails
 *       closed if no effective method exists.
 *   RC-3  Preparation Wave membership is evicted when an admitted order becomes ineligible.
 *   Q4  NULL-warehouse orders are a separate operational exception, never wave members.
 */
class PaymentPreparationEligibilityContractTest extends TestCase
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

    // ─────────────────────────────────────────────────────────────────────────
    // The financial control (Q2/M1 + Q3)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Matrix 1 — AwaitingPayment + COD: a reservation-triggered run MAY advance.
     *
     * COD resolves to a proof requirement of `none` (D2-B), so the gate permits and the
     * order enters the operational queue.
     */
    public function test_awaiting_payment_with_cod_advances_to_in_progress(): void
    {
        $order = $this->order(OrderStatus::AwaitingPayment, 'cod');

        $this->runProcessWorkflow($order);

        self::assertSame(
            OrderStatus::InProgress,
            $order->refresh()->status,
            'a gate-satisfying COD order must enter in_progress',
        );
    }

    /**
     * Matrix 2 — AwaitingPayment + proof-required method, no proof: must NOT advance.
     *
     * This is the control working. The order stays parked on payment.
     */
    public function test_awaiting_payment_with_proof_required_method_does_not_advance(): void
    {
        $order = $this->order(OrderStatus::AwaitingPayment, 'instapay');

        self::assertFalse(
            app(PaymentFulfillmentGate::class)->permits($order),
            'instapay with no verified proof and nothing paid must not satisfy the gate',
        );

        $this->runProcessWorkflow($order);

        self::assertSame(
            OrderStatus::AwaitingPayment,
            $order->refresh()->status,
            'an unpaid proof-required order must stay awaiting_payment',
        );
    }

    /**
     * Matrix 3 — BL-1: the generic status patch cannot bypass the gate.
     *
     * `PATCH /orders/{id}/quick-update {status: in_progress}` routes to
     * ProcessOrderWorkflow, and NOTHING on that path evaluates the payment gate. Before M1
     * this was safe only because the enum helper excluded AwaitingPayment; the gate check
     * inside the workflow is what keeps it safe now.
     */
    public function test_status_patch_cannot_bypass_the_payment_gate(): void
    {
        $order = $this->order(OrderStatus::AwaitingPayment, 'instapay');

        $this->actingAs($this->user())
            ->patchJson('/api/orders/'.$order->id.'/quick-update', [
                'status' => OrderStatus::InProgress->value,
            ]);

        self::assertSame(
            OrderStatus::AwaitingPayment,
            $order->refresh()->status,
            'a status patch must not walk an unpaid order past the financial control',
        );
    }

    /**
     * The enum backstop itself must remain in place. If this ever goes green with
     * AwaitingPayment present, BL-1 has been reintroduced.
     */
    public function test_the_enum_still_excludes_awaiting_payment(): void
    {
        self::assertFalse(
            OrderStatus::AwaitingPayment->advancesToInProgressOnReservation(),
            'the exclusion is a financial backstop for the ungated status-patch route (BL-1)',
        );
        self::assertFalse(
            OrderStatus::Confirmed->advancesToInProgressOnReservation(),
            'reserving must never un-confirm an order',
        );
    }

    /**
     * Requirement A — a blank method cannot bypass the ADVANCE gate.
     *
     * This is the whole purpose of the hardening: blanking `payment_method_manual` must not
     * be usable to buy passage into fulfilment eligibility.
     */
    public function test_a_blank_method_cannot_bypass_the_advance_gate(): void
    {
        $order = $this->order(OrderStatus::AwaitingPayment, 'instapay');

        // Blank BOTH method fields directly — simulating any writer that never sees the
        // request rule (a seeder, an importer, a console command).
        DB::table('orders')->where('id', $order->id)
            ->update(['payment_method_manual' => null, 'payment_method' => null]);

        self::assertFalse(
            app(PaymentFulfillmentGate::class)->permitsAdvance($order->refresh()),
            'a control that cannot identify its policy must not authorise an advance',
        );

        $this->runProcessWorkflow($order);

        self::assertSame(
            OrderStatus::AwaitingPayment,
            $order->refresh()->status,
            'a blank method must not walk the order into fulfilment',
        );

        // And the payment trigger must not either.
        app(ReevaluateOrderFulfillmentAction::class)->execute($order->refresh());

        self::assertSame(OrderStatus::AwaitingPayment, $order->refresh()->status);
    }

    /**
     * Requirement B — a blank method must NOT demote an order on the RETURN path.
     *
     * BL-2-A: the hardening is scoped to the ADVANCE decision. Failing closed in `permits()`
     * — which both directions share — demoted every method-less order to `awaiting_payment`
     * on its next payment event, punishing orders the hardening was never aimed at. Manual
     * creation legitimately accepts a null method, so those orders exist by design.
     */
    public function test_a_blank_method_does_not_demote_an_order_on_the_return_path(): void
    {
        $order = $this->order(OrderStatus::InProgress, 'cod');

        DB::table('orders')->where('id', $order->id)
            ->update(['payment_method_manual' => null, 'payment_method' => null]);

        self::assertTrue(
            app(PaymentFulfillmentGate::class)->permits($order->refresh()),
            'the ongoing/return decision stays permissive on a blank method',
        );

        app(ReevaluateOrderFulfillmentAction::class)->execute($order->refresh());

        self::assertSame(
            OrderStatus::InProgress,
            $order->refresh()->status,
            'an order must not be demoted merely because its method is absent',
        );
    }

    /**
     * Requirement B (end to end) — record-payment on a method-less order does not demote it.
     *
     * This is the exact regression BL-2 produced: two contract tests went red because
     * `record-payment` returned a method-less `in_progress` order to `awaiting_payment`.
     */
    public function test_record_payment_does_not_demote_a_method_less_order(): void
    {
        $order = $this->order(OrderStatus::InProgress, 'cod');

        DB::table('orders')->where('id', $order->id)
            ->update(['payment_method_manual' => null, 'payment_method' => null]);

        $this->actingAs($this->user())
            ->postJson('/api/orders/'.$order->id.'/record-payment', ['amount' => 50.0])
            ->assertOk();

        self::assertSame(
            OrderStatus::InProgress,
            $order->refresh()->status,
            'recording a payment must not change the status of a method-less order',
        );
    }

    /** Q3/O1 — blanking the method through the update path is rejected. */
    public function test_blanking_the_payment_method_is_rejected_by_the_update_request(): void
    {
        $order = $this->order(OrderStatus::AwaitingPayment, 'instapay');

        $this->actingAs($this->user())
            ->putJson('/api/orders/'.$order->id, $this->fullUpdatePayload($order, [
                'payment_method_manual' => null,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_method_manual');

        self::assertSame(
            'instapay',
            $order->refresh()->payment_method_manual,
            'the stored method must be untouched by a rejected request',
        );
    }

    /** An update that simply does not mention the method is unaffected (`sometimes`). */
    public function test_an_update_that_omits_the_payment_method_is_unaffected(): void
    {
        $order = $this->order(OrderStatus::AwaitingPayment, 'instapay');

        $payload = $this->fullUpdatePayload($order);
        unset($payload['payment_method_manual']);

        $this->actingAs($this->user())
            ->putJson('/api/orders/'.$order->id, $payload)
            ->assertSuccessful();

        self::assertSame('instapay', $order->refresh()->payment_method_manual);
    }

    /**
     * Requirement C — creation with a NULL method remains compatible.
     *
     * `permitsAtCreation()` keeps its own allowance (BL-2-A item 2). `CreateOrderAction`
     * always passes a null method, and `StoreManualOrderRequest` accepts one, so failing
     * closed here would break both manual creation and the Woo import — neither of which
     * this task may change.
     */
    public function test_creation_still_permits_a_null_method(): void
    {
        self::assertTrue(
            app(PaymentFulfillmentGate::class)->permitsAtCreation(null, null, $this->company->id),
            'CreateOrderAction always passes a null method; failing closed there would break creation',
        );

        // And end to end: a manual order with no method is still created successfully.
        $response = $this->actingAs($this->user())->postJson('/api/orders/manual', [
            'customer_id' => (string) $this->customer->id,
            'company_id' => (string) $this->company->id,
            'order_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => (string) Product::factory()->create()->id,
                'quantity' => 1,
                'unit_price' => 100.0,
            ]],
        ]);

        self::assertContains(
            $response->status(),
            [200, 201],
            'manual creation must not start failing because no method was supplied',
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The payment trigger (Q1)
    // ─────────────────────────────────────────────────────────────────────────

    /** Matrix 5 — the payment trigger advances AwaitingPayment → InProgress. */
    public function test_the_payment_trigger_advances_to_in_progress(): void
    {
        $order = $this->order(OrderStatus::AwaitingPayment, 'cod');

        app(ReevaluateOrderFulfillmentAction::class)->execute($order);

        self::assertSame(OrderStatus::InProgress, $order->refresh()->status);
    }

    /** Matrix 6 — and NEVER to Confirmed. This is the reported defect. */
    public function test_the_payment_trigger_never_produces_confirmed(): void
    {
        $order = $this->order(OrderStatus::AwaitingPayment, 'cod');

        app(ReevaluateOrderFulfillmentAction::class)->execute($order);

        self::assertNotSame(
            OrderStatus::Confirmed,
            $order->refresh()->status,
            'ADR-042 §7.1: a payment fact must never automatically confirm an order',
        );
        self::assertNull(
            $order->refresh()->confirmed_at,
            'nor may it stamp a confirmation',
        );
    }

    /** Matrix 6b — an unsatisfied gate leaves the order exactly where it was. */
    public function test_the_payment_trigger_does_not_advance_an_unsatisfied_order(): void
    {
        $order = $this->order(OrderStatus::AwaitingPayment, 'instapay');

        app(ReevaluateOrderFulfillmentAction::class)->execute($order);

        self::assertSame(OrderStatus::AwaitingPayment, $order->refresh()->status);
    }

    /** Matrix 7 — the RETURN direction is intact: eligible + gate fails ⇒ awaiting_payment. */
    public function test_the_return_direction_still_works(): void
    {
        $order = $this->order(OrderStatus::InProgress, 'instapay');

        app(ReevaluateOrderFulfillmentAction::class)->execute($order);

        self::assertSame(
            OrderStatus::AwaitingPayment,
            $order->refresh()->status,
            'an in_progress order on a proof-required method with no proof must be returned',
        );
    }

    /** Matrix 4 — explicit operator Confirm from AwaitingPayment remains legal (ADR-042 §6). */
    public function test_explicit_confirm_from_awaiting_payment_remains_legal(): void
    {
        $order = $this->order(OrderStatus::AwaitingPayment, 'cod');

        app(FulfillmentEngine::class)->run(
            app(ConfirmOrderWorkflow::class),
            $order,
            [],
            (string) $this->user()->id,
        );

        self::assertSame(
            OrderStatus::Confirmed,
            $order->refresh()->status,
            'the documented recovery source must not have been removed',
        );
    }

    /** Matrix 9 — there is exactly ONE payment gate implementation. */
    public function test_only_one_payment_gate_implementation_exists(): void
    {
        $matches = glob(base_path('Modules/**/*PaymentFulfillmentGate*.php'), GLOB_BRACE) ?: [];

        $found = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('Modules')));
        foreach ($it as $file) {
            if ($file->isFile() && str_contains($file->getFilename(), 'PaymentFulfillmentGate')) {
                $found[] = $file->getFilename();
            }
        }

        self::assertCount(1, $found, 'a second gate implementation would let the two drift: '.implode(', ', $found));
        unset($matches);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RC-3 — Preparation Wave stale-membership eviction
    // ─────────────────────────────────────────────────────────────────────────

    /** RC-3 — the ORD-00017 case: an order demoted after admission is evicted. */
    public function test_an_order_that_becomes_ineligible_is_released_from_its_wave(): void
    {
        $order = $this->order(OrderStatus::Confirmed, 'cod');
        $wave = $this->wave();
        $this->attach($wave, $order);

        self::assertSame(1, $this->activeMembers($wave), 'admitted while eligible');

        // The demotion the payment control performs, through the real control.
        $this->demoteToAwaitingPayment($order);

        self::assertSame(0, $this->activeMembers($wave), 'must not remain an active member');

        $row = DB::table('preparation_wave_orders')->where('order_id', $order->id)->first();
        self::assertNotNull($row, 'the row must SURVIVE as history — release, never delete');
        self::assertNotNull($row->released_at);
        self::assertNull($row->active_membership, 'the generated column follows released_at');
    }

    /** RC-3 — an eligible member is never released. No false positives. */
    public function test_an_eligible_order_is_never_released(): void
    {
        $order = $this->order(OrderStatus::InProgress, 'cod');
        $wave = $this->wave();
        $this->attach($wave, $order);

        // A status write that keeps it eligible, through the real workflow.
        $this->confirmThroughWorkflow($order);

        self::assertSame(1, $this->activeMembers($wave), 'confirmed is eligible (ADR-042 §7)');
    }

    /** RC-3 — the order's own status is never written by the eviction. */
    public function test_eviction_never_writes_the_order_status(): void
    {
        $order = $this->order(OrderStatus::Confirmed, 'cod');
        $wave = $this->wave();
        $this->attach($wave, $order);

        $this->demoteToAwaitingPayment($order);

        self::assertSame(
            OrderStatus::AwaitingPayment,
            $order->refresh()->status,
            'release is about membership, not about order status',
        );
    }

    /** RC-3 — releasing is idempotent and decrements the counter exactly once. */
    public function test_release_is_idempotent(): void
    {
        $order = $this->order(OrderStatus::Confirmed, 'cod');
        $wave = $this->wave();
        $this->attach($wave, $order);

        $before = (int) $wave->refresh()->orders_count;

        $service = app(WaveMembershipService::class);

        self::assertTrue($service->releaseIneligibleOrder($wave, (string) $order->id, 'test'));
        self::assertFalse(
            $service->releaseIneligibleOrder($wave, (string) $order->id, 'test'),
            'a second release must be a no-op',
        );

        self::assertSame(
            $before - 1,
            (int) $wave->refresh()->orders_count,
            'the counter must be decremented exactly once',
        );
    }

    /**
     * Q4 — clearing the warehouse does NOT evict, and that is deliberate.
     *
     * This row asserts the CORRECTED rule. The first cut of RC-3 evicted on a null
     * warehouse, and doing so broke the certified carry-over contract: wave membership is
     * warehouse-keyed only in the COLLECTOR that fills it, never in the store, so
     * WaveCarryOverDependencyTest's fixtures assign no warehouse at all. Evicting on null
     * released those members early, decremented `orders_count`, and flipped
     * HandlePreparationWaveClosed from CASE C (carry the unfinished order back to In
     * Progress) to CASE B (leave it Ready for Dispatch) across five certified scenarios.
     *
     * A missing warehouse is its own operational exception. It is already true and visible
     * on the order; it needs no membership change to be true, and no warehouse may be
     * invented to make it go away.
     */
    public function test_clearing_the_warehouse_does_not_evict_the_order(): void
    {
        $order = $this->order(OrderStatus::InProgress, 'cod');
        $wave = $this->wave();
        $this->attach($wave, $order);
        $countBefore = (int) $wave->refresh()->orders_count;

        // Not a status write, so unguarded — but the observer must still see it, so it
        // goes through the model rather than the query builder.
        $order->update(['assigned_warehouse_id' => null]);

        self::assertSame(
            1,
            $this->activeMembers($wave),
            'a cleared warehouse is an exception on the order, not a wave eviction',
        );
        self::assertSame(
            $countBefore,
            (int) $wave->refresh()->orders_count,
            'and the wave counter must not move',
        );
        self::assertNull(
            $order->refresh()->assigned_warehouse_id,
            'no warehouse may be invented or auto-assigned',
        );
    }

    /**
     * Q4 — reassignment to a DIFFERENT warehouse does evict.
     *
     * This is the fact a null cannot establish: the order now belongs somewhere else, so it
     * is no longer this warehouse's wave's work.
     */
    public function test_reassigning_to_another_warehouse_releases_the_order(): void
    {
        $order = $this->order(OrderStatus::InProgress, 'cod');
        $wave = $this->wave();
        $this->attach($wave, $order);
        $countBefore = (int) $wave->refresh()->orders_count;

        $elsewhere = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $order->update(['assigned_warehouse_id' => $elsewhere->id]);

        self::assertSame(
            0,
            $this->activeMembers($wave),
            'an order that now belongs to another warehouse is not this wave\'s work',
        );
        self::assertSame(
            $countBefore - 1,
            (int) $wave->refresh()->orders_count,
            'and the counter follows the real eviction',
        );
    }

    /** Q4 — a NULL-warehouse order is never admitted to a wave in the first place. */
    public function test_a_null_warehouse_order_is_never_admitted(): void
    {
        $order = $this->order(OrderStatus::InProgress, 'cod');
        DB::table('orders')->where('id', $order->id)->update(['assigned_warehouse_id' => null]);

        $wave = $this->wave();

        app(WaveMembershipService::class)->attachEligibleOrders(
            $wave,
            $this->waveConfig(),
            'system',
        );

        self::assertSame(
            0,
            $this->activeMembers($wave),
            'a wave belongs to one warehouse; an order with none belongs to no wave',
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Fixtures
    // ─────────────────────────────────────────────────────────────────────────

    private function order(OrderStatus $status, ?string $method): Order
    {
        $order = Order::query()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-PP-'.uniqid(),
            'order_date' => now()->toDateString(),
            'assigned_warehouse_id' => $this->warehouse->id,
            'city' => 'Nasr City',
            'governorate' => 'Cairo',
            'status' => $status->value,
            'payment_method_manual' => $method,
            'subtotal' => 100, 'total' => 100,
            'deposit_amount' => 0,
            'shipping_total' => 0, 'discount_total' => 0, 'tax_total' => 0,
            'discount_amount' => 0,
        ]);

        $product = Product::factory()->create();

        // Real stock, so a successful reservation is what the matrix observes. With no
        // inventory the order still LEAVES awaiting_payment (proving the gate and the
        // activation ran) but then correctly continues to `awaiting_stock` via
        // ADR-042 §6.1 — which is a different assertion from the one these rows make.
        InventoryItem::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $product->id,
            'company_id' => $this->company->id,
            'on_hand_qty' => 500.0,
            'reserved_qty' => 0.0,
        ]);

        DB::table('order_lines')->insert([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 10,
            'line_total' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $order->refresh();
    }

    /** Run the workflow through the real engine, swallowing precondition refusals. */
    private function runProcessWorkflow(Order $order): void
    {
        try {
            app(FulfillmentEngine::class)->run(
                app(ProcessOrderWorkflow::class),
                $order,
                [],
                (string) $this->user()->id,
            );
        } catch (Throwable) {
            // A refusal is a legitimate outcome for several rows in this matrix; the
            // assertion is always on the resulting STATUS, never on the absence of a throw.
        }
    }

    /**
     * Demote an order to `awaiting_payment` the way production does it.
     *
     * `Order.status` is protected by UnauthorizedOrderStatusWriteException — a direct
     * `update(['status' => ...])` is rejected, and rightly so. This drives the real payment
     * control instead: switch to a proof-required method and let the RETURN direction of
     * ReevaluateOrderFulfillmentAction perform the transition. That is exactly the sequence
     * observed live on ORD-00017.
     */
    private function demoteToAwaitingPayment(Order $order): void
    {
        DB::table('orders')->where('id', $order->id)
            ->update(['payment_method_manual' => 'instapay']);

        app(ReevaluateOrderFulfillmentAction::class)->execute($order->refresh());

        $order->refresh();
    }

    /** Advance an order to `confirmed` through the explicit operator workflow. */
    private function confirmThroughWorkflow(Order $order): void
    {
        app(FulfillmentEngine::class)->run(
            app(ConfirmOrderWorkflow::class),
            $order,
            [],
            (string) $this->user()->id,
        );

        $order->refresh();
    }

    /**
     * The wave engine configuration the collector reads.
     *
     * Its `eligible_order_statuses` is seeded from the CANONICAL enum rather than a literal,
     * so this fixture cannot drift from ADR-042 §7 the way the mutable DB column can.
     */
    private function waveConfig(): WaveEngineConfiguration
    {
        return WaveEngineConfiguration::query()->create([
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'eligible_order_statuses' => array_map(
                static fn (OrderStatus $s): string => $s->value,
                OrderStatus::fulfilmentEligible(),
            ),
            // Both NOT NULL with no default.
            'created_by' => 'system',
            'updated_by' => 'system',
        ]);
    }

    private function wave(): PreparationWave
    {
        $id = (string) Str::uuid();

        DB::table('preparation_waves')->insert([
            'id' => $id,
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'wave_number' => 'PREP-PP-'.substr(uniqid(), -6),
            'planning_date' => now()->toDateString(),
            'starts_at' => now()->copy()->subHour(),
            'intake_closes_at' => now()->copy()->addDay(),
            'ends_at' => now()->copy()->addDays(2),
            'status' => 'collecting',
            'wave_type' => 'engine',
            'orders_count' => 0,
            'created_at' => now(), 'updated_at' => now(),
            'created_by' => (string) Str::uuid(),
            'updated_by' => (string) Str::uuid(),
        ]);

        return PreparationWave::findOrFail($id);
    }

    private function attach(PreparationWave $wave, Order $order): void
    {
        DB::table('preparation_wave_orders')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $this->company->id,
            'preparation_wave_id' => $wave->id,
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'order_confirmed_at' => now(),
            'preparation_priority' => 0,
            'is_paid' => 0,
            'added_by' => 'system',
            'added_at' => now(),
        ]);

        $wave->increment('orders_count');
    }

    private function activeMembers(PreparationWave $wave): int
    {
        return DB::table('preparation_wave_orders')
            ->where('preparation_wave_id', $wave->id)
            ->whereNull('released_at')
            ->count();
    }

    /** A minimally complete payload for the FULL update route. */
    private function fullUpdatePayload(Order $order, array $overrides = []): array
    {
        return array_merge([
            'customer_id' => (string) $order->customer_id,
            'order_date' => now()->toDateString(),
            'status' => $order->status->value,
            'payment_method_manual' => $order->payment_method_manual,
            'lines' => [[
                'product_id' => (string) DB::table('order_lines')
                    ->where('order_id', $order->id)->value('product_id'),
                'quantity' => 1,
                'unit_price' => 10,
            ]],
        ], $overrides);
    }

    private function user(): User
    {
        return User::factory()->create(['company_id' => $this->company->id]);
    }

    /**
     * RC-3 RETENTION — forward progress must NOT be read as ineligibility.
     *
     * Regression for a defect the first RC-3 cut really had. Wave membership is released by
     * wave CLOSURE only (`released_at` is written by closeWave() and CancelWaveAction, never
     * by order completion), so a prepared order keeps an active row while it walks
     * ready_for_dispatch -> out_for_delivery -> delivered. Because none of those is in
     * `fulfilmentEligible()`, an eviction written as the bare negation of admission evicted
     * every order the wave successfully prepared and decremented `orders_count` — the number
     * CompleteWaveAction reports as the wave's own output.
     *
     * 11 of the 12 live members were sitting at ready_for_dispatch when this was found.
     */
    public function test_an_order_that_passed_preparation_keeps_its_wave_membership(): void
    {
        $order = $this->order(OrderStatus::InProgress, 'cod');
        $wave = $this->wave();
        $this->attach($wave, $order);

        $countBefore = $wave->refresh()->orders_count;
        self::assertSame(1, $this->activeMembers($wave));

        $this->setStatusThroughEngine($order, OrderStatus::ReadyForDispatch);

        self::assertSame(
            OrderStatus::ReadyForDispatch,
            $order->refresh()->status,
            'precondition: the order really did move forward',
        );
        self::assertSame(
            1,
            $this->activeMembers($wave),
            'a prepared order must stay a member until the wave closes',
        );
        self::assertSame(
            $countBefore,
            $wave->refresh()->orders_count,
            'orders_count is what the wave prepared; forward progress must not decrement it',
        );
    }

    /**
     * The same guard asserted on the enum, so the intent survives refactoring.
     *
     * `isTerminal()` cannot answer this question: it mixes `delivered` (completed THROUGH
     * preparation) with `cancelled`/`returned` (abandoned it). The first must be retained,
     * the other two evicted.
     */
    public function test_left_preparation_covers_forward_statuses_only(): void
    {
        foreach ([OrderStatus::ReadyForDispatch, OrderStatus::OutForDelivery, OrderStatus::Delivered] as $s) {
            self::assertTrue($s->hasLeftPreparation(), $s->value.' is downstream of preparation');
        }

        foreach ([
            OrderStatus::InProgress, OrderStatus::Confirmed, OrderStatus::AwaitingPayment,
            OrderStatus::AwaitingStock, OrderStatus::Scheduled, OrderStatus::OnHold,
            OrderStatus::Cancelled, OrderStatus::Returned,
        ] as $s) {
            self::assertFalse($s->hasLeftPreparation(), $s->value.' is not downstream of preparation');
        }
    }

    /**
     * A warehouse change after preparation must not evict either.
     *
     * The warehouse branch runs BEFORE the status branch, so the retention guard has to sit
     * above both — otherwise a post-preparation warehouse correction would silently rewrite
     * a wave's history.
     */
    public function test_a_warehouse_change_after_preparation_does_not_evict(): void
    {
        $order = $this->order(OrderStatus::InProgress, 'cod');
        $wave = $this->wave();
        $this->attach($wave, $order);
        $this->setStatusThroughEngine($order, OrderStatus::ReadyForDispatch);

        $countBefore = $wave->refresh()->orders_count;

        // A REASSIGNMENT, not a clear. Clearing no longer evicts at all, so it would prove
        // nothing here — this has to be the change that WOULD evict an eligible order, so
        // that retaining it can only be the work of the retention guard above.
        $elsewhere = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $order->update(['assigned_warehouse_id' => $elsewhere->id]);

        self::assertSame(1, $this->activeMembers($wave), 'history is not rewritten after preparation');
        self::assertSame($countBefore, $wave->refresh()->orders_count);
    }

    /**
     * Abandonment still evicts — the other half of the retention rule. `cancelled` shares
     * `isTerminal()` with `delivered` but must behave the opposite way.
     */
    public function test_a_cancelled_order_is_still_evicted(): void
    {
        $order = $this->order(OrderStatus::InProgress, 'cod');
        $wave = $this->wave();
        $this->attach($wave, $order);

        app(FulfillmentEngine::class)->run(
            app(CancelOrderWorkflow::class),
            $order,
            ['reason' => 'contract test'],
            (string) $this->user()->id,
        );

        self::assertSame(
            OrderStatus::Cancelled,
            $order->refresh()->status,
            'precondition: the order really was cancelled',
        );
        self::assertSame(
            0,
            $this->activeMembers($wave),
            'an abandoned order must not stay in the wave',
        );
    }

    /**
     * Move an order's status through the engine — never a direct write.
     *
     * SetEarlyStatusWorkflow is the generic no-inventory setter and is used here purely as
     * fixture plumbing: what these rows assert is how the OBSERVER reacts to a forward
     * status change, not which workflow produced it. Going through FulfillmentEngine keeps
     * the UnauthorizedOrderStatusWriteException guard satisfied, which a direct
     * `update(['status' => ...])` would not.
     */
    private function setStatusThroughEngine(Order $order, OrderStatus $target): void
    {
        app(FulfillmentEngine::class)->run(
            app(SetEarlyStatusWorkflow::class),
            $order,
            ['target_status' => $target->value],
            (string) $this->user()->id,
        );

        $order->refresh();
    }
}
