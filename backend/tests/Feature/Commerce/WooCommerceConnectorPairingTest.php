<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Commerce\Channels\Application\Actions\GeneratePairingCodeAction;
use Modules\Commerce\Channels\Domain\Enums\ChannelLifecycleState;
use Modules\Commerce\Channels\Domain\Enums\ConnectorHealth;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\Channels\Domain\Models\ChannelCredential;
use Modules\Commerce\Synchronization\Application\Actions\ExchangePairingCodeAction;
use Modules\Commerce\Synchronization\Application\Services\WooOutboundCommandDispatcher;
use Modules\Commerce\Synchronization\Domain\Models\ChannelSyncAudit;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-...-CONSOLIDATED-REMEDIATION-001-R2 — the pairing exchange, the dedicated
 * connector_token authentication it issues, and the full Connector surface
 * (status/heartbeat/repair/deactivated) built on it. Per the CTO's Track 2 execution model,
 * these are written as source-level regression coverage but their EXECUTION is deferred to
 * the consolidated test pass — no MySQL/PHPUnit cycle was launched for this ticket.
 */
final class WooCommerceConnectorPairingTest extends TestCase
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

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    // ═══ PAIRING ══════════════════════════════════════════════════════════════

    public function test_pairing_binds_exactly_one_channel_and_issues_a_connector_token(): void
    {
        Http::fake(['*/wp-json/wc/v3/webhooks' => Http::response(['id' => 1], 200)]);

        $channel = $this->makeChannelWithCredential();
        $code = app(GeneratePairingCodeAction::class)->execute($channel->id)->data()['pairing_code'];

        $result = app(ExchangePairingCodeAction::class)->execute($code);

        $this->assertTrue($result->isSuccess());
        $this->assertSame($channel->id, $result->data()['channel_id']);
        $this->assertNotEmpty($result->data()['connector_token']);

        $credential = $channel->credential->fresh();
        $this->assertSame($result->data()['connector_token'], $credential->connector_token);
        // The Woo REST credential is untouched by pairing — the plugin never sees it.
        $this->assertSame('ck_test', $credential->consumer_key);
    }

    public function test_pairing_registers_webhooks_automatically(): void
    {
        Http::fake(['*/wp-json/wc/v3/webhooks' => Http::response(['id' => 1], 200)]);

        $channel = $this->makeChannelWithCredential();
        $code = app(GeneratePairingCodeAction::class)->execute($channel->id)->data()['pairing_code'];

        app(ExchangePairingCodeAction::class)->execute($code);

        // All 7 topics — the merchant never chose a topic or pasted a callback URL.
        Http::assertSentCount(7);
        $this->assertNotNull($channel->fresh()->external_webhook_order_created_id);
    }

    public function test_pairing_rejects_an_unknown_code(): void
    {
        $result = app(ExchangePairingCodeAction::class)->execute('NOT-A-REAL-CODE');

        $this->assertFalse($result->isSuccess());
    }

    public function test_pairing_rejects_an_expired_code(): void
    {
        $channel = $this->makeChannelWithCredential();
        $channel->update([
            'pairing_code_hash' => hash('sha256', 'EXPIREDCODE'),
            'pairing_code_expires_at' => now()->subMinute(),
        ]);

        $result = app(ExchangePairingCodeAction::class)->execute('EXPIREDCODE');

        $this->assertFalse($result->isSuccess());
        $this->assertNull($channel->fresh()->credential->connector_token);
    }

    public function test_pairing_code_is_single_use(): void
    {
        Http::fake(['*/wp-json/wc/v3/webhooks' => Http::response(['id' => 1], 200)]);

        $channel = $this->makeChannelWithCredential();
        $code = app(GeneratePairingCodeAction::class)->execute($channel->id)->data()['pairing_code'];

        $first = app(ExchangePairingCodeAction::class)->execute($code);
        $this->assertTrue($first->isSuccess());

        $second = app(ExchangePairingCodeAction::class)->execute($code);
        $this->assertFalse($second->isSuccess());
    }

    public function test_generated_pairing_code_is_never_persisted_in_plaintext(): void
    {
        $channel = $this->makeChannelWithCredential();
        $code = app(GeneratePairingCodeAction::class)->execute($channel->id)->data()['pairing_code'];

        $this->assertNotSame($code, $channel->fresh()->pairing_code_hash);
        $this->assertSame(hash('sha256', $code), $channel->fresh()->pairing_code_hash);
    }

    // ═══ CONNECTOR TOKEN AUTHENTICATION ═══════════════════════════════════════

    private function pairedChannel(): array
    {
        Http::fake(['*/wp-json/wc/v3/webhooks' => Http::response(['id' => 1], 200)]);

        $channel = $this->makeChannelWithCredential();
        $code = app(GeneratePairingCodeAction::class)->execute($channel->id)->data()['pairing_code'];
        $result = app(ExchangePairingCodeAction::class)->execute($code);

        return [$channel->fresh(), $result->data()['connector_token']];
    }

    public function test_status_succeeds_with_the_channels_own_connector_token(): void
    {
        [$channel, $token] = $this->pairedChannel();

        $response = $this->getJson("/api/plugin/channels/{$channel->id}/status", $this->bearer($token));

        $response->assertOk();
        $response->assertJsonPath('data.channel.id', $channel->id);
    }

    public function test_status_rejects_missing_token(): void
    {
        [$channel] = $this->pairedChannel();

        $response = $this->getJson("/api/plugin/channels/{$channel->id}/status");

        $response->assertStatus(401);
    }

    public function test_connector_token_cannot_act_for_another_channel(): void
    {
        [$channelA] = $this->pairedChannel();
        [, $tokenB] = $this->pairedChannel();

        $response = $this->getJson("/api/plugin/channels/{$channelA->id}/status", $this->bearer($tokenB));

        $response->assertStatus(401);
    }

    public function test_status_response_never_contains_the_connector_token_or_woo_secret(): void
    {
        [$channel, $token] = $this->pairedChannel();

        $response = $this->getJson("/api/plugin/channels/{$channel->id}/status", $this->bearer($token));

        $response->assertOk();
        $this->assertStringNotContainsString($token, $response->getContent());
        $this->assertStringNotContainsString('cs_test', $response->getContent());
    }

    // ═══ HEARTBEAT / HEALTH ═══════════════════════════════════════════════════

    public function test_heartbeat_updates_connector_health_to_healthy(): void
    {
        [$channel, $token] = $this->pairedChannel();

        $response = $this->postJson("/api/plugin/channels/{$channel->id}/heartbeat", [], $this->bearer($token));

        $response->assertOk();
        $this->assertSame(ConnectorHealth::Healthy, $channel->fresh()->connectorHealth());
    }

    public function test_stale_heartbeat_degrades_connector_health(): void
    {
        [$channel] = $this->pairedChannel();

        $channel->update(['connector_last_heartbeat_at' => now()->subHours(2)]);

        $this->assertSame(ConnectorHealth::Degraded, $channel->fresh()->connectorHealth());
    }

    public function test_never_paired_channel_reports_never_connected(): void
    {
        $channel = $this->makeChannelWithCredential();

        $this->assertSame(ConnectorHealth::NeverConnected, $channel->connectorHealth());
    }

    // ═══ REPAIR ═══════════════════════════════════════════════════════════════

    public function test_repair_force_reregisters_all_webhook_topics(): void
    {
        Http::fake(['*/wp-json/wc/v3/webhooks*' => Http::response(['id' => 1], 200)]);

        [$channel, $token] = $this->pairedChannel();
        Http::fake(['*/wp-json/wc/v3/webhooks*' => Http::response(['id' => 2], 200)]);

        $response = $this->postJson("/api/plugin/channels/{$channel->id}/repair", [], $this->bearer($token));

        $response->assertOk();
        // reregisterAll deregisters (DELETE) then registers (POST) every one of the 7 topics.
        Http::assertSentCount(14);
    }

    // ═══ DEACTIVATION — offline, never a business-data deletion ═══════════════

    public function test_deactivation_marks_connector_disconnected_and_deregisters_webhooks(): void
    {
        [$channel, $token] = $this->pairedChannel();
        Http::fake(['*/wp-json/wc/v3/webhooks/*' => Http::response([], 200)]);

        $response = $this->postJson(
            "/api/plugin/channels/{$channel->id}/deactivated",
            ['reason' => 'wordpress_plugin_deactivated'],
            $this->bearer($token),
        );

        $response->assertOk();
        $fresh = $channel->fresh();
        $this->assertNotNull($fresh->connector_disconnected_at);
        $this->assertSame(ConnectorHealth::Disconnected, $fresh->connectorHealth());
        $this->assertNull($fresh->external_webhook_order_created_id);
    }

    public function test_deactivation_never_touches_channel_lifecycle_or_business_data(): void
    {
        [$channel, $token] = $this->pairedChannel();
        Http::fake(['*/wp-json/wc/v3/webhooks/*' => Http::response([], 200)]);

        $lifecycleBefore = $channel->lifecycle_state;

        $this->postJson("/api/plugin/channels/{$channel->id}/deactivated", [], $this->bearer($token));

        $this->assertSame($lifecycleBefore, $channel->fresh()->lifecycle_state);
        $this->assertNotNull(Channel::withTrashed()->find($channel->id));
    }

    public function test_deactivation_writes_exactly_one_audit_entry(): void
    {
        [$channel, $token] = $this->pairedChannel();
        Http::fake(['*/wp-json/wc/v3/webhooks/*' => Http::response([], 200)]);

        $this->postJson("/api/plugin/channels/{$channel->id}/deactivated", [], $this->bearer($token));

        $this->assertSame(
            1,
            ChannelSyncAudit::query()->where('channel_id', $channel->id)->where('action', 'connector.deactivated')->count(),
        );
    }

    // ═══ Zero manual Woo REST key setup (R2-R2 confirmation) ══════════════════

    public function test_pairing_succeeds_with_no_pre_existing_woo_rest_credential_at_all(): void
    {
        Http::fake(['*/wp-json/wc/v3/webhooks' => Http::response(['id' => 1], 200)]);

        $company = Company::factory()->create();
        $brand = Brand::factory()->create(['company_id' => $company->id]);
        // No ChannelCredential row created at all — the merchant never obtains or pastes a Woo
        // REST consumer_key/consumer_secret for an official Connector-mode pairing.
        $channel = Channel::factory()->create(['brand_id' => $brand->id]);

        $code = app(GeneratePairingCodeAction::class)->execute($channel->id)->data()['pairing_code'];
        $result = app(ExchangePairingCodeAction::class)->execute($code);

        $this->assertTrue($result->isSuccess());
        $credential = $channel->fresh()->credential;
        $this->assertNotNull($credential);
        $this->assertNotEmpty($credential->connector_token);
        $this->assertNull($credential->consumer_key);
        $this->assertNull($credential->consumer_secret);
    }

    // ═══ Immediate post-pair health (R2-R2 §11) ═══════════════════════════════

    public function test_successful_pairing_is_immediately_healthy_not_never_connected(): void
    {
        [$channel] = $this->pairedChannel();

        $this->assertSame(ConnectorHealth::Healthy, $channel->fresh()->connectorHealth());
    }

    // ═══ Business-sync gate after disconnect (R2-R2 §7/§8) ════════════════════

    private function liveDisconnectedChannel(): array
    {
        [$channel, $token] = $this->pairedChannel();
        $channel->update(['lifecycle_state' => ChannelLifecycleState::Live->value, 'connector_disconnected_at' => now()]);

        return [$channel->fresh(), $token];
    }

    public function test_business_command_is_blocked_for_a_disconnected_paired_channel(): void
    {
        Http::fake(); // any call here would be a defect

        [$channel] = $this->liveDisconnectedChannel();

        $result = app(WooOutboundCommandDispatcher::class)->put($channel, 'products', 'ext-1', ['stock_status' => 'instock']);

        $this->assertFalse($result->ok, 'A stale queued business command must not reach Woo once the paired Connector is disconnected.');
        Http::assertNothingSent();
    }

    public function test_business_command_succeeds_for_a_live_healthy_paired_channel(): void
    {
        Http::fake(['*/ecos-connector/v1/commands' => Http::response(['data' => ['id' => 1]], 200)]);

        [$channel] = $this->pairedChannel();
        $channel->update(['lifecycle_state' => ChannelLifecycleState::Live->value]);

        $result = app(WooOutboundCommandDispatcher::class)->put($channel->fresh(), 'products', 'ext-1', ['stock_status' => 'instock']);

        $this->assertTrue($result->ok);
    }

    public function test_connector_management_command_is_not_blocked_by_the_business_gate_while_disconnected(): void
    {
        Http::fake(['*/ecos-connector/v1/commands' => Http::response(['data' => ['id' => 5]], 200)]);

        [$channel] = $this->liveDisconnectedChannel();

        // A webhook (connector-management) command must still be able to run while
        // disconnected — this is exactly the repair/deregistration path that needs to work
        // during teardown or reconnection.
        $result = app(WooOutboundCommandDispatcher::class)->delete($channel, 'webhooks', 'wh-1');

        $this->assertTrue($result->ok);
        Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/ecos-connector/v1/commands'));
    }

    public function test_legacy_unpaired_channel_business_command_is_never_gated_by_connector_health(): void
    {
        Http::fake(['*/wp-json/wc/v3/products/*' => Http::response(['id' => 1], 200)]);

        // A legacy channel: consumer_key/secret configured, never paired (no connector_token),
        // not even Live — connector eligibility must simply not apply to it; only its own
        // existing legacy contract does. This channel is intentionally NOT Live to prove the
        // dispatcher itself never consults connector health for an unpaired channel (the
        // isLive() gate for legacy channels is enforced elsewhere, e.g. the observers, not here).
        $channel = $this->makeChannelWithCredential();

        $result = app(WooOutboundCommandDispatcher::class)->put($channel, 'products', 'ext-1', ['stock_status' => 'instock']);

        $this->assertTrue($result->ok);
    }
}
