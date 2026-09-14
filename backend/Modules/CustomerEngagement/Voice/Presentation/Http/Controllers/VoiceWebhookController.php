<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Voice\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\CustomerEngagement\Domain\Models\ChannelProvider;
use Modules\CustomerEngagement\Voice\Application\Contracts\TelephonyProviderContract;
use Modules\CustomerEngagement\Voice\Application\Services\VoiceCallService;
use RuntimeException;
use Throwable;

/**
 * TASK-ECOS-V1.1-CRM-03-OMNICHANNEL-VOICE-BACKEND-IMPLEMENTATION-015 §22 — mirrors
 * WebhookController::receive()'s exact fail-closed pattern: an unauthenticated/unverified event
 * never transitions Call state, but the provider still gets a 200 so it never retry-floods.
 * With no concrete vendor bound (UnavailableTelephonyProvider), validateWebhook() always
 * returns false here, so this endpoint exists and is reachable but genuinely does nothing yet —
 * exactly the "source foundation complete, concrete provider = external dependency" state the
 * architecture report and this ticket both call for.
 */
class VoiceWebhookController extends Controller
{
    public function __construct(
        private readonly TelephonyProviderContract $telephony,
        private readonly VoiceCallService $calls,
    ) {}

    public function receive(Request $request, string $channelProviderId): JsonResponse
    {
        $config = ChannelProvider::findOrFail($channelProviderId);

        try {
            if (! $this->telephony->validateWebhook($request, (string) $config->webhook_secret)) {
                report(new RuntimeException('Invalid Voice webhook signature'));

                return response()->json(['ok' => true]);
            }

            $event = $this->telephony->parseInboundEvent($request->all());
            $this->calls->handleInboundEvent($config, $event);
        } catch (Throwable $e) {
            report($e);
        }

        return response()->json(['ok' => true]);
    }
}
