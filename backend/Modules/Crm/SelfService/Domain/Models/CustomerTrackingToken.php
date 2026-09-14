<?php

declare(strict_types=1);

namespace Modules\Crm\SelfService\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A guest order-tracking session. The token itself is never stored — see
 * CustomerTrackingTokenService, the ONLY place that ever sees the raw value.
 */
class CustomerTrackingToken extends Model
{
    use HasUuids;

    protected $table = 'customer_tracking_tokens';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isLive(): bool
    {
        return ! $this->isExpired() && ! $this->isRevoked();
    }
}
