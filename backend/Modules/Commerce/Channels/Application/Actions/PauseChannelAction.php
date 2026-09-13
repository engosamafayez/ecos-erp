<?php

declare(strict_types=1);

namespace Modules\Commerce\Channels\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Modules\Commerce\Channels\Domain\Contracts\ChannelRepositoryInterface;
use Modules\Commerce\Channels\Domain\Enums\ChannelLifecycleState;
use Modules\Commerce\Channels\Domain\Exceptions\ChannelNotFoundException;
use Modules\Commerce\Synchronization\Application\Services\ChannelSyncAuditLogger;
use RuntimeException;

/**
 * TASK-ECOS-V1.1-WOO-04-GO-LIVE-LIFECYCLE — architecture authority 042A-R1 §5 (`LIVE ⇄ PAUSED`).
 *
 * Distinct from SetOrdersSyncStateAction's pause: that one pauses ONLY Orders ingestion (and
 * preserves its own watermark for a resume policy to consult). This pauses the CHANNEL'S live
 * status itself — Channel::isLive() is what every outbound dispatch point and inbound webhook
 * job actually gates on, so pausing here makes every sync type inert at once, not just Orders.
 * Neither duplicates the other; SetOrdersSyncStateAction is untouched.
 */
final class PauseChannelAction extends BaseAction
{
    public function __construct(
        private readonly ChannelRepositoryInterface $channels,
        private readonly ChannelSyncAuditLogger $auditLogger,
    ) {}

    public function execute(mixed ...$arguments): OperationResult
    {
        $channelId = (string) ($arguments[0] ?? '');
        $channel = $this->channels->findById($channelId);

        if ($channel === null) {
            throw new ChannelNotFoundException($channelId);
        }

        if ($channel->lifecycle_state === ChannelLifecycleState::Paused) {
            return OperationResult::success($channel, 'Channel is already paused.');
        }

        if (! $channel->isLive()) {
            throw new RuntimeException('Only a live channel can be paused.');
        }

        $channel->update(['lifecycle_state' => ChannelLifecycleState::Paused->value]);
        $channel->refresh();

        $this->auditLogger->log($channel, 'channel.paused', []);

        return OperationResult::success($channel, 'Channel paused. Inbound and outbound sync are inert until resumed.');
    }
}
