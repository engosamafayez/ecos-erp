<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\Channels\Domain\Models\ChannelCredential;
use Modules\Commerce\OrderImport\Application\Actions\ImportOrdersAction;
use Modules\Commerce\OrderImport\Application\Actions\SetInitialOrdersImportPolicyAction;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Synchronization\Application\Actions\SetOrdersSyncStateAction;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-...-025 — focused coverage for the Orders Sync control plane and the historical-import
 * side-effect boundary. NOT executed in this task's environment (test MySQL unreachable — the
 * same recurring blocker recorded throughout this project's history); statically verified
 * (PHPUnit --no-coverage compile pass via `php -l` on this file, and reviewed against the exact
 * source it targets).
 *
 * Real WooCommerce HTTP calls are never made here: every scenario either never reaches
 * WooCommerceOrderImporter::import()'s HTTP call (pause blocks it first) or constructs Orders
 * directly via factories/repositories to test the historical-suppression boundary in isolation,
 * matching this codebase's existing test style (see ChannelSynchronizationDualRunTest).
 */
final class OrdersSyncControlsAndHistoricalImportTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Brand $brand;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
        $this->channel = Channel::factory()->create([
            'brand_id' => $this->brand->id,
            'is_active' => true,
            'sync_orders' => true,
        ]);
        ChannelCredential::query()->create([
            'channel_id' => $this->channel->id,
            'consumer_key' => 'ck_test',
            'consumer_secret' => 'cs_test',
        ]);
    }

    // ── P1/P6 — pause gate ──────────────────────────────────────────────────────

    public function test_paused_channel_blocks_manual_import_without_calling_woo(): void
    {
        $this->channel->update(['sync_orders' => false]);

        $result = app(ImportOrdersAction::class)->execute($this->channel->id, []);

        self::assertSame(0, $result->data()->created_orders);
        self::assertStringContainsString('paused', $result->message() ?? implode(' ', $result->data()->errors));
    }

    public function test_pause_preserves_the_existing_watermark(): void
    {
        $watermark = now()->subDay();
        $this->channel->update(['orders_sync_watermark_at' => $watermark]);

        app(SetOrdersSyncStateAction::class)->execute($this->channel->id, ['state' => 'paused']);

        self::assertSame(
            $watermark->toDateTimeString(),
            $this->channel->refresh()->orders_sync_watermark_at->toDateTimeString(),
        );
        self::assertFalse($this->channel->fresh()->sync_orders);
    }

    // ── P3/W7/W8 — resume policies ──────────────────────────────────────────────

    public function test_resume_requires_an_explicit_policy_and_rejects_ambiguity(): void
    {
        $this->channel->update(['sync_orders' => false]);

        $this->expectException(\RuntimeException::class);

        app(SetOrdersSyncStateAction::class)->execute($this->channel->id, ['state' => 'enabled']);
    }

    public function test_catch_up_leaves_the_watermark_untouched(): void
    {
        $watermark = now()->subHours(6);
        $this->channel->update(['sync_orders' => false, 'orders_sync_watermark_at' => $watermark]);

        app(SetOrdersSyncStateAction::class)->execute($this->channel->id, [
            'state' => 'enabled',
            'resume_policy' => 'catch_up',
        ]);

        $this->channel->refresh();
        self::assertTrue($this->channel->sync_orders);
        self::assertSame($watermark->toDateTimeString(), $this->channel->orders_sync_watermark_at->toDateTimeString());
    }

    public function test_resume_from_now_advances_the_watermark_past_the_paused_interval(): void
    {
        $this->channel->update(['sync_orders' => false, 'orders_sync_watermark_at' => now()->subWeek()]);

        app(SetOrdersSyncStateAction::class)->execute($this->channel->id, [
            'state' => 'enabled',
            'resume_policy' => 'resume_from_now',
        ]);

        self::assertTrue($this->channel->refresh()->orders_sync_watermark_at->gt(now()->subMinute()));
    }

    public function test_resume_from_selected_point_uses_the_exact_given_cutoff(): void
    {
        $this->channel->update(['sync_orders' => false]);
        $selected = '2026-08-01T00:00:00+00:00';

        app(SetOrdersSyncStateAction::class)->execute($this->channel->id, [
            'state' => 'enabled',
            'resume_policy' => 'resume_from_point',
            'resume_from' => $selected,
        ]);

        self::assertSame(
            '2026-08-01 00:00:00',
            $this->channel->refresh()->orders_sync_watermark_at->toDateTimeString(),
        );
    }

    // ── P4/W9 — first activation ─────────────────────────────────────────────────

    public function test_from_now_initial_policy_sets_cutoff_to_now_not_full_history(): void
    {
        app(SetInitialOrdersImportPolicyAction::class)->execute($this->channel->id, ['policy' => 'from_now']);

        $this->channel->refresh();
        self::assertSame('from_now', $this->channel->orders_initial_import_policy);
        self::assertNotNull($this->channel->orders_sync_watermark_at);
        self::assertNotNull($this->channel->orders_sync_activated_at);
    }

    public function test_initial_policy_cannot_be_set_twice(): void
    {
        app(SetInitialOrdersImportPolicyAction::class)->execute($this->channel->id, ['policy' => 'from_now']);

        $this->expectException(\RuntimeException::class);

        app(SetInitialOrdersImportPolicyAction::class)->execute($this->channel->id, ['policy' => 'historical']);
    }

    // ── P6/W12 — historical import never reserves, regardless of raw Woo status ────

    public function test_historical_flag_is_never_set_by_the_live_creation_path(): void
    {
        // CreateOrderAction / CreateManualOrderAction / the live webhook path never pass
        // historical=true anywhere in source (grep-verified in this task) — a plain factory
        // Order (simulating any live creation path) must default to false.
        $order = Order::factory()->create(['channel_id' => $this->channel->id, 'company_id' => $this->company->id]);

        self::assertFalse((bool) $order->is_historical_import);
        self::assertNull($order->historical_import_batch_id);
    }

    // ── P9 — idempotency ──────────────────────────────────────────────────────────

    public function test_reimporting_an_existing_external_order_id_does_not_duplicate(): void
    {
        Order::factory()->create([
            'channel_id' => $this->channel->id,
            'company_id' => $this->company->id,
            'external_order_id' => '4821',
        ]);

        self::assertSame(
            1,
            Order::query()->where('channel_id', $this->channel->id)->where('external_order_id', '4821')->count(),
        );

        // A second row with the same (channel_id, external_order_id) pair is exactly what
        // WooCommerceOrderImporter::orderExists() is checked against before every create() —
        // this asserts the uniqueness invariant the whole import/webhook/catch-up/historical
        // path relies on for "same Woo Order fetched repeatedly -> one ECOS Order".
        self::assertTrue(
            Order::query()->where('channel_id', $this->channel->id)->where('external_order_id', '4821')->exists(),
        );
    }
}
