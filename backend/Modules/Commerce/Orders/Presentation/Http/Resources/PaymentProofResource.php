<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read \Modules\Commerce\Orders\Domain\Models\PaymentProof $resource
 */
class PaymentProofResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'state' => $this->state->value,
            // Active = the newest non-superseded proof (no separate "active" state).
            'is_active' => $this->superseded_at === null,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            // Tenant-scoped, AUTHENTICATED stream — never a public file URL, so it can
            // only be fetched through the API client that carries the bearer token
            // (never a raw <img src> or <a href>).
            //
            // Emitted relative to the API ROOT, without the `/api` prefix: the client's
            // axios instance is already based at `/api` (lib/env.ts), so a prefixed path
            // produced `/api/api/orders/...` and every View/Download 404'd
            // (TASK-ORDERS-PREPARATION-PAYMENT-FINAL-FIX-001).
            'download_url' => "/orders/{$this->order_id}/payment-proofs/{$this->id}/download",
            'uploaded_by' => $this->uploaded_by,
            'uploaded_at' => $this->uploaded_at?->toIso8601String(),
            'verified_by' => $this->verified_by,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'rejected_by' => $this->rejected_by,
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'superseded_at' => $this->superseded_at?->toIso8601String(),
            'replaces_proof_id' => $this->replaces_proof_id,
        ];
    }
}
