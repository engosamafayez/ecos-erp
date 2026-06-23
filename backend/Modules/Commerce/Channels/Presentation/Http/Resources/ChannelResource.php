<?php

declare(strict_types=1);

namespace Modules\Commerce\Channels\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Commerce\Channels\Domain\Models\Channel;

/**
 * @mixin Channel
 */
final class ChannelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'company' => $this->whenLoaded('company', fn () => [
                'id' => $this->company->id,
                'name' => $this->company->name,
            ]),
            'name' => $this->name,
            'platform' => $this->platform->value,
            'platform_label' => $this->platform->label(),
            'store_url' => $this->store_url,
            'is_active' => (bool) $this->is_active,
            'sync_products' => (bool) $this->sync_products,
            'sync_prices' => (bool) $this->sync_prices,
            'sync_stock' => (bool) $this->sync_stock,
            'sync_customers' => (bool) $this->sync_customers,
            'connection_status' => $this->connection_status->value,
            'connection_status_label' => $this->connection_status->label(),
            'last_sync_at' => $this->last_sync_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
