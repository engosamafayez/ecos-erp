<?php

declare(strict_types=1);

namespace Modules\Commerce\Channels\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Commerce\Channels\Infrastructure\Casts\TransitionalEncryptedCast;

/**
 * Stores API credentials for a channel. Never serialised into API responses.
 *
 * TASK-...-025 (P12): `consumer_key`/`consumer_secret` are encrypted at rest via
 * {@see TransitionalEncryptedCast} — a cast that WRITES ciphertext always, but on READ falls
 * back to the raw stored value when it does not decrypt (pre-025 plaintext rows), so existing
 * rows keep working transparently until the (separate, not-run-here) backfill command re-saves
 * them. Do not replace this with Laravel's stock `encrypted` cast: that throws on a legacy
 * plaintext row instead of falling back, which would make every credential written before this
 * task unreadable.
 *
 * @property string $id
 * @property string $channel_id
 * @property string $consumer_key
 * @property string $consumer_secret
 * @property string|null $connector_token TASK-...-CONSOLIDATED-REMEDIATION-001-R2 — the
 *                                        WooCommerce Connector plugin's own authentication
 *                                        secret, distinct from consumer_key/consumer_secret
 *                                        (ECOS→Woo REST only). Issued once during pairing.
 */
class ChannelCredential extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'channel_id',
        'consumer_key',
        'consumer_secret',
        'connector_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'consumer_key' => TransitionalEncryptedCast::class,
            'consumer_secret' => TransitionalEncryptedCast::class,
            // A brand-new column with no pre-existing plaintext rows to stay compatible with —
            // the stock `encrypted` cast is used directly rather than the transitional
            // fallback-on-failure variant the other two fields need for legacy data.
            'connector_token' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
