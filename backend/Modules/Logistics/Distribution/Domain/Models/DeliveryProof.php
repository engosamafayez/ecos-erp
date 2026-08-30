<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Signature and photo evidence captured at a stop. */
class DeliveryProof extends Model
{
    protected $table = 'distribution_delivery_proofs';

    protected $fillable = [
        'stop_id',
        // Secure-upload columns (TASK-DELIVERY-POD-SECURE-UPLOAD-001). `storage_disk`
        // names the PRIVATE disk holding the signature; `signature_path` is a
        // SERVER-generated path on it (never a client string); mime/size are sniffed
        // server-side. Null on legacy rows written by the old string contract.
        'storage_disk',
        'signature_path',
        'signature_mime',
        'signature_size',
        'photos',
        'notes',
        'captured_at',
        'captured_by',
    ];

    protected function casts(): array
    {
        return [
            'photos' => 'array',
            'signature_size' => 'integer',
            'captured_at' => 'datetime',
        ];
    }

    public function stop(): BelongsTo
    {
        return $this->belongsTo(DeliveryStop::class, 'stop_id');
    }

    public function hasSignature(): bool
    {
        return $this->signature_path !== null && $this->signature_path !== '';
    }

    public function photoCount(): int
    {
        return count($this->photos ?? []);
    }
}
