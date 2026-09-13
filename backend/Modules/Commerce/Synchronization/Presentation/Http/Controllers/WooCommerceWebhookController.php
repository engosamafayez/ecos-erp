<?php

declare(strict_types=1);

namespace Modules\Commerce\Synchronization\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\Synchronization\Application\Jobs\ProcessCustomerWebhookJob;
use Modules\Commerce\Synchronization\Application\Jobs\ProcessOrderWebhookJob;
use Modules\Commerce\Synchronization\Application\Jobs\ProcessProductWebhookJob;
use Modules\Commerce\Synchronization\Application\Services\SyncLogService;
use Modules\Commerce\Synchronization\Domain\Enums\SyncDirection;
use Modules\Commerce\Synchronization\Domain\Enums\SyncEntityType;

final class WooCommerceWebhookController extends Controller
{
    use HasApiResponse;

    public function handleOrder(Request $request, Channel $channel, SyncLogService $logService): JsonResponse
    {
        if (! $this->verifySignature($request, $channel)) {
            $this->logRejection($channel, $logService, SyncEntityType::Order);

            return $this->error('Invalid or missing webhook signature.', 401);
        }

        $channel->update(['last_webhook_received_at' => now()]);

        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();

        $topic = is_string($request->header('X-WC-Webhook-Topic')) ? $request->header('X-WC-Webhook-Topic') : 'order.webhook';
        $externalOrderId = (string) ($payload['id'] ?? '');

        // TASK-...-WOO-05 (042A-R1 / implementation ticket §4) — Channel::isLive() is the
        // established dispatch authority (WOO-04): a channel that is DRAFT/CONFIGURED/READY/
        // PAUSED/DISABLED must not perform normal live synchronization side effects. Checked
        // here (not only inside ProcessOrderWebhookJob's own defense-in-depth check) so a
        // not-yet-live channel's webhooks are never even queued, not merely skipped later.
        // TASK-...-CONSOLIDATED-REMEDIATION-001-R2-R1 §17 — canSyncNow() also holds a stale
        // native Woo webhook for a paired Channel whose Connector has explicitly disconnected
        // or gone stale, at the ingress boundary — not only inside each Job's own
        // defense-in-depth check.
        if (! $channel->canSyncNow()) {
            $logService->createSkippedLog(
                $channel,
                SyncEntityType::Order,
                SyncDirection::Inbound,
                'channel_not_live',
                $externalOrderId !== '' ? $externalOrderId : null,
                ['topic' => $topic, 'lifecycle_state' => $channel->lifecycle_state->value],
            );

            return $this->success(null, 'Channel is not live; webhook skipped.');
        }

        // TASK-...-025 (W6/P1) — Orders Sync pause gate. Still 200s the webhook (Woo would
        // otherwise retry-storm a non-2xx response) and still records it in sync_logs, but never
        // reaches ProcessOrderWebhookJob: "no NEW Woo Orders enter ECOS" while paused. The
        // checkpoint (channel.orders_sync_watermark_at) is untouched by this branch.
        if (! $channel->sync_orders) {
            $logService->createSkippedLog(
                $channel,
                SyncEntityType::Order,
                SyncDirection::Inbound,
                'orders_sync_paused',
                $externalOrderId !== '' ? $externalOrderId : null,
                ['topic' => $topic],
            );

            return $this->success(null, 'Orders Sync is paused for this channel; webhook skipped.');
        }

        if ($externalOrderId !== '' && $this->isDuplicate($channel->id, $externalOrderId, $topic)) {
            $logService->createSkippedLog(
                $channel,
                SyncEntityType::Order,
                SyncDirection::Inbound,
                'duplicate_webhook',
                $externalOrderId,
                ['topic' => $topic, 'external_order_id' => $externalOrderId],
            );

            return $this->success(null, 'Duplicate webhook detected, skipped.');
        }

        ProcessOrderWebhookJob::dispatch($channel, $payload, $topic);

        return $this->success(null, 'Webhook received.');
    }

    public function handleProduct(Request $request, Channel $channel, SyncLogService $logService): JsonResponse
    {
        if (! $this->verifySignature($request, $channel)) {
            $this->logRejection($channel, $logService, SyncEntityType::Product);

            return $this->error('Invalid or missing webhook signature.', 401);
        }

        $channel->update(['last_webhook_received_at' => now()]);

        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();
        $topic = is_string($request->header('X-WC-Webhook-Topic')) ? $request->header('X-WC-Webhook-Topic') : 'product.webhook';
        $externalId = (string) ($payload['id'] ?? '');

        // TASK-...-WOO-05 — see handleOrder()'s identical check for the full rationale.
        // TASK-...-CONSOLIDATED-REMEDIATION-001-R2-R1 §17 — canSyncNow() also holds a stale
        // native Woo webhook for a paired Channel whose Connector has explicitly disconnected
        // or gone stale, at the ingress boundary — not only inside each Job's own
        // defense-in-depth check.
        if (! $channel->canSyncNow()) {
            $logService->createSkippedLog(
                $channel,
                SyncEntityType::Product,
                SyncDirection::Inbound,
                'channel_not_live',
                $externalId !== '' ? $externalId : null,
                ['topic' => $topic, 'lifecycle_state' => $channel->lifecycle_state->value],
            );

            return $this->success(null, 'Channel is not live; webhook skipped.');
        }

        if ($externalId !== '' && $this->isDuplicate($channel->id, $externalId, $topic)) {
            $logService->createSkippedLog(
                $channel,
                SyncEntityType::Product,
                SyncDirection::Inbound,
                'duplicate_webhook',
                $externalId,
                ['topic' => $topic, 'external_product_id' => $externalId],
            );

            return $this->success(null, 'Duplicate webhook detected, skipped.');
        }

        ProcessProductWebhookJob::dispatch($channel, $payload, $topic);

        return $this->success(null, 'Webhook received.');
    }

    public function handleCustomer(Request $request, Channel $channel, SyncLogService $logService): JsonResponse
    {
        if (! $this->verifySignature($request, $channel)) {
            $this->logRejection($channel, $logService, SyncEntityType::Customer);

            return $this->error('Invalid or missing webhook signature.', 401);
        }

        $channel->update(['last_webhook_received_at' => now()]);

        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();
        $topic = is_string($request->header('X-WC-Webhook-Topic')) ? $request->header('X-WC-Webhook-Topic') : 'customer.webhook';
        $externalId = (string) ($payload['id'] ?? '');

        // TASK-...-WOO-05 — see handleOrder()'s identical check for the full rationale.
        // TASK-...-CONSOLIDATED-REMEDIATION-001-R2-R1 §17 — canSyncNow() also holds a stale
        // native Woo webhook for a paired Channel whose Connector has explicitly disconnected
        // or gone stale, at the ingress boundary — not only inside each Job's own
        // defense-in-depth check.
        if (! $channel->canSyncNow()) {
            $logService->createSkippedLog(
                $channel,
                SyncEntityType::Customer,
                SyncDirection::Inbound,
                'channel_not_live',
                $externalId !== '' ? $externalId : null,
                ['topic' => $topic, 'lifecycle_state' => $channel->lifecycle_state->value],
            );

            return $this->success(null, 'Channel is not live; webhook skipped.');
        }

        if ($externalId !== '' && $this->isDuplicate($channel->id, $externalId, $topic)) {
            $logService->createSkippedLog(
                $channel,
                SyncEntityType::Customer,
                SyncDirection::Inbound,
                'duplicate_webhook',
                $externalId,
                ['topic' => $topic, 'external_customer_id' => $externalId],
            );

            return $this->success(null, 'Duplicate webhook detected, skipped.');
        }

        ProcessCustomerWebhookJob::dispatch($channel, $payload, $topic);

        return $this->success(null, 'Webhook received.');
    }

    /**
     * TASK-...-CONSOLIDATED-REMEDIATION-001-R2-R1 §16 — the signing secret is
     * connector_token for a paired (Connector-mode) Channel or consumer_secret for a legacy
     * direct-REST one, mirroring exactly which secret WebhookManagerService registered the
     * webhook with (see its own webhookSecretFor()) — never a third, separately-tracked secret.
     */
    private function verifySignature(Request $request, Channel $channel): bool
    {
        $signature = $request->header('X-WC-Webhook-Signature');

        if (! is_string($signature) || $signature === '') {
            return false;
        }

        $credential = $channel->credential;

        if ($credential === null) {
            return false;
        }

        $secret = $credential->connector_token ?? $credential->consumer_secret;

        if ($secret === null) {
            return false;
        }

        $rawBody = $request->getContent();
        $expected = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));

        return hash_equals($expected, $signature);
    }

    private function isDuplicate(string $channelId, string $externalId, string $topic): bool
    {
        $key = "wc_webhook:{$channelId}:{$externalId}:{$topic}";

        return ! Cache::add($key, true, 300);
    }

    private function logRejection(Channel $channel, SyncLogService $logService, SyncEntityType $entityType): void
    {
        $logService->createSkippedLog(
            $channel,
            $entityType,
            SyncDirection::Inbound,
            'signature_rejected',
            null,
            ['reason' => 'invalid or missing X-WC-Webhook-Signature'],
        );
    }
}
