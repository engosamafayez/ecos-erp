<?php

declare(strict_types=1);

namespace Modules\Reporting\Domain\Catalog;

use Modules\Reporting\Domain\Enums\FreshnessClass;
use Modules\Reporting\Domain\Enums\MetricClassification;
use Modules\Reporting\Domain\Enums\ReportCategory;

/**
 * The 45 ratified V1 metrics (`docs/architecture/ENTERPRISE-REPORTING-PLATFORM.md` §17
 * Metric Dictionary, ADR-045 — Status: Ratified). Transcribed verbatim from that document;
 * this class defines no metric not present there (task requirement: "Do not invent report
 * definitions not present in the approved architecture").
 *
 * A metric is a single formula over a single source — not one entry per dimension cut
 * (§17 Design principle). `source_modules` always names the exact canonical-owner
 * namespace(s) from §5's Source Authority Matrix, so a future developer cannot query the
 * wrong bounded context. `classification` is never left ambiguous (Core Principle #4):
 * every Finance-derived (GL-posted) metric is `MetricClassification::Accounting`; every
 * other metric is `MetricClassification::Operational`, even when it is financial in
 * *nature* (e.g. Driver cash/expenses — Distribution-owned, not Finance-owned; ADR-045 §5).
 *
 * No DB dependency — same convention as `Modules\IAM\Domain\Catalog\RoleTemplateCatalog`.
 */
final class MetricDictionary
{
    /**
     * @return list<array{
     *     id: string,
     *     name: string,
     *     category: string,
     *     definition: string,
     *     source_modules: list<string>,
     *     classification: string,
     *     freshness: string,
     *     freshness_note: string|null,
     *     date_basis: string|null,
     *     known_dependency: string|null,
     * }>
     */
    public static function all(): array
    {
        return [
            ...self::sales(),
            ...self::customers(),
            ...self::products(),
            ...self::inventory(),
            ...self::procurement(),
            ...self::preparation(),
            ...self::distribution(),
            ...self::drivers(),
            ...self::financial(),
        ];
    }

    private static function sales(): array
    {
        $category = ReportCategory::Sales->value;

        return [
            self::make('MET-SALES-01', 'Gross Sales', $category,
                'Sum of order-line value before discounts, tax, and shipping, for orders in a commercially-active (non-cancelled) state.',
                ['Modules\\Commerce\\Orders'], MetricClassification::Operational, FreshnessClass::Live,
                null, 'orders.order_date',
                'Not equal to MET-FIN-01 (Recognized Revenue) — see ADR-045 Decision 2b.'),

            self::make('MET-SALES-02', 'Net Sales', $category,
                'Gross Sales less order-level discounts and coupon/fee adjustments.',
                ['Modules\\Commerce\\Orders'], MetricClassification::Operational, FreshnessClass::Live,
                null, 'orders.order_date',
                'No line-level discount exists — only order-level; a "discount by product" breakdown is not derivable today (UPSTREAM DATA CONTRACT REQUIRED if ever requested).'),

            self::make('MET-SALES-03', 'Delivered Sales', $category,
                "Net Sales restricted to orders whose status is 'Delivered'.",
                ['Modules\\Commerce\\Orders'], MetricClassification::Operational, FreshnessClass::Live,
                null, 'orders.order_date',
                'No reliable order-level "delivered_at" column exists (§12). For POS-originated deliveries, cross-check against pos_sales directly — the POS→Order mirror can silently fail (best-effort listener, never throws).'),

            self::make('MET-SALES-04', 'Average Order Value (AOV)', $category,
                'Net Sales divided by order count, over the same filter set.',
                ['Modules\\Commerce\\Orders'], MetricClassification::Operational, FreshnessClass::Live,
                null, 'orders.order_date', null),

            self::make('MET-SALES-05', 'Units Sold', $category,
                'Total product quantity across order lines, excluding cancelled orders.',
                ['Modules\\Commerce\\Orders'], MetricClassification::Operational, FreshnessClass::Live,
                null, null,
                'order_lines.loaded_qty/returned_qty/cancelled_qty/prepared_qty/packed_qty/available_qty are permanently-zero, never-written projection columns — never source a metric from them; only quantity, reserved_qty, and delivered_qty are real.'),

            self::make('MET-SALES-06', 'Cancelled Orders Count', $category,
                "Count of orders with status 'Cancelled' in the period.",
                ['Modules\\Commerce\\Orders'], MetricClassification::Operational, FreshnessClass::Live,
                null, 'orders.order_date',
                'Cancellation reason is not persisted on orders; only recoverable from the order_events audit trail (UPSTREAM DATA CONTRACT REQUIRED if a reason breakdown is needed).'),

            self::make('MET-SALES-07', 'Scheduled Orders Count', $category,
                "Count of orders currently in 'Scheduled' status.",
                ['Modules\\Commerce\\Orders'], MetricClassification::Operational, FreshnessClass::Live,
                null, 'orders.requested_delivery_date', null),

            self::make('MET-SALES-08', 'Order Count by Status', $category,
                "Count of orders grouped by canonical OrderStatus (ADR-042's 11 values).",
                ['Modules\\Commerce\\Orders'], MetricClassification::Operational, FreshnessClass::Live,
                null, null,
                'Never map a legacy pre-V3 status word (pending, processing, preparing, completed, review, rescheduled) into a live filter — none are accepted at runtime (ADR-042 §8).'),

            self::make('MET-SALES-09', 'Payment Method Mix (incl. COD/Instapay)', $category,
                'Distribution of orders by resolved payment method (COALESCE of manual whitelist and ecommerce free text).',
                ['Modules\\Commerce\\Orders'], MetricClassification::Operational, FreshnessClass::Live,
                null, null,
                'Neither orders.payment_method nor payment_method_manual is a backed PHP enum; values are request-validated, not DB-constrained — normalize casing/nulls defensively in the query, not assumed clean.'),

            self::make('MET-SALES-10', 'Requested-Delivery On-Time Rate', $category,
                'Share of delivered orders whose stop-level completion fell on or before orders.requested_delivery_date.',
                ['Modules\\Commerce\\Orders', 'Modules\\Logistics\\Distribution'], MetricClassification::Operational, FreshnessClass::Live,
                'LIVE (recent) / EVENTUAL (trend)', 'orders.requested_delivery_date / distribution_delivery_stops.completed_at',
                'Cross-module join — must go through the order↔stop link (distribution_window_orders/distribution_trip_orders), not a direct FK.'),
        ];
    }

    private static function customers(): array
    {
        $category = ReportCategory::Customers->value;

        return [
            self::make('MET-CUST-01', 'Total Customers', $category,
                'Count of customer rows for the company.',
                ['Modules\\Sales\\Customers'], MetricClassification::Operational, FreshnessClass::Live,
                null, null, null),

            self::make('MET-CUST-02', 'Active Customers', $category,
                'Customers with at least one order in the trailing period (period length is a report parameter, not fixed here).',
                ['Modules\\Sales\\Customers', 'Modules\\Commerce\\Orders'], MetricClassification::Operational, FreshnessClass::Live,
                null, null, null),

            self::make('MET-CUST-03', 'New Customers', $category,
                'Customers whose first order in the system falls inside the period.',
                ['Modules\\Sales\\Customers'], MetricClassification::Operational, FreshnessClass::Live,
                null, null,
                'Source: CustomerBrand.first_order_at (already tracked) or MIN(orders.order_date) per customer.'),

            self::make('MET-CUST-04', 'Repeat Customer Rate', $category,
                'Share of active customers (MET-CUST-02) with more than one order in the period.',
                ['Modules\\Sales\\Customers', 'Modules\\Commerce\\Orders'], MetricClassification::Operational, FreshnessClass::Eventual,
                null, null, null),

            self::make('MET-CUST-05', 'Orders per Customer', $category,
                'Order count divided by distinct customer count, over the same filter set.',
                ['Modules\\Sales\\Customers', 'Modules\\Commerce\\Orders'], MetricClassification::Operational, FreshnessClass::Live,
                null, null, null),

            self::make('MET-CUST-06', 'Customer Lifetime Value (Operational)', $category,
                'Cumulative operational sales value attributed to a customer, all-time.',
                ['Modules\\Sales\\Customers'], MetricClassification::Operational, FreshnessClass::Eventual,
                null, null,
                'Source: CustomerBrand.lifetime_value (already maintained by Sales\\Customers) — reuse, do not recompute. This is an operational figure maintained per Brand pivot, not an accounting-adjusted value; label accordingly.'),
        ];
    }

    private static function products(): array
    {
        $category = ReportCategory::Products->value;

        return [
            self::make('MET-PROD-01', 'Average Selling Price (ASP)', $category,
                'Net Sales for a product divided by units sold of that product.',
                ['Modules\\Inventory\\Products', 'Modules\\Commerce\\Orders'], MetricClassification::Operational, FreshnessClass::Live,
                null, null, null),

            self::make('MET-PROD-02', 'COGS — Operational (Commerce-Sourced)', $category,
                'Cost of goods sold as computed by Commerce at the point of shipment or order-confirm snapshot.',
                ['Modules\\Commerce\\Orders'], MetricClassification::Operational, FreshnessClass::Live,
                'LIVE (ship-time) / immutable (snapshot)', null,
                'Source is orders.actual_cogs_amount (mutable, ShipOrderInventoryAction) OR order_line_snapshots cost columns (immutable, Confirm) — two independently-computed, non-reconciled figures; a report must pick and label one, never blend them. Not the same number as MET-PROD-03 — see ADR-045 Decision 2b.'),

            self::make('MET-PROD-03', 'COGS — Accounting (GL-Posted)', $category,
                "Cost of goods sold as posted to the General Ledger's cost-of-sales accounts (5100-5130).",
                ['Modules\\Finance'], MetricClassification::Accounting, FreshnessClass::AccountingPosted,
                null, null,
                'READY IN FINANCE CANDIDATE — CANONICAL RECONCILIATION REQUIRED (per the ratified architecture document at time of writing). Reporting integrates this metric only once canonical develop confirms the posting rule is live, never against an unintegrated candidate lane alone.'),

            self::make('MET-PROD-04', 'Gross Profit — Operational', $category,
                'MET-SALES-01 (or MET-SALES-03) minus MET-PROD-02, matched at the same grain.',
                ['Modules\\Commerce\\Orders'], MetricClassification::Operational, FreshnessClass::Live,
                null, null, null),

            self::make('MET-PROD-05', 'Gross Profit — Accounting', $category,
                'MET-FIN-01 minus MET-PROD-03.',
                ['Modules\\Finance'], MetricClassification::Accounting, FreshnessClass::AccountingPosted,
                null, null,
                'Depends on MET-FIN-01 and MET-PROD-03, both READY IN FINANCE CANDIDATE — CANONICAL RECONCILIATION REQUIRED. Do not compute this metric against unintegrated-candidate figures alone.'),

            self::make('MET-PROD-06', 'Gross Margin — Operational', $category,
                'MET-PROD-04 divided by MET-SALES-01 (or MET-SALES-03), as a percentage.',
                ['Modules\\Commerce\\Orders'], MetricClassification::Operational, FreshnessClass::Live,
                null, null, null),

            self::make('MET-PROD-07', 'Gross Margin — Accounting', $category,
                'MET-PROD-05 divided by MET-FIN-01, as a percentage.',
                ['Modules\\Finance'], MetricClassification::Accounting, FreshnessClass::AccountingPosted,
                null, null,
                'FINANCE DEPENDENCY — a separate, still-fully-open gap from MET-PROD-03/05: the per-posting profit_center_id/Brand dimension is never populated. Finance\'s own ProfitabilityService returns available:false (an honest null) for the product/channel cut today; Reporting must mirror that honesty, never fabricate a per-product accounting margin.'),
        ];
    }

    private static function inventory(): array
    {
        $category = ReportCategory::Inventory->value;

        return [
            self::make('MET-INV-01', 'Available Stock', $category,
                'On-hand quantity minus reserved quantity, per product/warehouse.',
                ['Modules\\Inventory\\InventoryItems'], MetricClassification::Operational, FreshnessClass::Live,
                null, null,
                'Deliberately signed/unclamped — a negative value is meaningful, not an error, per the model\'s own documented design.'),

            self::make('MET-INV-02', 'Reserved Stock', $category,
                'Quantity reserved against active orders (and, since ADR-027 §17, active BOM/recipe raw-material demand).',
                ['Modules\\Inventory\\InventoryItems'], MetricClassification::Operational, FreshnessClass::Live,
                null, null,
                'Reservation is order-driven end-to-end (ADR-027) — Preparation never reserves or releases; a reservation is only consumed at physical shipment.'),

            self::make('MET-INV-03', 'Inventory Value', $category,
                'Valuation of on-hand stock using the canonical costing strategy.',
                ['Modules\\CostManagement'], MetricClassification::Operational, FreshnessClass::Live,
                null, null,
                'Inventory asset value; the GL asset balance is a separate, Finance-owned figure. Do not recompute FIFO/valuation logic inside Reporting — call EnterpriseCostEngine (DO-NOT-REIMPLEMENT).'),

            self::make('MET-INV-04', 'Stock Shortage (Material)', $category,
                'Missing raw-material quantity against active wave/order demand.',
                ['Modules\\Operations\\DemandAnalysis'], MetricClassification::Operational, FreshnessClass::Live,
                null, null,
                'missing_qty is always the real physical shortage — never zeroed by an allow_negative_stock override (ADR-027 §18.6).'),

            self::make('MET-INV-05', 'Zero-Stock Product Count', $category,
                'Count of active products with on_hand_qty <= 0 (or no InventoryItem row) for a given warehouse.',
                ['Modules\\Inventory\\InventoryItems'], MetricClassification::Operational, FreshnessClass::Live,
                null, null, null),
        ];
    }

    private static function procurement(): array
    {
        $category = ReportCategory::Procurement->value;

        return [
            self::make('MET-PROC-01', 'Purchase Volume', $category,
                'Total quantity/value received across Purchase Orders in the period.',
                ['Modules\\Purchasing'], MetricClassification::Operational, FreshnessClass::Live,
                null, null, null),

            self::make('MET-PROC-02', 'Supplier Spend', $category,
                'Total invoiced or received value attributed to a supplier in the period.',
                ['Modules\\Purchasing'], MetricClassification::Operational, FreshnessClass::Live,
                null, null,
                'Do not use goods_receipts.paid_amount for anything payment-related — legacy, diverges from the AP ledger (Purchasing\'s own repository comment names this exact divergence). For a paid-basis figure use Finance\'s SupplierLedgerService.'),

            self::make('MET-PROC-03', 'Supplier Procurement Health Score', $category,
                'Weighted 0-100 composite of delivery performance, fill rate, price stability, activity recency, financial standing, inventory impact.',
                ['Modules\\Purchasing'], MetricClassification::Operational, FreshnessClass::Eventual,
                null, null,
                'Reuse GetProcurementHealthQuery verbatim — do not re-derive the weighting (DO-NOT-REIMPLEMENT).'),

            self::make('MET-PROC-04', 'Supplier On-Time Delivery Rate', $category,
                'Share of goods receipts landing on or before purchase_orders.expected_date.',
                ['Modules\\Purchasing'], MetricClassification::Operational, FreshnessClass::Eventual,
                null, null,
                'Distinct metric from MET-DIST-01 (Delivery Rate) — do not conflate supplier inbound performance with outbound customer delivery performance.'),
        ];
    }

    private static function preparation(): array
    {
        $category = ReportCategory::Preparation->value;

        return [
            self::make('MET-PREP-01', 'Preparation Completion', $category,
                "Quantity-weighted completion of a wave's demand: SUM(prepared_qty) / SUM(required_qty) x 100.",
                ['Modules\\Operations\\DemandAnalysis'], MetricClassification::Operational, FreshnessClass::Live,
                null, null,
                'This is a live-computed percentage, not the same signal as WaveStatus::Completed — the status is a one-shot operator action independent of reaching 100% (DO-NOT-REIMPLEMENT: do not invent a second completion formula).'),

            self::make('MET-PREP-02', 'Wave Shortage Rate', $category,
                'Share of required material quantity currently missing across an active wave.',
                ['Modules\\Operations\\DemandAnalysis'], MetricClassification::Operational, FreshnessClass::Eventual,
                null, null,
                'Source: PreparationAnalyticsController (shortage_rate_pct, already computed; 5-minute controller cache precedent).'),

            self::make('MET-PREP-03', 'Postponed Orders Count', $category,
                'Count of orders currently postponed out of active wave membership.',
                ['Modules\\Operations\\Preparation'], MetricClassification::Operational, FreshnessClass::Live,
                null, null,
                'Source: preparation_wave_orders.postponed_at IS NOT NULL. "Returned order" is not a Preparation-module concept at all — do not source a return metric from Preparation; it lives in Logistics (TripReturn/DeliveryReturn).'),
        ];
    }

    private static function distribution(): array
    {
        $category = ReportCategory::Distribution->value;

        return [
            self::make('MET-DIST-01', 'Delivery Rate', $category,
                "Share of assigned delivery stops completed as 'Delivered' (vs. Partial/Failed/Returned).",
                ['Modules\\Logistics\\Distribution'], MetricClassification::Operational, FreshnessClass::Live,
                null, null,
                'Source from Distribution\'s DeliveryStop, never from Modules\\Logistics\\Delivery (a parallel, uncalled stack with no driver-runtime caller — UPSTREAM DATA QUALITY DEPENDENCY if ever queried by mistake).'),

            self::make('MET-DIST-02', 'Group Capacity Utilization', $category,
                'Live order count in a Distribution Group divided by its enforced order-count capacity.',
                ['Modules\\Logistics\\Distribution'], MetricClassification::Operational, FreshnessClass::Live,
                null, null,
                'capacity_stops/capacity_weight_kg/capacity_volume_m3 columns exist but are NOT enforced by any guard — do not report utilization against them as if they were live constraints. Distinct from Vehicle/Trip capacity (Trip.capacity) — never conflate the two.'),

            self::make('MET-DIST-03', 'Delivery Duration', $category,
                'Elapsed time from stop attempt to stop completion (completed_at - attempted_at).',
                ['Modules\\Logistics\\Distribution'], MetricClassification::Operational, FreshnessClass::Eventual,
                null, null, null),
        ];
    }

    private static function drivers(): array
    {
        $category = ReportCategory::Drivers->value;

        return [
            self::make('MET-DRV-01', 'Orders Delivered (Driver)', $category,
                "Count of stops a driver completed as 'Delivered' in a trip/day/period.",
                ['Modules\\Logistics\\Distribution'], MetricClassification::Operational, FreshnessClass::Live,
                null, null,
                'Already computed in DriverDaySettlementReadService.kpis() (total_delivered) — reuse verbatim.'),

            self::make('MET-DRV-02', 'Cash Collected (Driver, Raw)', $category,
                'Total payment collections recorded by a driver, regardless of verification state.',
                ['Modules\\Logistics\\Distribution'], MetricClassification::Operational, FreshnessClass::Live,
                null, null,
                'Source: distribution_payment_collections. "Raw" means recorded, not reconciled — do not present this as settled cash without also showing MET-DRV-04.'),

            self::make('MET-DRV-03', 'Driver Approved Expenses', $category,
                'Sum of driver-submitted expense movements that have passed the Approved/Settled gate.',
                ['Modules\\Logistics\\Distribution'], MetricClassification::Operational, FreshnessClass::Live,
                null, null,
                'Financial in nature, but Distribution-owned, never Finance (ADR-045 §5). The gate is an application-layer filter (DriverTripMovementStatus::countsTowardTotals()), not a SQL predicate — any new query must reapply it in code, not assume status=\'approved\' is sufficient.'),

            self::make('MET-DRV-04', 'Driver Net Cash', $category,
                'Cash collected less approved expenses, per the already-built Day Settlement KPI.',
                ['Modules\\Logistics\\Distribution'], MetricClassification::Operational, FreshnessClass::Live,
                null, null,
                'Source: DriverDaySettlementReadService.kpis() (net_cash) — reuse verbatim. Hard rule: raw waste/damage is never folded into this figure, and no monetary "shortage liability" exists anywhere in the codebase today — never fabricate one in a report.'),
        ];
    }

    private static function financial(): array
    {
        $category = ReportCategory::Financial->value;

        return [
            self::make('MET-FIN-01', 'Recognized Revenue', $category,
                'Revenue posted to the General Ledger.',
                ['Modules\\Finance'], MetricClassification::Accounting, FreshnessClass::AccountingPosted,
                null, null,
                "READY IN FINANCE CANDIDATE — CANONICAL RECONCILIATION REQUIRED (per the ratified architecture document at time of writing). Never equate this metric with MET-SALES-01/02/03 in any state — Operational Sales and Recognized Revenue remain two separate metrics even after full accounting integration lands (ADR-045's locked hard rule)."),

            self::make('MET-FIN-02', 'Outstanding AR', $category,
                'Amount owed by a customer, net of allocated receipts.',
                ['Modules\\Finance'], MetricClassification::Accounting, FreshnessClass::AccountingPosted,
                null, null,
                'Three formula variants exist in Finance itself (CustomerInvoice::outstanding(), CustomerLedgerService::balance(), ArAgingService — the last explicitly excludes credit notes) and can diverge; Reporting must call one named service consistently per report and disclose which.'),

            self::make('MET-FIN-03', 'Outstanding AP', $category,
                'Amount owed to a supplier, net of allocated payments.',
                ['Modules\\Finance'], MetricClassification::Accounting, FreshnessClass::AccountingPosted,
                null, null,
                'Never source this from goods_receipts.paid_amount/payment_status (Purchasing\'s own legacy scalar, explicitly superseded) — SupplierLedgerService states its own SSOT claim verbatim ("never from the hand-entered goods-receipt fields").'),
        ];
    }

    /**
     * @param  list<string>  $sourceModules
     * @return array{id: string, name: string, category: string, definition: string, source_modules: list<string>, classification: string, freshness: string, freshness_note: string|null, date_basis: string|null, known_dependency: string|null}
     */
    private static function make(
        string $id,
        string $name,
        string $category,
        string $definition,
        array $sourceModules,
        MetricClassification $classification,
        FreshnessClass $freshness,
        ?string $freshnessNote,
        ?string $dateBasis,
        ?string $knownDependency,
    ): array {
        return [
            'id' => $id,
            'name' => $name,
            'category' => $category,
            'definition' => $definition,
            'source_modules' => $sourceModules,
            'classification' => $classification->value,
            'freshness' => $freshness->value,
            'freshness_note' => $freshnessNote,
            'date_basis' => $dateBasis,
            'known_dependency' => $knownDependency,
        ];
    }
}
