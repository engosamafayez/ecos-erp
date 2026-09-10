<?php

declare(strict_types=1);

namespace Modules\Commerce\Channels\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Modules\Commerce\Channels\Application\DTO\ChannelDTO;
use Modules\Commerce\Channels\Domain\Contracts\ChannelRepositoryInterface;
use Modules\Commerce\Channels\Domain\Exceptions\ChannelNotFoundException;
use Modules\Commerce\Synchronization\Application\Services\ChannelSyncAuditLogger;

final class UpdateChannelAction extends BaseAction
{
    public function __construct(
        private readonly ChannelRepositoryInterface $channels,
        // TASK-...-025 (P14) — precedent for a Channels-module Action depending on
        // Synchronization already exists (Connectors\Application\Actions\TestConnectionAction
        // depends on Synchronization\...\WebhookManagerService); this follows it rather than
        // duplicating the actor-resolution logic the logger already centralizes.
        private readonly ChannelSyncAuditLogger $auditLogger,
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

        return OperationResult::success($updated, 'Channel updated successfully.');
    }
}
