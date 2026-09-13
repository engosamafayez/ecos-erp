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
 * TASK-ECOS-V1.1-WOO-04-GO-LIVE-LIFECYCLE — architecture authority 042A-R1 §5.
 *
 * "Explicit LIVE-transition action: a new, distinct authorized action... is the only way
 * lifecycle_state becomes LIVE." No other code path writes ChannelLifecycleState::Live —
 * outbound sync and inbound webhook processing (see Channel::isLive()'s callers) stay inert
 * regardless of is_active/sync_* flags until this runs and every go-live gate passes.
 *
 * Idempotent: calling this on an already-live channel is a successful no-op, not an error —
 * "already-live/replayed activation" per the implementation ticket's §6.
 */
final class TransitionChannelToLiveAction extends BaseAction
{
    public function __construct(
        private readonly ChannelRepositoryInterface $channels,
        private readonly ChannelGoLiveReadinessService $readiness,
        private readonly ChannelSyncAuditLogger $auditLogger,
    ) {}

    public function execute(mixed ...$arguments): OperationResult
    {
        $channelId = (string) ($arguments[0] ?? '');

        // Tenant safety: findById() resolves through Channel's own 'tenant' global scope,
        // the same fail-closed contract every other Channel Action already relies on
        // (TestConnectionAction, SetInitialOrdersImportPolicyAction, ...) — no second
        // ownership check is invented here.
        $channel = $this->channels->findById($channelId);

        if ($channel === null) {
            throw new ChannelNotFoundException($channelId);
        }

        if ($channel->isLive()) {
            return OperationResult::success($channel, 'Channel is already live.');
        }

        if ($channel->lifecycle_state === ChannelLifecycleState::Disabled) {
            throw new RuntimeException('A disabled channel cannot go live directly. Re-enable it first (ReenableChannelAction).');
        }

        if ($channel->lifecycle_state === ChannelLifecycleState::Paused) {
            throw new RuntimeException('A paused channel must be resumed (ResumeChannelAction), not transitioned to live directly.');
        }

        // CTO source-review closure item A/item 4 — always act on the FRESHEST truth: refresh
        // DRAFT/CONFIGURED/READY to whatever the current data actually supports before deciding.
        // A channel the caller believed was still DRAFT/CONFIGURED may already satisfy every
        // gate; this is what lets that same call both refresh state to READY and go live in one
        // step, per the implementation ticket's §4. If gates still fail, the channel is left at
        // whatever DRAFT/CONFIGURED the fresh assessment actually supports, not silently at its
        // old (possibly stale) state.
        $channel = $this->readiness->refreshPreLiveState($channel);
        $assessment = $this->readiness->assess($channel);

        if (! $assessment['ready']) {
            throw new RuntimeException(self::failingGatesMessage($assessment['gates'], 'go live'));
        }

        $previousState = $channel->lifecycle_state->value;

        $channel->update(['lifecycle_state' => ChannelLifecycleState::Live->value]);
        $channel->refresh();

        $this->auditLogger->log($channel, 'channel.go_live', [
            'previous_state' => $previousState,
            'gates' => $assessment['gates'],
        ]);

        return OperationResult::success($channel, 'Channel is now live.');
    }

    /**
     * @param  list<array{key: string, label: string, ready: bool, reason: string}>  $gates
     */
    public static function failingGatesMessage(array $gates, string $verb): string
    {
        $failing = array_values(array_filter($gates, fn (array $g): bool => ! $g['ready']));

        return "Channel is not ready to {$verb}. Failing gate(s): ".implode('; ', array_map(
            fn (array $g): string => "{$g['label']}: {$g['reason']}",
            $failing,
        ));
    }
}
