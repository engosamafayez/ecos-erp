<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\Channels\Domain\Models\ChannelCredential;
use Modules\Commerce\Synchronization\Domain\Models\ChannelSyncAudit;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-V1.1-WOO-07-OFFICIAL-WOOCOMMERCE-WORDPRESS-ADAPTER — covers PluginAdapterController,
 * the one new ECOS-side surface this ticket introduces (the WordPress plugin itself has no PHPUnit
 * runner; its side is covered by a manual verification plan, not this suite).
 *
 * Per the CTO's Track 2 execution model, these are written as source-level regression coverage for
 * the approved architecture (042A §7 / 042A-R1 §7-8) but their EXECUTION is deferred to the
 * consolidated Track 2 test pass — no MySQL/PHPUnit cycle was launched for this ticket.
 */
final class WooCommercePluginAdapterTest extends TestCase
{
    use RefreshDatabase;

    private function makeChannelWithCredential(array $overrides = [], ?string $consumerKey = 'ck_test', ?string $consumerSecret = 'cs_test'): Channel
    {
        $company = Company::factory()->create();
        $brand = Brand::factory()->create(['company_id' => $company->id]);
        $channel = Channel::factory()->create(array_merge(['brand_id' => $brand->id], $overrides));

        if ($consumerKey !== null && $consumerSecret !== null) {
            ChannelCredential::query()->create([
                'channel_id' => $channel->id, 'consumer_key' => $consumerKey, 'consumer_secret' => $consumerSecret,
            ]);
        }

        return $channel->fresh();
    }

    private function basicAuthHeader(string $key, string $secret): array
    {
        return ['Authorization' => 'Basic '.base64_encode("{$key}:{$secret}")];
    }

    // ═══ STATUS — AUTHENTICATION ══════════════════════════════════════════════

    public function test_status_succeeds_with_the_channels_own_credential(): void
    {
        $channel = $this->makeChannelWithCredential();

        $response = $this->getJson(
            "/api/plugin/channels/{$channel->id}/status",
            $this->basicAuthHeader('ck_test', 'cs_test'),
        );

        $response->assertOk();
        $response->assertJsonPath('data.channel.id', $channel->id);
    }

    public function test_status_rejects_missing_authorization_header(): void
    {
        $channel = $this->makeChannelWithCredential();

        $response = $this->getJson("/api/plugin/channels/{$channel->id}/status");

        $response->assertStatus(401);
    }

    public function test_status_rejects_wrong_secret_for_the_correct_key(): void
    {
        $channel = $this->makeChannelWithCredential();

        $response = $this->getJson(
            "/api/plugin/channels/{$channel->id}/status",
            $this->basicAuthHeader('ck_test', 'wrong-secret'),
        );

        $response->assertStatus(401);
    }

    public function test_status_rejects_another_channels_valid_credential(): void
    {
        $channelA = $this->makeChannelWithCredential(consumerKey: 'ck_a', consumerSecret: 'cs_a');
        $this->makeChannelWithCredential(consumerKey: 'ck_b', consumerSecret: 'cs_b');

        $response = $this->getJson(
            "/api/plugin/channels/{$channelA->id}/status",
            $this->basicAuthHeader('ck_b', 'cs_b'),
        );

        $response->assertStatus(401);
    }

    public function test_status_rejects_when_channel_has_no_credential_at_all(): void
    {
        $channel = $this->makeChannelWithCredential(consumerKey: null, consumerSecret: null);

        $response = $this->getJson(
            "/api/plugin/channels/{$channel->id}/status",
            $this->basicAuthHeader('anything', 'anything'),
        );

        $response->assertStatus(401);
    }

    // ═══ STATUS — PAYLOAD SHAPE (existing data only, never fabricated) ═══════

    public function test_status_reports_registered_webhooks_from_existing_columns_only(): void
    {
        $channel = $this->makeChannelWithCredential([
            'external_webhook_order_created_id' => 'wh_1',
            'external_webhook_order_updated_id' => 'wh_2',
        ]);

        $response = $this->getJson(
            "/api/plugin/channels/{$channel->id}/status",
            $this->basicAuthHeader('ck_test', 'cs_test'),
        );

        $response->assertOk();
        $response->assertJsonPath('data.webhooks.order.created', true);
        $response->assertJsonPath('data.webhooks.order.updated', true);
        $response->assertJsonPath('data.webhooks.product.created', false);
        $response->assertJsonPath('data.webhooks.customer.updated', false);
    }

    public function test_status_reflects_the_canonical_health_status_authority(): void
    {
        $channel = $this->makeChannelWithCredential([
            'last_error_at' => now(),
            'last_error_message' => 'Woo returned HTTP 500',
            'last_successful_sync_at' => now()->subDay(),
        ]);

        $response = $this->getJson(
            "/api/plugin/channels/{$channel->id}/status",
            $this->basicAuthHeader('ck_test', 'cs_test'),
        );

        $response->assertOk();
        $response->assertJsonPath('data.channel.health_status', $channel->healthStatus()->value);
        $this->assertSame('error', $channel->healthStatus()->value);
    }

    public function test_status_response_never_contains_the_consumer_secret(): void
    {
        $channel = $this->makeChannelWithCredential(consumerKey: 'ck_test', consumerSecret: 'super-secret-value');

        $response = $this->getJson(
            "/api/plugin/channels/{$channel->id}/status",
            $this->basicAuthHeader('ck_test', 'super-secret-value'),
        );

        $response->assertOk();
        $this->assertStringNotContainsString('super-secret-value', $response->getContent());
    }

    // ═══ DEACTIVATED — AUDIT BREADCRUMB, NEVER A LIFECYCLE MUTATION ══════════

    public function test_deactivated_writes_exactly_one_audit_entry(): void
    {
        $channel = $this->makeChannelWithCredential();

        $response = $this->postJson(
            "/api/plugin/channels/{$channel->id}/deactivated",
            ['reason' => 'wordpress_plugin_deactivated'],
            $this->basicAuthHeader('ck_test', 'cs_test'),
        );

        $response->assertOk();
        $this->assertSame(
            1,
            ChannelSyncAudit::query()->where('channel_id', $channel->id)->where('action', 'plugin.deactivated')->count(),
        );
    }

    public function test_deactivated_never_mutates_lifecycle_state(): void
    {
        $channel = $this->makeChannelWithCredential(['lifecycle_state' => 'live']);

        $this->postJson(
            "/api/plugin/channels/{$channel->id}/deactivated",
            [],
            $this->basicAuthHeader('ck_test', 'cs_test'),
        );

        $this->assertTrue($channel->fresh()->isLive());
    }

    public function test_deactivated_rejects_invalid_credential_and_writes_no_audit_entry(): void
    {
        $channel = $this->makeChannelWithCredential();

        $response = $this->postJson(
            "/api/plugin/channels/{$channel->id}/deactivated",
            [],
            $this->basicAuthHeader('ck_test', 'wrong-secret'),
        );

        $response->assertStatus(401);
        $this->assertSame(0, ChannelSyncAudit::query()->where('channel_id', $channel->id)->count());
    }
}
