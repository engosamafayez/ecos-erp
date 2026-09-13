<?php

declare(strict_types=1);

namespace Modules\Commerce\Synchronization\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Illuminate\Support\Str;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\Synchronization\Application\Services\ChannelSyncAuditLogger;
use Modules\Commerce\Synchronization\Application\Services\WebhookManagerService;

/**
 * TASK-...-CONSOLIDATED-REMEDIATION-001-R2 §14/§15 — the ONE pairing-exchange authority. A
 * WordPress plugin, holding only a short-lived pairing code the merchant pasted in, exchanges
 * it here for a persistent, Channel-scoped `connector_token` — a credential distinct from the
 * consumer_key/consumer_secret pair ECOS uses to call Woo's own REST API, which the plugin
 * never sees or needs.
 *
 * Reuses WebhookManagerService (unchanged, not duplicated) to complete "required webhooks are
 * configured automatically" as the last pairing step — the merchant never creates a Woo
 * webhook, chooses a topic, or pastes a callback URL.
 */
final class ExchangePairingCodeAction extends BaseAction
{
    public function __construct(
        private readonly WebhookManagerService $webhookManager,
        private readonly ChannelSyncAuditLogger $auditLogger,
    ) {}

    /**
     * Arguments:
     *   [0] pairing_code (string), as pasted into the plugin
     *
     * @return OperationResult with data ['channel_id' => string, 'connector_token' => string]
     *                         on success, or a failure result on an invalid/expired code.
     */
    public function execute(mixed ...$arguments): OperationResult
    {
        $code = trim((string) ($arguments[0] ?? ''));

        if ($code === '') {
            return OperationResult::failure('Pairing code is required.');
        }

        $channel = Channel::query()
            ->where('pairing_code_hash', hash('sha256', strtoupper($code)))
            ->where('pairing_code_expires_at', '>', now())
            ->first();

        if ($channel === null) {
            return OperationResult::failure('Pairing code is invalid or has expired.');
        }

        $credential = $channel->credential;

        if ($credential === null) {
            return OperationResult::failure(
                'This channel has no WooCommerce REST credential configured yet. An ECOS operator must configure it before pairing.',
            );
        }

        $connectorToken = Str::random(64);

        $credential->update(['connector_token' => $connectorToken]);

        // Single-use: consumed regardless of outcome from here on, so a leaked/observed code
        // cannot be replayed even if webhook registration below partially fails.
        $channel->update([
            'pairing_code_hash' => null,
            'pairing_code_expires_at' => null,
            'connector_last_heartbeat_at' => now(),
            'connector_disconnected_at' => null,
        ]);

        // Idempotent — registerAll() already skips any topic that's already registered, so a
        // re-pairing of an already-connected channel does not create duplicate Woo webhooks.
        $this->webhookManager->registerAll($channel->refresh());

        $this->auditLogger->log($channel, 'connector.paired', []);

        return OperationResult::success([
            'channel_id' => $channel->id,
            'connector_token' => $connectorToken,
        ], 'Paired successfully.');
    }
}
