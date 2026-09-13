<?php

declare(strict_types=1);

namespace Modules\Commerce\Channels\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Modules\Commerce\Channels\Domain\Contracts\ChannelRepositoryInterface;
use Modules\Commerce\Channels\Domain\Enums\ChannelLifecycleState;
use Modules\Commerce\Channels\Domain\Exceptions\ChannelNotFoundException;
use Modules\Commerce\Channels\Domain\Services\ChannelGoLiveReadinessService;
use Modules\Commerce\Synchronization\Application\Services\ChannelSyncAuditLogger;
use RuntimeException;

/**
 * TASK-ECOS-V1.1-WOO-04-GO-LIVE-LIFECYCLE — the inverse of PauseChannelAction.
 *
 * Re-validates every go-live gate rather than trusting the earlier pass: time has elapsed since
 * the original go-live decision, and credentials/mappings/policies can regress in the meantime.
 * A channel that no longer qualifies stays paused instead of resuming on stale evidence.
 */
final class ResumeChannelAction extends BaseAction
{
    public function __construct(
        private readonly ChannelRepositoryInterface $channels,
        private readonly ChannelGoLiveReadinessService $readiness,
        private readonly ChannelSyncAuditLogger $auditLogger,
    ) {}

    public function execute(mixed ...$arguments): OperationResult
    {
        $channelId = (string) ($arguments[0] ?? '');
        $channel = $this->channels->findById($channelId);

        if ($channel === null) {
            throw new ChannelNotFoundException($channelId);
        }

        if ($channel->isLive()) {
            return OperationResult::success($channel, 'Channel is already live.');
        }

        if ($channel->lifecycle_state !== ChannelLifecycleState::Paused) {
            throw new RuntimeException('Only a paused channel can be resumed.');
        }

        $assessment = $this->readiness->assess($channel);

        if (! $assessment['ready']) {
            throw new RuntimeException(TransitionChannelToLiveAction::failingGatesMessage($assessment['gates'], 'resume'));
        }

        $channel->update(['lifecycle_state' => ChannelLifecycleState::Live->value]);
        $channel->refresh();

        $this->auditLogger->log($channel, 'channel.resumed', ['gates' => $assessment['gates']]);

        return OperationResult::success($channel, 'Channel resumed and is live again.');
    }
}
