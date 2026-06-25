<?php

declare(strict_types=1);

namespace Modules\Inventory\CountSessions\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\CountSessions\Application\Actions\ApproveCountSessionAction;
use Modules\Inventory\CountSessions\Application\Actions\CancelCountSessionAction;
use Modules\Inventory\CountSessions\Application\Actions\CompleteCountSessionAction;
use Modules\Inventory\CountSessions\Application\Actions\CreateCountSessionAction;
use Modules\Inventory\CountSessions\Application\Actions\StartCountSessionAction;
use Modules\Inventory\CountSessions\Domain\Models\InventoryCountLine;
use Modules\Inventory\CountSessions\Domain\Models\InventoryCountSession;

final class InventoryCountController extends Controller
{
    use HasApiResponse;

    /**
     * GET /inventory-counts
     */
    public function index(Request $request): JsonResponse
    {
        $query = InventoryCountSession::query()
            ->with(['warehouse', 'company'])
            ->latest();

        if ($warehouseId = $request->query('warehouse_id')) {
            $query->where('warehouse_id', $warehouseId);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $perPage = min((int) $request->query('per_page', 15), 100);
        $sessions = $query->paginate($perPage);

        return $this->success([
            'data' => collect($sessions->items())->map(fn ($s) => $this->formatSession($s, false)),
            'meta' => [
                'current_page' => $sessions->currentPage(),
                'per_page'     => $sessions->perPage(),
                'total'        => $sessions->total(),
                'last_page'    => $sessions->lastPage(),
            ],
        ]);
    }

    /**
     * POST /inventory-counts
     */
    public function store(Request $request, CreateCountSessionAction $action): JsonResponse
    {
        $validated = $request->validate([
            'company_id'   => 'required|uuid|exists:companies,id',
            'warehouse_id' => 'required|uuid|exists:warehouses,id',
            'notes'        => 'nullable|string|max:1000',
            'product_ids'  => 'nullable|array',
            'product_ids.*'=> 'uuid|exists:products,id',
        ]);

        $session = $action->execute($validated);

        return $this->created($this->formatSession($session->load('lines.product', 'warehouse')));
    }

    /**
     * GET /inventory-counts/{inventoryCount}
     *
     * Mobile mode (hide_system_qty=1): omits system_qty from lines for blind counting.
     */
    public function show(Request $request, InventoryCountSession $inventoryCount): JsonResponse
    {
        $inventoryCount->loadMissing('lines.product', 'warehouse', 'company');
        $hideSysQty = $request->boolean('hide_system_qty');

        return $this->success($this->formatSession($inventoryCount, true, $hideSysQty));
    }

    /**
     * PUT /inventory-counts/{inventoryCount}
     *
     * Updates counted quantities and notes on count lines.
     * Only allowed while status is draft or in_progress.
     */
    public function update(Request $request, InventoryCountSession $inventoryCount): JsonResponse
    {
        if (! in_array($inventoryCount->status->value, ['draft', 'in_progress'], true)) {
            return $this->error('Count session cannot be edited in its current status.', 422);
        }

        $validated = $request->validate([
            'notes'                    => 'nullable|string|max:1000',
            'lines'                    => 'nullable|array',
            'lines.*.id'               => 'required|uuid|exists:inventory_count_lines,id',
            'lines.*.counted_qty'      => 'nullable|numeric|min:0',
            'lines.*.notes'            => 'nullable|string|max:500',
            'lines.*.photo_path'       => 'nullable|string|max:500',
        ]);

        if (isset($validated['notes'])) {
            $inventoryCount->update(['notes' => $validated['notes']]);
        }

        foreach ($validated['lines'] ?? [] as $lineData) {
            InventoryCountLine::query()->where('id', $lineData['id'])
                ->where('session_id', $inventoryCount->id)
                ->update(array_filter([
                    'counted_qty' => $lineData['counted_qty'] ?? null,
                    'notes'       => $lineData['notes'] ?? null,
                    'photo_path'  => $lineData['photo_path'] ?? null,
                ], fn ($v) => $v !== null));
        }

        $inventoryCount->loadMissing('lines.product', 'warehouse');

        return $this->updated($this->formatSession($inventoryCount, true));
    }

    /**
     * DELETE /inventory-counts/{inventoryCount}
     * Only draft sessions can be deleted.
     */
    public function destroy(InventoryCountSession $inventoryCount): JsonResponse
    {
        if ($inventoryCount->status->value !== 'draft') {
            return $this->error('Only draft count sessions can be deleted.', 422);
        }

        $inventoryCount->lines()->delete();
        $inventoryCount->delete();

        return $this->deleted();
    }

    public function start(InventoryCountSession $inventoryCount, StartCountSessionAction $action): JsonResponse
    {
        $session = $action->execute($inventoryCount);
        return $this->updated($this->formatSession($session));
    }

    public function complete(InventoryCountSession $inventoryCount, CompleteCountSessionAction $action): JsonResponse
    {
        $session = $action->execute($inventoryCount);
        return $this->updated($this->formatSession($session->load('lines.product', 'warehouse')));
    }

    public function approve(Request $request, InventoryCountSession $inventoryCount, ApproveCountSessionAction $action): JsonResponse
    {
        $approvedBy = $request->input('approved_by');
        $session    = $action->execute($inventoryCount, $approvedBy);
        return $this->updated($this->formatSession($session->load('lines.product', 'warehouse')));
    }

    public function cancel(InventoryCountSession $inventoryCount, CancelCountSessionAction $action): JsonResponse
    {
        $session = $action->execute($inventoryCount);
        return $this->updated($this->formatSession($session));
    }

    /** @param bool $includeLines whether to include line details */
    private function formatSession(InventoryCountSession $session, bool $includeLines = false, bool $hideSysQty = false): array
    {
        $data = [
            'id'           => $session->id,
            'count_number' => $session->count_number,
            'company_id'   => $session->company_id,
            'warehouse_id' => $session->warehouse_id,
            'warehouse'    => $session->relationLoaded('warehouse')
                ? ['id' => $session->warehouse->id, 'name' => $session->warehouse->name]
                : null,
            'status'       => $session->status->value,
            'status_label' => $session->status->label(),
            'started_at'   => $session->started_at?->toIso8601String(),
            'completed_at' => $session->completed_at?->toIso8601String(),
            'notes'        => $session->notes,
            'created_by'   => $session->created_by,
            'approved_by'  => $session->approved_by,
            'created_at'   => $session->created_at?->toIso8601String(),
            'updated_at'   => $session->updated_at?->toIso8601String(),
        ];

        if ($session->relationLoaded('lines')) {
            $lines = $session->lines->map(function (InventoryCountLine $line) use ($hideSysQty): array {
                $row = [
                    'id'          => $line->id,
                    'product_id'  => $line->product_id,
                    'product'     => $line->relationLoaded('product') && $line->product !== null
                        ? ['id' => $line->product->id, 'sku' => $line->product->sku, 'name' => $line->product->name, 'image_url' => $line->product->image_url]
                        : null,
                    'counted_qty' => $line->counted_qty !== null ? (float) $line->counted_qty : null,
                    'variance_qty'   => $line->variance_qty !== null ? (float) $line->variance_qty : null,
                    'variance_value' => $line->variance_value !== null ? (float) $line->variance_value : null,
                    'notes'       => $line->notes,
                    'photo_path'  => $line->photo_path,
                ];

                if (! $hideSysQty) {
                    $row['system_qty'] = (float) $line->system_qty;
                }

                return $row;
            })->values()->toArray();

            $data['lines'] = $lines;

            // Variance summary (only after completion)
            if (in_array($session->status->value, ['completed', 'approved'], true)) {
                $data['variance_summary'] = [
                    'total_lines'     => count($lines),
                    'counted_lines'   => collect($session->lines)->filter(fn ($l) => $l->counted_qty !== null)->count(),
                    'positive_lines'  => collect($session->lines)->filter(fn ($l) => (float) ($l->variance_qty ?? 0) > 0)->count(),
                    'negative_lines'  => collect($session->lines)->filter(fn ($l) => (float) ($l->variance_qty ?? 0) < 0)->count(),
                    'total_variance_value' => round($session->lines->sum(fn ($l) => (float) ($l->variance_value ?? 0)), 2),
                    'inventory_accuracy_pct' => $this->calcAccuracy($session),
                ];
            }
        }

        return $data;
    }

    private function calcAccuracy(InventoryCountSession $session): float|null
    {
        $counted = $session->lines->filter(fn ($l) => $l->counted_qty !== null);
        $total   = $counted->count();

        if ($total === 0) {
            return null;
        }

        $correct = $counted->filter(fn ($l) => (float) $l->variance_qty === 0.0)->count();

        return round($correct / $total * 100, 2);
    }
}
