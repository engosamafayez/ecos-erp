<?php

declare(strict_types=1);

namespace Modules\Crm\SelfService\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A single guest-tracking OTP challenge — see the migration's own docblock for why this table
 * exists rather than reusing Laravel's `password_reset_tokens`.
 */
class CustomerVerificationChallenge extends Model
{
    use HasUuids;

    protected $table = 'customer_verification_challenges';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function attemptsExhausted(): bool
    {
        return $this->attempts >= $this->max_attempts;
    }

    /** Usable for a fresh verification attempt right now. */
    public function isLive(): bool
    {
        return ! $this->isExpired() && ! $this->isConsumed() && ! $this->attemptsExhausted();
    }
}
