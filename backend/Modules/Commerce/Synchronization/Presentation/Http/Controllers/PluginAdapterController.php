<?php

declare(strict_types=1);

namespace Modules\Commerce\Synchronization\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\Synchronization\Application\Services\ChannelSyncAuditLogger;

/**
 * TASK-ECOS-V1.1-WOO-07-OFFICIAL-WOOCOMMERCE-WORDPRESS-ADAPTER — architecture authority
 * 042A §7 / 042A-R1 §7-8, the "official adapter/plugin" slice (labelled "Plugin bootstrap +
 * webhook relay" in 042A, resequenced to "Plugin adapter" in 042A-R1; both agree the plugin is
 * only structurally necessary for connection bootstrap + diagnostics, never for owning
 * products/prices/stock/customers/orders/status — that stays REST + webhook, unchanged).
 *
 * This is the ONLY new server surface WOO-07 introduces: a minimal, read-mostly channel diagnostics
 * endpoint the WordPress plugin polls, plus a deactivation notice. It is deliberately NOT part of
 * the existing Woo→ECOS event-webhook path (WooCommerceWebhookController, HMAC-signed,
 * order/product/customer topics) — this is the reverse direction (plugin→ECOS, pull, no business
 * events, no entity mutation) and authenticates with HTTP Basic Auth against the SAME
 * `channel.credential` (consumer_key/consumer_secret) Woo's own REST API and the outbound
 * WebhookManagerService already use — not a new secret system, just the existing secret checked in
 * the reverse direction. Rotating that credential (UpdateChannelAction) naturally requires the
 * operator to re-paste the new pair into the plugin's settings screen; nothing here needs its own
 * rotation propagation.
 *
 * Every field returned by status() is read directly from an already-persisted Channel column or
 * from Channel::healthStatus() (the existing, non-fabricated health authority) — never a fabricated
 * "healthy". Mirrors the WOO-05 carry-forward rule (a read must not silently persist): status() has
 * no side effects at all. deactivated() is the one discrete lifecycle-relevant event, so it writes
 * a single ChannelSyncAuditLogger entry — the existing channel-lifecycle-audit mechanism, not a new
 * one — and deliberately does NOT flip lifecycle_state, call PauseChannelAction/DisableChannelAction,
 * or deregister webhooks: Channel disable/delete remains an explicit, separate ECOS operator
 * decision (042A/R1 do not say otherwise for plugin uninstall), so this is only ever a visible
 * breadcrumb for that operator to act on.
 */
final class PluginAdapterController extends Controller
{
    use HasApiResponse;

    public function status(Request $request, Channel $channel): JsonResponse
    {
        if (! $this->verifyCredential($request, $channel)) {
            return $this->error('Invalid or missing channel credential.', 401);
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
            // Confirms which credential the plugin is presenting without ever echoing the secret.
            'credential_key_suffix' => $credential !== null ? mb_substr($credential->consumer_key, -4) : null,
        ]);
    }

    public function deactivated(Request $request, Channel $channel, ChannelSyncAuditLogger $auditLogger): JsonResponse
    {
        if (! $this->verifyCredential($request, $channel)) {
            return $this->error('Invalid or missing channel credential.', 401);
        }

        $reason = $request->string('reason')->limit(200)->value();

        $auditLogger->log($channel, 'plugin.deactivated', [
            'reason' => $reason !== '' ? $reason : 'wordpress_plugin_deactivated',
        ]);

        return $this->success(null, 'Deactivation noted.');
    }

    /**
     * Fail-closed HTTP Basic Auth check against this channel's own credential — mirrors the
     * fail-closed style of WooCommerceWebhookController::verifySignature(), just Basic-Auth
     * shaped (the plugin cannot compute an HMAC over its own outbound GET the way Woo signs its
     * outbound webhook POSTs, so Basic Auth over TLS is the equivalent existing-secret check for
     * this direction — the same scheme Woo's own REST API and WebhookManagerService already use).
     */
    private function verifyCredential(Request $request, Channel $channel): bool
    {
        $credential = $channel->credential;

        if ($credential === null) {
            return false;
        }

        $key = $request->getUser();
        $secret = $request->getPassword();

        if (! is_string($key) || $key === '' || ! is_string($secret) || $secret === '') {
            return false;
        }

        return hash_equals($credential->consumer_key, $key) && hash_equals($credential->consumer_secret, $secret);
    }
}
