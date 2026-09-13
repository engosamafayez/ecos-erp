<?php

declare(strict_types=1);

namespace Modules\Commerce\Channels\Domain\Services;

use Modules\Admin\Configuration\Domain\Services\ConfigurationManager;
use Modules\Commerce\Channels\Domain\Enums\ChannelLifecycleState;
use Modules\Commerce\Channels\Domain\Enums\ConnectionStatus;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\ProductMappings\Domain\Models\ProductMapping;
use Modules\Inventory\Products\Domain\Models\Product;

/**
 * TASK-ECOS-V1.1-WOO-04-GO-LIVE-LIFECYCLE — architecture authority 042A-R1 §5 / 042A §10.
 *
 * Computes the seven go-live readiness gates the architecture defines, honestly — a gate is
 * never reported ready merely because credentials exist or a flag happens to be on (§8 of the
 * implementation ticket). Every gate reads an EXISTING authority; this class adds no new state
 * of its own beyond the two narrow fields WOO-04's migration introduced specifically because no
 * existing signal covered them (customer_sync_policy, shipping_mapping_reviewed_at) — mirroring
 * the pattern orders_initial_import_policy already established.
 *
 * "Payment mapping resolved" will legitimately never pass for a channel with an unmapped Woo
 * gateway until the pre-existing, separately-tracked payment-gateway-vocabulary business
 * decision (card vs credit_card; the Paymob umbrella gateway) lands — that is the architecture's
 * own documented expectation (042A-R1 §5), not a defect in this service.
 */
final class ChannelGoLiveReadinessService
{
    /** Pre-live states this service is the sole writer of — see refreshPreLiveState(). */
    private const PRE_LIVE_STATES = [ChannelLifecycleState::Draft, ChannelLifecycleState::Configured, ChannelLifecycleState::Ready];

    /** Always required — Orders is core regardless of which optional sync types are enabled. */
    private const ORDER_WEBHOOK_COLUMNS = ['external_webhook_order_created_id', 'external_webhook_order_updated_id'];

    private const PRODUCT_WEBHOOK_COLUMNS = [
        'external_webhook_product_created_id', 'external_webhook_product_updated_id', 'external_webhook_product_deleted_id',
    ];

    private const CUSTOMER_WEBHOOK_COLUMNS = ['external_webhook_customer_created_id', 'external_webhook_customer_updated_id'];

    public function __construct(private readonly ConfigurationManager $config) {}

    /**
     * @return array{ready: bool, gates: list<array{key: string, label: string, ready: bool, reason: string}>}
     */
    public function assess(Channel $channel): array
    {
        $gates = [
            $this->credentialsValid($channel),
            $this->productMappingCoverage($channel),
            $this->paymentMappingResolved($channel),
            $this->shippingMappingAcknowledged($channel),
            $this->webhooksRegistered($channel),
            $this->customerSyncPolicyChosen($channel),
            $this->historicalImportPolicyChosen($channel),
        ];

        $ready = true;
        foreach ($gates as $gate) {
            if (! $gate['ready']) {
                $ready = false;

                break;
            }
        }

        return ['ready' => $ready, 'gates' => $gates];
    }

    /**
     * The canonical pre-live state for a channel RIGHT NOW, derived purely from its current
     * data — never guessed, never left to a frontend to compute (CTO source-review closure
     * item A / implementation ticket §2-§3):
     *
     *   DRAFT      — credentials/connection have not reached a valid configured state.
     *   CONFIGURED — credentials are valid, but at least one other readiness gate is not.
     *   READY      — every one of the seven gates passes.
     *
     * Does not read or write `lifecycle_state` itself — see refreshPreLiveState() for the
     * persisting counterpart. Callers never derive LIVE/PAUSED/DISABLED from this; those three
     * change only through their own explicit, audited actions.
     */
    public function derivePreLiveState(Channel $channel): ChannelLifecycleState
    {
        $assessment = $this->assess($channel);

        if ($assessment['ready']) {
            return ChannelLifecycleState::Ready;
        }

        $credentialsGate = null;
        foreach ($assessment['gates'] as $gate) {
            if ($gate['key'] === 'credentials_valid') {
                $credentialsGate = $gate;

                break;
            }
        }

        return ($credentialsGate['ready'] ?? false) ? ChannelLifecycleState::Configured : ChannelLifecycleState::Draft;
    }

    /**
     * Recomputes and PERSISTS the pre-live state for a channel currently in one of DRAFT/
     * CONFIGURED/READY. A channel already LIVE, PAUSED, or DISABLED is returned untouched —
     * those only ever change through their own explicit actions
     * (TransitionChannelToLiveAction, Pause/ResumeChannelAction, Disable/ReenableChannelAction),
     * never as a side effect of viewing or assessing readiness.
     */
    public function refreshPreLiveState(Channel $channel): Channel
    {
        if (! in_array($channel->lifecycle_state, self::PRE_LIVE_STATES, true)) {
            return $channel;
        }

        $derived = $this->derivePreLiveState($channel);

        if ($channel->lifecycle_state !== $derived) {
            $channel->update(['lifecycle_state' => $derived->value]);
            $channel->refresh();
        }

        return $channel;
    }

    /**
     * @return array{key: string, label: string, ready: bool, reason: string}
     */
    private function credentialsValid(Channel $channel): array
    {
        $ready = $channel->connection_status === ConnectionStatus::Connected;

        return [
            'key' => 'credentials_valid',
            'label' => 'Credentials valid',
            'ready' => $ready,
            'reason' => $ready
                ? 'Test Connection last succeeded for this channel.'
                : "Run Test Connection successfully before going live (current status: {$channel->connection_status->label()}).",
        ];
    }

    /**
     * @return array{key: string, label: string, ready: bool, reason: string}
     */
    private function productMappingCoverage(Channel $channel): array
    {
        // Bypasses Product's own 'tenant' global scope so this reads identically regardless of
        // the executing actor — the same reasoning as WOO-03's SKU lookups.
        $totalProducts = Product::withoutGlobalScope('tenant')->where('brand_id', $channel->brand_id)->count();

        if ($totalProducts === 0) {
            return [
                'key' => 'product_mapping_coverage',
                'label' => 'Product/price/stock mapping coverage',
                'ready' => true,
                'reason' => 'No products exist for this brand yet — nothing to map.',
            ];
        }

        $mapped = ProductMapping::query()
            ->where('channel_id', $channel->id)
            ->whereHas('product', fn ($q) => $q->where('brand_id', $channel->brand_id))
            ->count();

        // CTO source-review closure item C — operator-set per channel, not a hard-coded
        // constant. `product_mapping_coverage_threshold` is a 0-100 percentage (validated at
        // the request layer); 80 is only the column's backward-compatible default.
        $thresholdPercent = $channel->product_mapping_coverage_threshold;
        $coveragePercent = ($mapped / $totalProducts) * 100;
        $ready = $coveragePercent >= $thresholdPercent;

        return [
            'key' => 'product_mapping_coverage',
            'label' => 'Product/price/stock mapping coverage',
            'ready' => $ready,
            'reason' => sprintf(
                '%d of %d brand products (%.0f%%) are mapped to this channel; %d%% required.',
                $mapped,
                $totalProducts,
                $coveragePercent,
                $thresholdPercent,
            ),
        ];
    }

    /**
     * @return array{key: string, label: string, ready: bool, reason: string}
     */
    private function paymentMappingResolved(Channel $channel): array
    {
        $methods = Order::query()->withoutGlobalScope('tenant')
            ->where('channel_id', $channel->id)
            ->whereNotNull('payment_method')
            ->where('payment_method', '!=', '')
            ->distinct()
            ->pluck('payment_method');

        if ($methods->isEmpty()) {
            return [
                'key' => 'payment_mapping_resolved',
                'label' => 'Payment mapping resolved',
                'ready' => true,
                'reason' => 'No orders with a payment method exist yet for this channel.',
            ];
        }

        // Same brand-scoped payment_proof_policy map PaymentFulfillmentGate resolves from —
        // read directly here (not through that class) because this asks a different question:
        // is the method EXPLICITLY present in the map, not what it resolves to when absent
        // (PaymentFulfillmentGate's own documented key-miss fallback is 'none' — fail-open by
        // design, per GAP-5 — which is exactly the state this gate must not treat as "resolved").
        $policy = $this->config->getBrandPolicy((string) $channel->brand_id, 'order')['payment_proof_policy'] ?? [];
        $policy = is_array($policy) ? $policy : [];

        $unmapped = $methods->reject(fn (string $m) => array_key_exists($m, $policy))->values();
        $ready = $unmapped->isEmpty();

        return [
            'key' => 'payment_mapping_resolved',
            'label' => 'Payment mapping resolved',
            'ready' => $ready,
            'reason' => $ready
                ? 'Every payment method seen on this channel has an explicit mapping.'
                : 'Unmapped payment gateway(s): '.$unmapped->implode(', ').
                    '. Blocked on the platform-wide payment gateway vocabulary decision (card vs credit_card; the Paymob umbrella gateway) until that lands.',
        ];
    }

    /**
     * @return array{key: string, label: string, ready: bool, reason: string}
     */
    private function shippingMappingAcknowledged(Channel $channel): array
    {
        $ready = $channel->shipping_mapping_reviewed_at !== null;

        return [
            'key' => 'shipping_mapping_acknowledged',
            'label' => 'Shipping mapping acknowledged',
            'ready' => $ready,
            'reason' => $ready
                ? "Reviewed on {$channel->shipping_mapping_reviewed_at->toIso8601String()}."
                : 'An operator has not yet reviewed shipping_method handling for this store.',
        ];
    }

    /**
     * @return array{key: string, label: string, ready: bool, reason: string}
     */
    private function webhooksRegistered(Channel $channel): array
    {
        $required = self::ORDER_WEBHOOK_COLUMNS;

        if ($channel->sync_products) {
            $required = [...$required, ...self::PRODUCT_WEBHOOK_COLUMNS];
        }

        if ($channel->sync_customers) {
            $required = [...$required, ...self::CUSTOMER_WEBHOOK_COLUMNS];
        }

        $missing = array_values(array_filter($required, fn (string $column) => $channel->{$column} === null));
        $ready = $missing === [];

        return [
            'key' => 'webhooks_registered',
            'label' => 'Webhooks registered & verified',
            'ready' => $ready,
            'reason' => $ready
                ? 'Every webhook topic this channel needs is registered.'
                : 'Missing webhook registration(s) for: '.implode(', ', $missing).'. Run Test Connection or webhooks:register.',
        ];
    }

    /**
     * @return array{key: string, label: string, ready: bool, reason: string}
     */
    private function customerSyncPolicyChosen(Channel $channel): array
    {
        $ready = ! $channel->sync_customers || $channel->customer_sync_policy !== null;

        return [
            'key' => 'customer_sync_policy_chosen',
            'label' => 'Customer sync policy chosen',
            'ready' => $ready,
            'reason' => $ready
                ? ($channel->sync_customers ? "Policy: {$channel->customer_sync_policy}." : 'Customer sync is disabled for this channel.')
                : 'Customer sync is enabled but no matching policy has been explicitly chosen.',
        ];
    }

    /**
     * @return array{key: string, label: string, ready: bool, reason: string}
     */
    private function historicalImportPolicyChosen(Channel $channel): array
    {
        $ready = $channel->orders_initial_import_policy !== null;

        return [
            'key' => 'historical_import_policy_chosen',
            'label' => 'Historical import policy chosen',
            'ready' => $ready,
            'reason' => $ready
                ? "Policy: {$channel->orders_initial_import_policy}."
                : 'No initial Orders import policy has been set (SetInitialOrdersImportPolicyAction).',
        ];
    }
}
