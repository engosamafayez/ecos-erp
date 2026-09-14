<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\CustomerEngagement\Domain\Enums\ChannelProviderStatus;
use Modules\CustomerEngagement\Infrastructure\Casts\TransitionalEncryptedArrayCast;

class ChannelProvider extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'cep_channel_providers';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => ChannelProviderStatus::class,
            // TASK-...-CRM-03-...-015 §3C — was a plain 'array' cast (plaintext JSON at rest)
            // despite the original migration comment claiming encryption; see the CRM-03
            // architecture report's gap #3. Transitional so pre-existing plaintext rows keep
            // reading correctly (same discipline as ChannelCredential's own encrypted cast).
            'credentials' => TransitionalEncryptedArrayCast::class,
            'last_verified_at' => 'datetime',
            // Voice-specific, per-Brand/number config (persona, greeting, recording toggle —
            // §18/§30 of the architecture report). Business hours deliberately do NOT live
            // here — see SlaPolicy::business_hours, the one shared authority both SLA tracking
            // and Voice's after-hours fallback read (§17: "do not duplicate schedules").
            'voice_settings' => 'array',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === ChannelProviderStatus::ACTIVE;
    }

    public function getCredential(string $key): mixed
    {
        return $this->credentials[$key] ?? null;
    }
}
