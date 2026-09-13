<?php

declare(strict_types=1);

namespace Modules\Inventory\InventoryControl\Application\Services;

use App\Core\Company\TenantOwnershipResolver;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\CountSessions\Domain\Enums\CountSessionStatus;

/**
 * Computes per-warehouse inventory performance metrics.
 *
 * TASK-ECOS-V1.1-OPS-01-IMPLEMENTATION-044A-R1: every query here is scoped to
 * the acting company using the same tenant idiom as Warehouse/GoodsReceipt
 * (see App\Core\Company\TenantOwnershipResolver). Before this fix, `allWarehouses()`
 * enumerated every warehouse in the database regardless of company, and every
 * per-warehouse breakdown query bypassed Eloquent (and therefore any model-level
 * global scope) entirely.
 */
final class WarehousePerformanceService
{
    public function __construct(
        private readonly TenantOwnershipResolver $tenant,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function allWarehouses(int $months = 12): array
    {
        $since = Carbon::now()->subMonths($months);

        $warehousesQuery = DB::table('warehouses')->whereNull('deleted_at');
        $this->scopeToCompany($warehousesQuery, 'company_id');

        $warehouses = $warehousesQuery->get();

        return $warehouses->map(function ($warehouse) use ($since): array {
            return $this->forWarehouse($warehouse->id, $warehouse->name, $since);
        })->values()->all();
    }

    private function forWarehouse(string $warehouseId, string $warehouseName, Carbon $since): array
    {
        // ── Accuracy ──────────────────────────────────────────────────────────
        $accQuery = DB::table('inventory_count_lines as icl')
            ->join('inventory_count_sessions as ics', 'ics.id', '=', 'icl.session_id')
            ->where('ics.warehouse_id', $warehouseId)
            ->where('ics.status', CountSessionStatus::Approved->value)
            ->whereNotNull('icl.counted_qty')
            ->where('ics.completed_at', '>=', $since);
        $this->scopeToCompany($accQuery, 'ics.company_id');

        $acc = $accQuery
            ->selectRaw('
                COUNT(icl.id) as total,
                SUM(CASE WHEN icl.variance_qty = 0 THEN 1 ELSE 0 END) as matched
            ')
            ->first();

        $total = (int) ($acc?->total ?? 0);
        $matched = (int) ($acc?->matched ?? 0);
        $accuracy = $total > 0 ? round($matched / $total * 100, 2) : null;

        // ── Average Variance % ────────────────────────────────────────────────
        $avgVarianceQuery = DB::table('inventory_count_lines as icl')
            ->join('inventory_count_sessions as ics', 'ics.id', '=', 'icl.session_id')
            ->where('ics.warehouse_id', $warehouseId)
            ->where('ics.status', CountSessionStatus::Approved->value)
            ->whereNotNull('icl.variance_qty')
            ->whereRaw('icl.system_qty > 0')
            ->where('ics.completed_at', '>=', $since);
        $this->scopeToCompany($avgVarianceQuery, 'ics.company_id');

        $avgVariancePct = $avgVarianceQuery
            ->selectRaw('AVG(ABS(icl.variance_qty) / icl.system_qty * 100) as avg_variance_pct')
            ->value('avg_variance_pct');

        // ── Adjustment Value ──────────────────────────────────────────────────
        $adjQuery = DB::table('inventory_count_lines as icl')
            ->join('inventory_count_sessions as ics', 'ics.id', '=', 'icl.session_id')
            ->where('ics.warehouse_id', $warehouseId)
            ->where('ics.status', CountSessionStatus::Approved->value)
            ->where('ics.completed_at', '>=', $since)
            ->whereNotNull('icl.variance_value');
        $this->scopeToCompany($adjQuery, 'ics.company_id');

        $adj = $adjQuery
            ->selectRaw('
                COALESCE(SUM(CASE WHEN icl.variance_value > 0 THEN icl.variance_value ELSE 0 END), 0) as adj_in,
                COALESCE(SUM(CASE WHEN icl.variance_value < 0 THEN ABS(icl.variance_value) ELSE 0 END), 0) as adj_out
            ')
            ->first();

        // ── Session Counts ────────────────────────────────────────────────────
        $sessionCountsQuery = DB::table('inventory_count_sessions')
            ->where('warehouse_id', $warehouseId)
            ->where('created_at', '>=', $since);
        $this->scopeToCompany($sessionCountsQuery, 'company_id');

        $sessionCounts = $sessionCountsQuery
            ->selectRaw('
                COUNT(*) as total_sessions,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as approved_sessions,
                SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) as open_sessions
            ', [
                CountSessionStatus::Approved->value,
                CountSessionStatus::Draft->value,
                CountSessionStatus::InProgress->value,
            ])
            ->first();

        $totalSessions = (int) ($sessionCounts?->total_sessions ?? 0);
        $approvedSessions = (int) ($sessionCounts?->approved_sessions ?? 0);
        $openSessions = (int) ($sessionCounts?->open_sessions ?? 0);

        // Completion rate excludes cancelled sessions
        $completionRate = $totalSessions > 0
            ? round($approvedSessions / $totalSessions * 100, 2)
            : null;

        return [
            'warehouse_id' => $warehouseId,
            'warehouse_name' => $warehouseName,
            'accuracy_pct' => $accuracy,
            'avg_variance_pct' => $avgVariancePct !== null ? round((float) $avgVariancePct, 2) : null,
            'adj_in_value' => round((float) ($adj?->adj_in ?? 0), 2),
            'adj_out_value' => round((float) ($adj?->adj_out ?? 0), 2),
            'count_completion_rate' => $completionRate,
            'open_counts' => $openSessions,
            'total_sessions' => $totalSessions,
        ];
    }

    /**
     * Applies the same tenant-isolation idiom used by Warehouse/GoodsReceipt
     * (see Modules\MasterData\Warehouses\Domain\Models\Warehouse::booted()) to
     * a raw query-builder query, since these performance queries bypass
     * Eloquent (and therefore any model-level global scope) entirely.
     */
    private function scopeToCompany(Builder $query, string $column): void
    {
        if (! $this->tenant->appliesTo()) {
            return;
        }

        if ($this->tenant->isUnrestricted()) {
            return;
        }

        $companyId = $this->tenant->companyId();

        if ($companyId === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where($column, $companyId);
    }
}
