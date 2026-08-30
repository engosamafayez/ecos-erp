<?php

declare(strict_types=1);

namespace Modules\Operations\DemandAnalysis\Application\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Application\Actions\ReconcileOrderMaterialReservationsAction;
use Modules\Inventory\InventoryItems\Domain\Enums\LedgerMovementType;
use Modules\Manufacturing\BillsOfMaterials\Domain\Services\ActiveRecipeResolver;
use Modules\Operations\Preparation\Domain\Models\PreparationWave;

/**
 * Explodes product demand through BOMs to derive raw-material requirements.
 *
 * Reads product demand from wave_product_demand (already calculated).
 * Joins bills_of_materials + bill_of_material_lines + inventory_items in DB.
 * Never loads full collections into PHP memory.
 *
 * Incremental mode: pass $affectedProductIds to re-explode only those products.
 * All other material rows are left untouched (the upsert only updates matched keys).
 */
final class MaterialDemandCalculator
{
    public function __construct(private readonly ActiveRecipeResolver $activeRecipes) {}

    /**
     * Which materials each of the wave's products consumes, via its ONE active recipe.
     *
     * This is the reverse edge readiness needs (short material → affected products). The
     * explosion below already derives it per BOM line and then aggregates it away into a
     * material-keyed total; exposing it here reuses that exact traversal and the same
     * canonical `ActiveRecipeResolver` authority rather than introducing a second one.
     *
     * @param  list<string>|null  $affectedProductIds  Null = every product in the wave.
     * @return array<string, list<string>> productId => materialId[]
     */
    public function materialsByProduct(PreparationWave $wave, ?array $affectedProductIds = null): array
    {
        $productQuery = DB::table('wave_product_demand')
            ->where('preparation_wave_id', $wave->id)
            ->select(['product_id']);

        if ($affectedProductIds !== null && count($affectedProductIds) > 0) {
            $productQuery->whereIn('product_id', $affectedProductIds);
        }

        $productIds = $productQuery->pluck('product_id')->all();

        if ($productIds === []) {
            return [];
        }

        $bomIdByProduct = $this->activeRecipes->bomIdsByProduct($productIds);

        if ($bomIdByProduct === []) {
            return [];
        }

        $materialsByBom = DB::table('bill_of_material_lines')
            ->whereIn('bom_id', array_values($bomIdByProduct))
            ->select(['bom_id', 'raw_material_id'])
            ->get()
            ->groupBy('bom_id');

        $map = [];

        foreach ($bomIdByProduct as $productId => $bomId) {
            $map[(string) $productId] = array_values(array_unique(
                ($materialsByBom[$bomId] ?? collect())
                    ->pluck('raw_material_id')
                    ->map(static fn ($id): string => (string) $id)
                    ->all(),
            ));
        }

        return $map;
    }

    /**
     * @param  list<string>|null  $affectedProductIds  Null = full recalculation.
     * @return list<array<string, mixed>>
     */
    public function calculate(PreparationWave $wave, ?array $affectedProductIds = null): array
    {
        // ── Step 1: load the relevant product demand rows ──────────────────────
        $productQuery = DB::table('wave_product_demand')
            ->where('preparation_wave_id', $wave->id)
            ->where('required_qty', '>', 0)
            ->select(['product_id', 'required_qty']);

        if ($affectedProductIds !== null && count($affectedProductIds) > 0) {
            $productQuery->whereIn('product_id', $affectedProductIds);
        }

        $productDemand = $productQuery->get()->keyBy('product_id');

        if ($productDemand->isEmpty()) {
            return [];
        }

        $productIds = $productDemand->keys()->all();

        // ── Step 2: explode BOMs ───────────────────────────────────────────────
        // EXACTLY ONE recipe per product, resolved through the canonical
        // Product::activeRecipe() rule (highest bom_version_number among active,
        // non-soft-deleted versions).
        //
        // This used to join `bills_of_materials WHERE is_active = true` directly. There
        // is no unique constraint on (product_id, is_active) and the deactivate-others
        // convention cannot reach a soft-deleted row that is still flagged active, so a
        // product carrying two active versions had EVERY component counted once per
        // version — Required, Missing and the shortage priority all multiplied.
        $bomIdByProduct = $this->activeRecipes->bomIdsByProduct($productIds);

        if ($bomIdByProduct === []) {
            return [];
        }

        $productIdByBom = array_flip($bomIdByProduct);

        $bomLines = DB::table('bill_of_material_lines as boml')
            ->whereIn('boml.bom_id', array_values($bomIdByProduct))
            ->join('products as p', 'p.id', '=', 'boml.raw_material_id')
            ->select([
                'boml.bom_id                   AS bom_id',
                'boml.raw_material_id          AS material_id',
                'p.name                        AS material_name',
                'p.sku                         AS material_sku',
                'p.allow_negative_stock        AS allow_negative',
                DB::raw('CAST(boml.quantity AS DECIMAL(15,4))          AS qty_per_unit'),
                DB::raw('COALESCE(CAST(boml.waste_percentage AS DECIMAL(15,4)), 0) AS waste_pct'),
            ])
            ->get();

        if ($bomLines->isEmpty()) {
            return [];
        }

        // ── Step 3: aggregate required quantities per material ────────────────
        /** @var array<string, array{material_id:string, material_name:string, material_sku:string|null, required_qty:float}> $aggregates */
        $aggregates = [];

        foreach ($bomLines as $line) {
            $finishedProductId = $productIdByBom[$line->bom_id] ?? null;

            if ($finishedProductId === null) {
                continue;
            }

            $productRequiredQty = (float) ($productDemand[$finishedProductId]->required_qty ?? 0.0);
            $qtyPerUnit = (float) $line->qty_per_unit;
            $wasteFactor = 1.0 + ((float) $line->waste_pct / 100.0);
            $materialRequired = $productRequiredQty * $qtyPerUnit * $wasteFactor;

            if (! isset($aggregates[$line->material_id])) {
                $aggregates[$line->material_id] = [
                    'material_id' => $line->material_id,
                    'material_name' => $line->material_name,
                    'material_sku' => $line->material_sku,
                    'allow_negative' => (bool) $line->allow_negative,
                    'required_qty' => 0.0,
                ];
            }

            $aggregates[$line->material_id]['required_qty'] += $materialRequired;
        }

        // ── Step 4: fetch stock levels for all materials in one query ─────────
        $materialIds = array_keys($aggregates);

        // `inventory_items` is soft-deleting, and since the partial unique index
        // (warehouse_id, product_id) WHERE deleted_at IS NULL, a live row and any number
        // of soft-deleted rows for the SAME warehouse+product legitimately coexist.
        // This is a raw query builder, so the model's SoftDeletes scope does not apply:
        // without this filter keyBy() kept whichever row the engine returned last, which
        // could be a deleted row carrying stale on_hand / reserved — the material then
        // reads negative Available inside the wave while the warehouse genuinely holds
        // stock. Every other availability reader (Eloquent-based) already excludes them.
        $stockLevels = DB::table('inventory_items')
            ->where('warehouse_id', $wave->warehouse_id)
            ->whereIn('product_id', $materialIds)
            ->whereNull('deleted_at')
            ->selectRaw('product_id, on_hand_qty, reserved_qty')
            ->get()
            ->keyBy('product_id');

        // ── Step 5: build result rows ─────────────────────────────────────────
        $now = now()->toDateTimeString();

        $rows = [];

        // Material commitment this wave's OWN active orders already hold (see
        // ownWaveMaterialReservations). Subtracting it is what stops Required and the
        // reservation behind it being charged twice.
        $ownReserved = $this->ownWaveMaterialReservations($wave, $materialIds);

        // Reservations still held by orders this wave has POSTPONED (members whose
        // membership has not been released). Postponement releases no inventory, so these
        // reservations linger in inventory_items.reserved_qty; their Required has already
        // left the projection and the commitment is parked for the order's next eligible
        // wave, so they must not resurface as competing demand against the orders still
        // being prepared now (ADR-027 §18.3). See postponedMemberMaterialReservations.
        $postponedReserved = $this->postponedMemberMaterialReservations($wave, $materialIds);

        foreach ($aggregates as $agg) {
            $required = round($agg['required_qty'], 4);
            $stockRow = $stockLevels[$agg['material_id']] ?? null;
            $onHand = $stockRow ? (float) $stockRow->on_hand_qty : 0.0;
            $reserved = $stockRow ? (float) $stockRow->reserved_qty : 0.0;
            // Raw-material availability is on-hand minus the reservations held by OTHER
            // demand. For the Preparation wave view it is FLOORED at zero (ADR-027 §18.2):
            // a competing over-commitment caps availability at nothing so operator-facing
            // Missing never runs past Required. The raw signed deficit stays visible to
            // Inventory/Manufacturing through the unchanged §17.3 availableQty.
            //
            // The previous comment here claimed "order reservation reserves the ORDERED
            // product and never explodes a BOM, so a component's reserved_qty is always
            // demand competing with this wave". THAT IS NO LONGER TRUE, and had not been
            // since ADR-027 §17: ReserveOrderInventoryAction calls
            // ReconcileOrderMaterialReservationsAction, which explodes the active recipe
            // and reserves the components. The two certifications the old comment cited
            // predate §17 and cannot speak to it.
            //
            // The same comment also claimed parity with ManufacturingAvailabilityService's
            // "SUM(GREATEST(on_hand_qty - reserved_qty, 0.0))". That expression does not
            // exist in that service either — it uses SUM(on_hand_qty - reserved_qty) with
            // no floor. Both claims are removed rather than restated.
            //
            // Scope: raw materials inside this calculator only. It does not govern
            // finished-product availability, reservation, or inventory-wide availability.
            // ── The wave's own reservation is not additional demand ────────────
            //
            // When an order is confirmed, ReconcileOrderMaterialReservationsAction
            // (ADR-027 §17) derives its raw-material requirement from the active recipe
            // and raises inventory_items.reserved_qty. When that same order later enters
            // this wave, the requirement is derived a SECOND time as `required` above —
            // and the first derivation was still sitting inside `reserved`, so it was
            // subtracted again. Required and its own reservation are the same commitment
            // at two stages, not two commitments.
            //
            // Worked example, the reported case (line qty 2, component 1, waste 2%,
            // yield 1, on_hand 0):
            //     required = 2 x 1 x 1.02 = 2.04
            //     reserved = 2 x 1 / 1    = 2.00   <- this order's own commitment
            //     before:  available = 0 - 2.00 = -2.00 -> missing = 4.04
            //     after:   own = 2.00, available = 0 - 0 = 0 -> missing = 2.04
            //
            // THE THREE CLAMPS ARE LOAD-BEARING, not defensive decoration. Each closes a
            // way this subtraction could invent stock that is not there — and an
            // under-reported shortage is far more damaging than an over-reported one,
            // because missing_qty = 0 deletes the row from wave_missing_materials
            // entirely (DemandReadRepository::deleteResolvedMissingMaterials).
            //
            //   >= 0        a warehouse-blind release in ReconcileOrderMaterialReservations
            //               can leave a NEGATIVE ledger balance at this warehouse; without
            //               the floor that negative would be ADDED to the shortage.
            //   <= required the ledger balance is historical while `required` is re-derived
            //               here and now, from the current recipe, the current lines and —
            //               on the incremental path — only a SLICE of the wave's products.
            //               The two sides also use different arithmetic (reservation
            //               divides by yield_quantity and ignores waste; this calculator
            //               applies waste and ignores yield), so the ledger figure can
            //               legitimately exceed what `required` accounts for. Capping here
            //               means only the part Required actually re-derives is ever
            //               given back.
            //   <= reserved keeps `reserved - own` non-negative, so `available` can never
            //               exceed on_hand. ShipStockAction drains reserved_qty while
            //               recording `sales_issue` rather than a paired release, so the
            //               ledger can outlive the reservation it describes; this cap is
            //               what makes that harmless.
            //
            // Net effect: missing >= max(0, required - on_hand) always. The figure can
            // still be conservative, never optimistic.
            $own = max(0.0, min(
                $ownReserved[$agg['material_id']] ?? 0.0,
                $required,
                $reserved,
            ));

            // A postponed member's reservation is parked for a later cycle, not competing
            // demand for the work in front of the operators now (ADR-027 §18.3). Net it out
            // of the reserved pool too — but NOT clamped by `$required`: Required already
            // excludes the postponed order (ProductDemandCalculator filters
            // `postponed_at IS NULL`), so the `$own` clamp above would discard it. It is
            // capped instead by what remains of the reserved pool after the active-order
            // netting, so (reserved - own - postponed) can never fall below zero.
            $postponed = max(0.0, min(
                $postponedReserved[$agg['material_id']] ?? 0.0,
                $reserved - $own,
            ));

            // Floored at zero (ADR-027 §18.2): a competing over-commitment caps availability
            // at nothing rather than a negative that would inflate Missing past Required.
            // Preparation never shows negative Available.
            $available = max(0.0, $onHand - ($reserved - $own - $postponed));
            $missing = max(0.0, $required - $available);
            $coveragePct = $required > 0.0
                ? min(100.0, round(($available / $required) * 100.0, 2))
                : 100.0;

            // MISSING IS THE REAL PHYSICAL SHORTAGE — ALWAYS (owner decision, ADR-027 §18.4 v1.6).
            //
            // This previously overrode `$missing = 0.0; $coveragePct = 100.0;` whenever the
            // material carried `allow_negative_stock`. That conflated two different questions
            // and hid a genuine shortage from Procurement: a material bought but not yet
            // received still has to be received, and the buyer must see the quantity.
            //
            // `allow_negative_stock` no longer touches these figures at all. It answers a
            // SEPARATE question — may preparation proceed despite the shortage? — which is
            // decided per product by ProductReadinessCalculator from the flag carried below.
            //
            //   Required 7 · Available 0 · allow_negative = true
            //     → missing_qty 7 (Procurement sees the real shortage)
            //     → material_status READY (Preparation is allowed to proceed)

            $rows[] = [
                'id' => Str::uuid()->toString(),
                'company_id' => $wave->company_id,
                'warehouse_id' => $wave->warehouse_id,
                'preparation_wave_id' => $wave->id,
                'material_id' => $agg['material_id'],
                'material_name' => $agg['material_name'],
                'material_sku' => $agg['material_sku'] ?? null,
                'required_qty' => $required,
                'available_qty' => round($available, 4),
                'reserved_qty' => round($reserved, 4),
                'expected_today' => 0.0,
                'in_transit_qty' => 0.0,
                'missing_qty' => round($missing, 4),
                'coverage_pct' => $coveragePct,
                // Persisted so readiness (and the operator) can tell a BLOCKING shortage from
                // one that is drawable on open credit. Previously computed and thrown away.
                'allow_negative' => (bool) $agg['allow_negative'],
                'data_hash' => md5($wave->id.$agg['material_id'].$required.$available),
                'last_calculated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }

    /**
     * Net raw-material commitment held by THIS wave's own active orders, per material.
     *
     * Identifying "this wave's own" needs three things to line up, and all three come
     * from canonical sources rather than anything invented here:
     *
     *  - WHICH ORDERS — `preparation_wave_orders` filtered `postponed_at IS NULL`, the
     *    same active-membership predicate ProductDemandCalculator uses to build Required.
     *    A postponed order is excluded on both sides symmetrically: it leaves Required AND
     *    stops being netted, so the commitment it still holds correctly reverts to
     *    competing demand (postponement deliberately releases nothing).
     *  - WHICH RESERVATIONS — `reference_type = sales_order_material`, the constant owned
     *    by ReconcileOrderMaterialReservationsAction. It is the ONLY writer of that value
     *    in the codebase, so it cleanly separates order-driven MATERIAL commitments from
     *    finished-goods `sales_order` ones. FG reservations are deliberately NOT netted:
     *    a directly-sold raw material belongs to wave_product_demand, not to this row's
     *    Required, so it remains genuine competing demand for the material.
     *  - HOW MUCH — reservations minus releases from `stock_ledger_entries`, the same
     *    netting ReconcileOrderMaterialReservationsAction::heldByThisOrder performs to
     *    decide its own deltas. Both reserve and release write their ledger row inside the
     *    same transaction as the reserved_qty mutation, so the two cannot drift apart.
     *
     * Warehouse-scoped to the wave, using the ledger's own warehouse_id: that is where the
     * reservation was actually taken, which is stricter and more correct than the order's
     * current assigned_warehouse_id (an order can be reassigned after reserving, leaving
     * the commitment stranded at the original warehouse).
     *
     * `sales_issue` is deliberately NOT part of this sum even though it also lowers
     * reserved_qty: it is written against the `sales_order` reference, never against
     * `sales_order_material`, so it can never describe a row this query returns. The
     * caller's `min(..., $reserved)` clamp is what protects against a shipment having
     * drained the underlying reservation.
     *
     * @param  list<string>  $materialIds
     * @return array<string, float> material_id => net reserved by this wave's own orders
     */
    private function ownWaveMaterialReservations(PreparationWave $wave, array $materialIds): array
    {
        if ($materialIds === []) {
            return [];
        }

        return DB::table('stock_ledger_entries as sle')
            ->join('preparation_wave_orders as pwo', function ($join) use ($wave) {
                $join->on('pwo.order_id', '=', 'sle.reference_id')
                    ->where('pwo.preparation_wave_id', '=', $wave->id)
                    ->whereNull('pwo.postponed_at');
            })
            ->where('sle.reference_type', ReconcileOrderMaterialReservationsAction::REFERENCE_TYPE)
            ->where('sle.warehouse_id', $wave->warehouse_id)
            ->whereIn('sle.product_id', $materialIds)
            ->whereIn('sle.movement_type', [
                LedgerMovementType::Reservation->value,
                LedgerMovementType::ReservationRelease->value,
            ])
            ->groupBy('sle.product_id')
            ->selectRaw(sprintf(
                "sle.product_id, SUM(CASE WHEN sle.movement_type = '%s' THEN sle.quantity ELSE -sle.quantity END) AS net",
                LedgerMovementType::Reservation->value,
            ))
            ->pluck('net', 'product_id')
            ->map(static fn ($v): float => round((float) $v, 4))
            ->all();
    }

    /**
     * Raw-material commitment held by this wave's POSTPONED members, per material (§18.3).
     *
     * Identical in shape to {@see ownWaveMaterialReservations()} but selects the opposite
     * membership slice: rows whose `postponed_at` is set yet whose `released_at` is still
     * NULL — orders postponed out of the CURRENT cycle's work but still bound to the wave
     * (they carry over when it ends, per the two-predicate design in PreparationWaveOrder).
     * Their `sales_order_material` reservations remain live in inventory because
     * postponement releases nothing, so without netting them here they would read as
     * competing demand against the orders still being prepared and inflate Missing — the
     * exact opposite of what a deferral is meant to do.
     *
     * `released_at IS NULL` is the boundary: the instant the membership is released at wave
     * close, the reservation genuinely becomes competing demand for whichever wave collects
     * the order next, and this netting correctly stops.
     *
     * @param  list<string>  $materialIds
     * @return array<string, float> material_id => net reserved by this wave's postponed members
     */
    private function postponedMemberMaterialReservations(PreparationWave $wave, array $materialIds): array
    {
        if ($materialIds === []) {
            return [];
        }

        return DB::table('stock_ledger_entries as sle')
            ->join('preparation_wave_orders as pwo', function ($join) use ($wave) {
                $join->on('pwo.order_id', '=', 'sle.reference_id')
                    ->where('pwo.preparation_wave_id', '=', $wave->id)
                    ->whereNotNull('pwo.postponed_at')
                    ->whereNull('pwo.released_at');
            })
            ->where('sle.reference_type', ReconcileOrderMaterialReservationsAction::REFERENCE_TYPE)
            ->where('sle.warehouse_id', $wave->warehouse_id)
            ->whereIn('sle.product_id', $materialIds)
            ->whereIn('sle.movement_type', [
                LedgerMovementType::Reservation->value,
                LedgerMovementType::ReservationRelease->value,
            ])
            ->groupBy('sle.product_id')
            ->selectRaw(sprintf(
                "sle.product_id, SUM(CASE WHEN sle.movement_type = '%s' THEN sle.quantity ELSE -sle.quantity END) AS net",
                LedgerMovementType::Reservation->value,
            ))
            ->pluck('net', 'product_id')
            ->map(static fn ($v): float => round((float) $v, 4))
            ->all();
    }
}
