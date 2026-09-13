<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Commerce\Channels\Domain\Enums\ChannelLifecycleState;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\Channels\Domain\Models\ChannelCredential;
use Modules\Commerce\Synchronization\Application\Actions\RetrySyncLogAction;
use Modules\Commerce\Synchronization\Application\Jobs\CustomerSyncJob;
use Modules\Commerce\Synchronization\Domain\Enums\SyncDirection;
use Modules\Commerce\Synchronization\Domain\Enums\SyncEntityType;
use Modules\Commerce\Synchronization\Domain\Enums\SyncStatus;
use Modules\Commerce\Synchronization\Domain\Models\SyncLog;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-...-CONSOLIDATED-REMEDIATION-001-R2-R1 §2/§3/§9 — outbound Customer synchronization must
 * use the SAME WooOutboundCommandDispatcher transport decision every other Woo outbound resource
 * already uses, so a paired (Connector) Channel never falls back to Woo REST Basic Auth (whose
 * consumer_key/consumer_secret are legitimately null in Connector mode).
 *
 * Per the CTO's Track-2 execution model these are written as source-level regression coverage but
 * their EXECUTION is deferred to the consolidated test pass — no MySQL/PHPUnit cycle was launched
 * for this ticket.
 */
final class CustomerConnectorTransportTest extends TestCase
{
    use RefreshDatabase;

    private const CONNECTOR_COMMANDS_URL = '*/wp-json/ecos-connector/v1/commands';

    private const WOO_CUSTOMERS_URL = '*/wp-json/wc/v3/customers*';

    private Company $company;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
    }

    private function makeChannel(array $overrides = []): Channel
    {
        return Channel::factory()->create(array_merge([
            'brand_id' => $this->brand->id,
            'store_url' => 'https://shop.example.com',
            'is_active' => true,
            'sync_customers' => true,
            'lifecycle_state' => ChannelLifecycleState::Live->value,
        ], $overrides));
    }

    /** A paired Connector-mode Channel: connector_token set, consumer_key/secret null, healthy heartbeat. */
    private function pairedChannel(array $overrides = []): Channel
    {
        $channel = $this->makeChannel(array_merge([
            'connector_last_heartbeat_at' => now(),
            'connector_disconnected_at' => null,
        ], $overrides));

        ChannelCredential::query()->create([
            'channel_id' => $channel->id,
            'consumer_key' => null,
            'consumer_secret' => null,
            'connector_token' => 'ct_test_token',
        ]);

        return $channel->fresh();
    }

    /** An unpaired legacy Channel: consumer_key/secret set, no connector_token. */
    private function legacyChannel(array $overrides = []): Channel
    {
        $channel = $this->makeChannel($overrides);

        ChannelCredential::query()->create([
            'channel_id' => $channel->id,
            'consumer_key' => 'ck_test',
            'consumer_secret' => 'cs_test',
            'connector_token' => null,
        ]);

        return $channel->fresh();
    }

    private function customer(array $overrides = []): Customer
    {
        return Customer::factory()->withCompany($this->company->id)->create(array_merge([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
        ], $overrides));
    }

    // ═══ PAIRED CHANNEL — CONNECTOR TRANSPORT ════════════════════════════════

    public function test_paired_customer_create_uses_connector_transport_not_basic_auth(): void
    {
        Http::fake([
            self::CONNECTOR_COMMANDS_URL => Http::response(['data' => ['id' => 4242]], 200),
            self::WOO_CUSTOMERS_URL => Http::response(['id' => 9999], 200),
        ]);

        $channel = $this->pairedChannel();
        $customer = $this->customer();

        (new CustomerSyncJob($channel, $customer))->handle(app(\Modules\Commerce\Synchronization\Application\Services\SyncLogService::class), app(\Modules\Commerce\Synchronization\Application\Services\WooOutboundCommandDispatcher::class));

        // The command travelled through the Connector plugin endpoint, carrying the ECOS-decided
        // customer fields — never Woo's public REST API with Basic Auth.
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/wp-json/ecos-connector/v1/commands')
            && $r['resource'] === 'customers'
            && $r['operation'] === 'create'
            && ($r['fields']['email'] ?? null) === 'jane@example.com');

        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/wp-json/wc/v3/customers'));
    }

    public function test_paired_customer_update_also_uses_connector_transport(): void
    {
        Http::fake([
            self::CONNECTOR_COMMANDS_URL => Http::response(['data' => ['id' => 4242]], 200),
            self::WOO_CUSTOMERS_URL => Http::response(['id' => 9999], 200),
        ]);

        $channel = $this->pairedChannel();
        // A subsequent sync of the same customer — the plugin resolves the existing Woo customer by
        // email locally and applies it as an update; from ECOS's side the transport is identical.
        $customer = $this->customer(['name' => 'Jane Updated']);

        (new CustomerSyncJob($channel, $customer))->handle(app(\Modules\Commerce\Synchronization\Application\Services\SyncLogService::class), app(\Modules\Commerce\Synchronization\Application\Services\WooOutboundCommandDispatcher::class));

        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/wp-json/ecos-connector/v1/commands')
            && $r['resource'] === 'customers');
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/wp-json/wc/v3/customers'));
    }

    public function test_connector_customer_create_persists_returned_woo_id_on_the_sync_log(): void
    {
        Http::fake([self::CONNECTOR_COMMANDS_URL => Http::response(['data' => ['id' => 4242]], 200)]);

        $channel = $this->pairedChannel();
        $customer = $this->customer();

        (new CustomerSyncJob($channel, $customer))->handle(app(\Modules\Commerce\Synchronization\Application\Services\SyncLogService::class), app(\Modules\Commerce\Synchronization\Application\Services\WooOutboundCommandDispatcher::class));

        $log = SyncLog::query()
            ->where('channel_id', $channel->id)
            ->where('entity_type', SyncEntityType::Customer->value)
            ->where('direction', SyncDirection::Outbound->value)
            ->latest('created_at')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame(SyncStatus::Success->value, $log->status->value);
        // The Woo customer id returned by the plugin is recorded through the existing authority
        // (the SyncLog success payload) — ECOS keeps no separate Woo-customer-id mapping table.
        $this->assertSame(4242, $log->response_payload['wc_customer_id']);
    }

    // ═══ DISCONNECT ELIGIBILITY ══════════════════════════════════════════════

    public function test_disconnected_paired_channel_does_not_mutate_woo(): void
    {
        Http::fake();

        // Queued while healthy, executing after an explicit Connector disconnect.
        $channel = $this->pairedChannel(['connector_disconnected_at' => now()]);
        $customer = $this->customer();

        (new CustomerSyncJob($channel, $customer))->handle(app(\Modules\Commerce\Synchronization\Application\Services\SyncLogService::class), app(\Modules\Commerce\Synchronization\Application\Services\WooOutboundCommandDispatcher::class));

        // No outbound Woo mutation at all — neither transport is used once the Channel is ineligible.
        Http::assertNothingSent();

        $log = SyncLog::query()
            ->where('channel_id', $channel->id)
            ->where('entity_type', SyncEntityType::Customer->value)
            ->latest('created_at')
            ->first();
        $this->assertNotNull($log);
        $this->assertSame(SyncStatus::Failed->value, $log->status->value);
    }

    // ═══ LEGACY (UNPAIRED) CHANNEL — DIRECT REST PRESERVED ═══════════════════

    public function test_legacy_unpaired_channel_still_uses_direct_woo_rest(): void
    {
        Http::fake([
            // Resolution GET (by email) → not found, so a create POST follows.
            self::WOO_CUSTOMERS_URL => function (Request $request) {
                return $request->method() === 'GET'
                    ? Http::response([], 200)
                    : Http::response(['id' => 77], 201);
            },
        ]);

        $channel = $this->legacyChannel();
        $customer = $this->customer();

        (new CustomerSyncJob($channel, $customer))->handle(app(\Modules\Commerce\Synchronization\Application\Services\SyncLogService::class), app(\Modules\Commerce\Synchronization\Application\Services\WooOutboundCommandDispatcher::class));

        // Legacy compatibility: the direct Woo REST API is used with Basic Auth, and the Connector
        // command endpoint is never involved.
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/wp-json/wc/v3/customers')
            && $r->method() === 'POST');
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/wp-json/ecos-connector/v1/commands'));
    }

    // ═══ RETRY / MANUAL SYNC — SAME TRANSPORT AUTHORITY ══════════════════════

    public function test_retry_dispatches_the_same_customer_sync_job(): void
    {
        Queue::fake();

        $channel = $this->pairedChannel();
        $customer = $this->customer();

        $log = SyncLog::create([
            'channel_id' => $channel->id,
            'entity_type' => SyncEntityType::Customer->value,
            'direction' => SyncDirection::Outbound->value,
            'action' => 'customer.sync',
            'entity_id' => $customer->id,
            'status' => SyncStatus::Failed->value,
        ]);

        app(RetrySyncLogAction::class)->execute($log);

        // Retry funnels back through the one job — which now owns a single transport decision — so
        // a retried Customer sync can never diverge onto a different transport than the normal path.
        Queue::assertPushed(CustomerSyncJob::class);
    }
}
