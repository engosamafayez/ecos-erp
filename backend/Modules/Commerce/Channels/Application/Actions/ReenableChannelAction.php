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
 * TASK-ECOS-V1.1-WOO-04-GO-LIVE-LIFECYCLE — CTO source-review closure item B.
 *
 * DISABLED never jumps directly back to LIVE — that would let a single action both undo an
 * administrative shutdown AND resume real traffic, with no fresh readiness check in between.
 * Re-enabling only restores the channel to whatever DRAFT/CONFIGURED/READY the CURRENT
 * configuration actually supports (the exact same derivation TransitionChannelToLiveAction
 * itself refreshes against); reaching LIVE afterward still requires that separate, explicit,
 * fully-gated action.
 */
final class ReenableChannelAction extends BaseAction
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

        if ($channel->lifecycle_state !== ChannelLifecycleState::Disabled) {
            throw new RuntimeException('Only a disabled channel can be re-enabled.');
        }

        $derived = $this->readiness->derivePreLiveState($channel);

        $channel->update(['lifecycle_state' => $derived->value]);
        $channel->refresh();

        $this->auditLogger->log($channel, 'channel.reenabled', ['restored_to' => $derived->value]);

        return OperationResult::success($channel, "Channel re-enabled as {$derived->label()}. A separate go-live action is required to reach Live.");
    }
}
