<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Voice\Application\Contracts;

use Illuminate\Http\Request;

/**
 * TASK-ECOS-V1.1-CRM-03-OMNICHANNEL-VOICE-BACKEND-IMPLEMENTATION-015 §6 — the provider-neutral
 * telephony seam, modeled explicitly on
 * {@see \Modules\CustomerEngagement\Application\Contracts\ChannelProviderContract}'s proven
 * shape (same validateWebhook()/parse-inbound-event()/verification-challenge style) rather than
 * shoehorned into it — sendMessage/sendTemplate/sendMedia/markAsRead simply do not fit a call.
 *
 * No concrete vendor is selected in this task (architecture report, EXTERNAL DEPENDENCIES) — see
 * {@see \Modules\CustomerEngagement\Voice\Infrastructure\Providers\UnavailableTelephonyProvider}
 * for the fail-closed binding until one is.
 */
interface TelephonyProviderContract
{
    /**
     * @param  array<string, mixed>  $options
     * @return array{provider_call_id: string} At minimum; adapters may return more.
     */
    public function initiateOutboundCall(string $fromNumber, string $toNumber, array $options = []): array;

    /**
     * Validate the incoming webhook/event signature/authenticity. Same contract shape as
     * ChannelProviderContract::validateWebhook() — a false/failed result must never be allowed
     * to transition Call state (§22).
     */
    public function validateWebhook(Request $request, string $webhookSecret): bool;

    /**
     * Parse a raw inbound provider payload into ONE normalized call-lifecycle event.
     *
     * @param  array<string, mixed>  $payload
     * @return array{
     *     provider_call_id: string,
     *     direction: string,
     *     from_number: string,
     *     to_number: string,
     *     signal: string,
     *     raw_status: string,
     *     event_id: string|null,
     * }
     */
    public function parseInboundEvent(array $payload): array;

    public function answer(string $providerCallId): bool;

    public function hangup(string $providerCallId): bool;

    /**
     * @return array{provider_call_id: string, status: string}
     */
    public function bridgeTransfer(string $providerCallId, string $targetNumber): array;

    /**
     * @return array{provider_call_id: string, raw_status: string}
     */
    public function getCallStatus(string $providerCallId): array;
}
