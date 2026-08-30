<?php

declare(strict_types=1);

namespace Modules\Purchasing\Suppliers\Application\Queries;

use App\Core\Company\TenantOwnershipResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Purchasing\GoodsReceipts\Domain\Enums\GoodsReceiptStatus;
use Modules\Purchasing\PurchaseMaterials\Domain\Enums\PurchaseMaterialStatus;
use Modules\Purchasing\PurchaseOrders\Domain\Enums\PurchaseOrderStatus;
use Modules\Purchasing\Suppliers\Domain\Exceptions\SupplierNotFoundException;
use Modules\Purchasing\Suppliers\Domain\Models\Supplier;

/**
 * Supplier Product Demand — product-level purchasing rate for one supplier.
 *
 * Answers "how much of this product do we normally need to purchase from this
 * supplier again?". This is deliberately NOT the chronological Purchase History
 * list (that is {@see GetSupplierPriceHistoryQuery} and the Purchase Orders /
 * Goods Receipts tabs), and it is NOT a purchase-order frequency metric. The
 * primary measure here is product QUANTITY per rolling period.
 *
 * ── Canonical quantity (TASK Part 12.1 / 12.2) ───────────────────────────────
 *
 * The quantity is RECEIVED quantity, never ordered quantity. The procurement
 * contract already fixes this and every existing aggregate agrees, so nothing
 * new is defined here:
 *
 *   - {@see \Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceiptLine::effectiveReceivedQty()}
 *     — `net_received_quantity ?? received_quantity`; `net_received_quantity` is
 *     documented as "authoritative quantity used for stock movements" and is the
 *     value {@see \Modules\Purchasing\GoodsReceipts\Application\Actions\PostGoodsReceiptAction}
 *     writes to stock, to `purchase_order_lines.received_qty` and to the receipt layers.
 *   - {@see GetSupplierAnalyticsQuery} (fill rate), {@see GetProcurementHealthQuery}
 *     (fill rate) and {@see GetSupplierPriceHistoryQuery} (quantity) all read
 *     `COALESCE(net_received_quantity, received_quantity)`.
 *
 * Ordered quantity is therefore never treated as received: a PO line ordering
 * 100 that received 60 contributes 60. Over-receipts contribute their full
 * received amount, because that is what actually entered stock.
 *
 * ── Valid transactions only ──────────────────────────────────────────────────
 *
 *   - goods_receipts.status = 'posted'   (draft receipts have not happened yet)
 *   - goods_receipts.deleted_at IS NULL
 *   - purchase_orders.deleted_at IS NULL
 *   - purchase_orders.status <> 'cancelled'
 *
 * Supplier returns are NOT netted off. No existing procurement aggregate nets
 * them, and for demand estimation a returned delivery still had to be bought
 * again — netting it would understate what Procurement must re-order.
 *
 * ── Periods (Part 12.5) ──────────────────────────────────────────────────────
 *
 * Rolling, calendar-independent windows anchored on `goods_receipts.receipt_date`,
 * matching {@see \Modules\Core\DemandAnalysis\Application\Services\DemandAnalysisService}:
 * `receipt_date >= today - N days` for N in {7, 30, 90}.
 *
 * Averages use the same rate-based formula as DemandAnalysisService
 * (daily rate x 7 / x 30) over a fixed 90-day basis:
 *
 *   daily rate      = quantity_90d / 90
 *   average weekly  = quantity_90d / (90 / 7)  = quantity_90d / 12.857142857…
 *   average monthly = quantity_90d / (90 / 30) = quantity_90d / 3
 *
 * The denominator is the WINDOW LENGTH, never the number of purchase
 * transactions — dividing 350 KG across 2 deliveries by 2 would describe
 * delivery size, not purchasing rate. Both denominators are returned in the
 * payload (`average_weekly_denominator_weeks`, `average_monthly_denominator_months`)
 * so no consumer has to infer them.
 *
 * ── No purchase history (Part 12.6) ──────────────────────────────────────────
 *
 * A supplier/product pair that exists (an open PO line, or a supplier selected
 * on a purchase material line) but has never been received reports
 * `has_purchase_history = false` and NULL for every metric. It never reports 0,
 * because no quantity was ever calculated. A pair that has been received but
 * had nothing in a given window reports a real 0 for that window.
 *
 * ── Tenant isolation (Part 12.7) ─────────────────────────────────────────────
 *
 * The supplier is resolved through the `Supplier` tenant global scope, so a
 * foreign supplier is already a 404. The aggregates additionally filter
 * `purchase_orders.company_id` / `purchase_materials.company_id` for restricted
 * actors, so a purchase owned by another company can never contribute. Following
 * the D-8 precedent, a NULL company on the row closes it out rather than passing.
 *
 * ── Performance (Part 12.8) ──────────────────────────────────────────────────
 *
 * One grouped statement for the whole supplier. There is no per-product query.
 */
final class GetSupplierProductDemandQuery
{
    /** Rolling windows, in days. */
    private const WINDOW_7 = 7;

    private const WINDOW_30 = 30;

    private const WINDOW_90 = 90;

    /** The window the weekly/monthly averages are derived from. */
    private const AVERAGE_BASIS_DAYS = self::WINDOW_90;

    private const DAYS_PER_WEEK = 7;

    private const DAYS_PER_MONTH = 30;

    /** Relative price move that counts as a trend, per DemandAnalysisService::priceTrend(). */
    private const PRICE_TREND_THRESHOLD = 0.05;

    public function __construct(
        private readonly TenantOwnershipResolver $tenant,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function execute(string $supplierId): Collection
    {
        if (Supplier::query()->find($supplierId) === null) {
            throw new SupplierNotFoundException($supplierId);
        }

        $today = Carbon::today();

        $bindings = [
            'supplier_received' => $supplierId,
            'supplier_ordered' => $supplierId,
            'supplier_requested' => $supplierId,
            'posted' => GoodsReceiptStatus::Posted->value,
            'po_cancelled_received' => PurchaseOrderStatus::Cancelled->value,
            'po_cancelled_ordered' => PurchaseOrderStatus::Cancelled->value,
            'pm_cancelled' => PurchaseMaterialStatus::Cancelled->value,
            'pm_rejected' => PurchaseMaterialStatus::Rejected->value,
            'window_7' => $today->copy()->subDays(self::WINDOW_7)->toDateString(),
            'window_30' => $today->copy()->subDays(self::WINDOW_30)->toDateString(),
            'window_90' => $today->copy()->subDays(self::WINDOW_90)->toDateString(),
        ];

        // Tenant filters are interpolated as SQL fragments rather than as
        // `(:company IS NULL OR ...)` because PostgreSQL cannot infer the type
        // of a bound NULL in that position.
        $receivedCompanyFilter = '';
        $orderedCompanyFilter = '';
        $requestedCompanyFilter = '';

        if ($this->tenant->appliesTo() && ! $this->tenant->isUnrestricted()) {
            $receivedCompanyFilter = 'AND po.company_id = :company_received';
            $orderedCompanyFilter = 'AND po.company_id = :company_ordered';
            $requestedCompanyFilter = 'AND pm.company_id = :company_requested';

            $companyId = $this->tenant->companyId();

            $bindings['company_received'] = $companyId;
            $bindings['company_ordered'] = $companyId;
            $bindings['company_requested'] = $companyId;
        }

        $rows = DB::select(<<<SQL
            WITH received AS (
                SELECT
                    grl.product_id                                                AS product_id,
                    gr.receipt_date                                               AS receipt_date,
                    grl.id                                                        AS line_id,
                    COALESCE(grl.net_received_quantity, grl.received_quantity, 0) AS qty,
                    grl.unit_price                                                AS unit_price,
                    grl.uom_symbol_snapshot                                       AS uom_symbol
                FROM goods_receipt_lines grl
                JOIN goods_receipts  gr ON gr.id = grl.goods_receipt_id
                JOIN purchase_orders po ON po.id = gr.purchase_order_id
                WHERE po.supplier_id = :supplier_received
                  AND gr.status      = :posted
                  AND gr.deleted_at IS NULL
                  AND po.deleted_at IS NULL
                  AND po.status     <> :po_cancelled_received
                  {$receivedCompanyFilter}
            ),
            ranked AS (
                SELECT
                    received.*,
                    ROW_NUMBER() OVER (
                        PARTITION BY received.product_id
                        ORDER BY received.receipt_date DESC, received.line_id DESC
                    ) AS rn
                FROM received
            ),
            aggregated AS (
                SELECT
                    product_id,
                    SUM(qty)                                                       AS total_quantity,
                    COUNT(*)                                                       AS purchase_line_count,
                    MIN(receipt_date)                                              AS first_purchase_date,
                    MAX(receipt_date)                                              AS last_purchase_date,
                    SUM(CASE WHEN receipt_date >= :window_7  THEN qty ELSE 0 END)  AS quantity_7d,
                    SUM(CASE WHEN receipt_date >= :window_30 THEN qty ELSE 0 END)  AS quantity_30d,
                    SUM(CASE WHEN receipt_date >= :window_90 THEN qty ELSE 0 END)  AS quantity_90d
                FROM received
                GROUP BY product_id
            ),
            catalog AS (
                SELECT product_id FROM aggregated
                UNION
                SELECT pol.product_id
                FROM purchase_order_lines pol
                JOIN purchase_orders po ON po.id = pol.purchase_order_id
                WHERE po.supplier_id = :supplier_ordered
                  AND po.deleted_at IS NULL
                  AND po.status     <> :po_cancelled_ordered
                  {$orderedCompanyFilter}
                UNION
                SELECT pml.product_id
                FROM purchase_material_lines pml
                JOIN purchase_materials pm ON pm.id = pml.purchase_material_id
                WHERE pml.supplier_id = :supplier_requested
                  AND pm.deleted_at IS NULL
                  AND pm.status NOT IN (:pm_cancelled, :pm_rejected)
                  {$requestedCompanyFilter}
            )
            SELECT
                c.product_id,
                p.sku                     AS product_sku,
                p.name                    AS product_name,
                u.symbol                  AS unit_symbol,
                u.name                    AS unit_name,
                a.total_quantity,
                a.purchase_line_count,
                a.first_purchase_date,
                a.last_purchase_date,
                a.quantity_7d,
                a.quantity_30d,
                a.quantity_90d,
                latest.qty                AS last_purchase_quantity,
                latest.unit_price         AS last_purchase_price,
                latest.uom_symbol         AS last_uom_symbol,
                previous.unit_price       AS previous_purchase_price
            FROM catalog c
            JOIN products p ON p.id = c.product_id AND p.deleted_at IS NULL
            LEFT JOIN units      u        ON u.id = p.unit_id
            LEFT JOIN aggregated a        ON a.product_id = c.product_id
            LEFT JOIN ranked     latest   ON latest.product_id   = c.product_id AND latest.rn = 1
            LEFT JOIN ranked     previous ON previous.product_id = c.product_id AND previous.rn = 2
            ORDER BY
                CASE WHEN a.product_id IS NULL THEN 1 ELSE 0 END ASC,
                COALESCE(a.quantity_90d, 0) DESC,
                COALESCE(a.total_quantity, 0) DESC,
                p.name ASC
        SQL, $bindings);

        return collect($rows)->map(fn (object $row): array => $this->present($row));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(object $row): array
    {
        $hasHistory = $row->last_purchase_date !== null;

        $base = [
            'product_id' => $row->product_id,
            'product_sku' => $row->product_sku,
            'product_name' => $row->product_name,
            'unit_symbol' => $row->last_uom_symbol ?? $row->unit_symbol,
            'unit_name' => $row->unit_name,
            'has_purchase_history' => $hasHistory,
            // The denominators behind the averages, so no consumer has to guess.
            'average_basis_days' => self::AVERAGE_BASIS_DAYS,
            'average_weekly_denominator_weeks' => round(self::AVERAGE_BASIS_DAYS / self::DAYS_PER_WEEK, 6),
            'average_monthly_denominator_months' => round(self::AVERAGE_BASIS_DAYS / self::DAYS_PER_MONTH, 6),
        ];

        if (! $hasHistory) {
            // Part 12.6 — never fabricate a zero for a pair that was never purchased.
            return $base + [
                'supplier_price' => null,
                'last_purchase_date' => null,
                'last_purchase_quantity' => null,
                'first_purchase_date' => null,
                'total_quantity' => null,
                'purchase_line_count' => 0,
                'quantity_7d' => null,
                'quantity_30d' => null,
                'quantity_90d' => null,
                'average_daily_quantity' => null,
                'average_weekly_quantity' => null,
                'average_monthly_quantity' => null,
                'price_trend' => null,
                'price_change_percent' => null,
            ];
        }

        $quantity90d = (float) $row->quantity_90d;
        $dailyRate = $quantity90d / self::AVERAGE_BASIS_DAYS;

        $lastPrice = $row->last_purchase_price !== null ? (float) $row->last_purchase_price : null;
        $previousPrice = $row->previous_purchase_price !== null ? (float) $row->previous_purchase_price : null;

        return $base + [
            'supplier_price' => $lastPrice !== null ? round($lastPrice, 4) : null,
            'last_purchase_date' => $row->last_purchase_date,
            'last_purchase_quantity' => round((float) $row->last_purchase_quantity, 4),
            'first_purchase_date' => $row->first_purchase_date,
            'total_quantity' => round((float) $row->total_quantity, 4),
            'purchase_line_count' => (int) $row->purchase_line_count,
            'quantity_7d' => round((float) $row->quantity_7d, 4),
            'quantity_30d' => round((float) $row->quantity_30d, 4),
            'quantity_90d' => round($quantity90d, 4),
            'average_daily_quantity' => round($dailyRate, 4),
            'average_weekly_quantity' => round($dailyRate * self::DAYS_PER_WEEK, 4),
            'average_monthly_quantity' => round($dailyRate * self::DAYS_PER_MONTH, 4),
            'price_trend' => $this->priceTrend($lastPrice, $previousPrice),
            'price_change_percent' => $this->priceChangePercent($lastPrice, $previousPrice),
        ];
    }

    /** Same +/-5% band DemandAnalysisService::priceTrend() uses. */
    private function priceTrend(?float $latest, ?float $previous): ?string
    {
        if ($latest === null || $previous === null || $previous <= 0.0) {
            return null;
        }

        $change = ($latest - $previous) / $previous;

        return match (true) {
            $change > self::PRICE_TREND_THRESHOLD => 'rising',
            $change < -self::PRICE_TREND_THRESHOLD => 'falling',
            default => 'stable',
        };
    }

    private function priceChangePercent(?float $latest, ?float $previous): ?float
    {
        if ($latest === null || $previous === null || $previous <= 0.0) {
            return null;
        }

        return round(($latest - $previous) / $previous * 100, 2);
    }
}
