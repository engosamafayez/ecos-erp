<?php

declare(strict_types=1);

namespace Modules\Commerce\Synchronization\Application\Services;

use Illuminate\Support\Facades\Http;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\Synchronization\Domain\Enums\SyncDirection;
use Modules\Commerce\Synchronization\Domain\Enums\SyncEntityType;
use Modules\Commerce\Synchronization\Domain\Enums\SyncStatus;
use Throwable;

/**
 * Manages the full lifecycle of WooCommerce webhooks for all 7 topics.
 *
 * TASK-ECOS-V1.1-WOO-05-WEBHOOK-LIFECYCLE — architecture authority 042A-R1 §6, the ONE
 * registration authority. `WooCommerceWebhookRegistrar` (a strict subset — only 2 of these 7
 * topics — with zero real consumers, confirmed by direct search) was deleted in
 * TASK-ECOS-V1.1-WOO-06-COMPLETED-EXTERNAL-FULFILLMENT-SEMANTICS §17-A.
 *
 * Registration failures used to be silently swallowed (`catch (Throwable) {}`) — per 042A-R1
 * §6 ("persist registration failures... so they're retryable and visible, the same way sync
 * failures already are via SyncLog"), every register()/deregister() attempt now produces a
 * SyncLog row exactly like every other outbound sync job already does, via the same
 * SyncLogService. Not a new logging mechanism.
 *
 * `deregister()` also had a real bug fixed as part of "persist failures": it cleared the
 * channel's own webhook-id column unconditionally, even when the remote DELETE request failed
 * — losing track of a webhook that might still be registered on the Woo side. It now only
 * clears the column on a confirmed-successful remote response.
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

    public function __construct(private readonly SyncLogService $logService) {}

    /** Registers only topics not already registered — the existing, correct behavior. */
    public function registerAll(Channel $channel): void
    {
        $credential = $channel->credential;

        if ($credential === null) {
            return;
        }

        $baseUrl = rtrim(config('app.url'), '/').'/api/webhooks/woocommerce/'.$channel->id.'/';

        foreach (self::TOPICS as $topic => $config) {
            if ($channel->{$config['column']} !== null) {
                continue;
            }

            $this->register(
                $channel,
                $credential->consumer_key,
                $credential->consumer_secret,
                $topic,
                $baseUrl.$config['route'],
                $config['column'],
            );
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
        $credential = $channel->credential;

        if ($credential === null) {
            return;
        }

        $baseUrl = rtrim(config('app.url'), '/').'/api/webhooks/woocommerce/'.$channel->id.'/';

        foreach (self::TOPICS as $topic => $config) {
            $existingId = $channel->{$config['column']};

            if ($existingId !== null) {
                $this->deregister($channel, $credential->consumer_key, $credential->consumer_secret, $existingId, $config['column']);
            }

            $this->register(
                $channel,
                $credential->consumer_key,
                $credential->consumer_secret,
                $topic,
                $baseUrl.$config['route'],
                $config['column'],
            );
        }
    }

    public function deregisterAll(Channel $channel): void
    {
        $credential = $channel->credential;

        if ($credential === null) {
            return;
        }

        foreach (self::TOPICS as $config) {
            $webhookId = $channel->{$config['column']};

            if ($webhookId === null) {
                continue;
            }

            $this->deregister(
                $channel,
                $credential->consumer_key,
                $credential->consumer_secret,
                $webhookId,
                $config['column'],
            );
        }
    }

    private function register(
        Channel $channel,
        string $consumerKey,
        string $consumerSecret,
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
            $response = Http::withBasicAuth($consumerKey, $consumerSecret)
                ->timeout(15)
                ->post(
                    rtrim($channel->store_url, '/').'/wp-json/wc/v3/webhooks',
                    [
                        'name' => 'ECOS ERP – '.$topic,
                        'topic' => $topic,
                        'delivery_url' => $deliveryUrl,
                        // Never logged — see SyncLogService payloads above/below, neither of
                        // which includes $consumerSecret.
                        'secret' => $consumerSecret,
                        'status' => 'active',
                    ],
                );

            if (! $response->successful()) {
                $this->logService->markFailed(
                    $log,
                    "HTTP {$response->status()}: ".substr($response->body(), 0, 500),
                    null,
                    $channel,
                );

                return;
            }

            $webhookId = (string) ($response->json('id') ?? '');

            if ($webhookId === '') {
                $this->logService->markFailed($log, 'Woo did not return a webhook id.', ['status' => $response->status()], $channel);

                return;
            }

            $channel->update([$idColumn => $webhookId]);
            $this->logService->markSuccess($log, ['webhook_id' => $webhookId], $channel);
        } catch (Throwable $e) {
            $this->logService->markFailed($log, $e->getMessage(), null, $channel);
        }
    }

    private function deregister(
        Channel $channel,
        string $consumerKey,
        string $consumerSecret,
        string $webhookId,
        string $idColumn,
    ): void {
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
            $response = Http::withBasicAuth($consumerKey, $consumerSecret)
                ->timeout(15)
                ->delete(rtrim($channel->store_url, '/').'/wp-json/wc/v3/webhooks/'.$webhookId);

            if (! $response->successful()) {
                // Deliberately NOT clearing $idColumn — an unconfirmed deregistration must not
                // make ECOS forget a webhook that may still be live on the Woo side.
                $this->logService->markFailed(
                    $log,
                    "HTTP {$response->status()}: ".substr($response->body(), 0, 500),
                    null,
                    $channel,
                );

                return;
            }

            $channel->update([$idColumn => null]);
            $this->logService->markSuccess($log, ['webhook_id' => $webhookId], $channel);
        } catch (Throwable $e) {
            $this->logService->markFailed($log, $e->getMessage(), null, $channel);
        }
    }
}
