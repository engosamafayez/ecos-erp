<?php

declare(strict_types=1);

namespace Modules\Admin\GoLive\Application\Services;

use Illuminate\Support\Facades\DB;

/**
 * TASK-...-026 §3.D/§9 — Inventory domain reset.
 *
 * `stock_ledger_entries` is documented as "immutable, append-only... never update or delete rows"
 * (Task 026's own research read this docblock directly) — but that invariant protects a LIVE
 * company's real audit trail. A Pre-Live company's ledger is test data by construction (the
 * company has never gone live), so hard-deleting it here does not violate that invariant's
 * purpose; §17's Live-lock is what makes this permanently unavailable the moment it would matter.
 *
 * No FK ordering concerns: Inventory's link back to `orders` is the polymorphic
 * `reference_type`/`reference_id` pair (a plain indexed column, never a real FK — confirmed by
 * reading the migration directly), so this domain is fully independent of whether Commerce was
 * also selected. `inventory_items`/`stock_ledger_entries` DO carry real FKs to `warehouses`/
 * `products` (restrict) — untouched here, satisfying §11 (master data preserved).
 */
final class InventoryResetService
{
    public function previewCounts(string $companyId): array
    {
        return [
            'stock_ledger_entries' => DB::table('stock_ledger_entries')->where('company_id', $companyId)->count(),
            'inventory_receipt_layers' => $this->receiptLayerCount($companyId),
            'inventory_count_sessions' => DB::table('inventory_count_sessions')->where('company_id', $companyId)->count(),
            'inventory_items' => DB::table('inventory_items')->where('company_id', $companyId)->count(),
        ];
    }

    public function execute(string $companyId): array
    {
        $counts = [];

        $itemIds = DB::table('inventory_items')->where('company_id', $companyId)->pluck('id')->all();

        if ($itemIds !== []) {
            $counts['inventory_receipt_layers'] = DB::table('inventory_receipt_layers')->whereIn('inventory_item_id', $itemIds)->delete();
        }

        $sessionIds = DB::table('inventory_count_sessions')->where('company_id', $companyId)->pluck('id')->all();
        if ($sessionIds !== []) {
            DB::table('inventory_count_lines')->whereIn('count_session_id', $sessionIds)->delete();
        }
        $counts['inventory_count_sessions'] = DB::table('inventory_count_sessions')->where('company_id', $companyId)->delete();

        // Legacy compat table (Modules\Inventory\StockLedger) — cleared defensively alongside the
        // canonical ledger even though current writes flow through stock_ledger_entries.
        $counts['stock_movements'] = $this->safeDeleteWhereCompany('stock_movements', $companyId);

        $counts['stock_ledger_entries'] = DB::table('stock_ledger_entries')->where('company_id', $companyId)->delete();
        $counts['inventory_items'] = DB::table('inventory_items')->where('company_id', $companyId)->delete();

        return $counts;
    }

    private function receiptLayerCount(string $companyId): int
    {
        $itemIds = DB::table('inventory_items')->where('company_id', $companyId)->pluck('id')->all();

        if ($itemIds === []) {
            return 0;
        }

        return DB::table('inventory_receipt_layers')->whereIn('inventory_item_id', $itemIds)->count();
    }

    /**
     * Some legacy/compat tables may not exist in every environment or may not carry company_id
     * directly; fail soft (0) rather than blow up the whole reset over an optional table.
     */
    private function safeDeleteWhereCompany(string $table, string $companyId): int
    {
        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable($table) || ! \Illuminate\Support\Facades\Schema::hasColumn($table, 'company_id')) {
                return 0;
            }

            return DB::table($table)->where('company_id', $companyId)->delete();
        } catch (\Throwable) {
            return 0;
        }
    }
}
