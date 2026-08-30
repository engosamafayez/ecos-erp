<?php

declare(strict_types=1);

namespace Modules\Operations\DemandAnalysis\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Manufacturing\BillsOfMaterials\Domain\Services\ActiveRecipeResolver;
use Modules\Operations\DemandAnalysis\Application\Services\DemandProjectionBuilder;
use Modules\Operations\DemandAnalysis\Application\Services\DemandReadRepository;
use Modules\Operations\DemandAnalysis\Application\Services\ProductReadinessCalculator;
use Modules\Operations\DemandAnalysis\Application\Services\ShortageImpactAttributor;
use Modules\Operations\DemandAnalysis\Application\Services\WaveKpiCalculator;
use Modules\Operations\DemandAnalysis\Domain\Models\WaveExpectedIncoming;
use Modules\Operations\DemandAnalysis\Domain\Models\WaveMaterialDemand;
use Modules\Operations\DemandAnalysis\Domain\Models\WaveProductDemand;
use Modules\Operations\Preparation\Application\Services\WaveEngine\WaveMembershipService;
use Modules\Operations\Preparation\Domain\Models\PreparationWave;
use Modules\Operations\Preparation\Domain\Models\WaveEngineConfiguration;
use Modules\Purchasing\PurchaseOrders\Application\Queries\ExpectedIncomingQuery;

final class WaveDemandController extends Controller
{
    use HasApiResponse;

    public function __construct(
        private readonly DemandReadRepository $repository,
        private readonly ExpectedIncomingQuery $expectedIncoming,
    ) {}

    private function findWave(string $waveId, string $companyId): PreparationWave
    {
        return PreparationWave::where('id', $waveId)
            ->where('company_id', $companyId)
            ->firstOrFail();
    }

    /**
     * Expected Incoming per material for a wave.
     *
     * Two sources, in strict precedence (TASK-PREPARATION-WORKSPACE-FIX-003 §2):
     *   1. An operator value saved by Procurement for this wave+material, if one exists.
     *      Once set it is an INDEPENDENT planning input and no longer tracks the POs.
     *   2. Otherwise the derived open-purchase-order balance, exactly as before, so a
     *      material nobody has touched behaves identically to the pre-existing contract.
     *
     * PLANNING ONLY in both cases: this figure never touches inventory, the ledger, GRNs,
     * reservations, or the real missing_qty. It is used solely for
     * Uncovered = max(0, missing_qty - expected_incoming).
     *
     * @param  array<int, string>  $materialIds
     * @return array<string, float>
     */
    private function expectedIncomingFor(PreparationWave $wave, array $materialIds): array
    {
        if ($materialIds === []) {
            return [];
        }

        $derived = $this->expectedIncoming->forWarehouse(
            (string) $wave->company_id,
            (string) $wave->warehouse_id,
            $materialIds,
        );

        $overrides = WaveExpectedIncoming::where('preparation_wave_id', $wave->id)
            ->whereIn('material_id', $materialIds)
            ->pluck('expected_qty', 'material_id')
            ->map(static fn ($q): float => (float) $q)
            ->all();

        return array_merge($derived, $overrides);
    }

    /**
     * Wave-level KPIs, including the SINGLE SOURCE OF COMPLETION TRUTH
     * (TASK-PREPARATION-WORKSPACE-MOBILE-UX-ACTIVE-WAVE-001 §19-§21).
     *
     * Completion is computed LIVE from the same `wave_product_demand` the product-demand
     * rows derive from, via the canonical {@see WaveKpiCalculator} — so the wave header can
     * never disagree with the product list. It is quantity-weighted by contract
     * (SUM(prepared) / SUM(required)), never a naive average of per-product percentages.
     *
     * The stored `wave_kpis` row and the wave header's `total_units_*` columns are still
     * kept in sync by the demand builder (for the wave list and archive); this read simply
     * never trusts a snapshot that a mutation on another path might have left stale.
     */
    public function kpis(Request $request, string $waveId, WaveKpiCalculator $calculator): JsonResponse
    {
        $wave = $this->findWave($waveId, $request->user()->company_id);
        $kpi = $calculator->calculate($wave);

        return $this->success([
            'preparation_wave_id' => (string) $wave->id,
            'orders_count' => (int) $kpi['orders_count'],
            'products_count' => (int) $kpi['products_count'],
            'materials_count' => (int) $kpi['materials_count'],
            'missing_materials_count' => (int) $kpi['missing_materials_count'],
            'prepared_count' => (int) $kpi['prepared_count'],
            'remaining_count' => (int) $kpi['remaining_count'],
            'completion_pct' => (float) $kpi['completion_pct'],
            // Exposed so the header can render the exact quantities behind the percentage.
            'total_units_required' => (float) $kpi['_total_units_required'],
            'total_units_prepared' => (float) $kpi['_total_units_prepared'],
            'last_calculated_at' => now()->toIso8601String(),
        ]);
    }

    public function productDemand(Request $request, string $waveId): JsonResponse
    {
        $wave = $this->findWave($waveId, $request->user()->company_id);
        $items = $this->repository->getProductDemand($wave->id);

        // Remaining and Completion are DERIVED here, never read from storage.
        // Prepared is operator-owned and survives demand rebuilds, while Required is
        // recalculated on every rebuild - so a stored Remaining would go stale the
        // moment an order is postponed under an already-prepared product.
        return $this->success($items->map(fn ($i) => self::presentProductDemand($i))->values()->all());
    }

    /**
     * The single shape for a product-demand row. Used by the list and by the
     * Prepared write endpoint so both can never disagree.
     *
     * @return array<string, mixed>
     */
    private static function presentProductDemand(object $i): array
    {
        $required = (float) $i->required_qty;
        $prepared = (float) $i->prepared_qty;
        $remaining = max(0.0, round($required - $prepared, 4));
        $completion = $required > 0.0 ? round(($prepared / $required) * 100.0, 2) : 0.0;

        return [
            'id' => $i->id,
            'product_id' => $i->product_id,
            'product_name' => $i->product_name,
            'product_sku' => $i->product_sku,
            'required_qty' => $required,
            'prepared_qty' => $prepared,
            'remaining_qty' => $remaining,
            'orders_count' => (int) $i->orders_count,
            'completion_pct' => $completion,
            // Per-product preparation readiness. Distinct from Missing: a material may be
            // physically short (Procurement sees the real quantity) and preparation still
            // allowed, when that material is drawable on open credit.
            'material_status' => $i->material_status ?? ProductReadinessCalculator::READY,
            'blocking_materials_count' => (int) ($i->blocking_materials_count ?? 0),
            // "Continue despite shortage" operator decision (null = none). Records that a
            // human chose to let this product proceed short — it never deletes the line.
            'shortage_decision' => $i->shortage_decision ?? null,
            'shortage_decided_by' => $i->shortage_decided_by ?? null,
            'shortage_decided_at' => isset($i->shortage_decided_at) && $i->shortage_decided_at
                ? Carbon::parse($i->shortage_decided_at)->toIso8601String()
                : null,
            'preparation_completed_at' => $i->preparation_completed_at?->toIso8601String(),
            'last_calculated_at' => $i->last_calculated_at?->toIso8601String(),
        ];
    }

    /**
     * PATCH /preparation/waves/{waveId}/product-demand/{productId}/prepared
     *
     * Product-level Prepared (Option A): the quantity of this product actually
     * prepared so far in this wave. It is NOT distributed across order_lines and no
     * allocation rule is applied - the operator states one number per product.
     */
    public function updatePrepared(Request $request, string $waveId, string $productId, DemandProjectionBuilder $builder): JsonResponse
    {
        $wave = $this->findWave($waveId, $request->user()->company_id);

        $row = WaveProductDemand::where('preparation_wave_id', $wave->id)
            ->where('product_id', $productId)
            ->firstOrFail();

        // Preparation eligibility (owner contract). A product whose recipe needs a
        // material that is physically short AND not drawable on open credit cannot be
        // physically prepared, so recording progress against it is refused. This is
        // strictly PER PRODUCT: the wave is not blocked, other products stay preparable,
        // and neither the order status nor any reservation is touched.
        if ($row->material_status === ProductReadinessCalculator::WAITING_MATERIAL) {
            return $this->validation(
                ['material_status' => [
                    "Waiting for {$row->blocking_materials_count} missing material(s).",
                ]],
                'This product is waiting for material and cannot be prepared yet.',
            );
        }

        $validated = $request->validate([
            'prepared_qty' => ['required', 'numeric', 'min:0', 'max:'.(float) $row->required_qty],
        ]);

        $prepared = round((float) $validated['prepared_qty'], 4);
        $required = (float) $row->required_qty;

        $row->update([
            'prepared_qty' => $prepared,
            // Stored alongside for any consumer reading the table directly; the API
            // always re-derives, so these can never become the source of a discrepancy.
            'remaining_qty' => max(0.0, round($required - $prepared, 4)),
            'completion_pct' => $required > 0.0 ? round(($prepared / $required) * 100.0, 2) : 0.0,
        ]);

        // The wave header derives Complete from total_units_prepared, which only the demand
        // rebuild used to write - so without this the header stayed at its last snapshot while
        // the product row already read 100%. Recompute the aggregate from the same canonical
        // product demand that was just written.
        $builder->refreshWaveTotals($wave, 'prepared_recorded');

        return $this->success(self::presentProductDemand($row->fresh()));
    }

    /**
     * POST /preparation/waves/{waveId}/product-demand/{productId}/complete
     *
     * "Preparation finished" for this product. Deliberately a separate, explicit
     * action - editing Prepared never auto-completes, because reaching the required
     * quantity is not the same as the operator declaring the work done.
     */
    public function completePreparation(Request $request, string $waveId, string $productId, DemandProjectionBuilder $builder): JsonResponse
    {
        $wave = $this->findWave($waveId, $request->user()->company_id);

        $row = WaveProductDemand::where('preparation_wave_id', $wave->id)
            ->where('product_id', $productId)
            ->firstOrFail();

        // Both completion guards below are RELAXED for a row the operator has explicitly
        // decided to continue despite the shortage (استمرار العملية رغم العجز): that decision
        // IS the authorization to close a product that is waiting on material and/or short of
        // Required. Undecided rows stay fully guarded.
        $decided = $row->shortage_decision !== null;

        // Preparation eligibility (owner contract). A product whose recipe needs a
        // material that is physically short AND not drawable on open credit cannot be
        // physically prepared, so recording progress against it is refused. This is
        // strictly PER PRODUCT: the wave is not blocked, other products stay preparable,
        // and neither the order status nor any reservation is touched.
        if (! $decided && $row->material_status === ProductReadinessCalculator::WAITING_MATERIAL) {
            return $this->validation(
                ['material_status' => [
                    "Waiting for {$row->blocking_materials_count} missing material(s).",
                ]],
                'This product is waiting for material and cannot be prepared yet.',
            );
        }

        // Partial preparation must not be declared complete (P-04,
        // TASK-PREPARATION-MANUAL-REMEDIATION-001). The finished quantity has to reach
        // Required before the product can leave "in progress"; a shortfall keeps the row
        // open. Completion stays an explicit operator action — this only stops it being
        // claimed while work remains. round() mirrors the Prepared write path so float
        // dust (4.9999 vs 5.0) can never block a genuinely finished product.
        $required = round((float) $row->required_qty, 4);
        $prepared = round((float) $row->prepared_qty, 4);

        if (! $decided && $prepared < $required) {
            $remaining = round($required - $prepared, 4);

            return $this->validation(
                ['prepared_qty' => [
                    "Only {$prepared} of {$required} prepared — {$remaining} still remaining.",
                ]],
                'This product is not fully prepared yet and cannot be marked complete.',
            );
        }

        $row->update(['preparation_completed_at' => now()]);

        // The wave header derives Complete from total_units_prepared, which only the demand
        // rebuild used to write - so without this the header stayed at its last snapshot while
        // the product row already read 100%. Recompute the aggregate from the same canonical
        // product demand that was just written.
        $builder->refreshWaveTotals($wave, 'preparation_completed');

        return $this->success(self::presentProductDemand($row->fresh()));
    }

    /**
     * POST /preparation/waves/{waveId}/product-demand/{productId}/continue-despite-shortage
     *
     * "استمرار العملية رغم العجز" — Continue the process despite the shortage
     * (TASK-PREPARATION-OPERATIONS-UX-002 §15-§16). The operator explicitly decides to let a
     * product that is short on material proceed WITHOUT full preparation.
     *
     * What it does:
     *   - records the decision (`shortage_decision='continue'`, by whom/when) on the
     *     product-demand row — the level the engine already owns Prepared at;
     *   - the product is recorded as NOT FULLY FULFILLED via the EXISTING representation
     *     `prepared_qty < required_qty` (whatever has actually been prepared is kept).
     *
     * What it MUST NOT do (and does not):
     *   - delete the order line or the product from the order;
     *   - invent a new OrderStatus, or touch the order's status/price/totals;
     *   - touch inventory, the ledger, reservations, or fulfilment quantities on the order.
     *
     * The downstream shortage travels as data: Loading/Driver/Customer already carry the
     * short quantity in their own records. Financial settlement is deliberately NOT performed
     * here (see the engineering report — FULFILLMENT SHORTAGE FINANCIAL SETTLEMENT CONTRACT GAP).
     */
    public function continueDespiteShortage(Request $request, string $waveId, string $productId): JsonResponse
    {
        $wave = $this->findWave($waveId, $request->user()->company_id);

        $row = WaveProductDemand::where('preparation_wave_id', $wave->id)
            ->where('product_id', $productId)
            ->firstOrFail();

        $row->update([
            'shortage_decision' => 'continue',
            'shortage_decided_by' => (string) ($request->user()->id ?? ''),
            'shortage_decided_at' => now(),
        ]);

        return $this->success(self::presentProductDemand($row->fresh()));
    }

    /**
     * POST /preparation/waves/{waveId}/product-demand/{productId}/uncomplete
     *
     * Withdraw the completion declaration. The mirror of completePreparation(): a
     * product can go back to "not completed" at any time, because Required keeps moving
     * while a wave is open — an order postponed under an already-completed product makes
     * the old declaration describe work that no longer exists.
     *
     * prepared_qty is untouched. Only the claim is withdrawn, never the floor's number.
     */
    public function uncompletePreparation(Request $request, string $waveId, string $productId, DemandProjectionBuilder $builder): JsonResponse
    {
        $wave = $this->findWave($waveId, $request->user()->company_id);

        $row = WaveProductDemand::where('preparation_wave_id', $wave->id)
            ->where('product_id', $productId)
            ->firstOrFail();

        $row->update(['preparation_completed_at' => null]);

        // The wave header derives Complete from total_units_prepared, which only the demand
        // rebuild used to write - so without this the header stayed at its last snapshot while
        // the product row already read 100%. Recompute the aggregate from the same canonical
        // product demand that was just written.
        $builder->refreshWaveTotals($wave, 'preparation_uncompleted');

        return $this->success(self::presentProductDemand($row->fresh()));
    }

    /**
     * The order columns every Related Orders drill-down returns.
     *
     * One list, used by both drill-downs, so the two panels can never present a
     * different idea of an order. Delivery Zone resolves through the canonical
     * Distribution relation only — order -> logistics_city -> distribution_zone —
     * exactly as waveOrders() does; an unresolvable chain stays null and renders as
     * "Unassigned" rather than being guessed from the free-text columns.
     *
     * @return list<string>
     */
    private static function relatedOrderColumns(): array
    {
        return [
            'o.id            AS order_id',
            'o.order_number  AS order_number',
            'o.customer_name AS customer_name',
            'o.status        AS status',
            'o.payment_status AS payment_status',
            'o.total         AS total',
            'o.shipping_address AS shipping_address',
            'o.governorate   AS governorate',
            'o.city          AS city',
            'dz.name_en      AS zone_name_en',
            'dz.name_ar      AS zone_name_ar',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentRelatedOrder(object $r): array
    {
        return [
            'order_id' => $r->order_id,
            'order_number' => $r->order_number,
            'customer_name' => $r->customer_name,
            'status' => $r->status,
            'payment_status' => $r->payment_status,
            'total' => $r->total !== null ? (float) $r->total : null,
            'shipping_address' => $r->shipping_address,
            'governorate' => $r->governorate,
            'city' => $r->city,
            // null is meaningful: the presentation layer renders it as "Unassigned".
            'delivery_zone' => $r->zone_name_en ?? $r->zone_name_ar,
        ];
    }

    /**
     * GET /preparation/waves/{waveId}/product-demand/{productId}/orders
     *
     * The orders that make up this product's Required, straight down the canonical
     * chain wave -> wave orders -> order lines, using the SAME postponed_at exclusion
     * as ProductDemandCalculator so the rows always sum to the Required shown.
     *
     * Prepared is not returned per order: it is product-level (Option A).
     */
    public function productRelatedOrders(Request $request, string $waveId, string $productId): JsonResponse
    {
        $wave = $this->findWave($waveId, $request->user()->company_id);

        $groupBy = ['o.id', 'o.order_number', 'o.customer_name', 'o.status', 'o.payment_status',
            'o.total', 'o.shipping_address', 'o.governorate', 'o.city', 'dz.name_en', 'dz.name_ar'];

        $rows = DB::table('preparation_wave_orders as pwo')
            ->join('orders as o', 'o.id', '=', 'pwo.order_id')
            ->join('order_lines as ol', 'ol.order_id', '=', 'o.id')
            ->leftJoin('logistics_cities as lc', 'lc.id', '=', 'o.logistics_city_id')
            ->leftJoin('distribution_zones as dz', 'dz.id', '=', 'lc.distribution_zone_id')
            ->where('pwo.preparation_wave_id', $wave->id)
            ->whereNull('pwo.postponed_at')
            ->where('ol.product_id', $productId)
            ->groupBy($groupBy)
            ->selectRaw(implode(', ', array_merge(
                self::relatedOrderColumns(),
                ['SUM(ol.quantity) AS required_qty'],
            )))
            ->orderBy('o.order_number')
            ->get();

        return $this->success($rows->map(fn ($r) => self::presentRelatedOrder($r) + [
            'required_qty' => round((float) $r->required_qty, 4),
        ])->values()->all());
    }

    /**
     * GET /preparation/waves/{waveId}/missing-materials/{materialId}/orders
     *
     * Orders that drive demand for this raw material, through the ONLY relation that
     * exists: order -> product -> active recipe -> material. There is no direct
     * order/material link and none is invented here.
     *
     * The per-order material quantity must reconcile with the Required that
     * MaterialDemandCalculator aggregates, so it applies the SAME two factors that
     * calculator does — component quantity AND the component's waste percentage — over
     * the SAME one-recipe-per-product resolution (ActiveRecipeResolver). Joining
     * `bills_of_materials WHERE is_active` directly, as this did, both dropped waste and
     * counted a product carrying two active versions twice.
     */
    public function materialRelatedOrders(
        Request $request,
        string $waveId,
        string $materialId,
        ActiveRecipeResolver $activeRecipes,
    ): JsonResponse {
        $wave = $this->findWave($waveId, $request->user()->company_id);

        // Products actually demanded by this wave's active memberships. Bounded by the
        // wave's product count, which is the same bound the demand projection accepts.
        $productIds = DB::table('preparation_wave_orders as pwo')
            ->join('order_lines as ol', 'ol.order_id', '=', 'pwo.order_id')
            ->where('pwo.preparation_wave_id', $wave->id)
            ->whereNull('pwo.postponed_at')
            ->distinct()
            ->pluck('ol.product_id')
            ->all();

        $bomIds = array_values($activeRecipes->bomIdsByProduct($productIds));

        if ($bomIds === []) {
            return $this->success([]);
        }

        $groupBy = ['o.id', 'o.order_number', 'o.customer_name', 'o.status', 'o.payment_status',
            'o.total', 'o.shipping_address', 'o.governorate', 'o.city', 'dz.name_en', 'dz.name_ar',
            'p.id', 'p.name'];

        $rows = DB::table('preparation_wave_orders as pwo')
            ->join('orders as o', 'o.id', '=', 'pwo.order_id')
            ->join('order_lines as ol', 'ol.order_id', '=', 'o.id')
            ->join('products as p', 'p.id', '=', 'ol.product_id')
            ->join('bills_of_materials as bom', 'bom.product_id', '=', 'p.id')
            ->join('bill_of_material_lines as boml', 'boml.bom_id', '=', 'bom.id')
            ->leftJoin('logistics_cities as lc', 'lc.id', '=', 'o.logistics_city_id')
            ->leftJoin('distribution_zones as dz', 'dz.id', '=', 'lc.distribution_zone_id')
            ->where('pwo.preparation_wave_id', $wave->id)
            ->whereNull('pwo.postponed_at')
            ->whereIn('bom.id', $bomIds)
            ->where('boml.raw_material_id', $materialId)
            ->groupBy($groupBy)
            ->selectRaw(implode(', ', array_merge(
                self::relatedOrderColumns(),
                [
                    'p.id   AS product_id',
                    'p.name AS product_name',
                    'SUM(ol.quantity) AS product_qty',
                    'SUM(ol.quantity * boml.quantity * (1 + COALESCE(boml.waste_percentage, 0) / 100)) AS material_qty',
                ],
            )))
            ->orderBy('o.order_number')
            ->get();

        return $this->success($rows->map(fn ($r) => self::presentRelatedOrder($r) + [
            'product_id' => $r->product_id,
            'product_name' => $r->product_name,
            'product_qty' => round((float) $r->product_qty, 4),
            'material_qty' => round((float) $r->material_qty, 4),
        ])->values()->all());
    }

    public function materialDemand(Request $request, string $waveId): JsonResponse
    {
        $wave = $this->findWave($waveId, $request->user()->company_id);
        $items = $this->repository->getMaterialDemand($wave->id);

        return $this->success($items->map(fn ($i) => [
            'id' => $i->id,
            'material_id' => $i->material_id,
            'material_name' => $i->material_name,
            'material_sku' => $i->material_sku,
            'required_qty' => (float) $i->required_qty,
            'available_qty' => (float) $i->available_qty,
            'reserved_qty' => (float) $i->reserved_qty,
            'expected_today' => (float) $i->expected_today,
            'in_transit_qty' => (float) $i->in_transit_qty,
            'missing_qty' => (float) $i->missing_qty,
            'coverage_pct' => (float) $i->coverage_pct,
            'last_calculated_at' => $i->last_calculated_at?->toIso8601String(),
        ])->values()->all());
    }

    /**
     * GET /preparation/waves/{waveId}/deficit-decisions
     *
     * The operator decision workspace, driven by REAL UNCOVERED MATERIAL SHORTAGE IMPACT
     * (TASK-PREPARATION-DEFICIT-DECISIONS-IMPACT-001 section 1).
     *
     * THE CANDIDATE RULE. A material needs a decision when
     * `uncovered = max(0, missing_qty - expected_incoming) > 0`. That is the whole rule. It is
     * deliberately NOT gated on `material_status = waiting_material` nor on
     * `allow_negative_stock`, because READINESS and SHORTAGE DECISION are independent:
     *
     *   readiness - "may preparation proceed?"        (owned by ProductReadinessCalculator)
     *   decision  - "does this uncovered shortage need an operator call?"  (owned here)
     *
     * An allow-negative material is still READY - that contract is untouched - yet its
     * uncovered shortage is real and still needs a decision. ADR-027 section 18.6.1 is explicit
     * that missing_qty is "the REAL physical shortage, always ... even when preparation is
     * allowed to proceed", and 18.6.3 scopes material_status enforcement to
     * updatePrepared/completePreparation, not to this endpoint. The expected-incoming half of
     * the old rule is PRESERVED: a shortage fully covered by expected incoming is not a
     * candidate (owner section 13).
     *
     * GRAIN. One row per ORDER, never per (order x product) - an order affected by several
     * materials or several lines appears exactly once.
     *
     * READS ONLY: no order, status, inventory, reservation, ledger, goods receipt, purchase
     * order or total is touched. Decisions happen via continue-despite-shortage / postpone,
     * both unchanged.
     */
    public function deficitDecisions(
        Request $request,
        string $waveId,
        ShortageImpactAttributor $attributor,
    ): JsonResponse {
        $wave = $this->findWave($waveId, $request->user()->company_id);

        $materialDemand = $this->repository->getMaterialDemand($wave->id)->keyBy('material_id');

        // Single source of truth for Expected Incoming - the same helper Missing Materials
        // uses, so the two screens can never disagree on Uncovered.
        $expected = $this->expectedIncomingFor(
            $wave,
            $materialDemand->keys()->map(static fn ($id): string => (string) $id)->all(),
        );

        // 1. Materials carrying an uncovered shortage.
        $uncoveredMaterials = [];

        foreach ($materialDemand as $materialId => $m) {
            $missing = (float) $m->missing_qty;
            $incoming = (float) ($expected[(string) $materialId] ?? 0.0);
            $uncovered = round(max(0.0, $missing - $incoming), 4);

            if ($uncovered <= 0.0) {
                continue;
            }

            $uncoveredMaterials[(string) $materialId] = [
                'material_id' => (string) $materialId,
                'material_name' => $m->material_name,
                'material_sku' => $m->material_sku,
                'required_qty' => round((float) $m->required_qty, 4),
                'available_qty' => round((float) $m->available_qty, 4),
                'missing_qty' => round($missing, 4),
                'expected_incoming_qty' => round($incoming, 4),
                'uncovered_qty' => $uncovered,
                // Readiness is reported for transparency; it does NOT gate candidacy.
                'allow_negative' => (bool) ($m->allow_negative ?? false),
                'affected_orders_count' => 0,
            ];
        }

        if ($uncoveredMaterials === []) {
            return $this->success(['materials' => [], 'totals' => self::emptyDeficitTotals(), 'orders' => [],
                'postponed_orders' => $this->postponedOrders($wave, $attributor)]);
        }

        // 2. Material -> Recipe -> Product -> Order Line attribution.
        $impacts = $attributor->forMaterials($wave, array_keys($uncoveredMaterials));

        if ($impacts->isEmpty()) {
            return $this->success([
                'materials' => array_values($uncoveredMaterials),
                'totals' => ['uncovered_materials' => count($uncoveredMaterials), 'affected_orders' => 0],
                'orders' => [],
                'postponed_orders' => $this->postponedOrders($wave, $attributor),
            ]);
        }

        // 3. Fold the line-level impact up to ONE ROW PER ORDER.
        $byOrder = [];

        foreach ($impacts as $row) {
            $orderId = (string) $row->order_id;
            $productId = (string) $row->product_id;
            $materialId = (string) $row->material_id;
            $impact = round((float) $row->impact_qty, 4);

            if (! isset($byOrder[$orderId])) {
                $byOrder[$orderId] = ['products' => [], 'materials' => [], 'lines' => []];
            }

            $byOrder[$orderId]['lines'][(string) $row->order_line_id] = true;

            if (! isset($byOrder[$orderId]['products'][$productId])) {
                $byOrder[$orderId]['products'][$productId] = [
                    'product_id' => $productId,
                    'product_name' => $row->product_name,
                    'affected_lines' => 0,
                    'impact_qty' => 0.0,
                ];
            }
            $byOrder[$orderId]['products'][$productId]['affected_lines']++;
            $byOrder[$orderId]['products'][$productId]['impact_qty'] = round(
                $byOrder[$orderId]['products'][$productId]['impact_qty'] + $impact, 4,
            );

            if (! isset($byOrder[$orderId]['materials'][$materialId])) {
                $byOrder[$orderId]['materials'][$materialId] = [
                    'material_id' => $materialId,
                    'material_name' => $uncoveredMaterials[$materialId]['material_name'],
                    'impact_qty' => 0.0,
                ];
            }
            $byOrder[$orderId]['materials'][$materialId]['impact_qty'] = round(
                $byOrder[$orderId]['materials'][$materialId]['impact_qty'] + $impact, 4,
            );
        }

        foreach ($byOrder as $data) {
            foreach (array_keys($data['materials']) as $materialId) {
                $uncoveredMaterials[$materialId]['affected_orders_count']++;
            }
        }

        // 4. Order facts, read from the Order domain. No financial figure is recomputed.
        $orders = DB::table('orders as o')
            ->whereIn('o.id', array_keys($byOrder))
            ->select(['o.id', 'o.order_number', 'o.customer_name', 'o.status', 'o.payment_status',
                'o.total', 'o.deposit_amount', 'o.created_at', 'o.updated_at',
                // Payment METHOD (not state). The Orders domain keeps it across three columns
                // and resolves them in this precedence; see paymentMethodOf().
                'o.payment_method', 'o.payment_method_title', 'o.payment_method_manual'])
            ->orderBy('o.order_number')
            ->get();

        // Every product on each affected order - the Products column shows the WHOLE order,
        // not only the affected lines.
        $allLines = DB::table('order_lines as ol')
            ->join('products as p', 'p.id', '=', 'ol.product_id')
            ->whereIn('ol.order_id', array_keys($byOrder))
            ->select(['ol.order_id', 'ol.product_id', 'p.name as product_name', 'ol.quantity'])
            ->get()
            ->groupBy('order_id');

        $decisions = WaveProductDemand::where('preparation_wave_id', $wave->id)
            ->get()
            ->keyBy('product_id');

        $presented = $orders->map(function ($o) use ($byOrder, $allLines, $decisions, $wave) {
            $data = $byOrder[(string) $o->id];
            $affectedProducts = array_values($data['products']);
            $lines = $allLines[(string) $o->id] ?? collect();

            // A decision is recorded per product; the order reads as decided only when EVERY
            // affected product on it carries one. No new order status is introduced.
            $decidedCount = 0;
            foreach ($affectedProducts as $p) {
                if (($decisions[$p['product_id']]->shortage_decision ?? null) !== null) {
                    $decidedCount++;
                }
            }

            return [
                'wave_id' => (string) $wave->id,
                'order_id' => (string) $o->id,
                'order_number' => $o->order_number,
                'customer_name' => $o->customer_name,
                'order_value' => round((float) $o->total, 2),
                'deposit_amount' => round((float) $o->deposit_amount, 2),
                'payment_status' => $o->payment_status,
                // The METHOD the customer pays by (cod / instapay / ...), resolved exactly as
                // the Orders UI resolves it. Null stays null — never invented.
                'payment_method' => self::paymentMethodOf($o),
                'status' => $o->status,
                'entry_at' => self::isoOrNull($o->created_at),
                'last_updated_at' => self::isoOrNull($o->updated_at),
                'products' => $lines->map(static fn ($l): array => [
                    'product_id' => (string) $l->product_id,
                    'product_name' => $l->product_name,
                    'quantity' => round((float) $l->quantity, 4),
                ])->values()->all(),
                'products_count' => $lines->count(),
                'affected_products' => $affectedProducts,
                'affected_materials' => array_values($data['materials']),
                'affected_lines_count' => count($data['lines']),
                // Impact of the uncovered materials on THIS order, from the attribution above.
                'shortage_impact_qty' => round(array_sum(array_column($affectedProducts, 'impact_qty')), 4),
                'shortage_decision' => $decidedCount > 0 && $decidedCount === count($affectedProducts) ? 'continue' : null,
            ];
        })->values()->all();

        return $this->success([
            'materials' => array_values($uncoveredMaterials),
            'totals' => [
                // Quantities are NOT summed across materials: units differ, so totals are
                // reported per material and only the COUNTS are aggregated.
                'uncovered_materials' => count($uncoveredMaterials),
                'affected_orders' => count($presented),
            ],
            'orders' => $presented,
            'postponed_orders' => $this->postponedOrders($wave, $attributor),
        ]);
    }

    /**
     * POST /preparation/waves/{waveId}/orders/{orderId}/return-to-preparation
     *
     * Return a postponed order to preparation once the material that caused the postponement
     * is available again (TASK-PREPARATION-DEFICIT-RETURN-TO-PREPARATION-PART-2).
     *
     * This is an UPDATE clearing `postponed_at` on the RETAINED membership row — never an
     * insert, never a new order, never a status change. Everything it refuses, it refuses by
     * an EXISTING rule:
     *
     *  - the wave must still be Collecting. Intake belongs to a Collecting wave; a wave that
     *    has moved to Preparing has frozen its Required. The approved path for that order is
     *    the existing carry-over (closeWave releases membership, the next cycle collects it),
     *    so this endpoint refuses rather than forcing an order into a frozen wave.
     *  - the order must be a postponed, still-unreleased member of THIS wave.
     *  - the order's status must still be fulfilment-eligible per the wave engine's own
     *    `eligible_order_statuses` configuration.
     *  - every material the order needs must actually be available again — otherwise the
     *    order would return only to be blocked, which is what postponement was for.
     *
     * Touches no inventory, reservation, ledger, goods receipt, purchase order or Expected
     * Incoming value.
     */
    public function returnOrderToPreparation(
        Request $request,
        string $waveId,
        string $orderId,
        ShortageImpactAttributor $attributor,
        WaveMembershipService $membership,
    ): JsonResponse {
        $wave = $this->findWave($waveId, $request->user()->company_id);

        // CUTOFF IS NOT A MEMBERSHIP LOCK (TASK-…-DEFERRED-ORDER-CUTOFF-RETURN-001).
        // This required `Collecting`, so an order that joined the wave BEFORE cutoff and was
        // then parked for a shortage could never come back once intake closed — even with the
        // wave still open and preparation still running. Intake governs orders trying to JOIN;
        // this endpoint returns an order that is already a member. Only a wave that has
        // genuinely ENDED refuses it, and such an order is handled by the existing carry-over.
        if ($wave->status->isTerminal()) {
            return $this->validation(
                ['wave' => ['This preparation wave has closed.']],
                'This wave has closed. The order will be collected by the next preparation wave.',
            );
        }

        $member = DB::table('preparation_wave_orders')
            ->where('preparation_wave_id', $wave->id)
            ->where('order_id', $orderId)
            ->whereNull('released_at')
            ->first(['postponed_at']);

        if ($member === null || $member->postponed_at === null) {
            return $this->validation(
                ['order' => ['This order is not postponed in this wave.']],
                'Only a postponed order can be returned to preparation.',
            );
        }

        $eligibility = $this->returnEligibility($wave, $orderId, $attributor);

        if (! $eligibility['can_return']) {
            return $this->validation(
                ['order' => [$eligibility['reason'] ?? 'Not eligible.']],
                $eligibility['reason'] ?? 'This order cannot be returned to preparation.',
            );
        }

        $returned = $membership->returnPostponedOrder($wave, $orderId, (string) $request->user()->id);

        if (! $returned) {
            return $this->validation(
                ['order' => ['The order could not be returned.']],
                'The order could not be returned to preparation.',
            );
        }

        return $this->success([
            'wave_id' => (string) $wave->id,
            'order_id' => $orderId,
            'returned' => true,
        ], 'Order returned to preparation.');
    }

    /**
     * Can this postponed order be returned to preparation right now, and if not, why?
     *
     * ONE implementation, used by both the read (so the UI only offers a button that will
     * work) and the write (so the refusal is authoritative). Every rule it applies already
     * exists somewhere else — none is invented here.
     *
     * @return array{can_return: bool, reason: string|null}
     */
    private function returnEligibility(
        PreparationWave $wave,
        string $orderId,
        ShortageImpactAttributor $attributor,
    ): array {
        $order = DB::table('orders')->where('id', $orderId)->first(['id', 'status']);

        if ($order === null) {
            return ['can_return' => false, 'reason' => 'Order not found.'];
        }

        // The wave engine's own eligibility catalogue, not a second opinion.
        $eligible = WaveEngineConfiguration::where('company_id', $wave->company_id)
            ->where('warehouse_id', $wave->warehouse_id)
            ->value('eligible_order_statuses');
        $eligible = is_string($eligible) ? (json_decode($eligible, true) ?: []) : ($eligible ?: []);

        if ($eligible !== [] && ! in_array((string) $order->status, $eligible, true)) {
            return ['can_return' => false, 'reason' => 'This order is no longer eligible for preparation.'];
        }

        // Is the material that caused the postponement available again?
        //
        // Availability is read from inventory, NOT from wave_material_demand. The projection
        // is the wrong source here: postponing the order removed its requirement, so the
        // material's demand row may no longer exist at all — and an ABSENT row means
        // "nobody currently needs it", never "it is in stock". Reading inventory directly
        // uses the same figures the projection derives from and behaves correctly either way.
        //
        // An allow-negative material is drawable on credit by the certified contract, so it
        // never blocks a return.
        $materialIds = DB::table('wave_material_demand')
            ->where('preparation_wave_id', $wave->id)
            ->pluck('material_id')->map(static fn ($id): string => (string) $id)->all();

        // The postponed order's own materials are not in the wave projection any more, so the
        // catalogue is widened to every material its lines actually consume.
        $ownMaterials = DB::table('order_lines as ol')
            ->join('bills_of_materials as bom', 'bom.product_id', '=', 'ol.product_id')
            ->join('bill_of_material_lines as boml', 'boml.bom_id', '=', 'bom.id')
            ->where('ol.order_id', $orderId)
            ->pluck('boml.raw_material_id')->map(static fn ($id): string => (string) $id)->all();

        $materialIds = array_values(array_unique(array_merge($materialIds, $ownMaterials)));

        $needed = [];
        foreach ($attributor->forMaterials($wave, $materialIds, includePostponed: true) as $impact) {
            if ((string) $impact->order_id !== $orderId) {
                continue;
            }
            $key = (string) $impact->material_id;
            $needed[$key] = ($needed[$key] ?? 0.0) + (float) $impact->impact_qty;
        }

        if ($needed === []) {
            return ['can_return' => true, 'reason' => null];
        }

        $stock = DB::table('inventory_items as ii')
            ->join('products as p', 'p.id', '=', 'ii.product_id')
            ->where('ii.warehouse_id', $wave->warehouse_id)
            ->whereIn('ii.product_id', array_keys($needed))
            ->get(['ii.product_id', 'ii.on_hand_qty', 'ii.reserved_qty', 'p.allow_negative_stock'])
            ->keyBy('product_id');

        foreach ($needed as $materialId => $requiredQty) {
            $row = $stock[$materialId] ?? null;

            if ($row !== null && (bool) $row->allow_negative_stock) {
                continue;
            }

            $available = $row === null
                ? 0.0
                : (float) $row->on_hand_qty - (float) $row->reserved_qty;

            if ($available + 1e-6 < $requiredQty) {
                return [
                    'can_return' => false,
                    'reason' => 'The material that caused the postponement is still not available.',
                ];
            }
        }

        return ['can_return' => true, 'reason' => null];
    }

    /**
     * Orders postponed out of THIS wave, with whether each may be returned right now.
     *
     * Deliberately a SEPARATE list from `orders`: a postponed order is excluded from demand
     * and from the uncovered calculation, and that stays true. This adds a surface for the
     * Return action without altering the deficit queue by one figure.
     *
     * @return array<int, array<string, mixed>>
     */
    private function postponedOrders(PreparationWave $wave, ShortageImpactAttributor $attributor): array
    {
        $rows = DB::table('preparation_wave_orders as pwo')
            ->join('orders as o', 'o.id', '=', 'pwo.order_id')
            ->where('pwo.preparation_wave_id', $wave->id)
            ->whereNotNull('pwo.postponed_at')
            ->whereNull('pwo.released_at')
            ->select(['o.id', 'o.order_number', 'o.customer_name', 'o.status', 'o.total',
                'o.payment_status', 'o.payment_method', 'o.payment_method_title',
                'o.payment_method_manual', 'pwo.postponed_at'])
            ->orderBy('o.order_number')
            ->get();

        // Must mirror the write path EXACTLY, or the screen offers a button the endpoint
        // refuses (or hides one it would accept). Both now key on "has the wave ended",
        // never on "is intake still open" — cutoff stops new admissions, it does not lock
        // the members already in the wave (TASK-…-DEFERRED-ORDER-CUTOFF-RETURN-001).
        $waveOpen = ! $wave->status->isTerminal();

        return $rows->map(function ($o) use ($wave, $attributor, $waveOpen): array {
            $eligibility = $waveOpen
                ? $this->returnEligibility($wave, (string) $o->id, $attributor)
                : ['can_return' => false, 'reason' => 'This wave has closed. The order will be collected by the next preparation wave.'];

            return [
                'order_id' => (string) $o->id,
                'order_number' => $o->order_number,
                'customer_name' => $o->customer_name,
                'order_value' => round((float) $o->total, 2),
                'payment_method' => self::paymentMethodOf($o),
                'status' => $o->status,
                'postponed_at' => self::isoOrNull($o->postponed_at),
                'can_return' => $eligibility['can_return'],
                'return_blocked_reason' => $eligibility['reason'],
            ];
        })->values()->all();
    }

    /**
     * The order's payment METHOD, resolved with the Orders domain's own precedence.
     *
     * The method is stored across three nullable columns and there is no enum: an
     * operator-set `payment_method_manual` (the five-value catalogue the create/update gates
     * enforce), the WooCommerce gateway's display `payment_method_title`, and the raw gateway
     * slug `payment_method`. The Orders UI resolves manual -> title -> slug, so this reads the
     * same way rather than inventing a fourth opinion. Returns null when nothing is recorded —
     * the caller renders a dash; no value is ever fabricated.
     */
    private static function paymentMethodOf(object $order): ?string
    {
        foreach (['payment_method_manual', 'payment_method_title', 'payment_method'] as $field) {
            $value = $order->{$field} ?? null;

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    /** @return array{uncovered_materials: int, affected_orders: int} */
    private static function emptyDeficitTotals(): array
    {
        return ['uncovered_materials' => 0, 'affected_orders' => 0];
    }

    private static function isoOrNull(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->toIso8601String();
    }

    public function missingMaterials(Request $request, string $waveId): JsonResponse
    {
        $wave = $this->findWave($waveId, $request->user()->company_id);
        $items = $this->repository->getMissingMaterials($wave->id);

        // The shortage row itself carries only missing_qty. Required / Available /
        // Reserved / Coverage are the SAME canonical figures the Raw Materials tab
        // shows, and both tables are keyed by (wave, material) — so they are joined
        // here rather than recomputed. No inventory arithmetic happens in this
        // controller and none happens in the UI.
        $demandByMaterial = $this->repository->getMaterialDemand($wave->id)->keyBy('material_id');

        // Expected Incoming = what Procurement has on open purchase orders for this warehouse
        // but has not yet received (TASK-PREPARATION-OPERATIONS-UX-002 §9-§11). PLANNING data,
        // read-only, derived from purchase_order_lines — it NEVER touches inventory, the ledger,
        // GRNs, FIFO, or reservations, and NEVER reduces the real missing_qty. Uncovered Shortage
        // = missing_qty - expected_incoming (floored at 0): the part no in-flight order yet covers.
        // Operator value when Procurement has set one, else the open-PO balance.
        $expectedIncoming = $this->expectedIncomingFor(
            $wave,
            $items->pluck('material_id')->map(static fn ($id): string => (string) $id)->all(),
        );

        return $this->success($items->map(function ($i) use ($demandByMaterial, $expectedIncoming, $wave) {
            $demand = $demandByMaterial->get($i->material_id);
            $missing = (float) $i->missing_qty;
            $incoming = (float) ($expectedIncoming[(string) $i->material_id] ?? 0.0);

            return [
                'id' => $i->id,
                'material_id' => $i->material_id,
                'material_name' => $i->material_name,
                'material_sku' => $demand?->material_sku,
                'warehouse_id' => (string) $wave->warehouse_id,
                'required_qty' => $demand !== null ? (float) $demand->required_qty : null,
                'available_qty' => $demand !== null ? (float) $demand->available_qty : null,
                'reserved_qty' => $demand !== null ? (float) $demand->reserved_qty : null,
                'coverage_pct' => $demand !== null ? (float) $demand->coverage_pct : null,
                'missing_qty' => $missing,
                // Planning-only; explicitly separate from missing_qty so the operator can see
                // the real shortage and what is expected to cover it, without conflating them.
                'expected_incoming_qty' => round($incoming, 4),
                'uncovered_shortage_qty' => round(max(0.0, $missing - $incoming), 4),
                'affected_orders_count' => (int) $i->affected_orders_count,
                'last_calculated_at' => $i->last_calculated_at?->toIso8601String(),
            ];
        })->values()->all());
    }

    /**
     * Set Procurement's Expected Incoming for one material in this wave.
     *
     * PLANNING WRITE ONLY (TASK-PREPARATION-WORKSPACE-FIX-003 §2). It saves a single
     * number and nothing else: no goods receipt, no stock movement, no ledger entry, no
     * reservation, no readiness recalculation, no order-status or wave-status change. It
     * does NOT alter missing_qty — the real shortage keeps its own meaning — and it does
     * NOT edit the underlying purchase order. When the material actually arrives, the
     * normal Goods Receipt path updates inventory and readiness as it always has.
     *
     * Tenant isolation uses the same findWave() scope as every other endpoint here, so a
     * wave belonging to another company 404s before anything is written.
     */
    public function updateExpectedIncoming(Request $request, string $waveId, string $materialId): JsonResponse
    {
        $wave = $this->findWave($waveId, $request->user()->company_id);

        // The material must genuinely be part of this wave's demand — this prevents the
        // endpoint from being used to create planning rows for arbitrary material ids.
        $demand = WaveMaterialDemand::where('preparation_wave_id', $wave->id)
            ->where('material_id', $materialId)
            ->firstOrFail();

        $validated = $request->validate([
            'expected_qty' => ['required', 'numeric', 'min:0', 'max:99999999'],
        ]);

        $expected = round((float) $validated['expected_qty'], 4);

        $row = WaveExpectedIncoming::updateOrCreate(
            ['preparation_wave_id' => $wave->id, 'material_id' => $materialId],
            [
                'company_id' => (string) $wave->company_id,
                'expected_qty' => $expected,
                'updated_by' => $request->user()->id,
            ],
        );

        $missing = (float) $demand->missing_qty;

        return $this->success([
            'preparation_wave_id' => (string) $wave->id,
            'material_id' => (string) $materialId,
            // The three figures stay explicitly distinct (owner §9).
            'missing_qty' => round($missing, 4),
            'expected_incoming_qty' => round((float) $row->expected_qty, 4),
            'uncovered_shortage_qty' => round(max(0.0, $missing - (float) $row->expected_qty), 4),
            'updated_at' => $row->updated_at?->toIso8601String(),
        ]);
    }

    public function manufacturingDemand(Request $request, string $waveId): JsonResponse
    {
        $wave = $this->findWave($waveId, $request->user()->company_id);
        $items = $this->repository->getManufacturingDemand($wave->id);

        return $this->success($items->map(fn ($i) => [
            'id' => $i->id,
            'product_id' => $i->product_id,
            'product_name' => $i->product_name,
            'required_qty' => (float) $i->required_qty,
            'planned_qty' => (float) $i->planned_qty,
            'manufacturing_qty' => (float) $i->manufacturing_qty,
            'completed_qty' => (float) $i->completed_qty,
            'remaining_qty' => (float) $i->remaining_qty,
            'last_calculated_at' => $i->last_calculated_at?->toIso8601String(),
        ])->values()->all());
    }

    public function waveOrders(Request $request, string $waveId): JsonResponse
    {
        $wave = $this->findWave($waveId, $request->user()->company_id);

        $orders = DB::table('preparation_wave_orders as pwo')
            ->join('orders as o', 'o.id', '=', 'pwo.order_id')
            // Delivery Zone resolves ONLY through the canonical Distribution relation
            // (REFINEMENT-002 §5): order -> logistics_city -> distribution_zone. The
            // free-text `orders.delivery_zone` and `pwo.delivery_zone_snapshot`, the
            // governorate name and `master_zones` are all deliberately NOT used — an
            // unresolvable chain reads as null here and renders "Unassigned" rather than
            // being guessed from text.
            ->leftJoin('logistics_cities as lc', 'lc.id', '=', 'o.logistics_city_id')
            ->leftJoin('distribution_zones as dz', 'dz.id', '=', 'lc.distribution_zone_id')
            ->where('pwo.preparation_wave_id', $wave->id)
            // Postponed memberships are retained as history but have left the current
            // cycle, so they are not part of the active order list (§2, §22).
            ->whereNull('pwo.postponed_at')
            ->orderBy('pwo.added_at', 'desc')
            ->select([
                'pwo.id',
                'pwo.order_id',
                'pwo.order_number',
                'pwo.preparation_priority',
                // Retained for the Dashboard, which reads this same endpoint and is out of
                // scope for this task. The orders table does not render them.
                'pwo.customer_name_snapshot',
                'pwo.delivery_zone_snapshot',
                'pwo.added_at',
                // Customer comes from the order itself, not a duplicated snapshot (§6).
                'o.customer_name',
                'dz.name_en as zone_name_en',
                'dz.name_ar as zone_name_ar',
            ])
            ->get();

        // Per-order line items (§7). Sourced from the order's own canonical lines —
        // `wave_items` is a wave-level aggregate and can never answer "what is in THIS
        // order". One query for the whole page, so the table stays O(1) in round trips.
        $lines = $orders->isEmpty() ? collect() : DB::table('order_lines as ol')
            ->join('products as p', 'p.id', '=', 'ol.product_id')
            ->whereIn('ol.order_id', $orders->pluck('order_id')->all())
            ->select(['ol.order_id', 'ol.product_id', 'ol.quantity', 'p.name as product_name', 'p.sku'])
            ->get()
            ->groupBy('order_id');

        return $this->success($orders->map(fn ($o) => [
            'id' => $o->id,
            'order_id' => $o->order_id,
            'order_number' => $o->order_number,
            'customer_name' => $o->customer_name,
            // null is meaningful: the presentation layer renders it as "Unassigned".
            'delivery_zone' => $o->zone_name_en ?? $o->zone_name_ar,
            'preparation_priority' => $o->preparation_priority ?? 5,
            // Legacy keys kept so the wave Dashboard keeps working untouched.
            'customer_name_snapshot' => $o->customer_name_snapshot,
            'delivery_zone_snapshot' => $o->delivery_zone_snapshot,
            'added_at' => $o->added_at,
            'products' => collect($lines->get($o->order_id, collect()))
                ->map(fn ($l) => [
                    'product_id' => $l->product_id,
                    'name' => $l->product_name,
                    'sku' => $l->sku,
                    'quantity' => (float) $l->quantity,
                ])->values()->all(),
        ])->values()->all());
    }
}
