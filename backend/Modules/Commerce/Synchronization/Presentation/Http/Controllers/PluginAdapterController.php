<?php

declare(strict_types=1);

namespace Modules\Commerce\Synchronization\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\Synchronization\Application\Actions\ExchangePairingCodeAction;
use Modules\Commerce\Synchronization\Application\Services\ChannelSyncAuditLogger;
use Modules\Commerce\Synchronization\Application\Services\WebhookManagerService;

/**
 * TASK-...-CONSOLIDATED-REMEDIATION-001-R2 — the ECOS WooCommerce Connector plugin's full
 * server-side surface: pairing, status/diagnostics, heartbeat, repair, and deactivation. Every
 * authenticated action here uses the Channel-scoped `connector_token` issued during pairing
 * (ExchangePairingCodeAction) — never the consumer_key/consumer_secret pair that remains
 * exclusively ECOS's own credential for calling Woo's REST API.
 *
 * This controller owns NO business authority: it never mutates Order/Product/Customer/Finance
 * state, never touches Inventory, and reuses WebhookManagerService (the one webhook
 * registration authority, unchanged) rather than registering/deregistering anything itself.
 */
final class PluginAdapterController extends Controller
{
    use HasApiResponse;

    public function pair(Request $request, ExchangePairingCodeAction $action): JsonResponse
    {
        $result = $action->execute((string) $request->input('pairing_code', ''));

        if (! $result->isSuccess()) {
            return $this->error($result->message() ?? 'Pairing failed.', 422);
        }

        return $this->success($result->data(), $result->message());
    }

    public function status(Request $request, Channel $channel): JsonResponse
    {
        if (! $this->verifyConnectorToken($request, $channel)) {
            return $this->error('Invalid or missing connector token.', 401);
        }

        $credential = $channel->credential;

        return $this->success([
            'channel' => [
                'id' => $channel->id,
                'name' => $channel->name,
                'store_url' => $channel->store_url,
                'lifecycle_state' => $channel->lifecycle_state->value,
                'lifecycle_state_label' => $channel->lifecycle_state->label(),
                'connection_status' => $channel->connection_status->value,
                'health_status' => $channel->healthStatus()->value,
                'connector_health' => $channel->connectorHealth()->value,
                'connector_health_label' => $channel->connectorHealth()->label(),
                'is_active' => $channel->is_active,
            ],
            'webhooks' => [
                'order.created' => $channel->external_webhook_order_created_id !== null,
                'order.updated' => $channel->external_webhook_order_updated_id !== null,
                'product.created' => $channel->external_webhook_product_created_id !== null,
                'product.updated' => $channel->external_webhook_product_updated_id !== null,
                'product.deleted' => $channel->external_webhook_product_deleted_id !== null,
                'customer.created' => $channel->external_webhook_customer_created_id !== null,
                'customer.updated' => $channel->external_webhook_customer_updated_id !== null,
            ],
            'last_sync_at' => $channel->last_sync_at?->toIso8601String(),
            'last_webhook_received_at' => $channel->last_webhook_received_at?->toIso8601String(),
            'last_successful_sync_at' => $channel->last_successful_sync_at?->toIso8601String(),
            'last_error_at' => $channel->last_error_at?->toIso8601String(),
            'last_error_message' => $channel->last_error_message,
            'connector_last_heartbeat_at' => $channel->connector_last_heartbeat_at?->toIso8601String(),
            // Confirms which credential the plugin is presenting without ever echoing the
            // secret. Null for a pure Connector-mode channel — there is no consumer_key at all
            // (TASK-...-CONSOLIDATED-REMEDIATION-001-R2-R1 §6).
            'credential_key_suffix' => $credential?->consumer_key !== null ? mb_substr($credential->consumer_key, -4) : null,
            'transport_mode' => $credential?->connector_token !== null ? 'connector' : 'direct_rest',
        ]);
    }

    /**
     * Called on the plugin's own periodic schedule (WP-Cron) — the ONLY thing that keeps
     * connectorHealth() from degrading to Degraded/stale. Read-only for everything except the
     * heartbeat timestamp itself; no business side effects.
     */
    public function heartbeat(Request $request, Channel $channel): JsonResponse
    {
        if (! $this->verifyConnectorToken($request, $channel)) {
            return $this->error('Invalid or missing connector token.', 401);
        }

        $channel->update([
            'connector_last_heartbeat_at' => now(),
            'connector_disconnected_at' => null,
        ]);

        return $this->success([
            'connector_health' => $channel->fresh()->connectorHealth()->value,
        ], 'Heartbeat recorded.');
    }

    /**
     * "Repair Connection" — re-verifies Woo REST connectivity is possible with the channel's
     * existing credential and force-re-registers every webhook topic via
     * WebhookManagerService::reregisterAll() (the same authority UpdateChannelAction already
     * uses on a credential/URL change — not a second registration mechanism), fixing a
     * manually-deleted, stale, or wrongly-pointed Woo-side subscription without the merchant
     * ever touching Woo's own webhook settings screen.
     */
    public function repair(Request $request, Channel $channel, WebhookManagerService $webhookManager, ChannelSyncAuditLogger $auditLogger): JsonResponse
    {
        if (! $this->verifyConnectorToken($request, $channel)) {
            return $this->error('Invalid or missing connector token.', 401);
        }

        $webhookManager->reregisterAll($channel);
        $auditLogger->log($channel, 'connector.repaired', []);

        $channel->update(['connector_last_heartbeat_at' => now(), 'connector_disconnected_at' => null]);

        return $this->success(null, 'Repair complete.');
    }

    /**
     * WordPress plugin deactivation. Per the CTO's corrected contract (superseding the
     * bootstrap-only WOO-07 behaviour): the Connector goes OFFLINE and Woo-side webhooks are
     * deregistered (reusing WebhookManagerService — not a second deregistration path) so a
     * deactivated plugin does not keep an invisible integration running against a store that
     * no longer wants it. Channel lifecycle_state, Brand, mappings, Orders, Products,
     * Customers, and historical SyncLogs are never touched — deactivation is a connectivity
     * fact, never an implicit business decision.
     */
    public function deactivated(Request $request, Channel $channel, WebhookManagerService $webhookManager, ChannelSyncAuditLogger $auditLogger): JsonResponse
    {
        if (! $this->verifyConnectorToken($request, $channel)) {
            return $this->error('Invalid or missing connector token.', 401);
        }

        $reason = $request->string('reason')->limit(200)->value();

        $channel->update(['connector_disconnected_at' => now()]);
        $webhookManager->deregisterAll($channel);

        $auditLogger->log($channel, 'connector.deactivated', [
            'reason' => $reason !== '' ? $reason : 'wordpress_plugin_deactivated',
        ]);

        return $this->success(null, 'Deactivation noted; connector marked offline.');
    }

    /**
     * Fail-closed Bearer-token check against this channel's own connector_token — the plugin's
     * dedicated authentication secret (see ExchangePairingCodeAction), never the
     * consumer_key/consumer_secret pair.
     */
    private function verifyConnectorToken(Request $request, Channel $channel): bool
    {
        $credential = $channel->credential;

        if ($credential === null || $credential->connector_token === null) {
            return false;
        }

        $presented = $request->bearerToken();

        if (! is_string($presented) || $presented === '') {
            return false;
        }

        return hash_equals($credential->connector_token, $presented);
    }
}
