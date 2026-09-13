<?php

declare(strict_types=1);

namespace Modules\Commerce\Channels\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Modules\Commerce\Channels\Application\DTO\ChannelDTO;
use Modules\Commerce\Channels\Domain\Contracts\ChannelRepositoryInterface;
use Modules\Commerce\Channels\Domain\Exceptions\ChannelNotFoundException;
use Modules\Commerce\Synchronization\Application\Services\ChannelSyncAuditLogger;
use Modules\Commerce\Synchronization\Application\Services\WebhookManagerService;

final class UpdateChannelAction extends BaseAction
{
    public function __construct(
        private readonly ChannelRepositoryInterface $channels,
        // TASK-...-025 (P14) — precedent for a Channels-module Action depending on
        // Synchronization already exists (Connectors\Application\Actions\TestConnectionAction
        // depends on Synchronization\...\WebhookManagerService); this follows it rather than
        // duplicating the actor-resolution logic the logger already centralizes.
        private readonly ChannelSyncAuditLogger $auditLogger,
        // TASK-...-WOO-05 (042A-R1 §6) — "on store-url update, re-register... Secret rotation
        // ... must also trigger re-registration". Reuses the same WebhookManagerService every
        // other registration trigger already depends on.
        private readonly WebhookManagerService $webhookManager,
    ) {}

    public function execute(mixed ...$arguments): OperationResult
    {
        $id = (string) ($arguments[0] ?? '');

        /** @var ChannelDTO $dto */
        $dto = $arguments[1];

        $channel = $this->channels->findById($id);

        if ($channel === null) {
            throw new ChannelNotFoundException($id);
        }

        $storeUrlChanged = $channel->store_url !== $dto->store_url;
        $credentialAttributes = $dto->credentialAttributes();

        $updated = $this->channels->update(
            $channel,
            $dto->channelAttributes(),
            $credentialAttributes,
        );

        // TASK-...-025 (P14) — audited WITHOUT the secret value itself, only that a rotation
        // happened. credentialAttributes() is non-null only when the caller actually supplied
        // new consumer_key/consumer_secret values (the frontend form leaves both blank unless
        // the operator deliberately types new ones), so this fires on a real rotation only.
        if ($credentialAttributes !== null) {
            $this->auditLogger->log($updated, 'channel.credentials_rotated', [
                'rotated_fields' => array_keys($credentialAttributes),
            ]);
        }

        // TASK-...-WOO-05 — either event means any existing remote webhook registration is now
        // stale (wrong delivery URL, or signed with a secret Woo no longer has): force
        // re-registration of every topic rather than registerAll()'s normal "skip if already
        // registered" behavior, which would otherwise leave the stale registration in place.
        if ($storeUrlChanged || $credentialAttributes !== null) {
            $this->webhookManager->reregisterAll($updated);
            $updated = $updated->fresh();
        }

        return OperationResult::success($updated, 'Channel updated successfully.');
    }
}
