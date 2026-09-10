<?php

declare(strict_types=1);

namespace Modules\Purchasing\PurchaseMaterials\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Purchasing\PurchaseMaterials\Domain\Models\PurchaseMaterial;

final class GetPurchaseMaterialStatsAction
{
    private const OPEN_STATUSES = [
        'draft', 'under_review', 'waiting_supplier_selection', 'approved', 'purchasing', 'receiving', 'on_hold',
    ];

    private const TERMINAL_STATUSES = ['completed', 'cancelled', 'rejected'];

    public function execute(?string $companyId = null, ?string $warehouseId = null, ?string $recordType = null): array
    {
        $query = PurchaseMaterial::query();

        if ($companyId !== null && $companyId !== '') {
            $query->where('company_id', $companyId);
        }
        if ($warehouseId !== null && $warehouseId !== '') {
            $query->where('warehouse_id', $warehouseId);
        }
        // Scope the KPI aggregation by record_type — mirrors the LIST repository filter
        // (EloquentPurchaseMaterialRepository). Without this the Purchases screen's KPI
        // cards summed material_request rows together with purchase rows, so Material
        // Requests "appeared" on the Purchases screen through its stats even though the
        // table below was filtered. Skip empty / 'all' so an unscoped caller is unchanged.
        $recordType = $recordType !== null ? trim($recordType) : null;
        if ($recordType !== null && $recordType !== '' && $recordType !== 'all') {
            $query->where('record_type', $recordType);
        }

        // Status counts — TASK-...-011 §17/§19: completed/rejected/on_hold/cancelled were silently
        // dropped from the operational breakdown before, so a request could vanish from every KPI
        // the moment it left the "in progress" states without appearing to have gone anywhere.
        $byCounts = (clone $query)
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // Priority counts
        $byPriority = (clone $query)
            ->select('priority', DB::raw('COUNT(*) as count'))
            ->groupBy('priority')
            ->pluck('count', 'priority')
            ->toArray();

        $openIds = (clone $query)->whereIn('status', self::OPEN_STATUSES)->pluck('id');

        // Ownership / SLA workload — TASK-...-011 §5/§17/§18. Scoped to OPEN requests only: a
        // completed or cancelled request being "unowned" or "overdue" is not actionable workload.
        $unownedCount = (clone $query)->whereIn('status', self::OPEN_STATUSES)
            ->whereNull('assigned_buyer_id')->count();

        $overdueCount = (clone $query)->whereIn('status', self::OPEN_STATUSES)
            ->whereNotNull('required_date')->where('required_date', '<', now()->toDateString())->count();

        $requiredSoonCount = (clone $query)->whereIn('status', self::OPEN_STATUSES)
            ->whereNotNull('required_date')
            ->whereBetween('required_date', [now()->toDateString(), now()->addDays(3)->toDateString()])
            ->count();

        // Ordering workload across open requests — one grouped query over lines, not one query
        // per request (§20's N+1 guard). A line counts as "ordered" only once its full requested
        // quantity is committed, matching PurchaseMaterialReceivingService::isFullyOrdered().
        $lineOrdering = $openIds->isEmpty() ? (object) ['ordered' => 0, 'not_yet_ordered' => 0] : DB::table('purchase_material_lines')
            ->whereIn('purchase_material_id', $openIds)
            ->selectRaw(
                'SUM(CASE WHEN COALESCE(agreed_qty, 0) >= requested_qty THEN 1 ELSE 0 END) as ordered,
                 SUM(CASE WHEN COALESCE(agreed_qty, 0) < requested_qty THEN 1 ELSE 0 END) as not_yet_ordered',
            )
            ->first();

        // Real derived value (requested qty x LATEST purchase price) across open requests — the
        // stored estimated_value/approved_value/purchased_value columns are never written by any
        // Action (confirmed by repo-wide search), so they are intentionally NOT surfaced as a
        // reconciled Hub KPI; this is the one honest value figure this endpoint exposes.
        // TASK-...-019 §3: uses product.last_purchase_cost (updated on every posted Goods
        // Receipt, PO- or Purchase-Material-anchored alike), NOT average_cost — a weighted
        // average is not "the latest price." A line never yet received (no last_purchase_cost)
        // contributes 0 to this sum rather than a fabricated price; it is a running total of
        // known prices, not a claim that every open line is accounted for.
        $estimatedValue = $openIds->isEmpty() ? 0.0 : (float) DB::table('purchase_material_lines as pml')
            ->join('products as p', 'p.id', '=', 'pml.product_id')
            ->whereIn('pml.purchase_material_id', $openIds)
            ->sum(DB::raw('pml.requested_qty * COALESCE(p.last_purchase_cost, 0)'));

        return [
            'operational' => [
                'draft' => (int) ($byCounts['draft'] ?? 0),
                'under_review' => (int) ($byCounts['under_review'] ?? 0),
                'waiting_supplier_selection' => (int) ($byCounts['waiting_supplier_selection'] ?? 0),
                'approved' => (int) ($byCounts['approved'] ?? 0),
                'purchasing' => (int) ($byCounts['purchasing'] ?? 0),
                'receiving' => (int) ($byCounts['receiving'] ?? 0),
                'completed' => (int) ($byCounts['completed'] ?? 0),
                'on_hold' => (int) ($byCounts['on_hold'] ?? 0),
                'rejected' => (int) ($byCounts['rejected'] ?? 0),
                'cancelled' => (int) ($byCounts['cancelled'] ?? 0),
                'open_total' => $openIds->count(),
            ],
            // Requests by the 6 user-approved visible statuses (TASK-...-019 §5/§17) — reuses
            // $byCounts above (zero extra queries), bucketed exactly like
            // PurchaseMaterialStatus::displayBucket(): under_review/waiting_supplier_selection/
            // approved/on_hold all fold into "awaiting_supplier", cancelled folds into
            // "rejected". This is an aggregate snapshot, not a per-record view, so on_hold uses
            // the enum's own safe-default bucket rather than resolving each row's
            // held_from_status individually — see PurchaseMaterial::displayStatus() for the
            // precise per-record version shown on the table/detail.
            'by_display_status' => [
                'draft' => (int) ($byCounts['draft'] ?? 0),
                'awaiting_supplier' => (int) ($byCounts['under_review'] ?? 0)
                    + (int) ($byCounts['waiting_supplier_selection'] ?? 0)
                    + (int) ($byCounts['approved'] ?? 0)
                    + (int) ($byCounts['on_hold'] ?? 0),
                'purchasing' => (int) ($byCounts['purchasing'] ?? 0),
                'receiving' => (int) ($byCounts['receiving'] ?? 0),
                'completed' => (int) ($byCounts['completed'] ?? 0),
                'rejected' => (int) ($byCounts['rejected'] ?? 0) + (int) ($byCounts['cancelled'] ?? 0),
            ],
            'workload' => [
                'unowned_count' => $unownedCount,
                'overdue_count' => $overdueCount,
                'required_soon_count' => $requiredSoonCount,
                'ordered_lines' => (int) ($lineOrdering->ordered ?? 0),
                'not_yet_ordered_lines' => (int) ($lineOrdering->not_yet_ordered ?? 0),
            ],
            'financial' => [
                'estimated_value_open' => round($estimatedValue, 2),
            ],
            'by_priority' => [
                'urgent' => (int) ($byPriority['urgent'] ?? 0),
                'high' => (int) ($byPriority['high'] ?? 0),
                'normal' => (int) ($byPriority['normal'] ?? 0),
                'low' => (int) ($byPriority['low'] ?? 0),
            ],
        ];
    }
}
