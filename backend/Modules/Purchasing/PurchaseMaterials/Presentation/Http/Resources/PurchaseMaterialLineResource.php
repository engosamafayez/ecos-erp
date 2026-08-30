<?php

declare(strict_types=1);

namespace Modules\Purchasing\PurchaseMaterials\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Purchasing\PurchaseMaterials\Domain\Services\PurchaseMaterialReceivingService;

/** @mixin \Modules\Purchasing\PurchaseMaterials\Domain\Models\PurchaseMaterialLine */
class PurchaseMaterialLineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $required = app(PurchaseMaterialReceivingService::class)->requiredQty($this->resource);
        $received = $this->receivedGross();

        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'sku' => $this->product->sku,
                'name' => $this->product->name,
                'image_url' => $this->product->image_url ?? null,
                'average_cost' => $this->product->average_cost,
            ]),
            'requested_qty' => (float) $this->requested_qty,
            'unit_label' => $this->unit_label,
            'notes' => $this->notes,
            'supplier_id' => $this->supplier_id,
            'supplier' => $this->whenLoaded('supplier', fn () => $this->supplier ? [
                'id' => $this->supplier->id,
                'name' => $this->supplier->name,
            ] : null),
            'agreed_price' => $this->agreed_price !== null ? (float) $this->agreed_price : null,
            'agreed_qty' => $this->agreed_qty !== null ? (float) $this->agreed_qty : null,
            'lead_time_days' => $this->lead_time_days,
            'supplier_selected_at' => $this->supplier_selected_at?->toIso8601String(),

            // ── Receiving position (TASK-PROC-PURCHASING-PHASE2-PART1) ───────────
            // ONE definition, computed by PurchaseMaterialReceivingService, so no screen can
            // invent its own arithmetic:
            //   required_qty  = COALESCE(agreed_qty, requested_qty)          (RD-2)
            //   received_qty  = Σ posted goods-receipt lines, gross of returns (RD-3)
            //   remaining_qty = max(0, required − received)
            'required_qty' => $required,
            'received_qty' => $received,
            'remaining_qty' => round(max(0.0, $required - $received), 4),
        ];
    }

    /**
     * Gross received for this line, resolved through a per-request batch.
     *
     * The purchases LIST eager-loads `lines.product` and renders every line, so asking the
     * service per line would issue one query per line per row. The first line of a Purchase
     * loads the received totals for ALL of that Purchase's lines in a single grouped query and
     * memoises them for the rest of the request.
     */
    private function receivedGross(): float
    {
        $materialId = (string) $this->purchase_material_id;

        if (! array_key_exists($materialId, self::$receivedCache)) {
            $lineIds = \Modules\Purchasing\PurchaseMaterials\Domain\Models\PurchaseMaterialLine::query()
                ->where('purchase_material_id', $materialId)
                ->pluck('id')
                ->map(fn ($id): string => (string) $id)
                ->all();

            self::$receivedCache[$materialId] = app(PurchaseMaterialReceivingService::class)
                ->receivedGrossFor($lineIds);
        }

        return (float) (self::$receivedCache[$materialId][(string) $this->id] ?? 0.0);
    }

    /** @var array<string, array<string, float>> purchase_material_id => (line id => received) */
    private static array $receivedCache = [];
}
