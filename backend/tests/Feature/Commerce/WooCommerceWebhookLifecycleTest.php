<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Modules\Commerce\Channels\Application\Actions\CreateChannelAction;
use Modules\Commerce\Channels\Application\Actions\DeleteChannelAction;
use Modules\Commerce\Channels\Application\Actions\DisableChannelAction;
use Modules\Commerce\Channels\Application\Actions\UpdateChannelAction;
use Modules\Commerce\Channels\Application\DTO\ChannelDTO;
use Modules\Commerce\Channels\Domain\Contracts\ChannelRepositoryInterface;
use Modules\Commerce\Channels\Domain\Enums\ChannelLifecycleState;
use Modules\Commerce\Channels\Domain\Enums\ChannelPlatform;
use Modules\Commerce\Channels\Domain\Enums\ConnectionStatus;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\Channels\Domain\Models\ChannelCredential;
use Modules\Commerce\Channels\Domain\Services\ChannelGoLiveReadinessService;
use Modules\Commerce\Channels\Presentation\Http\Controllers\ChannelLifecycleController;
use Modules\Commerce\Synchronization\Application\Jobs\ProcessCustomerWebhookJob;
use Modules\Commerce\Synchronization\Application\Jobs\ProcessOrderWebhookJob;
use Modules\Commerce\Synchronization\Application\Jobs\ProcessProductWebhookJob;
use Modules\Commerce\Synchronization\Application\Services\WebhookManagerService;
use Modules\Commerce\Synchronization\Domain\Models\SyncLog;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-V1.1-WOO-05-WEBHOOK-LIFECYCLE.
 *
 * Per the CTO's Track 2 execution model, these are written as source-level regression
 * coverage for the approved architecture (042A-R1 §6) but their EXECUTION is deferred to the
 * consolidated Track 2 test pass — no MySQL/PHPUnit cycle was launched for this ticket.
 */
final class WooCommerceWebhookLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function makeChannelWithCredential(array $overrides = []): Channel
    {
        $company = Company::factory()->create();
        $brand = Brand::factory()->create(['company_id' => $company->id]);
        $channel = Channel::factory()->create(array_merge(['brand_id' => $brand->id], $overrides));

        ChannelCredential::query()->create([
            'channel_id' => $channel->id, 'consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test',
        ]);

        return $channel->fresh();
    }

    private function sign(Channel $channel, string $rawBody): string
    {
        return base64_encode(hash_hmac('sha256', $rawBody, $channel->credential->consumer_secret, true));
    }

    /** All 7 webhook-id columns pre-populated, for asserting a full-set forced re-registration. */
    private function allRegisteredWebhookColumns(): array
    {
        return [
            'external_webhook_order_created_id' => 'wh_order_created',
            'external_webhook_order_updated_id' => 'wh_order_updated',
            'external_webhook_product_created_id' => 'wh_product_created',
            'external_webhook_product_updated_id' => 'wh_product_updated',
            'external_webhook_product_deleted_id' => 'wh_product_deleted',
            'external_webhook_customer_created_id' => 'wh_customer_created',
            'external_webhook_customer_updated_id' => 'wh_customer_updated',
        ];
    }

    // ═══ REGISTRATION LIFECYCLE ═══════════════════════════════════════════════

    public function test_register_all_skips_topics_already_registered(): void
    {
        Http::fake(['*/wp-json/wc/v3/webhooks' => Http::response(['id' => 999], 200)]);

        $channel = $this->makeChannelWithCredential(['external_webhook_order_created_id' => 'wh_existing']);

        app(WebhookManagerService::class)->registerAll($channel);

        Http::assertSentCount(6); // 7 topics minus the 1 already registered
    }

    public function test_register_all_persists_a_failure_via_synclog_instead_of_swallowing_it(): void
    {
        Http::fake(['*/wp-json/wc/v3/webhooks' => Http::response(['message' => 'Unauthorized'], 401)]);

        $channel = $this->makeChannelWithCredential();

        app(WebhookManagerService::class)->registerAll($channel);

        $this->assertSame(
            7,
            SyncLog::query()->where('channel_id', $channel->id)->where('action', 'webhook.register')->where('status', 'failed')->count(),
        );
        $this->assertNull($channel->fresh()->external_webhook_order_created_id);
    }

    public function test_reregister_all_forces_registration_even_when_already_registered(): void
    {
        Http::fake(['*/wp-json/wc/v3/webhooks*' => Http::response(['id' => 555], 200)]);

        $channel = $this->makeChannelWithCredential($this->allRegisteredWebhookColumns());

        app(WebhookManagerService::class)->reregisterAll($channel);

        // One DELETE (deregistering the stale id) + one POST (re-registering) per topic.
        Http::assertSentCount(14);
        $this->assertSame('555', $channel->fresh()->external_webhook_order_created_id);
    }

    public function test_deregister_failure_does_not_clear_the_id_column(): void
    {
        Http::fake(['*/wp-json/wc/v3/webhooks/*' => Http::response(['message' => 'Not Found'], 404)]);

        $channel = $this->makeChannelWithCredential(['external_webhook_order_created_id' => 'wh_keep']);

        app(WebhookManagerService::class)->deregisterAll($channel);

        // The failed DELETE must not lose track of a webhook that might still be live on Woo.
        $this->assertSame('wh_keep', $channel->fresh()->external_webhook_order_created_id);
        $this->assertSame(
            1,
            SyncLog::query()->where('channel_id', $channel->id)->where('action', 'webhook.deregister')->where('status', 'failed')->count(),
        );
    }

    public function test_updating_store_url_triggers_forced_reregistration(): void
    {
        Http::fake(['*/wp-json/wc/v3/webhooks*' => Http::response(['id' => 777], 200)]);

        $channel = $this->makeChannelWithCredential(['external_webhook_order_created_id' => 'wh_old_url']);

        $dto = ChannelDTO::fromArray([
            'brand_id' => $channel->brand_id, 'name' => $channel->name, 'platform' => $channel->platform->value,
            'store_url' => 'https://a-brand-new-store-url.test',
        ]);

        app(UpdateChannelAction::class)->execute((string) $channel->id, $dto);

        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_contains((string) $request->url(), 'wh_old_url'));
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_contains((string) $request->url(), '/wp-json/wc/v3/webhooks'));
    }

    public function test_rotating_credentials_triggers_forced_reregistration(): void
    {
        Http::fake(['*/wp-json/wc/v3/webhooks*' => Http::response(['id' => 888], 200)]);

        $channel = $this->makeChannelWithCredential($this->allRegisteredWebhookColumns());

        $dto = ChannelDTO::fromArray([
            'brand_id' => $channel->brand_id, 'name' => $channel->name, 'platform' => $channel->platform->value,
            'store_url' => $channel->store_url, 'consumer_key' => 'ck_new', 'consumer_secret' => 'cs_new',
        ]);

        app(UpdateChannelAction::class)->execute((string) $channel->id, $dto);

        Http::assertSentCount(14); // 7 deregister + 7 register
    }

    public function test_updating_with_no_url_or_credential_change_does_not_reregister(): void
    {
        Http::fake(['*/wp-json/wc/v3/webhooks*' => Http::response(['id' => 999], 200)]);

        $channel = $this->makeChannelWithCredential(['external_webhook_order_created_id' => 'wh_untouched']);

        $dto = ChannelDTO::fromArray([
            'brand_id' => $channel->brand_id, 'name' => 'A New Name Only', 'platform' => $channel->platform->value,
            'store_url' => $channel->store_url,
        ]);

        app(UpdateChannelAction::class)->execute((string) $channel->id, $dto);

        Http::assertNothingSent();
    }

    public function test_creating_a_channel_with_credentials_registers_webhooks(): void
    {
        Http::fake(['*/wp-json/wc/v3/webhooks' => Http::response(['id' => 111], 200)]);

        $brand = Brand::factory()->create();
        $dto = ChannelDTO::fromArray([
            'brand_id' => $brand->id, 'name' => 'New Store', 'platform' => ChannelPlatform::WooCommerce->value,
            'store_url' => 'https://brand-new-store.test', 'consumer_key' => 'ck', 'consumer_secret' => 'cs',
        ]);

        app(CreateChannelAction::class)->execute($dto);

        Http::assertSentCount(7);
    }

    public function test_disabling_a_channel_deregisters_its_webhooks(): void
    {
        Http::fake(['*/wp-json/wc/v3/webhooks/*' => Http::response([], 200)]);

        $channel = $this->makeChannelWithCredential([
            'external_webhook_order_created_id' => 'wh_1', 'lifecycle_state' => ChannelLifecycleState::Live->value,
        ]);

        app(DisableChannelAction::class)->execute((string) $channel->id);

        Http::assertSent(fn ($request) => $request->method() === 'DELETE');
        $this->assertNull($channel->fresh()->external_webhook_order_created_id);
    }

    public function test_deleting_a_channel_deregisters_its_webhooks_first(): void
    {
        Http::fake(['*/wp-json/wc/v3/webhooks/*' => Http::response([], 200)]);

        $channel = $this->makeChannelWithCredential(['external_webhook_order_created_id' => 'wh_1']);
        $channelId = (string) $channel->id;

        app(DeleteChannelAction::class)->execute($channelId);

        Http::assertSent(fn ($request) => $request->method() === 'DELETE');
        $this->assertSoftDeleted('channels', ['id' => $channelId]);
    }

    public function test_webhook_manager_never_logs_the_consumer_secret(): void
    {
        Http::fake(['*/wp-json/wc/v3/webhooks' => Http::response(['id' => 222], 200)]);

        $channel = $this->makeChannelWithCredential();

        app(WebhookManagerService::class)->registerAll($channel);

        $logs = SyncLog::query()->where('channel_id', $channel->id)->where('action', 'webhook.register')->get();
        $this->assertNotEmpty($logs);

        foreach ($logs as $log) {
            $encoded = json_encode([$log->request_payload, $log->response_payload]);
            $this->assertIsString($encoded);
            $this->assertStringNotContainsString('cs_test', $encoded);
            $this->assertStringNotContainsString('consumer_secret', $encoded);
        }
    }

    // ═══ INGRESS: CHANNEL LIFECYCLE GATE ══════════════════════════════════════

    public function test_order_webhook_with_valid_signature_on_a_live_channel_dispatches_the_job(): void
    {
        Bus::fake([ProcessOrderWebhookJob::class]);

        $channel = $this->makeChannelWithCredential(['lifecycle_state' => ChannelLifecycleState::Live->value]);
        $payload = ['id' => 4001, 'status' => 'processing'];
        $rawBody = json_encode($payload);

        $this->postJson('/api/webhooks/woocommerce/'.$channel->id.'/orders', $payload, [
            'X-WC-Webhook-Signature' => $this->sign($channel, $rawBody),
            'X-WC-Webhook-Topic' => 'order.updated',
        ])->assertOk();

        Bus::assertDispatched(ProcessOrderWebhookJob::class);
    }

    public function test_order_webhook_with_invalid_signature_is_rejected_and_no_job_dispatched(): void
    {
        Bus::fake([ProcessOrderWebhookJob::class]);

        $channel = $this->makeChannelWithCredential(['lifecycle_state' => ChannelLifecycleState::Live->value]);
        $payload = ['id' => 4002, 'status' => 'processing'];

        $this->postJson('/api/webhooks/woocommerce/'.$channel->id.'/orders', $payload, [
            'X-WC-Webhook-Signature' => 'not-a-valid-signature',
            'X-WC-Webhook-Topic' => 'order.updated',
        ])->assertStatus(401);

        Bus::assertNotDispatched(ProcessOrderWebhookJob::class);
        $this->assertSame(
            1,
            SyncLog::query()->where('channel_id', $channel->id)->where('action', 'signature_rejected')->count(),
        );
    }

    public function test_order_webhook_for_a_non_live_channel_is_skipped_and_no_job_dispatched(): void
    {
        $externalId = 4100;

        foreach ([ChannelLifecycleState::Draft, ChannelLifecycleState::Configured, ChannelLifecycleState::Ready, ChannelLifecycleState::Paused, ChannelLifecycleState::Disabled] as $state) {
            Bus::fake([ProcessOrderWebhookJob::class]);

            $channel = $this->makeChannelWithCredential(['lifecycle_state' => $state->value]);
            $payload = ['id' => $externalId++, 'status' => 'processing'];
            $rawBody = json_encode($payload);

            $this->postJson('/api/webhooks/woocommerce/'.$channel->id.'/orders', $payload, [
                'X-WC-Webhook-Signature' => $this->sign($channel, $rawBody),
                'X-WC-Webhook-Topic' => 'order.updated',
            ])->assertOk();

            Bus::assertNotDispatched(ProcessOrderWebhookJob::class);
        }
    }

    public function test_product_webhook_for_a_non_live_channel_is_skipped(): void
    {
        Bus::fake([ProcessProductWebhookJob::class]);

        $channel = $this->makeChannelWithCredential(['lifecycle_state' => ChannelLifecycleState::Ready->value]);
        $payload = ['id' => 4200, 'sku' => 'SKU-WH-1'];
        $rawBody = json_encode($payload);

        $this->postJson('/api/webhooks/woocommerce/'.$channel->id.'/products', $payload, [
            'X-WC-Webhook-Signature' => $this->sign($channel, $rawBody),
            'X-WC-Webhook-Topic' => 'product.updated',
        ])->assertOk();

        Bus::assertNotDispatched(ProcessProductWebhookJob::class);
    }

    public function test_customer_webhook_for_a_non_live_channel_is_skipped(): void
    {
        Bus::fake([ProcessCustomerWebhookJob::class]);

        $channel = $this->makeChannelWithCredential(['lifecycle_state' => ChannelLifecycleState::Paused->value]);
        $payload = ['id' => 4300, 'billing' => ['email' => 'x@example.test']];
        $rawBody = json_encode($payload);

        $this->postJson('/api/webhooks/woocommerce/'.$channel->id.'/customers', $payload, [
            'X-WC-Webhook-Signature' => $this->sign($channel, $rawBody),
            'X-WC-Webhook-Topic' => 'customer.updated',
        ])->assertOk();

        Bus::assertNotDispatched(ProcessCustomerWebhookJob::class);
    }

    public function test_replayed_order_webhook_is_treated_as_duplicate_and_skipped(): void
    {
        Bus::fake([ProcessOrderWebhookJob::class]);

        $channel = $this->makeChannelWithCredential(['lifecycle_state' => ChannelLifecycleState::Live->value]);
        $payload = ['id' => 4400, 'status' => 'processing'];
        $rawBody = json_encode($payload);
        $headers = [
            'X-WC-Webhook-Signature' => $this->sign($channel, $rawBody),
            'X-WC-Webhook-Topic' => 'order.updated',
        ];

        $this->postJson('/api/webhooks/woocommerce/'.$channel->id.'/orders', $payload, $headers)->assertOk();
        $this->postJson('/api/webhooks/woocommerce/'.$channel->id.'/orders', $payload, $headers)->assertOk();

        Bus::assertDispatchedTimes(ProcessOrderWebhookJob::class, 1);
    }

    // ═══ WOO-04 CARRY-FORWARD: READ-ONLY READINESS ═══════════════════════════

    public function test_readiness_endpoint_computes_but_does_not_persist_a_changed_pre_live_state(): void
    {
        $channel = $this->makeChannelWithCredential([
            'lifecycle_state' => ChannelLifecycleState::Draft->value,
            'connection_status' => ConnectionStatus::Connected->value,
            'sync_products' => false, 'sync_stock' => false, 'sync_customers' => false,
            'external_webhook_order_created_id' => 'wh_1', 'external_webhook_order_updated_id' => 'wh_2',
            'shipping_mapping_reviewed_at' => now(), 'orders_initial_import_policy' => 'from_now',
        ]);

        app(ChannelLifecycleController::class)->readiness(
            (string) $channel->id,
            app(ChannelRepositoryInterface::class),
            app(ChannelGoLiveReadinessService::class),
        );

        // Every gate on this fixture passes — derived state is READY — but the read must not
        // have written it. The persisted column stays exactly what it was set to.
        $this->assertSame(ChannelLifecycleState::Draft, $channel->fresh()->lifecycle_state);
    }
}
