<?php

declare(strict_types=1);

namespace Modules\Commerce\Synchronization\Application\Services;

use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\Synchronization\Domain\Enums\SyncDirection;
use Modules\Commerce\Synchronization\Domain\Enums\SyncEntityType;
use Modules\Commerce\Synchronization\Domain\Enums\SyncStatus;
use Throwable;

/**
 * Manages the full lifecycle of WooCommerce webhooks for all 7 topics — the ONE desired-webhook
 * manifest authority (topics, callback destination, signing secret). TASK-ECOS-V1.1-WOO-05 /
 * 042A-R1 §6. `WooCommerceWebhookRegistrar` (a strict subset, zero real consumers) was deleted
 * in WOO-06 §17-A.
 *
 * TASK-...-CONSOLIDATED-REMEDIATION-001-R2-R1 §11/§12/§16 — store-side APPLICATION of that
 * manifest now goes through WooOutboundCommandDispatcher exactly like every other outbound
 * mutation, so there are never two independent store-side webhook creators for one Channel:
 *
 *   - A paired Channel (connector_token set) applies the manifest via the plugin, which creates
 *     Woo webhooks locally using WooCommerce's own WC_REST_Webhooks_Controller — ECOS never
 *     calls Woo's REST API directly for such a Channel again.
 *   - An unpaired (legacy) Channel keeps the original direct-REST application, unchanged.
 *
 * The webhook's HMAC signing secret is the connector_token for a paired Channel (the same
 * already-issued, channel-scoped secret — not a third one) or consumer_secret for a legacy
 * Channel, mirrored by WooCommerceWebhookController::verifySignature() on the ingress side.
 *
 * Registration failures used to be silently swallowed (`catch (Throwable) {}`) — per 042A-R1
 * §6, every register()/deregister() attempt produces a SyncLog row via the same SyncLogService
 * every other outbound sync job already uses. `deregister()` also only clears the channel's
 * webhook-id column on a confirmed-successful remote response — an unconfirmed deregistration
 * must not make ECOS forget a webhook that may still be live on the Woo side.
 *
 * Delivery URL scheme (production-ready):
 *   orders:    {APP_URL}/api/webhooks/woocommerce/{channel_id}/orders
 *   products:  {APP_URL}/api/webhooks/woocommerce/{channel_id}/products
 *   customers: {APP_URL}/api/webhooks/woocommerce/{channel_id}/customers
 *
 * APP_URL must be a publicly accessible URL in production.
 */
final class WebhookManagerService
{
    /**
     * Maps each WooCommerce topic to the Channel column that stores its webhook ID
     * and the ERP route segment that handles it.
     *
     * @var array<string, array{column: string, route: string}>
     */
    private const TOPICS = [
        'order.created' => ['column' => 'external_webhook_order_created_id',    'route' => 'orders'],
        'order.updated' => ['column' => 'external_webhook_order_updated_id',    'route' => 'orders'],
        'product.created' => ['column' => 'external_webhook_product_created_id',  'route' => 'products'],
        'product.updated' => ['column' => 'external_webhook_product_updated_id',  'route' => 'products'],
        'product.deleted' => ['column' => 'external_webhook_product_deleted_id',  'route' => 'products'],
        'customer.created' => ['column' => 'external_webhook_customer_created_id', 'route' => 'customers'],
        'customer.updated' => ['column' => 'external_webhook_customer_updated_id', 'route' => 'customers'],
    ];

    public function __construct(
        private readonly SyncLogService $logService,
        private readonly WooOutboundCommandDispatcher $dispatcher,
    ) {}

    /** Registers only topics not already registered — the existing, correct behavior. */
    public function registerAll(Channel $channel): void
    {
        $secret = $this->webhookSecretFor($channel);

        if ($secret === null) {
            return;
        }

        $baseUrl = rtrim(config('app.url'), '/').'/api/webhooks/woocommerce/'.$channel->id.'/';

        foreach (self::TOPICS as $topic => $config) {
            if ($channel->{$config['column']} !== null) {
                continue;
            }

            $this->register($channel, $secret, $topic, $baseUrl.$config['route'], $config['column']);
        }
    }

    /**
     * Forces re-registration of every topic regardless of whether it's already registered —
     * for the two events 042A-R1 §6 names explicitly: a store-url change (the existing remote
     * webhook, if any, now delivers to a stale URL) and a credential rotation (the existing
     * remote webhook was signed with a secret Woo no longer has). Deregisters the stale
     * registration first where one exists; a failed deregistration there is non-fatal (logged,
     * not thrown) — the row may already be gone on the Woo side, or the old URL may no longer
     * resolve at all, and either way the correct registration must still be attempted.
     */
    public function reregisterAll(Channel $channel): void
    {
        $secret = $this->webhookSecretFor($channel);

        if ($secret === null) {
            return;
        }

        $baseUrl = rtrim(config('app.url'), '/').'/api/webhooks/woocommerce/'.$channel->id.'/';

        foreach (self::TOPICS as $topic => $config) {
            $existingId = $channel->{$config['column']};

            if ($existingId !== null) {
                $this->deregister($channel, $existingId, $config['column']);
            }

            $this->register($channel, $secret, $topic, $baseUrl.$config['route'], $config['column']);
        }
    }

    public function deregisterAll(Channel $channel): void
    {
        if ($this->webhookSecretFor($channel) === null) {
            return;
        }

        foreach (self::TOPICS as $config) {
            $webhookId = $channel->{$config['column']};

            if ($webhookId === null) {
                continue;
            }

            $this->deregister($channel, $webhookId, $config['column']);
        }
    }

    /**
     * The webhook HMAC signing secret for this Channel — connector_token for a paired
     * (Connector-mode) Channel, consumer_secret for a legacy direct-REST one. Null means
     * neither exists yet, so there is nothing to register/deregister against.
     */
    private function webhookSecretFor(Channel $channel): ?string
    {
        $credential = $channel->credential;

        if ($credential === null) {
            return null;
        }

        return $credential->connector_token ?? $credential->consumer_secret;
    }

    private function register(
        Channel $channel,
        string $secret,
        string $topic,
        string $deliveryUrl,
        string $idColumn,
    ): void {
        $log = $this->logService->createLog(
            $channel,
            SyncEntityType::Webhook,
            SyncDirection::Outbound,
            'webhook.register',
            $topic,
            SyncStatus::Processing,
            ['topic' => $topic, 'delivery_url' => $deliveryUrl],
        );

        try {
            $result = $this->dispatcher->create($channel, 'webhooks', [
                'name' => 'ECOS ERP – '.$topic,
                'topic' => $topic,
                'delivery_url' => $deliveryUrl,
                // Never logged — see SyncLogService payloads above/below, neither of which
                // includes $secret.
                'secret' => $secret,
                'status' => 'active',
            ]);

            if (! $result->ok) {
                $this->logService->markFailed($log, (string) $result->error, null, $channel);

                return;
            }

            $webhookId = (string) ($result->data['id'] ?? '');

            if ($webhookId === '') {
                $this->logService->markFailed($log, 'Woo did not return a webhook id.', ['status' => $result->status], $channel);

                return;
            }

            $channel->update([$idColumn => $webhookId]);
            $this->logService->markSuccess($log, ['webhook_id' => $webhookId], $channel);
        } catch (Throwable $e) {
            $this->logService->markFailed($log, $e->getMessage(), null, $channel);
        }
    }

    private function deregister(Channel $channel, string $webhookId, string $idColumn): void
    {
        $log = $this->logService->createLog(
            $channel,
            SyncEntityType::Webhook,
            SyncDirection::Outbound,
            'webhook.deregister',
            $webhookId,
            SyncStatus::Processing,
            ['webhook_id' => $webhookId],
        );

        try {
            $result = $this->dispatcher->delete($channel, 'webhooks', $webhookId);

            if (! $result->ok) {
                // Deliberately NOT clearing $idColumn — an unconfirmed deregistration must not
                // make ECOS forget a webhook that may still be live on the Woo side.
                $this->logService->markFailed($log, (string) $result->error, null, $channel);

                return;
            }

            $channel->update([$idColumn => null]);
            $this->logService->markSuccess($log, ['webhook_id' => $webhookId], $channel);
        } catch (Throwable $e) {
            $this->logService->markFailed($log, $e->getMessage(), null, $channel);
        }
    }
}
