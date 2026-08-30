<?php

declare(strict_types=1);

namespace Modules\Operations\Preparation\Application\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Organization\Branches\Domain\Models\Branch;
use Modules\Organization\Branches\Domain\Models\BranchCoverageArea;
use Modules\Operations\Preparation\Domain\Enums\WarehouseAssignmentSource;
use Modules\Operations\Preparation\Domain\Events\BranchAssigned;
use Modules\Operations\Preparation\Domain\Events\WarehouseAssigned;

/**
 * TASK-BRANCH-ASSIGNMENT-ENGINE-001 — Branch Assignment Engine.
 *
 * Replaces WarehouseAssignmentEngine with an intelligent coverage-driven assignment.
 *
 * Flow:
 *   Order → customer delivery governorate + zone
 *   → CoverageResolutionService → candidate BranchCoverageArea list
 *   → select best branch (single: direct; multiple: nearest by Haversine / priority)
 *   → BranchWarehouseResolver → warehouse for that branch
 *   → persist: assigned_branch_id + assigned_warehouse_id + warehouse_assignment_source
 *   → dispatch BranchAssigned event
 *
 * When no branch covers the destination:
 *   → warehouse_assignment_source = no_branch_coverage
 *   → warehouse_assignment_failure_reason = 'No Branch Covers Destination'
 *   → assigned_branch_id + assigned_warehouse_id stay NULL
 *   → order status is NOT changed (remains Operations problem, not Inventory problem)
 */
final class BranchAssignmentEngine
{
    public function __construct(
        private readonly CoverageResolutionService $coverage,
        private readonly BranchWarehouseResolver $warehouseResolver,
    ) {}

    /**
     * Assign a branch (and its warehouse) to the order using coverage rules.
     *
     * Caller: CreateManualOrderAction (and any other order ingestion path).
     *
     * @param  string|null $companyId  Resolved company context; falls back to order.company_id.
     */
    public function assign(Order $order, ?string $companyId = null): void
    {
        $companyId ??= $order->company_id;

        if ($companyId === null) {
            $this->markUnresolved($order);

            return;
        }

        $governorate = (string) ($order->governorate ?? '');
        $zone        = (string) ($order->area ?? $order->delivery_zone ?? '');

        if ($governorate === '') {
            $this->markUnresolved($order);

            return;
        }

        // D1: the order's canonical zone reference is passed through when present,
        // so resolution is by identity rather than by name wherever possible.
        $candidates = $this->coverage->resolve($governorate, $zone ?: null, $order->delivery_zone_id);

        // Filter to branches that belong to this company and are active.
        $candidates = $candidates->filter(
            fn (BranchCoverageArea $c) => $c->branch !== null
                && $c->branch->company_id === $companyId
                && $c->branch->is_active,
        );

        if ($candidates->isEmpty()) {
            $this->markNoCoverage($order);

            return;
        }

        // ── Brand eligibility ────────────────────────────────────────────────────
        //
        // Applied BEFORE ranking, never after: a warehouse that cannot serve the
        // order's brands is not a lower-ranked candidate, it is not a candidate.
        // Ranking the geography first and checking brands afterwards would let the
        // nearest branch win and then fail, which is the regression the negative
        // test guards against.
        //
        // Geography and brand are independent AND-conditions. Neither may stand in
        // for the other, and brand compatibility never overrides geography.
        $order->loadMissing('lines.product');

        // A line whose brand cannot be derived makes coverage UNVERIFIABLE — fail
        // closed. This is different from an order with no lines at all, which
        // requires no brand and so cannot leave one unserved.
        if ($order->lines->isNotEmpty()
            && $order->lines->contains(fn ($line) => $line->product?->brand_id === null)
        ) {
            $this->markNoCoverage($order, 'Order line has no resolvable product brand');

            return;
        }

        $requiredBrands = $this->requiredBrandIds($order);

        if ($requiredBrands !== []) {
            $candidates = $this->filterByBrandCoverage($candidates, $requiredBrands, $companyId);

            if ($candidates->isEmpty()) {
                // Geography matched; brands did not. A distinct reason, so Operations
                // can tell "we do not deliver there" from "we deliver there, but no
                // warehouse there carries this brand".
                $this->markNoCoverage($order, 'No Warehouse Serves Order Brands');

                return;
            }
        }

        // Existing priority selection, unchanged, over the brand-eligible survivors.
        $branch = $candidates->count() === 1
            ? $candidates->first()->branch
            : $this->selectNearest($order, $candidates);

        $warehouseId = $this->warehouseResolver->resolve($branch);

        if ($warehouseId === null) {
            $this->markNoCoverage($order, 'Assigned branch has no active warehouse');

            return;
        }

        $previousBranchId    = $order->assigned_branch_id;
        $previousWarehouseId = $order->assigned_warehouse_id;

        $order->update([
            'assigned_branch_id'        => $branch->id,
            'assigned_warehouse_id'     => $warehouseId,
            'warehouse_assigned_at'     => now(),
            'warehouse_assignment_source' => WarehouseAssignmentSource::BranchCoverage->value,
            'warehouse_assignment_failure_reason' => null,
        ]);

        BranchAssigned::dispatch(
            orderId: $order->id,
            branchId: $branch->id,
            warehouseId: $warehouseId,
            previousBranchId: $previousBranchId,
            previousWarehouseId: $previousWarehouseId,
            occurredAt: now()->toIso8601String(),
        );

        $this->announceWarehouseAssignment(
            $order,
            $warehouseId,
            $previousWarehouseId,
            WarehouseAssignmentSource::BranchCoverage,
        );
    }

    /**
     * Supervisor manually assigns a specific branch (and its warehouse) to an order.
     * Stores an audit trail via warehouse_assignment_source = manual_override.
     */
    public function override(Order $order, Branch $branch, string $reason): void
    {
        $warehouseId = $this->warehouseResolver->resolve($branch);

        if ($warehouseId === null) {
            $this->markNoCoverage($order, 'Overridden branch has no active warehouse');

            return;
        }

        $previousBranchId    = $order->assigned_branch_id;
        $previousWarehouseId = $order->assigned_warehouse_id;

        $order->update([
            'assigned_branch_id'        => $branch->id,
            'assigned_warehouse_id'     => $warehouseId,
            'warehouse_assigned_at'     => now(),
            'warehouse_assignment_source' => WarehouseAssignmentSource::ManualOverride->value,
            'warehouse_assignment_failure_reason' => null,
        ]);

        BranchAssigned::dispatch(
            orderId: $order->id,
            branchId: $branch->id,
            warehouseId: $warehouseId,
            previousBranchId: $previousBranchId,
            previousWarehouseId: $previousWarehouseId,
            occurredAt: now()->toIso8601String(),
        );

        $this->announceWarehouseAssignment(
            $order,
            $warehouseId,
            $previousWarehouseId,
            WarehouseAssignmentSource::ManualOverride,
        );
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Every distinct brand the order requires, via Product → Brand (ADR-013).
     *
     * A multi-brand order returns several ids and ALL of them must be served by a
     * single warehouse — the order is never split here (that is a separate
     * authorised capability).
     *
     * @return list<string>
     */
    private function requiredBrandIds(Order $order): array
    {
        return $order->lines
            ->map(fn ($line) => $line->product?->brand_id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Keep only candidates whose warehouse serves EVERY required brand.
     *
     * NO ROWS = SERVES NO BRANDS. An unconfigured warehouse is filtered out, never
     * waved through — absence of permission is not permission.
     *
     * Coverage rows are matched on company_id as well as warehouse_id: defence in
     * depth behind the branch-level company filter, so a mis-set row can never leak
     * one tenant's warehouse into another tenant's order.
     *
     * @param  Collection<int, BranchCoverageArea>  $candidates
     * @param  list<string>  $requiredBrands
     * @return Collection<int, BranchCoverageArea>
     */
    private function filterByBrandCoverage(
        Collection $candidates,
        array $requiredBrands,
        string $companyId,
    ): Collection {
        // Resolve each candidate branch to its warehouse once.
        $warehouseByBranch = [];
        foreach ($candidates as $candidate) {
            $branch = $candidate->branch;
            if ($branch === null || isset($warehouseByBranch[$branch->id])) {
                continue;
            }
            $warehouseByBranch[$branch->id] = $this->warehouseResolver->resolve($branch);
        }

        $warehouseIds = array_values(array_filter($warehouseByBranch));

        if ($warehouseIds === []) {
            return collect();
        }

        // One query for the whole candidate set.
        $served = DB::table('warehouse_brand_coverage')
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->whereIn('warehouse_id', $warehouseIds)
            ->whereIn('brand_id', $requiredBrands)
            ->get(['warehouse_id', 'brand_id'])
            ->groupBy('warehouse_id')
            ->map(fn ($rows) => $rows->pluck('brand_id')->unique()->all())
            ->all();

        $requiredCount = count($requiredBrands);

        return $candidates->filter(function (BranchCoverageArea $c) use ($warehouseByBranch, $served, $requiredCount): bool {
            $branch = $c->branch;
            if ($branch === null) {
                return false;
            }

            $warehouseId = $warehouseByBranch[$branch->id] ?? null;
            if ($warehouseId === null) {
                return false;
            }

            // Every required brand must be present — a warehouse serving a subset
            // of a multi-brand order is not eligible.
            return count($served[$warehouseId] ?? []) === $requiredCount;
        })->values();
    }

    /**
     * Emit the CANONICAL warehouse-assignment event.
     *
     * ADR-027 §2 names `WarehouseAssigned` as the trigger that resumes a postponed
     * reservation, and §15 H3 builds its retry listener on it. Preparation's
     * auto-attach (`WarehouseAssignedListener`) subscribes to it too.
     *
     * `BranchAssigned` above stays as the branch-specific detail event for consumers
     * that need the branch transition. It is deliberately NOT the assignment trigger:
     * one canonical event per fact (ADR-024), so a consumer never has to subscribe
     * twice to learn that an order gained a warehouse.
     *
     * This is the seam that broke when BranchAssignmentEngine replaced
     * WarehouseAssignmentEngine — the dispatch moved, the subscribers did not.
     */
    private function announceWarehouseAssignment(
        Order $order,
        string $warehouseId,
        ?string $previousWarehouseId,
        WarehouseAssignmentSource $source,
    ): void {
        WarehouseAssigned::dispatch(
            orderId: $order->id,
            warehouseId: $warehouseId,
            previousWarehouseId: $previousWarehouseId,
            source: $source,
            policyId: null,
            occurredAt: now()->toIso8601String(),
        );
    }

    /**
     * Select the nearest branch to the order's delivery coordinates.
     * Falls back to priority ordering when GPS coordinates are unavailable on
     * either the order or the branch.
     *
     * @param  Collection<int, BranchCoverageArea> $candidates  Already priority-sorted ASC.
     */
    private function selectNearest(Order $order, Collection $candidates): Branch
    {
        $orderLat = $order->google_maps_lat !== null ? (float) $order->google_maps_lat : null;
        $orderLng = $order->google_maps_lng !== null ? (float) $order->google_maps_lng : null;

        if ($orderLat !== null && $orderLng !== null) {
            $best     = null;
            $bestDist = PHP_FLOAT_MAX;

            foreach ($candidates as $candidate) {
                $branch = $candidate->branch;
                if ($branch === null) {
                    continue;
                }

                $bLat = $branch->latitude !== null ? (float) $branch->latitude : null;
                $bLng = $branch->longitude !== null ? (float) $branch->longitude : null;

                if ($bLat === null || $bLng === null) {
                    continue;
                }

                $dist = $this->haversineKm($orderLat, $orderLng, $bLat, $bLng);
                if ($dist < $bestDist) {
                    $bestDist = $dist;
                    $best     = $branch;
                }
            }

            if ($best !== null) {
                return $best;
            }
        }

        // No GPS available on order or branches — fall back to priority ordering (candidates are
        // already sorted ASC by priority coming from CoverageResolutionService).
        return $candidates->first()->branch;
    }

    /**
     * Great-circle distance between two points (Haversine formula), in kilometres.
     */
    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** No governorate/zone on the order — cannot resolve coverage. */
    private function markUnresolved(Order $order): void
    {
        $order->update([
            'warehouse_assigned_at'     => now(),
            'warehouse_assignment_source' => WarehouseAssignmentSource::Unassigned->value,
        ]);
    }

    /**
     * Coverage resolution returned no matching branch.
     * This is an Operations triage signal — NOT an Inventory problem.
     * order.status is intentionally left unchanged.
     */
    private function markNoCoverage(Order $order, string $reason = 'No Branch Covers Destination'): void
    {
        $order->update([
            'warehouse_assigned_at'               => now(),
            'warehouse_assignment_source'          => WarehouseAssignmentSource::NoBranchCoverage->value,
            'warehouse_assignment_failure_reason'  => $reason,
        ]);
    }
}
