<?php

declare(strict_types=1);

namespace Modules\Purchasing\PurchaseMaterials\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Purchasing\PurchaseMaterials\Domain\Services\PurchaseMaterialReceivingService;

/** @mixin \Modules\Purchasing\PurchaseMaterials\Domain\Models\PurchaseMaterial */
class PurchaseMaterialResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'request_number' => $this->request_number,
            'record_type' => $this->record_type ?? 'material_request',
            'source_type' => $this->source_type,
            'company_id' => $this->company_id,
            'company' => $this->whenLoaded('company', fn () => [
                'id' => $this->company->id,
                'name' => $this->company->name,
            ]),
            'channel_id' => $this->channel_id,
            'channel' => $this->whenLoaded('channel', fn () => [
                'id' => $this->channel->id,
                'name' => $this->channel->name,
                'code' => $this->channel->code,
            ]),
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn () => [
                'id' => $this->warehouse->id,
                'name' => $this->warehouse->name,
            ]),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'held_from_status' => $this->held_from_status,
            // TASK-...-011 §6/§12: single source of truth for which actions this request accepts
            // right now — the table and detail page render buttons from this list rather than
            // re-deriving their own (and drifting from the backend's actual guards).
            'available_actions' => $this->status->availableActions(),
            'priority' => $this->priority->value,
            'priority_label' => $this->priority->label(),
            'requested_by' => $this->requested_by,
            'assigned_buyer' => $this->assigned_buyer,
            'assigned_buyer_id' => $this->assigned_buyer_id,
            'buyer' => $this->whenLoaded('buyer', fn () => $this->buyer ? [
                'id' => $this->buyer->id,
                'name' => $this->buyer->name,
                'job_title' => $this->buyer->job_title,
            ] : null),
            'is_unowned' => $this->assigned_buyer_id === null,
            'required_date' => $this->required_date?->toDateString(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            // Derived from lines × product cost when lines are loaded (list and show both eager
            // load lines.product) — TASK-...-011 §19: the stored column never accumulated a real
            // value, so every prior reader of "estimated value" showed 0. approved/purchased_value
            // remain the stored (dead) columns: no canonical negotiated-value ledger exists yet to
            // derive them from, so they are NOT surfaced as reconciled Hub KPIs (see stats action).
            'estimated_value' => $this->when(
                $this->relationLoaded('lines'),
                fn () => $this->derivedEstimatedValue(),
                fn () => (float) $this->estimated_value,
            ),
            'approved_value' => (float) $this->approved_value,
            'purchased_value' => (float) $this->purchased_value,
            'approved_by' => $this->approved_by,
            'rejected_by' => $this->rejected_by,
            'rejection_reason' => $this->rejection_reason,
            'review_notes' => $this->review_notes,
            'clarification_requested_at' => $this->clarification_requested_at?->toIso8601String(),
            'notes' => $this->notes,
            'items_count' => $this->items_count ?? $this->lines?->count() ?? 0,
            'total_requested_qty' => (float) ($this->total_requested_qty ?? 0),

            // ── Ordering progress (TASK-...-011 §7/§9) ────────────────────────────
            // execution_percent = fully-ordered lines / total lines * 100. A line counts as
            // "ordered" only once its FULL requested quantity has been committed to a supplier —
            // a partial commitment keeps it in not_yet_ordered_items until topped up.
            'execution_percent' => $this->whenLoaded('lines', fn () => $this->executionPercent()),
            'ordered_items_count' => $this->whenLoaded('lines', fn () => $this->orderedLines()->count()),
            'not_yet_ordered_items_count' => $this->whenLoaded('lines', fn () => $this->notYetOrderedLines()->count()),
            'ordered_items' => $this->whenLoaded('lines', fn () => $this->orderedLines()
                ->map(fn ($line) => $this->lineSummary($line))->values()),
            'not_yet_ordered_items' => $this->whenLoaded('lines', fn () => $this->notYetOrderedLines()
                ->map(fn ($line) => $this->lineSummary($line))->values()),

            'lines' => PurchaseMaterialLineResource::collection($this->whenLoaded('lines')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Ordered/not-yet-ordered lines, split in ONE pass and memoised per resource instance —
     * execution_percent, both counts and both item lists all read this same split rather than
     * each re-filtering the lines collection and re-resolving the service from the container.
     *
     * @return array{0: \Illuminate\Support\Collection, 1: \Illuminate\Support\Collection}
     */
    private function orderingSplit(): array
    {
        if ($this->orderingSplitCache === null) {
            $service = app(PurchaseMaterialReceivingService::class);
            $this->orderingSplitCache = $this->lines->partition(fn ($line) => $service->isFullyOrdered($line));
        }

        return $this->orderingSplitCache;
    }

    /** @var array{0: \Illuminate\Support\Collection, 1: \Illuminate\Support\Collection}|null */
    private ?array $orderingSplitCache = null;

    /** @return \Illuminate\Support\Collection<int, \Modules\Purchasing\PurchaseMaterials\Domain\Models\PurchaseMaterialLine> */
    private function orderedLines(): \Illuminate\Support\Collection
    {
        return $this->orderingSplit()[0];
    }

    /** @return \Illuminate\Support\Collection<int, \Modules\Purchasing\PurchaseMaterials\Domain\Models\PurchaseMaterialLine> */
    private function notYetOrderedLines(): \Illuminate\Support\Collection
    {
        return $this->orderingSplit()[1];
    }

    private function executionPercent(): float
    {
        $total = $this->lines->count();
        if ($total === 0) {
            return 0.0;
        }

        return round($this->orderedLines()->count() / $total * 100, 1);
    }

    /** @return array<string, mixed> */
    private function lineSummary($line): array
    {
        $requested = round((float) $line->requested_qty, 4);
        $ordered = round((float) ($line->agreed_qty ?? 0), 4);

        return [
            'id' => $line->id,
            'product_id' => $line->product_id,
            'product_name' => $line->product?->name,
            'sku' => $line->product?->sku,
            'requested_qty' => $requested,
            'ordered_qty' => $ordered,
            'remaining_to_order' => round(max(0.0, $requested - $ordered), 4),
        ];
    }

    private function derivedEstimatedValue(): float
    {
        return round($this->lines->sum(
            fn ($line) => (float) $line->requested_qty * (float) ($line->product?->average_cost ?? 0),
        ), 2);
    }
}
