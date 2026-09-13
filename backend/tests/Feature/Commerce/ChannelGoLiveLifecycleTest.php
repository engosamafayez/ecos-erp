<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Validator;
use Modules\Commerce\Channels\Application\Actions\AcknowledgeShippingMappingAction;
use Modules\Commerce\Channels\Application\Actions\DisableChannelAction;
use Modules\Commerce\Channels\Application\Actions\PauseChannelAction;
use Modules\Commerce\Channels\Application\Actions\ReenableChannelAction;
use Modules\Commerce\Channels\Application\Actions\ResumeChannelAction;
use Modules\Commerce\Channels\Application\Actions\TransitionChannelToLiveAction;
use Modules\Commerce\Channels\Domain\Enums\ChannelLifecycleState;
use Modules\Commerce\Channels\Domain\Enums\ConnectionStatus;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\Channels\Domain\Services\ChannelGoLiveReadinessService;
use Modules\Commerce\Channels\Presentation\Http\Requests\UpdateChannelRequest;
use Modules\Commerce\ProductMappings\Domain\Models\ProductMapping;
use Modules\Commerce\Synchronization\Application\Jobs\CustomerSyncJob;
use Modules\Commerce\Synchronization\Domain\Models\ChannelSyncAudit;
use Modules\Crm\Customers\Domain\Models\Customer;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-ECOS-V1.1-WOO-04-GO-LIVE-LIFECYCLE.
 *
 * Per the CTO's Track 2 execution-model change, these are written as source-level regression
 * coverage for the approved architecture (042A-R1 §5) but their EXECUTION is deferred to the
 * consolidated Track 2 test pass — no MySQL/PHPUnit cycle was launched for this ticket.
 */
final class ChannelGoLiveLifecycleTest extends TestCase
{
    use RefreshDatabase;

    /** A channel with every go-live gate already satisfied (via vacuous passes where honest). */
    private function makeReadyChannel(): Channel
    {
        $company = Company::factory()->create();
        $brand = Brand::factory()->create(['company_id' => $company->id]);

        return Channel::factory()->create([
            'brand_id' => $brand->id,
            'is_active' => true,
            'connection_status' => ConnectionStatus::Connected->value,
            // No products/orders exist for this brand — product-mapping-coverage and
            // payment-mapping gates are honestly, vacuously satisfied (nothing to map yet).
            'sync_products' => false,
            'sync_stock' => false,
            'sync_customers' => false,
            'external_webhook_order_created_id' => 'wh_1',
            'external_webhook_order_updated_id' => 'wh_2',
            'shipping_mapping_reviewed_at' => now(),
            'orders_initial_import_policy' => 'from_now',
            'lifecycle_state' => ChannelLifecycleState::Ready->value,
        ]);
    }

    // ═══ READINESS GATES ═══════════════════════════════════════════════════

    public function test_readiness_service_reports_every_gate_ready_for_a_fully_configured_channel(): void
    {
        $channel = $this->makeReadyChannel();

        $assessment = app(ChannelGoLiveReadinessService::class)->assess($channel);

        $this->assertTrue($assessment['ready']);
        $this->assertCount(7, $assessment['gates']);
        foreach ($assessment['gates'] as $gate) {
            $this->assertTrue($gate['ready'], "Gate [{$gate['key']}] unexpectedly not ready: {$gate['reason']}");
        }
    }

    public function test_readiness_fails_on_credentials_when_connection_never_tested(): void
    {
        $channel = $this->makeReadyChannel();
        $channel->update(['connection_status' => ConnectionStatus::Disconnected->value]);

        $assessment = app(ChannelGoLiveReadinessService::class)->assess($channel->fresh());

        $this->assertFalse($assessment['ready']);
        $failing = collect($assessment['gates'])->firstWhere('key', 'credentials_valid');
        $this->assertFalse($failing['ready']);
    }

    public function test_readiness_fails_on_historical_import_policy_when_unset(): void
    {
        $channel = $this->makeReadyChannel();
        $channel->update(['orders_initial_import_policy' => null]);

        $assessment = app(ChannelGoLiveReadinessService::class)->assess($channel->fresh());

        $this->assertFalse($assessment['ready']);
        $failing = collect($assessment['gates'])->firstWhere('key', 'historical_import_policy_chosen');
        $this->assertFalse($failing['ready']);
    }

    public function test_readiness_fails_on_shipping_mapping_when_never_acknowledged(): void
    {
        $channel = $this->makeReadyChannel();
        $channel->update(['shipping_mapping_reviewed_at' => null]);

        $assessment = app(ChannelGoLiveReadinessService::class)->assess($channel->fresh());

        $this->assertFalse($assessment['ready']);
        $failing = collect($assessment['gates'])->firstWhere('key', 'shipping_mapping_acknowledged');
        $this->assertFalse($failing['ready']);
    }

    public function test_readiness_fails_on_customer_sync_policy_when_enabled_but_unchosen(): void
    {
        $channel = $this->makeReadyChannel();
        $channel->update(['sync_customers' => true, 'customer_sync_policy' => null]);

        $assessment = app(ChannelGoLiveReadinessService::class)->assess($channel->fresh());

        $this->assertFalse($assessment['ready']);
        $failing = collect($assessment['gates'])->firstWhere('key', 'customer_sync_policy_chosen');
        $this->assertFalse($failing['ready']);
    }

    public function test_readiness_fails_on_missing_customer_webhooks_when_customer_sync_enabled(): void
    {
        $channel = $this->makeReadyChannel();
        $channel->update(['sync_customers' => true, 'customer_sync_policy' => 'reuse_existing']);

        $assessment = app(ChannelGoLiveReadinessService::class)->assess($channel->fresh());

        $this->assertFalse($assessment['ready']);
        $failing = collect($assessment['gates'])->firstWhere('key', 'webhooks_registered');
        $this->assertFalse($failing['ready']);
    }

    // ═══ VALID / INVALID TRANSITION ═══════════════════════════════════════════

    public function test_transition_to_live_succeeds_when_every_gate_passes(): void
    {
        $channel = $this->makeReadyChannel();

        $result = app(TransitionChannelToLiveAction::class)->execute((string) $channel->id);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(ChannelLifecycleState::Live, $result->data()->fresh()->lifecycle_state);
        $this->assertSame(
            1,
            ChannelSyncAudit::query()->where('channel_id', $channel->id)->where('action', 'channel.go_live')->count(),
        );
    }

    public function test_transition_to_live_is_rejected_and_state_refreshed_to_the_actual_truth_when_a_gate_fails(): void
    {
        $channel = $this->makeReadyChannel();
        $channel->update(['connection_status' => ConnectionStatus::Error->value]);

        $threw = false;

        try {
            app(TransitionChannelToLiveAction::class)->execute((string) $channel->id);
        } catch (RuntimeException $e) {
            $threw = true;
            $this->assertStringContainsString('Credentials valid', $e->getMessage());
        }

        $this->assertTrue($threw);
        // CTO closure item A/4 — the attempt refreshes state to the CURRENT truth rather than
        // leaving the stale 'ready' label: credentials specifically are what's failing, so the
        // fresh derivation is DRAFT, not CONFIGURED or the old (now-inaccurate) READY.
        $this->assertSame(ChannelLifecycleState::Draft, $channel->fresh()->lifecycle_state);
    }

    public function test_a_disabled_channel_cannot_go_live_directly(): void
    {
        $channel = $this->makeReadyChannel();
        $channel->update(['lifecycle_state' => ChannelLifecycleState::Disabled->value]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('disabled channel cannot go live directly');

        app(TransitionChannelToLiveAction::class)->execute((string) $channel->id);
    }

    // ═══ IDEMPOTENT / ALREADY-LIVE REPLAY ═══════════════════════════════════

    public function test_transitioning_an_already_live_channel_is_a_successful_no_op(): void
    {
        $channel = $this->makeReadyChannel();
        $channel->update(['lifecycle_state' => ChannelLifecycleState::Live->value]);

        $result = app(TransitionChannelToLiveAction::class)->execute((string) $channel->id);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('Channel is already live.', $result->message());
        // No second audit entry for a replayed activation.
        $this->assertSame(
            0,
            ChannelSyncAudit::query()->where('channel_id', $channel->id)->where('action', 'channel.go_live')->count(),
        );
    }

    public function test_pause_then_resume_round_trips_back_to_live(): void
    {
        $channel = $this->makeReadyChannel();
        $channel->update(['lifecycle_state' => ChannelLifecycleState::Live->value]);

        $paused = app(PauseChannelAction::class)->execute((string) $channel->id);
        $this->assertSame(ChannelLifecycleState::Paused, $paused->data()->fresh()->lifecycle_state);

        $resumed = app(ResumeChannelAction::class)->execute((string) $channel->id);
        $this->assertSame(ChannelLifecycleState::Live, $resumed->data()->fresh()->lifecycle_state);
    }

    public function test_resume_is_rejected_when_a_gate_has_regressed_since_pausing(): void
    {
        $channel = $this->makeReadyChannel();
        $channel->update(['lifecycle_state' => ChannelLifecycleState::Paused->value]);
        $channel->update(['connection_status' => ConnectionStatus::Error->value]);

        $this->expectException(RuntimeException::class);

        app(ResumeChannelAction::class)->execute((string) $channel->id);
    }

    // ═══ INBOUND/OUTBOUND STAY INERT UNTIL LIVE ═════════════════════════════

    public function test_customer_observer_does_not_dispatch_for_a_channel_that_is_not_live(): void
    {
        Bus::fake([CustomerSyncJob::class]);

        $company = Company::factory()->create();
        $brand = Brand::factory()->create(['company_id' => $company->id]);
        Channel::factory()->create([
            'brand_id' => $brand->id, 'is_active' => true, 'sync_customers' => true,
            'lifecycle_state' => ChannelLifecycleState::Ready->value,
        ]);

        Customer::query()->create([
            'code' => 'CUS-GOLIVE-001', 'company_id' => $company->id, 'name' => 'Not Live Yet', 'is_active' => true,
        ]);

        Bus::assertNotDispatched(CustomerSyncJob::class);
        unset($brand);
    }

    public function test_customer_observer_dispatches_once_the_channel_is_live(): void
    {
        Bus::fake([CustomerSyncJob::class]);

        $company = Company::factory()->create();
        $brand = Brand::factory()->create(['company_id' => $company->id]);
        Channel::factory()->create([
            'brand_id' => $brand->id, 'is_active' => true, 'sync_customers' => true,
            'lifecycle_state' => ChannelLifecycleState::Live->value,
        ]);

        Customer::query()->create([
            'code' => 'CUS-GOLIVE-002', 'company_id' => $company->id, 'name' => 'Now Live', 'is_active' => true,
        ]);

        Bus::assertDispatchedTimes(CustomerSyncJob::class, 1);
    }

    // ═══ SECRETS NEVER EXPOSED ═══════════════════════════════════════════════

    public function test_shipping_mapping_acknowledgement_audit_entry_carries_no_credential_values(): void
    {
        $channel = $this->makeReadyChannel();

        app(AcknowledgeShippingMappingAction::class)->execute((string) $channel->id);

        $entry = ChannelSyncAudit::query()->where('channel_id', $channel->id)
            ->where('action', 'channel.shipping_mapping_reviewed')->firstOrFail();

        $this->assertSame([], $entry->context);
    }

    public function test_go_live_audit_context_carries_gate_reasons_but_no_credential_fields(): void
    {
        $channel = $this->makeReadyChannel();

        app(TransitionChannelToLiveAction::class)->execute((string) $channel->id);

        $entry = ChannelSyncAudit::query()->where('channel_id', $channel->id)
            ->where('action', 'channel.go_live')->firstOrFail();

        $encoded = json_encode($entry->context);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('consumer_key', $encoded);
        $this->assertStringNotContainsString('consumer_secret', $encoded);
    }

    // ═══ WOO-01/02/03 CONTRACTS UNCHANGED FOR A LIVE CHANNEL ════════════════

    public function test_customer_observer_still_fires_exactly_once_for_a_live_channel_matching_woo_02_contract(): void
    {
        Bus::fake([CustomerSyncJob::class]);

        $company = Company::factory()->create();
        $brand = Brand::factory()->create(['company_id' => $company->id]);
        Channel::factory()->create([
            'brand_id' => $brand->id, 'is_active' => true, 'sync_customers' => true,
            'lifecycle_state' => ChannelLifecycleState::Live->value,
        ]);
        // A second, non-live channel for the same brand must not add a second dispatch.
        Channel::factory()->create([
            'brand_id' => $brand->id, 'is_active' => true, 'sync_customers' => true,
            'lifecycle_state' => ChannelLifecycleState::Draft->value,
        ]);

        Customer::query()->create([
            'code' => 'CUS-GOLIVE-003', 'company_id' => $company->id, 'name' => 'Exactly One', 'is_active' => true,
        ]);

        Bus::assertDispatchedTimes(CustomerSyncJob::class, 1);
        unset($brand);
    }

    // ═══ PRE-LIVE STATE PROGRESSION (CTO closure item A) ════════════════════

    public function test_refresh_derives_draft_when_credentials_are_not_valid(): void
    {
        $channel = $this->makeReadyChannel();
        $channel->update(['lifecycle_state' => ChannelLifecycleState::Draft->value, 'connection_status' => ConnectionStatus::Disconnected->value]);

        $refreshed = app(ChannelGoLiveReadinessService::class)->refreshPreLiveState($channel->fresh());

        $this->assertSame(ChannelLifecycleState::Draft, $refreshed->lifecycle_state);
    }

    public function test_refresh_derives_configured_when_credentials_valid_but_another_gate_fails(): void
    {
        $channel = $this->makeReadyChannel();
        $channel->update(['lifecycle_state' => ChannelLifecycleState::Draft->value, 'shipping_mapping_reviewed_at' => null]);

        $refreshed = app(ChannelGoLiveReadinessService::class)->refreshPreLiveState($channel->fresh());

        $this->assertSame(ChannelLifecycleState::Configured, $refreshed->lifecycle_state);
    }

    public function test_refresh_derives_ready_when_every_gate_passes(): void
    {
        $channel = $this->makeReadyChannel();
        $channel->update(['lifecycle_state' => ChannelLifecycleState::Draft->value]);

        $refreshed = app(ChannelGoLiveReadinessService::class)->refreshPreLiveState($channel->fresh());

        $this->assertSame(ChannelLifecycleState::Ready, $refreshed->lifecycle_state);
    }

    public function test_refresh_never_touches_live_paused_or_disabled_state(): void
    {
        $service = app(ChannelGoLiveReadinessService::class);

        foreach ([ChannelLifecycleState::Live, ChannelLifecycleState::Paused, ChannelLifecycleState::Disabled] as $protected) {
            $channel = $this->makeReadyChannel();
            // Break every gate — if refresh touched a protected state it would visibly change.
            $channel->update(['lifecycle_state' => $protected->value, 'connection_status' => ConnectionStatus::Error->value]);

            $refreshed = $service->refreshPreLiveState($channel->fresh());

            $this->assertSame($protected, $refreshed->lifecycle_state, "refreshPreLiveState must never touch {$protected->value}.");
        }
    }

    public function test_go_live_call_on_a_draft_channel_refreshes_through_to_live_when_actually_ready(): void
    {
        $channel = $this->makeReadyChannel();
        // Caller/UI still believes this is DRAFT (stale label) even though the data underneath
        // already satisfies every gate.
        $channel->update(['lifecycle_state' => ChannelLifecycleState::Draft->value]);

        $result = app(TransitionChannelToLiveAction::class)->execute((string) $channel->id);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(ChannelLifecycleState::Live, $result->data()->fresh()->lifecycle_state);
    }

    // ═══ DISABLED / RE-ENABLE (CTO closure item B) ══════════════════════════

    public function test_disable_moves_any_non_disabled_state_to_disabled_and_is_idempotent(): void
    {
        $channel = $this->makeReadyChannel();
        $channel->update(['lifecycle_state' => ChannelLifecycleState::Live->value]);

        $first = app(DisableChannelAction::class)->execute((string) $channel->id);
        $this->assertSame(ChannelLifecycleState::Disabled, $first->data()->fresh()->lifecycle_state);
        $this->assertSame(
            1,
            ChannelSyncAudit::query()->where('channel_id', $channel->id)->where('action', 'channel.disabled')->count(),
        );

        // Idempotent replay: disabling an already-disabled channel is a successful no-op, no
        // second audit entry.
        $second = app(DisableChannelAction::class)->execute((string) $channel->id);
        $this->assertTrue($second->isSuccess());
        $this->assertSame('Channel is already disabled.', $second->message());
        $this->assertSame(
            1,
            ChannelSyncAudit::query()->where('channel_id', $channel->id)->where('action', 'channel.disabled')->count(),
        );
    }

    public function test_reenable_restores_the_derived_pre_live_state_never_live_directly(): void
    {
        $channel = $this->makeReadyChannel();
        $channel->update(['lifecycle_state' => ChannelLifecycleState::Disabled->value]);

        $result = app(ReenableChannelAction::class)->execute((string) $channel->id);

        // Every gate on this fixture passes, so the derived state is READY — never LIVE, since
        // that requires a separate, explicit TransitionChannelToLiveAction call.
        $this->assertSame(ChannelLifecycleState::Ready, $result->data()->fresh()->lifecycle_state);
        $this->assertNotSame(ChannelLifecycleState::Live, $result->data()->fresh()->lifecycle_state);
    }

    public function test_reenable_restores_draft_when_the_underlying_configuration_regressed_while_disabled(): void
    {
        $channel = $this->makeReadyChannel();
        $channel->update(['lifecycle_state' => ChannelLifecycleState::Disabled->value, 'connection_status' => ConnectionStatus::Error->value]);

        $result = app(ReenableChannelAction::class)->execute((string) $channel->id);

        $this->assertSame(ChannelLifecycleState::Draft, $result->data()->fresh()->lifecycle_state);
    }

    public function test_reenable_is_rejected_for_a_channel_that_is_not_disabled(): void
    {
        $channel = $this->makeReadyChannel();
        $channel->update(['lifecycle_state' => ChannelLifecycleState::Live->value]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only a disabled channel can be re-enabled.');

        app(ReenableChannelAction::class)->execute((string) $channel->id);
    }

    public function test_reenabled_channel_still_requires_an_explicit_go_live_call_to_reach_live(): void
    {
        $channel = $this->makeReadyChannel();
        $channel->update(['lifecycle_state' => ChannelLifecycleState::Disabled->value]);

        app(ReenableChannelAction::class)->execute((string) $channel->id);
        $this->assertNotSame(ChannelLifecycleState::Live, $channel->fresh()->lifecycle_state);

        $result = app(TransitionChannelToLiveAction::class)->execute((string) $channel->id);

        $this->assertSame(ChannelLifecycleState::Live, $result->data()->fresh()->lifecycle_state);
    }

    // ═══ OPERATOR-SET PRODUCT MAPPING THRESHOLD (CTO closure item C) ════════

    public function test_new_channels_default_to_an_eighty_percent_threshold(): void
    {
        $company = Company::factory()->create();
        $brand = Brand::factory()->create(['company_id' => $company->id]);
        $channel = Channel::factory()->create(['brand_id' => $brand->id]);

        $this->assertSame(80, $channel->fresh()->product_mapping_coverage_threshold);
    }

    public function test_readiness_gate_reads_the_per_channel_threshold_not_a_hardcoded_value(): void
    {
        $channel = $this->makeReadyChannel();
        $product = Product::factory()->create(['brand_id' => $channel->brand_id]);
        Product::factory()->create(['brand_id' => $channel->brand_id]);
        // 1 of 2 brand products mapped = 50% coverage.
        ProductMapping::factory()->create(['channel_id' => $channel->id, 'product_id' => $product->id]);

        $channel->update(['product_mapping_coverage_threshold' => 80]);
        $strict = app(ChannelGoLiveReadinessService::class)->assess($channel->fresh());
        $strictGate = collect($strict['gates'])->firstWhere('key', 'product_mapping_coverage');
        $this->assertFalse($strictGate['ready'], '50% coverage must not satisfy an 80% threshold.');

        $channel->update(['product_mapping_coverage_threshold' => 40]);
        $lenient = app(ChannelGoLiveReadinessService::class)->assess($channel->fresh());
        $lenientGate = collect($lenient['gates'])->firstWhere('key', 'product_mapping_coverage');
        $this->assertTrue($lenientGate['ready'], '50% coverage must satisfy a 40% threshold.');
    }

    public function test_update_channel_request_accepts_a_valid_threshold_and_rejects_an_out_of_range_one(): void
    {
        $rules = ['product_mapping_coverage_threshold' => (new UpdateChannelRequest)->rules()['product_mapping_coverage_threshold']];

        $this->assertFalse(Validator::make(['product_mapping_coverage_threshold' => 95], $rules)->errors()->has('product_mapping_coverage_threshold'));
        $this->assertTrue(Validator::make(['product_mapping_coverage_threshold' => 101], $rules)->errors()->has('product_mapping_coverage_threshold'));
        $this->assertTrue(Validator::make(['product_mapping_coverage_threshold' => -1], $rules)->errors()->has('product_mapping_coverage_threshold'));
    }
}
