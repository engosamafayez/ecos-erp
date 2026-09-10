<?php

declare(strict_types=1);

namespace Modules\Commerce\Synchronization\Application\Services;

use Illuminate\Support\Facades\Auth;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\Synchronization\Domain\Models\ChannelSyncAudit;
use Modules\Organization\Brands\Domain\Models\Brand;

/**
 * TASK-...-025 (P14) — the single write path for `channel_sync_audits`. Mirrors the existing
 * `actor_type: Auth::check() ? 'user' : 'system'` convention already used by
 * OrderReservationAudit::record() elsewhere in Commerce, rather than inventing a new one.
 */
final class ChannelSyncAuditLogger
{
    /**
     * @param  array<string, mixed>  $context  Never include credential values.
     */
    public function log(Channel $channel, string $action, array $context = []): void
    {
        $companyId = $channel->brand_id !== null
            ? Brand::query()->whereKey($channel->brand_id)->value('company_id')
            : null;

        if ($companyId === null) {
            return;
        }

        ChannelSyncAudit::query()->create([
            'channel_id' => $channel->id,
            'company_id' => $companyId,
            'actor_id' => Auth::id(),
            'actor_type' => Auth::check() ? 'user' : 'system',
            'action' => $action,
            'context' => $context,
        ]);
    }
}
