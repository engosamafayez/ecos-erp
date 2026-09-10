<?php

declare(strict_types=1);

namespace Modules\Commerce\Synchronization\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Illuminate\Support\Carbon;
use Modules\Commerce\Channels\Domain\Contracts\ChannelRepositoryInterface;
use Modules\Commerce\Channels\Domain\Exceptions\ChannelNotFoundException;
use Modules\Commerce\Synchronization\Application\Services\ChannelSyncAuditLogger;
use RuntimeException;

/**
 * TASK-...-025 (W6/W7/W8) — the ONE Orders Sync pause/resume authority. No parallel state
 * machine: `channels.sync_orders` (the gate) and `channels.orders_sync_watermark_at` (the
 * checkpoint) are the entire model; this Action is the deliberate, audited way to change them
 * together instead of a bare column PATCH.
 */
final class SetOrdersSyncStateAction extends BaseAction
{
    private const RESUME_POLICIES = ['catch_up', 'resume_from_now', 'resume_from_point'];

    public function __construct(
        private readonly ChannelRepositoryInterface $channels,
        private readonly ChannelSyncAuditLogger $auditLogger,
    ) {}

    /**
     * @param  array{state: string, resume_policy?: string|null, resume_from?: string|null}  $input
     *   state: 'paused'|'enabled'. resume_policy (only meaningful when the channel is currently
     *   paused AND state='enabled'): 'catch_up'|'resume_from_now'|'resume_from_point'.
     *   resume_from: ISO date/time, required for 'resume_from_point'.
     */
    public function execute(mixed ...$arguments): OperationResult
    {
        $channelId = (string) ($arguments[0] ?? '');
        /** @var array<string, mixed> $input */
        $input = $arguments[1] ?? [];

        $channel = $this->channels->findById($channelId);

        if ($channel === null) {
            throw new ChannelNotFoundException($channelId);
        }

        $desiredState = (string) ($input['state'] ?? '');

        if (! in_array($desiredState, ['paused', 'enabled'], true)) {
            throw new RuntimeException("Invalid Orders Sync state [{$desiredState}]. Expected 'paused' or 'enabled'.");
        }

        if ($desiredState === 'paused') {
            $channel->update(['sync_orders' => false]);
            $this->auditLogger->log($channel, 'orders_sync.paused', [
                'watermark_preserved_at' => $channel->orders_sync_watermark_at?->toIso8601String(),
            ]);

            return OperationResult::success($channel->refresh(), 'Orders Sync paused. The current checkpoint is preserved.');
        }

        // Enabling. If it was already enabled, this is a no-op — a resume policy only has
        // meaning when transitioning FROM paused (W7/W8's "no ambiguous automatic behaviour").
        if ($channel->sync_orders) {
            return OperationResult::success($channel->refresh(), 'Orders Sync is already enabled.');
        }

        $policy = (string) ($input['resume_policy'] ?? '');

        if (! in_array($policy, self::RESUME_POLICIES, true)) {
            throw new RuntimeException(
                "Resuming a paused Orders Sync requires an explicit resume_policy: "
                ."'catch_up', 'resume_from_now', or 'resume_from_point'.",
            );
        }

        $updates = ['sync_orders' => true];

        if ($policy === 'resume_from_now') {
            $updates['orders_sync_watermark_at'] = now();
        } elseif ($policy === 'resume_from_point') {
            $resumeFrom = $input['resume_from'] ?? null;

            if ($resumeFrom === null || $resumeFrom === '') {
                throw new RuntimeException("resume_policy 'resume_from_point' requires a resume_from date/time.");
            }

            $updates['orders_sync_watermark_at'] = Carbon::parse((string) $resumeFrom);
        }
        // 'catch_up': sync_orders flips back on; orders_sync_watermark_at is left untouched on
        // purpose — the next import/catch-up run reads it and picks up exactly where the pause
        // left off.

        $channel->update($updates);
        $channel->refresh();

        $this->auditLogger->log($channel, 'orders_sync.resumed', [
            'resume_policy' => $policy,
            'watermark_after' => $channel->orders_sync_watermark_at?->toIso8601String(),
        ]);

        return OperationResult::success($channel, 'Orders Sync resumed.');
    }
}
