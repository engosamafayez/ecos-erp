<?php

declare(strict_types=1);

namespace Modules\Commerce\OrderImport\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Modules\Commerce\Channels\Domain\Contracts\ChannelRepositoryInterface;
use Modules\Commerce\Channels\Domain\Exceptions\ChannelNotFoundException;
use Modules\Commerce\Synchronization\Application\Services\ChannelSyncAuditLogger;
use RuntimeException;

/**
 * TASK-...-025 (W9) — first-activation policy. "First" means orders_sync_activated_at is still
 * null; calling this again after activation is rejected rather than silently re-running, since
 * changing an ALREADY-ACTIVE cutoff is a different operation (SetOrdersSyncStateAction's
 * resume_from_point), not a first activation.
 */
final class SetInitialOrdersImportPolicyAction extends BaseAction
{
    private const POLICIES = ['from_now', 'from_date', 'last_n_days', 'historical'];

    public function __construct(
        private readonly ChannelRepositoryInterface $channels,
        private readonly ChannelSyncAuditLogger $auditLogger,
    ) {}

    /**
     * @param  array{policy: string, date?: string|null, days?: int|null}  $input
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

        if ($channel->orders_sync_activated_at !== null) {
            throw new RuntimeException(
                'Orders Sync was already activated for this channel on '
                .$channel->orders_sync_activated_at->toIso8601String()
                .'. To change the cutoff now, pause and resume with an explicit resume policy instead.',
            );
        }

        $policy = (string) ($input['policy'] ?? '');

        if (! in_array($policy, self::POLICIES, true)) {
            throw new RuntimeException('Invalid initial import policy ['.$policy.']. Expected one of: '.implode(', ', self::POLICIES));
        }

        $cutoff = match ($policy) {
            'from_now' => now(),
            'from_date' => isset($input['date']) && $input['date'] !== null && $input['date'] !== ''
                ? Carbon::parse((string) $input['date'])
                : throw new RuntimeException("Policy 'from_date' requires a date."),
            'last_n_days' => now()->subDays(max(1, (int) ($input['days'] ?? 30))),
            // 'historical' sets no live cutoff — the live watermark stays null (unbounded) until
            // an explicit historical import runs or the first live sync sets it going forward.
            // This policy only records the operator's deliberate choice to backfill first.
            default => null,
        };

        $channel->update([
            'orders_initial_import_policy' => $policy,
            'orders_initial_import_cutoff_at' => $cutoff,
            'orders_sync_watermark_at' => $cutoff,
            'orders_sync_activated_at' => now(),
            'orders_sync_activated_by' => Auth::id(),
        ]);
        $channel->refresh();

        $this->auditLogger->log($channel, 'orders_sync.initial_import_policy_set', [
            'policy' => $policy,
            'cutoff_at' => $cutoff?->toIso8601String(),
        ]);

        return OperationResult::success($channel, 'Initial Orders Sync import policy recorded.');
    }
}
