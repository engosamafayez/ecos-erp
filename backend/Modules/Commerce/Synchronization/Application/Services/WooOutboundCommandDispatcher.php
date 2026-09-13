<?php

declare(strict_types=1);

namespace Modules\Commerce\Synchronization\Application\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Modules\Commerce\Channels\Domain\Models\Channel;

/**
 * TASK-...-CONSOLIDATED-REMEDIATION-001-R2-R1/R2-R2 §7/§8/§20/§21 — THE ONE outbound Woo
 * mutation path for a Channel. Every job that used to build its own
 * `Http::withBasicAuth(...)->put(...)` call against Woo's REST API (ProductSyncJob,
 * PriceSyncJob, ProductAvailabilitySyncJob, OrderStatusSyncJob) now goes through here, so a
 * Channel can never have two independent transports able to mutate the same Woo resource:
 *
 *   - A Channel that has completed Connector pairing (channel.credential.connector_token is
 *     set) uses CONNECTOR transport exclusively: ECOS never touches Woo's REST API directly
 *     for this Channel again. The command is sent to the paired plugin's own endpoint, which
 *     applies it locally via WooCommerce's own REST controller classes (WC_REST_*_Controller)
 *     — the exact same, already-correct logic Woo's REST API itself runs, just invoked
 *     in-process instead of over HTTP, so no field-mapping/validation is reinvented here or in
 *     the plugin.
 *   - A Channel that has never been paired (legacy / no Connector plugin installed) keeps the
 *     original direct-REST transport, unchanged, UNAFFECTED by connector health (there is no
 *     Plugin whose absence could matter) — this is the one channel state where that path may
 *     still run, and it is mutually exclusive with the Connector path above by construction.
 *
 * TASK-...-CONSOLIDATED-REMEDIATION-001-R2-R2 §7/§8 — BUSINESS_SYNC vs CONNECTOR_MANAGEMENT.
 * `products`/`orders` are business resources: a stale queued command (enqueued before an
 * explicit disconnect, but only now executing) must not mutate Woo once the paired Connector
 * is no longer eligible for normal sync — re-checked HERE, at the transport boundary, not only
 * by whatever observer/job originally enqueued it, because eligibility can change in the
 * interval between enqueue and execute. `webhooks` is connector-management traffic (pairing,
 * repair, deregistration) and is deliberately NEVER gated this way — it must keep working
 * precisely when the Connector is being paired, repaired, or torn down, which is exactly when
 * canSyncNow() may be false.
 *
 * The Plugin decides nothing: `fields` is always the already-decided canonical payload this
 * class was going to send to Woo directly. It only transports and locally applies.
 */
final class WooOutboundCommandDispatcher
{
    /** Resources whose mutation is normal business synchronization, gated on canSyncNow(). */
    private const BUSINESS_RESOURCES = ['products', 'orders'];

    /**
     * @param  array<string, mixed>  $fields
     */
    public function put(Channel $channel, string $wooResource, string $wooId, array $fields): DispatchResult
    {
        $credential = $channel->credential;

        if ($credential === null) {
            return DispatchResult::failure('No credentials configured for this channel.');
        }

        if ($credential->connector_token !== null) {
            $blocked = $this->rejectIfBusinessSyncIneligible($channel, $wooResource);

            if ($blocked !== null) {
                return $blocked;
            }

            return $this->sendCommandToPlugin($channel, $credential->connector_token, [
                'resource' => $wooResource,
                'operation' => 'update',
                'woo_id' => $wooId,
                'fields' => $fields,
            ]);
        }

        return $this->sendDirectToWoo(
            $channel->store_url,
            $credential->consumer_key,
            $credential->consumer_secret,
            'PUT',
            "/{$wooResource}/{$wooId}",
            $fields,
        );
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function create(Channel $channel, string $wooResource, array $fields): DispatchResult
    {
        $credential = $channel->credential;

        if ($credential === null) {
            return DispatchResult::failure('No credentials configured for this channel.');
        }

        if ($credential->connector_token !== null) {
            $blocked = $this->rejectIfBusinessSyncIneligible($channel, $wooResource);

            if ($blocked !== null) {
                return $blocked;
            }

            return $this->sendCommandToPlugin($channel, $credential->connector_token, [
                'resource' => $wooResource,
                'operation' => 'create',
                'woo_id' => null,
                'fields' => $fields,
            ]);
        }

        return $this->sendDirectToWoo(
            $channel->store_url,
            $credential->consumer_key,
            $credential->consumer_secret,
            'POST',
            "/{$wooResource}",
            $fields,
        );
    }

    public function delete(Channel $channel, string $wooResource, string $wooId): DispatchResult
    {
        $credential = $channel->credential;

        if ($credential === null) {
            return DispatchResult::failure('No credentials configured for this channel.');
        }

        if ($credential->connector_token !== null) {
            $blocked = $this->rejectIfBusinessSyncIneligible($channel, $wooResource);

            if ($blocked !== null) {
                return $blocked;
            }

            return $this->sendCommandToPlugin($channel, $credential->connector_token, [
                'resource' => $wooResource,
                'operation' => 'delete',
                'woo_id' => $wooId,
                'fields' => [],
            ]);
        }

        return $this->sendDirectToWoo(
            $channel->store_url,
            $credential->consumer_key,
            $credential->consumer_secret,
            'DELETE',
            "/{$wooResource}/{$wooId}",
            [],
        );
    }

    /**
     * Fail-closed re-check at the transport boundary, applied only to business resources on an
     * already-paired Channel. Returns null (proceed) when the resource is connector-management
     * traffic or the Channel remains eligible; a failure DispatchResult otherwise, so a command
     * enqueued while healthy but executing after an explicit disconnect never reaches Woo.
     */
    private function rejectIfBusinessSyncIneligible(Channel $channel, string $wooResource): ?DispatchResult
    {
        if (! in_array($wooResource, self::BUSINESS_RESOURCES, true)) {
            return null;
        }

        if ($channel->canSyncNow()) {
            return null;
        }

        return DispatchResult::failure(
            'Channel is not currently eligible for normal business synchronization (not Live, or the paired Connector is degraded/disconnected).',
        );
    }

    /**
     * Deliberately does NOT catch Throwable — a connection-level failure (timeout, DNS, etc.)
     * must propagate to the calling job so its own tries=3/backoff retry actually engages,
     * exactly as it did before every job built this same HTTP call inline. Only a well-formed
     * HTTP response (successful or not) is translated into a DispatchResult here.
     *
     * @param  array<string, mixed>  $command
     */
    private function sendCommandToPlugin(Channel $channel, string $connectorToken, array $command): DispatchResult
    {
        $response = Http::withToken($connectorToken)
            ->timeout(20)
            ->post(rtrim($channel->store_url, '/').'/wp-json/ecos-connector/v1/commands', $command);

        return $this->toResult($response);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sendDirectToWoo(
        string $storeUrl,
        string $consumerKey,
        string $consumerSecret,
        string $method,
        string $path,
        array $payload,
    ): DispatchResult {
        $request = Http::withBasicAuth($consumerKey, $consumerSecret)->timeout(15);
        $url = rtrim($storeUrl, '/').'/wp-json/wc/v3'.$path;

        $response = match ($method) {
            'POST' => $request->post($url, $payload),
            'DELETE' => $request->delete($url, $payload),
            default => $request->put($url, $payload),
        };

        return $this->toResult($response);
    }

    private function toResult(Response $response): DispatchResult
    {
        if ($response->successful()) {
            return DispatchResult::success($response->status(), $response->json() ?? []);
        }

        return DispatchResult::failure("HTTP {$response->status()}: ".mb_substr($response->body(), 0, 500));
    }
}
