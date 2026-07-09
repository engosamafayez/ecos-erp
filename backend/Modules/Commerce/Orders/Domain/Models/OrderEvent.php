<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string      $id
 * @property string      $order_id
 * @property string      $event_type
 * @property string      $description
 * @property string|null $actor_id
 * @property array|null  $payload
 */
final class OrderEvent extends Model
{
    use HasUuids;

    public $incrementing = false;
    public $timestamps   = false;

    protected $keyType = 'string';

    protected $fillable = [
        'order_id',
        'event_type',
        'description',
        'actor_id',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload'    => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    // ── Factories ─────────────────────────────────────────────────────────────

    public static function log(string $orderId, string $type, string $description, array $payload = [], ?string $actorId = null): static
    {
        return static::create([
            'order_id'    => $orderId,
            'event_type'  => $type,
            'description' => $description,
            'actor_id'    => $actorId,
            'payload'     => $payload ?: null,
        ]);
    }
}
