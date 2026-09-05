<?php

declare(strict_types=1);

namespace Modules\Purchasing\Suppliers\Application\Queries;

use App\Core\Company\TenantOwnershipResolver;
use Illuminate\Database\Eloquent\Builder;
use Modules\Inventory\ReceiptLayers\Domain\Models\InventoryReceiptLayer;
use Modules\Purchasing\GoodsReceipts\Domain\Enums\GoodsReceiptStatus;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceipt;
use Modules\Purchasing\PurchaseOrders\Domain\Models\PurchaseOrder;
use Modules\Purchasing\Suppliers\Domain\Models\Supplier;

final class GetSupplierSummaryStatsQuery
{
    /**
     * Global KPI aggregates across all suppliers — used for the workspace header cards.
     *
     * TASK-ECOS-REPORTING-CROSS-DOMAIN-AND-FINANCIAL-REPORTS-004 §4/§24 fix: `open_pos_total`,
     * `delayed_pos` (`PurchaseOrder`) and `total_inventory_value` (`InventoryReceiptLayer`)
     * previously carried no `company_id` filter at all — a real cross-tenant data leak found
     * while wiring RPT-PROC-01 to this function, not a hypothetical. `PurchaseOrder` and
     * `InventoryReceiptLayer` carry their own `company_id` column but no Eloquent global scope
     * (unlike `Order`/`Supplier`/`GoodsReceipt`), so nothing scoped them automatically. Fixed
     * by resolving the same `TenantOwnershipResolver` authority `Order`'s own global scope
     * uses, applied explicitly here.
     *
     * TASK-ECOS-REPORTING-V1-SOURCE-REMEDIATION-007 §3 fix: two further defects found by
     * direct source audit, both confirmed real:
     *
     * (A) `$explicitCompanyId` is a new, optional parameter — when a caller (Reporting)
     * already holds its own definite, resolved company id, that value now takes absolute
     * precedence over ambient `TenantOwnershipResolver` resolution, including for an
     * unrestricted/system-role actor. Reporting must never let a report caller's elevated
     * privilege widen a report execution to a cross-company total — `ReportQueryContext`
     * already guarantees exactly one company per execution (§9 of ADR-045 Decision 5), and
     * that guarantee must survive all the way to this function's own queries, not be
     * silently overridden by the ambient resolver's "unrestricted sees everything" rule
     * (a rule that is correct for direct, non-Reporting callers like
     * `SupplierAnalyticsController::summaryStats()`, but wrong for Reporting). The parameter
     * is optional and defaults to `null` specifically so that existing, non-Reporting caller
     * is unaffected — omitting it preserves the exact prior ambient-resolution behavior.
     *
     * (B) `needs_review_count`'s subquery built a raw sub-select directly against
     * `purchase_orders` inside a `whereNotIn` closure — bypassing `scoped()` entirely (that
     * closure is not itself a `Builder` `scoped()` can wrap) and drawing candidate
     * `supplier_id`s from every company's purchase orders, not just the caller's. Fixed by
     * qualifying the subquery's own `company_id` explicitly against the same resolved scope
     * (explicit-first, ambient-fallback — the same `resolveCompanyId()` helper as (A)).
     *
     * @return array<string, mixed>
     */
    public function execute(?string $explicitCompanyId = null): array
    {
        // `Supplier`/`GoodsReceipt` both carry their own ambient global scope already — but
        // TASK-ECOS-REPORTING-V1-SOURCE-REMEDIATION-007 §3 applies `scoped()` to every query
        // in this function uniformly (not just the two models that previously had none),
        // since an ambient-only scope still widens to every company for an
        // unrestricted/system-role actor. Reporting's own explicit company id must win
        // everywhere in this function, not on a field-by-field basis.
        $totalSuppliers = self::scoped(Supplier::query(), $explicitCompanyId)->count();
        $activeSuppliers = self::scoped(Supplier::query(), $explicitCompanyId)->where('is_active', true)->count();

        $newThisMonth = self::scoped(Supplier::query(), $explicitCompanyId)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->count();

        $openPos = self::scoped(PurchaseOrder::query(), $explicitCompanyId)
            ->whereIn('status', ['approved', 'partially_received'])
            ->whereNull('deleted_at')
            ->count();

        $delayedPos = self::scoped(PurchaseOrder::query(), $explicitCompanyId)
            ->whereIn('status', ['approved', 'partially_received'])
            ->whereNotNull('expected_date')
            ->where('expected_date', '<', now()->toDateString())
            ->whereNull('deleted_at')
            ->count();

        $financials = self::scoped(GoodsReceipt::query(), $explicitCompanyId)
            ->where('status', GoodsReceiptStatus::Posted->value)
            ->whereNull('deleted_at')
            ->selectRaw('
                COALESCE(SUM(invoice_total_amount), 0) as total_invoiced,
                COALESCE(SUM(paid_amount), 0)          as total_paid
            ')
            ->first();

        $totalInvoiced = (float) ($financials?->total_invoiced ?? 0);
        $totalPaid = (float) ($financials?->total_paid ?? 0);
        $totalOutstanding = max(0.0, $totalInvoiced - $totalPaid);

        $totalInventoryValue = (float) (self::scoped(InventoryReceiptLayer::query(), $explicitCompanyId)
            ->where('remaining_qty', '>', 0)
            ->whereNotNull('supplier_id')
            ->selectRaw('COALESCE(SUM(remaining_qty * landed_unit_cost), 0) as total_value')
            ->value('total_value') ?? 0);

        $needsReviewCount = self::scoped(Supplier::query(), $explicitCompanyId)
            ->where('is_active', true)
            ->whereNotIn('id', function ($q) use ($explicitCompanyId): void {
                self::scopedQueryBuilder(
                    $q->select('supplier_id')
                        ->from('purchase_orders')
                        ->where('created_at', '>=', now()->subDays(90))
                        ->whereNull('deleted_at'),
                    $explicitCompanyId,
                );
            })
            ->count();

        return [
            'total_suppliers' => $totalSuppliers,
            'active_suppliers' => $activeSuppliers,
            'new_this_month' => $newThisMonth,
            'open_pos_total' => $openPos,
            'delayed_pos' => $delayedPos,
            'total_outstanding' => round($totalOutstanding, 2),
            'total_inventory_value' => round($totalInventoryValue, 2),
            'needs_review_count' => $needsReviewCount,
        ];
    }

    /**
     * Applies the exact same fail-closed tenant semantics as `Order`'s own Eloquent global
     * scope (`Modules\Commerce\Orders\Domain\Models\Order::booted()`), replicated explicitly
     * here because `PurchaseOrder`/`InventoryReceiptLayer` carry a `company_id` column but no
     * global scope of their own.
     *
     * TASK-ECOS-REPORTING-V1-SOURCE-REMEDIATION-007 §3: when `$explicitCompanyId` is given
     * (Reporting always supplies its own `ReportQueryContext::$companyId` here), it takes
     * absolute precedence — filtered to exactly that one company, even for an actor whose
     * ambient tenant resolution would otherwise be unrestricted. This is what keeps a
     * system-role user's elevated privilege from ever widening a *Reporting* execution to a
     * cross-company total, while every other, non-Reporting caller of this class (e.g.
     * `SupplierAnalyticsController::summaryStats()`, which never passes this parameter) keeps
     * its prior ambient-resolution behavior exactly as before: console/queue/unauthenticated
     * context left unfiltered (matches `appliesTo()`), an unrestricted actor sees every
     * company, a scoped actor with a resolved company id is filtered to it, and — critically —
     * a scoped actor with NO resolvable company id gets a query-closing `1 = 0`, never a bare
     * `where('company_id', null)` (Laravel's query builder turns that into `IS NULL`, which
     * would wrongly match any row whose `company_id` happens to be null instead of returning
     * nothing).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private static function scoped(Builder $query, ?string $explicitCompanyId = null): Builder
    {
        if ($explicitCompanyId !== null) {
            return $query->where('company_id', $explicitCompanyId);
        }

        $tenant = app(TenantOwnershipResolver::class);

        if (! $tenant->appliesTo() || $tenant->isUnrestricted()) {
            return $query;
        }

        $companyId = $tenant->companyId();

        if ($companyId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('company_id', $companyId);
    }

    /**
     * Identical semantics to {@see self::scoped()}, for a plain query-builder subquery
     * (`needs_review_count`'s `whereNotIn` closure operates on
     * `Illuminate\Database\Query\Builder`, never an Eloquent model instance — there is
     * nothing here for `scoped()`'s `Builder<TModel>` signature to wrap).
     */
    private static function scopedQueryBuilder(\Illuminate\Database\Query\Builder $query, ?string $explicitCompanyId = null): \Illuminate\Database\Query\Builder
    {
        if ($explicitCompanyId !== null) {
            return $query->where('company_id', $explicitCompanyId);
        }

        $tenant = app(TenantOwnershipResolver::class);

        if (! $tenant->appliesTo() || $tenant->isUnrestricted()) {
            return $query;
        }

        $companyId = $tenant->companyId();

        if ($companyId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('company_id', $companyId);
    }
}
