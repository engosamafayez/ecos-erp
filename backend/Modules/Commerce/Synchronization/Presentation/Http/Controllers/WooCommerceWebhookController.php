<?php

declare(strict_types=1);

namespace Modules\Commerce\Synchronization\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\Synchronization\Application\Jobs\ProcessOrderWebhookJob;
use Modules\Commerce\Synchronization\Application\Services\SyncLogService;
use Modules\Commerce\Synchronization\Domain\Enums\SyncDirection;
use Modules\Commerce\Synchronization\Domain\Enums\SyncEntityType;

final class WooCommerceWebhookController extends Controller
{
    use HasApiResponse;

    public function handleOrder(Request $request, Channel $channel, SyncLogService $logService): JsonResponse
    {
        if (! $this->verifySignature($request, $channel)) {
            $this->logRejection($request, $channel, $logService);
            return $this->error('Invalid or missing webhook signature.', 401);
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();

        $topic = is_string($request->header('X-WC-Webhook-Topic')) ? $request->header('X-WC-Webhook-Topic') : 'order.webhook';
        $externalOrderId = (string) ($payload['id'] ?? '');

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

        $rawBody = $request->getContent();
        $expected = base64_encode(hash_hmac('sha256', $rawBody, $credential->consumer_secret, true));

        return hash_equals($expected, $signature);
    }

    private function isDuplicate(string $channelId, string $externalOrderId, string $topic): bool
    {
        $key = "wc_webhook:{$channelId}:{$externalOrderId}:{$topic}";

        return ! Cache::add($key, true, 300);
    }

    private function logRejection(Request $request, Channel $channel, SyncLogService $logService): void
    {
        $logService->createSkippedLog(
            $channel,
            SyncEntityType::Order,
            SyncDirection::Inbound,
            'signature_rejected',
            null,
            ['reason' => 'invalid or missing X-WC-Webhook-Signature'],
        );
    }
}
