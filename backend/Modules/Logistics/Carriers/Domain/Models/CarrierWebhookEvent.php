<?php

declare(strict_types=1);

namespace Modules\Logistics\Carriers\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The webhook idempotency/replay ledger (TASK-ECOS-V1.1-OPS-03-TASK1-BOSTA).
 *
 * Raw payload persisted BEFORE processing, per docs/logistics-v2/06-EXTERNAL-
 * CARRIER-PLATFORM.md §6.5 ("persist raw, then acknowledge, then process") —
 * so a mapping bug can be fixed and the backlog reprocessed rather than lost.
 * Uniqueness on (carrier_account_id, carrier_event_id) is the actual
 * idempotency guard: a repeated webhook for an already-processed event is a
 * no-op at the database level, not something application code must detect.
 */
class CarrierWebhookEvent extends Model
{
    protected $table = 'carrier_webhook_events';

    protected $fillable = [
        'carrier_account_id', 'carrier_event_id', 'raw_payload', 'processed_at', 'processing_error',
    ];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function carrierAccount(): BelongsTo
    {
        return $this->belongsTo(CarrierAccount::class, 'carrier_account_id');
    }

    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }
}
