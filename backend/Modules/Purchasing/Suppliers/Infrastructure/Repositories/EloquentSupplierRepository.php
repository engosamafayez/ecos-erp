<?php

declare(strict_types=1);

namespace Modules\Purchasing\Suppliers\Infrastructure\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Purchasing\Suppliers\Domain\Contracts\SupplierRepositoryInterface;
use Modules\Purchasing\Suppliers\Domain\Models\Supplier;

/**
 * Eloquent implementation of the supplier repository.
 */
final class EloquentSupplierRepository implements SupplierRepositoryInterface
{
    /** Columns that may be sorted on (whitelist). */
    private const SORTABLE = ['code', 'name', 'country', 'city', 'is_active', 'created_at'];

    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = Supplier::query()->select('suppliers.*');

        $query->leftJoin('supplier_categories', 'supplier_categories.id', '=', 'suppliers.supplier_category_id');
        $query->addSelect(['supplier_categories.name as supplier_category_name']);

        // Multiple Categories (§A.1) — the full assigned set, batched (one grouped join,
        // never a query per Supplier), for the list's compact multi-category display.
        // `supplier_category_name` above stays untouched (still the legacy single/"primary"
        // read) so nothing that already relies on it breaks.
        $supplierCategoryStats = DB::table('supplier_category_assignments')
            ->join('supplier_categories', 'supplier_categories.id', '=', 'supplier_category_assignments.supplier_category_id')
            ->selectRaw('
                supplier_category_assignments.supplier_id,
                GROUP_CONCAT(supplier_categories.name ORDER BY supplier_categories.name SEPARATOR ", ") AS supplier_category_names,
                COUNT(*) AS supplier_category_count
            ')
            ->groupBy('supplier_category_assignments.supplier_id');

        $query->leftJoinSub($supplierCategoryStats, 'sc_agg', fn ($j) => $j->on('suppliers.id', '=', 'sc_agg.supplier_id'));
        $query->addSelect([
            DB::raw('sc_agg.supplier_category_names AS supplier_category_names'),
            DB::raw('COALESCE(sc_agg.supplier_category_count, 0) AS supplier_category_count'),
        ]);

        // ── Aggregate subqueries (LEFT JOIN on derived tables) ────────────────

        $grStats = DB::table('goods_receipts')
            ->join('purchase_orders', 'goods_receipts.purchase_order_id', '=', 'purchase_orders.id')
            ->where('goods_receipts.status', 'posted')
            ->whereNull('goods_receipts.deleted_at')
            ->whereNull('purchase_orders.deleted_at')
            ->selectRaw('
                purchase_orders.supplier_id,
                COALESCE(SUM(goods_receipts.invoice_total_amount), 0) AS total_invoiced,
                COALESCE(SUM(goods_receipts.paid_amount), 0)          AS total_paid,
                MAX(goods_receipts.receipt_date)                       AS last_purchase_date,
                COUNT(goods_receipts.id)                               AS purchase_count
            ')
            ->groupBy('purchase_orders.supplier_id');

        $poStats = DB::table('purchase_orders')
            ->whereIn('status', ['approved', 'partially_received'])
            ->whereNull('deleted_at')
            ->selectRaw('supplier_id, COUNT(*) AS active_pos_count')
            ->groupBy('supplier_id');

        $invStats = DB::table('inventory_receipt_layers')
            ->where('remaining_qty', '>', 0)
            ->selectRaw('
                supplier_id,
                COALESCE(SUM(remaining_qty * landed_unit_cost), 0) AS inventory_cost_value
            ')
            ->groupBy('supplier_id');

        $query->leftJoinSub($grStats, 'gr_agg', fn ($j) => $j->on('suppliers.id', '=', 'gr_agg.supplier_id'));
        $query->leftJoinSub($poStats, 'po_agg', fn ($j) => $j->on('suppliers.id', '=', 'po_agg.supplier_id'));
        $query->leftJoinSub($invStats, 'inv_agg', fn ($j) => $j->on('suppliers.id', '=', 'inv_agg.supplier_id'));

        $query->addSelect([
            DB::raw('COALESCE(gr_agg.total_invoiced, 0)                          AS total_invoiced'),
            DB::raw('COALESCE(gr_agg.total_paid, 0)                              AS total_paid'),
            DB::raw('GREATEST(0, COALESCE(gr_agg.total_invoiced, 0) - COALESCE(gr_agg.total_paid, 0)) AS outstanding_balance'),
            DB::raw('gr_agg.last_purchase_date'),
            DB::raw('COALESCE(po_agg.active_pos_count, 0)                        AS active_pos_count'),
            DB::raw('COALESCE(inv_agg.inventory_cost_value, 0)                   AS inventory_cost_value'),
        ]);

        // ── Ledger-derived balances (REALIGNMENT-001 §16) ─────────────────────
        // The grid's money columns used to come from hand-entered goods-receipt scalars
        // (invoice_total_amount − paid_amount), which diverged from Supplier 360 and from
        // Finance. These aggregates mirror SupplierLedgerService::outstandingPayable() and
        // ::availableAdvance() EXACTLY — the AP subledger is the single source of truth —
        // batched here so the list does not issue a query per row. Advance is kept in its own
        // bucket and is never folded into the payable (the certified opening-balance contract).
        if (Schema::hasTable('finance_supplier_ledger_entries')) {
            $ledgerStats = DB::table('finance_supplier_ledger_entries')
                ->selectRaw("
                    supplier_id,
                    COALESCE(SUM(CASE WHEN entry_type <> 'advance' THEN amount ELSE 0 END), 0)        AS ledger_outstanding_payable,
                    COALESCE(-SUM(CASE WHEN entry_type = 'advance' THEN amount ELSE 0 END), 0)        AS ledger_available_advance,
                    COALESCE(SUM(CASE WHEN entry_type = 'opening_payable' THEN amount ELSE 0 END), 0) AS ledger_opening_payable
                ")
                ->groupBy('supplier_id');

            $query->leftJoinSub($ledgerStats, 'led_agg', fn ($j) => $j->on('suppliers.id', '=', 'led_agg.supplier_id'));

            $query->addSelect([
                DB::raw('COALESCE(led_agg.ledger_outstanding_payable, 0) AS ledger_outstanding_payable'),
                DB::raw('COALESCE(led_agg.ledger_available_advance, 0)   AS ledger_available_advance'),
                DB::raw('COALESCE(led_agg.ledger_opening_payable, 0)     AS ledger_opening_payable'),
            ]);
        }

        // ── Supply Capability counts (batched — one grouped join each, never a
        // query per Supplier) for the list's compact summary display. Full sets
        // are only ever fetched via findById() (Supplier detail). ─────────────
        $rawMaterialStats = DB::table('supplier_products')
            ->selectRaw('supplier_id, COUNT(*) AS raw_material_count')
            ->groupBy('supplier_id');

        $categoryStats = DB::table('supplier_product_categories')
            ->selectRaw('supplier_id, COUNT(*) AS product_category_count')
            ->groupBy('supplier_id');

        $query->leftJoinSub($rawMaterialStats, 'rm_agg', fn ($j) => $j->on('suppliers.id', '=', 'rm_agg.supplier_id'));
        $query->leftJoinSub($categoryStats, 'cat_agg', fn ($j) => $j->on('suppliers.id', '=', 'cat_agg.supplier_id'));

        $query->addSelect([
            DB::raw('COALESCE(rm_agg.raw_material_count, 0)     AS raw_material_count'),
            DB::raw('COALESCE(cat_agg.product_category_count, 0) AS product_category_count'),
        ]);

        // ── Filters ───────────────────────────────────────────────────────────

        $country = trim((string) ($filters['country'] ?? ''));
        if ($country !== '') {
            $query->where('suppliers.country', $country);
        }

        $city = trim((string) ($filters['city'] ?? ''));
        if ($city !== '') {
            $query->where('suppliers.city', $city);
        }

        // Multiple Categories (§A.1) — matches if ANY of the Supplier's assigned categories
        // is the filtered one, not just the legacy single/"primary" column, so a Supplier
        // assigned to [A, B] is correctly found when filtering by B even though A is primary.
        $categoryId = trim((string) ($filters['supplier_category_id'] ?? ''));
        if ($categoryId !== '') {
            $query->whereHas('categories', fn (Builder $q) => $q->where('supplier_categories.id', $categoryId));
        }

        // Capability filters — backend-authoritative (correlated EXISTS via
        // whereHas), not client-side filtering over the loaded page (§12).
        $rawMaterialId = trim((string) ($filters['raw_material_id'] ?? ''));
        if ($rawMaterialId !== '') {
            $query->whereHas('rawMaterials', fn (Builder $q) => $q->where('products.id', $rawMaterialId));
        }

        $productCategoryId = trim((string) ($filters['product_category_id'] ?? ''));
        if ($productCategoryId !== '') {
            $query->whereHas('productCategories', fn (Builder $q) => $q->where('categories.id', $productCategoryId));
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->where('suppliers.code', 'like', "%{$search}%")
                    ->orWhere('suppliers.name', 'like', "%{$search}%")
                    ->orWhere('suppliers.contact_person', 'like', "%{$search}%")
                    ->orWhere('suppliers.email', 'like', "%{$search}%")
                    ->orWhere('suppliers.city', 'like', "%{$search}%");
            });
        }

        $status = (string) ($filters['status'] ?? 'all');
        if ($status === 'active') {
            $query->where('suppliers.is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('suppliers.is_active', false);
        }

        $sortBy = (string) ($filters['sort_by'] ?? 'created_at');
        if (! in_array($sortBy, self::SORTABLE, true)) {
            $sortBy = 'created_at';
        }

        $sortDir = strtolower((string) ($filters['sort_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $perPage = (int) ($filters['per_page'] ?? 10);
        $perPage = max(1, min($perPage, 100));

        return $query->orderBy("suppliers.{$sortBy}", $sortDir)->paginate($perPage);
    }

    public function findById(string $id): ?Supplier
    {
        // Single-record fetch — eager-loading these relations is a constant number of
        // extra queries regardless of how many related rows exist, never N+1.
        return Supplier::query()->with(['supplierCategory', 'categories', 'rawMaterials', 'productCategories'])->find($id);
    }

    public function create(array $attributes): Supplier
    {
        return Supplier::query()->create($attributes);
    }

    public function update(Supplier $supplier, array $attributes): Supplier
    {
        $supplier->update($attributes);

        return $supplier->refresh();
    }

    public function delete(Supplier $supplier): void
    {
        $supplier->delete();
    }
}
