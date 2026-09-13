<?php

declare(strict_types=1);

namespace Modules\Commerce\Synchronization\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\Channels\Domain\Models\ChannelCredential;
use Modules\Commerce\Synchronization\Application\Services\ChannelSyncAuditLogger;
use Modules\Commerce\Synchronization\Application\Services\WebhookManagerService;

/**
 * TASK-...-CONSOLIDATED-REMEDIATION-001-R2/R2-R1 §6/§14/§15 — the ONE pairing-exchange
 * authority. A WordPress plugin, holding only a short-lived pairing code the merchant pasted
 * in, exchanges it here for a persistent, Channel-scoped `connector_token`.
 *
 * §6 (CTO correction): an official Connector-mode Channel never requires the merchant/operator
 * to obtain or paste a WooCommerce REST consumer_key/consumer_secret at all — unlike the R2
 * design, this no longer requires a pre-existing ChannelCredential row. A missing row is
 * created here holding ONLY connector_token; consumer_key/consumer_secret stay null (the
 * Channel never uses the legacy direct-REST transport — see WooOutboundCommandDispatcher).
 *
 * Reuses WebhookManagerService (unchanged, not duplicated) to complete "required webhooks are
 * configured automatically" as the last pairing step — the merchant never creates a Woo
 * webhook, chooses a topic, or pastes a callback URL. For a Connector-mode Channel,
 * WebhookManagerService itself now routes webhook creation through the paired plugin (see its
 * own docblock) rather than calling Woo's REST API directly.
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

        $connectorToken = Str::random(64);

        // TASK-...-CONSOLIDATED-REMEDIATION-001-R2-R2 §13 — atomic: the credential's
        // connector_token and the Channel's own pairing-success record (heartbeat set, pairing
        // code consumed) commit together or not at all. A mid-failure here leaves NEITHER set,
        // so Channel::connectorHealth() correctly reads NeverConnected rather than a
        // half-applied, falsely-Healthy state.
        DB::transaction(function () use ($channel, $connectorToken): void {
            $credential = $channel->credential;

            if ($credential === null) {
                ChannelCredential::query()->create([
                    'channel_id' => $channel->id,
                    'connector_token' => $connectorToken,
                ]);
            } else {
                $credential->update(['connector_token' => $connectorToken]);
            }

            // Single-use: consumed regardless of what webhook registration below does, so a
            // leaked/observed code cannot be replayed even if registration partially fails.
            $channel->update([
                'pairing_code_hash' => null,
                'pairing_code_expires_at' => null,
                'connector_last_heartbeat_at' => now(),
                'connector_disconnected_at' => null,
            ]);
        });

        // Deliberately OUTSIDE the transaction above and never throws (registerAll()'s own
        // register() swallows Throwable into a logged SyncLog failure) — a webhook-registration
        // failure must not roll back the pairing that already, genuinely, succeeded. Whether
        // registration itself succeeded is a separate, already-tracked fact surfaced through
        // ChannelGoLiveReadinessService::webhooksRegistered() (the real persisted webhook-id
        // columns), not through connectorHealth() — the two are deliberately independent
        // signals, not conflated into either falsely reads Healthy or falsely blocks pairing.
        $this->webhookManager->registerAll($channel->refresh());

        $this->auditLogger->log($channel, 'connector.paired', []);

        return OperationResult::success([
            'channel_id' => $channel->id,
            'connector_token' => $connectorToken,
        ], 'Paired successfully.');
    }
}
