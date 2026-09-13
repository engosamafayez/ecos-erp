<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Commerce\Channels\Domain\Enums\ChannelLifecycleState;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Synchronization\Application\Jobs\ProcessOrderWebhookJob;
use Modules\Commerce\Synchronization\Application\Services\EcosOrderStatusToWooTranslator;
use Modules\Commerce\Synchronization\Domain\Models\SyncLog;
use Modules\Operations\Fulfillment\Application\OrderStatusGuard;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-V1.1-WOO-06-COMPLETED-EXTERNAL-FULFILLMENT-SEMANTICS.
 *
 * Per the CTO's Track 2 execution model, these are written as source-level regression
 * coverage for the approved architecture (042A-R1 §2) but their EXECUTION is deferred to the
 * consolidated Track 2 test pass — no MySQL/PHPUnit cycle was launched for this ticket.
 *
 * 042A-R1 §2 confirmed "Case A only": Woo "completed" is informational and never marks an
 * order Delivered unless CompleteDeliveryWorkflow's own guard (OutForDelivery +
 * inventory_shipped_at already set) already independently allows it. "Case B" (Woo-side
 * fulfillment as an authoritative external source) has zero existing foundation and was
 * explicitly not built — there is nothing here that invents it. The one real change is making
 * a guard rejection ("held") a distinct, queryable SyncLog entry instead of a transient log
 * line; these tests prove that entry appears, carries the right evidence, and — just as
 * importantly — that nothing else about the authority itself changed.
 */
final class WooCommerceCompletedFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    private function makeLiveChannel(): Channel
    {
        $company = Company::factory()->create();
        $brand = Brand::factory()->create(['company_id' => $company->id]);

        return Channel::factory()->create([
            'brand_id' => $brand->id,
            'lifecycle_state' => ChannelLifecycleState::Live->value,
        ]);
    }

    private function makeOrder(Channel $channel, string $externalId, OrderStatus $status, array $overrides = []): Order
    {
        $companyId = $channel->brand->company_id;

        return OrderStatusGuard::withAuthorization(function () use ($channel, $externalId, $status, $overrides, $companyId): Order {
            return Order::create(array_merge([
                'channel_id' => $channel->id,
                'external_order_id' => $externalId,
                'company_id' => $companyId,
                'order_number' => 'ORD-WOO06-'.$externalId,
                'order_date' => now()->toDateString(),
                'status' => $status->value,
                'subtotal' => 100,
                'total' => 100,
            ], $overrides));
        });
    }

    private function dispatchWebhook(Channel $channel, array $payload, string $topic = 'order.updated'): void
    {
        app()->call([new ProcessOrderWebhookJob($channel, $payload, $topic), 'handle']);
    }

    // ═══ CASE A ONLY: STATUS IS NOT PHYSICAL EVIDENCE ═══════════════════════

    public function test_completed_webhook_is_held_when_order_was_never_dispatched(): void
    {
        $channel = $this->makeLiveChannel();
        $order = $this->makeOrder($channel, '9001', OrderStatus::InProgress);

        $this->dispatchWebhook($channel, ['id' => '9001', 'status' => 'completed']);

        // The guard (OutForDelivery + inventory_shipped_at) never passed — status is untouched.
        $this->assertSame(OrderStatus::InProgress, $order->fresh()->status);

        $held = SyncLog::query()->where('channel_id', $channel->id)
            ->where('action', 'fulfillment_transition_held')->first();
        $this->assertNotNull($held, 'A held transition must produce a distinct, queryable SyncLog entry.');
        $this->assertSame('completed', $held->request_payload['wc_status']);
        $this->assertSame('in_progress', $held->request_payload['ecos_from']);
    }

    public function test_completed_webhook_is_held_when_order_is_out_for_delivery_but_not_yet_shipped(): void
    {
        $channel = $this->makeLiveChannel();
        // OutForDelivery alone is not enough — inventory_shipped_at must also be set
        // (LoadVehicleWorkflow's own signal that dispatch genuinely happened).
        $order = $this->makeOrder($channel, '9002', OrderStatus::OutForDelivery, ['inventory_shipped_at' => null]);

        $this->dispatchWebhook($channel, ['id' => '9002', 'status' => 'completed']);

        $this->assertSame(OrderStatus::OutForDelivery, $order->fresh()->status);
        $this->assertSame(
            1,
            SyncLog::query()->where('channel_id', $channel->id)->where('action', 'fulfillment_transition_held')->count(),
        );
    }

    public function test_completed_webhook_delivers_the_order_when_ecos_already_dispatched_it(): void
    {
        $channel = $this->makeLiveChannel();
        $order = $this->makeOrder($channel, '9003', OrderStatus::OutForDelivery, ['inventory_shipped_at' => now()]);

        $this->dispatchWebhook($channel, ['id' => '9003', 'status' => 'completed']);

        // The SAME guard, unchanged, now legitimately passes — Case A's positive path.
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
        $this->assertSame(
            0,
            SyncLog::query()->where('channel_id', $channel->id)->where('action', 'fulfillment_transition_held')->count(),
        );
    }

    // ═══ NO SYNTHETIC EXTERNAL-FULFILLMENT MACHINERY ═══════════════════════

    public function test_no_synthetic_driver_trip_or_vehicle_records_are_created(): void
    {
        $channel = $this->makeLiveChannel();
        $this->makeOrder($channel, '9004', OrderStatus::OutForDelivery, ['inventory_shipped_at' => now()]);
        $this->makeOrder($channel, '9005', OrderStatus::InProgress);

        $tripsBefore = DB::table('distribution_trips')->count();

        $this->dispatchWebhook($channel, ['id' => '9004', 'status' => 'completed']);
        $this->dispatchWebhook($channel, ['id' => '9005', 'status' => 'completed']);

        $this->assertSame($tripsBefore, DB::table('distribution_trips')->count());
    }

    public function test_no_direct_inventory_mutation_from_a_completed_webhook(): void
    {
        $channel = $this->makeLiveChannel();
        $order = $this->makeOrder($channel, '9006', OrderStatus::OutForDelivery, ['inventory_shipped_at' => now()]);

        $stockMovementsBefore = DB::table('stock_movements')->count();

        $this->dispatchWebhook($channel, ['id' => '9006', 'status' => 'completed']);

        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
        // CompleteDeliveryWorkflow itself never writes StockMovement — inventory was already
        // decremented earlier by LoadVehicleWorkflow; delivery confirmation must not repeat it.
        $this->assertSame($stockMovementsBefore, DB::table('stock_movements')->count());
    }

    // ═══ IDEMPOTENCY / REPLAY / OUT-OF-ORDER ═════════════════════════════════

    public function test_replaying_a_completed_webhook_after_delivery_does_not_duplicate_the_transition(): void
    {
        $channel = $this->makeLiveChannel();
        $order = $this->makeOrder($channel, '9007', OrderStatus::OutForDelivery, ['inventory_shipped_at' => now()]);

        $this->dispatchWebhook($channel, ['id' => '9007', 'status' => 'completed']);
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);

        // Second delivery of the identical event: the order is no longer OutForDelivery, so the
        // SAME unchanged guard now correctly holds it rather than re-executing the transition.
        $this->dispatchWebhook($channel, ['id' => '9007', 'status' => 'completed']);

        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
        $this->assertSame(
            1,
            SyncLog::query()->where('channel_id', $channel->id)->where('action', 'fulfillment_transition_held')->count(),
        );
    }

    public function test_out_of_order_completed_before_processing_is_held_not_applied(): void
    {
        $channel = $this->makeLiveChannel();
        $order = $this->makeOrder($channel, '9008', OrderStatus::InProgress);

        // "completed" arrives before ECOS has even confirmed/processed the order internally.
        $this->dispatchWebhook($channel, ['id' => '9008', 'status' => 'completed']);

        $this->assertSame(OrderStatus::InProgress, $order->fresh()->status);
    }

    // ═══ TENANT / CROSS-COMPANY ═══════════════════════════════════════════════

    public function test_completed_webhook_from_a_different_channel_never_touches_another_channels_order(): void
    {
        $channelA = $this->makeLiveChannel();
        $channelB = $this->makeLiveChannel();
        $order = $this->makeOrder($channelA, '9009', OrderStatus::OutForDelivery, ['inventory_shipped_at' => now()]);

        // Same external id, but delivered on a DIFFERENT channel — must resolve to no order at
        // all (channel_id + external_order_id together identify an order), never to channel A's.
        $this->dispatchWebhook($channelB, ['id' => '9009', 'status' => 'completed']);

        $this->assertSame(OrderStatus::OutForDelivery, $order->fresh()->status);
    }

    // ═══ OUTBOUND: ECOS CANONICAL FULFILLMENT → WOO ═════════════════════════

    public function test_ecos_delivered_maps_outbound_to_woo_completed_only(): void
    {
        $translator = app(EcosOrderStatusToWooTranslator::class);

        $this->assertSame('completed', $translator->translate(OrderStatus::Delivered));
        // Dispatch-adjacent internal states are deliberately unmapped — they must never leak
        // outbound as an invented Woo status.
        $this->assertFalse($translator->hasMapping(OrderStatus::OutForDelivery));
    }

    // ═══ WOO-01 REFUND CONTRACT PRESERVED ═══════════════════════════════════

    public function test_refund_processing_still_runs_independently_of_a_held_completed_transition(): void
    {
        $channel = $this->makeLiveChannel();
        $order = $this->makeOrder($channel, '9010', OrderStatus::InProgress);

        // 'refunded' is excluded from the generic status-match() entirely (WOO-01) and 'status'
        // here is NOT 'completed', so this proves the two code paths remain independent: a
        // non-completed, non-refunded status simply finds no mapped transition to attempt.
        $this->dispatchWebhook($channel, ['id' => '9010', 'status' => 'on-hold']);

        $this->assertSame(
            0,
            SyncLog::query()->where('channel_id', $channel->id)->where('action', 'fulfillment_transition_held')->count(),
        );
    }

    // ═══ WOO-05 LIVE-GATE STILL PRESERVED ═══════════════════════════════════

    public function test_non_live_channel_still_blocks_a_completed_webhook_per_woo_05(): void
    {
        $company = Company::factory()->create();
        $brand = Brand::factory()->create(['company_id' => $company->id]);
        $channel = Channel::factory()->create(['brand_id' => $brand->id, 'lifecycle_state' => ChannelLifecycleState::Ready->value]);
        $order = $this->makeOrder($channel, '9011', OrderStatus::OutForDelivery, ['inventory_shipped_at' => now()]);

        $this->dispatchWebhook($channel, ['id' => '9011', 'status' => 'completed']);

        $this->assertSame(OrderStatus::OutForDelivery, $order->fresh()->status);
        $this->assertSame(
            0,
            SyncLog::query()->where('channel_id', $channel->id)->where('action', 'fulfillment_transition_held')->count(),
        );
    }

    // ═══ TRACEABILITY ═══════════════════════════════════════════════════════

    public function test_held_transition_log_carries_full_traceability_evidence(): void
    {
        $channel = $this->makeLiveChannel();
        $order = $this->makeOrder($channel, '9012', OrderStatus::InProgress);

        $this->dispatchWebhook($channel, ['id' => '9012', 'status' => 'completed']);

        $held = SyncLog::query()->where('channel_id', $channel->id)
            ->where('action', 'fulfillment_transition_held')->firstOrFail();

        $this->assertSame($channel->id, $held->channel_id);
        $this->assertSame($order->id, $held->entity_id);
        $this->assertSame('completed', $held->request_payload['wc_status']);
        $this->assertSame('in_progress', $held->request_payload['ecos_from']);
        $this->assertArrayHasKey('ecos_to', $held->request_payload);
        $this->assertArrayHasKey('reason', $held->request_payload);
    }
}
