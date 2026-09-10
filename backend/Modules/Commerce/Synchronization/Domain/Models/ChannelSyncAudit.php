<?php

declare(strict_types=1);

namespace Modules\Commerce\Synchronization\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Commerce\Channels\Domain\Models\Channel;

/**
 * TASK-...-025 (P14) — audit trail for Woo channel sync-setting changes (pause/resume/policy/
 * cutoff/historical import/credential rotation). Scoped to this integration only — not a
 * platform-wide audit framework.
 *
 * @property string $id
 * @property string $channel_id
 * @property string $company_id
 * @property string|null $actor_id
 * @property string $actor_type
 * @property string $action
 * @property array<string, mixed>|null $context
 */
class ChannelSyncAudit extends Model
{
    use HasUuids;

    protected $table = 'channel_sync_audits';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'channel_id',
        'company_id',
        'actor_id',
        'actor_type',
        'action',
        'context',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'context' => 'array',
        ];
    }

    /** @return BelongsTo<Channel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
