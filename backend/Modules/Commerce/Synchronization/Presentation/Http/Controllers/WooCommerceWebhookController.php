<?php

declare(strict_types=1);

namespace Modules\Commerce\Synchronization\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\Synchronization\Application\Jobs\ProcessOrderWebhookJob;

final class WooCommerceWebhookController extends Controller
{
    use HasApiResponse;

    public function handleOrder(Request $request, Channel $channel): JsonResponse
    {
        if (! $this->verifySignature($request, $channel)) {
            return $this->error('Invalid webhook signature.', 401);
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();

        $topic = $request->header('X-WC-Webhook-Topic', 'order.webhook');
        $action = is_string($topic) ? $topic : 'order.webhook';

        ProcessOrderWebhookJob::dispatch($channel, $payload, $action);

        return $this->success(null, 'Webhook received.');
    }

    private function verifySignature(Request $request, Channel $channel): bool
    {
        $signature = $request->header('X-WC-Webhook-Signature');

        if (! is_string($signature) || $signature === '') {
            return true;
        }

        $credential = $channel->credential;

        if ($credential === null) {
            return false;
        }

        $rawBody = $request->getContent();
        $expected = base64_encode(hash_hmac('sha256', $rawBody, $credential->consumer_secret, true));

        return hash_equals($expected, $signature);
    }
}
