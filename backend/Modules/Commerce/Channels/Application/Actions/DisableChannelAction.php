<?php

declare(strict_types=1);

namespace Modules\Commerce\Channels\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Modules\Commerce\Channels\Domain\Contracts\ChannelRepositoryInterface;
use Modules\Commerce\Channels\Domain\Enums\ChannelLifecycleState;
use Modules\Commerce\Channels\Domain\Exceptions\ChannelNotFoundException;
use Modules\Commerce\Synchronization\Application\Services\ChannelSyncAuditLogger;

/**
 * TASK-ECOS-V1.1-WOO-04-GO-LIVE-LIFECYCLE — CTO source-review closure item B.
 *
 * DISABLED is an explicit administrative shutdown, reachable from ANY other lifecycle state
 * (DRAFT/CONFIGURED/READY/LIVE/PAUSED) — unlike PAUSED, it carries no assumption the channel
 * was ever live. Channel::isLive() is already false for every one of those states except LIVE,
 * so disabling a pre-live channel needs no extra dispatch-safety work here — it was already
 * inert; this only records the deliberate administrative decision.
 */
final class DisableChannelAction extends BaseAction
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

        if ($channel->lifecycle_state === ChannelLifecycleState::Disabled) {
            return OperationResult::success($channel, 'Channel is already disabled.');
        }

        $previousState = $channel->lifecycle_state->value;

        $channel->update(['lifecycle_state' => ChannelLifecycleState::Disabled->value]);
        $channel->refresh();

        $this->auditLogger->log($channel, 'channel.disabled', ['previous_state' => $previousState]);

        return OperationResult::success($channel, 'Channel disabled.');
    }
}
