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
     * uses, applied explicitly here — same fail-closed semantics (unrestricted actors see
     * every company; a scoped actor with no company sees none; everyone else sees only their
     * own), no signature change, so every existing caller is automatically corrected.
     *
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $totalSuppliers = Supplier::query()->count();
        $activeSuppliers = Supplier::query()->where('is_active', true)->count();

        $newThisMonth = Supplier::query()
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->count();

        $openPos = self::scoped(PurchaseOrder::query())
            ->whereIn('status', ['approved', 'partially_received'])
            ->whereNull('deleted_at')
            ->count();

        $delayedPos = self::scoped(PurchaseOrder::query())
            ->whereIn('status', ['approved', 'partially_received'])
            ->whereNotNull('expected_date')
            ->where('expected_date', '<', now()->toDateString())
            ->whereNull('deleted_at')
            ->count();

        $financials = GoodsReceipt::query()
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

        $totalInventoryValue = (float) (self::scoped(InventoryReceiptLayer::query())
            ->where('remaining_qty', '>', 0)
            ->whereNotNull('supplier_id')
            ->selectRaw('COALESCE(SUM(remaining_qty * landed_unit_cost), 0) as total_value')
            ->value('total_value') ?? 0);

        $needsReviewCount = Supplier::query()
            ->where('is_active', true)
            ->whereNotIn('id', function ($q): void {
                $q->select('supplier_id')
                    ->from('purchase_orders')
                    ->where('created_at', '>=', now()->subDays(90))
                    ->whereNull('deleted_at');
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
     * global scope of their own: console/queue/unauthenticated context is left unfiltered
     * (matches `appliesTo()`), an unrestricted (system-role) actor sees every company, a
     * scoped actor with a resolved company id is filtered to it, and — critically — a scoped
     * actor with NO resolvable company id gets a query-closing `1 = 0`, never a bare
     * `where('company_id', null)` (Laravel's query builder turns that into `IS NULL`, which
     * would wrongly match any row whose `company_id` happens to be null instead of returning
     * nothing).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private static function scoped(Builder $query): Builder
    {
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
