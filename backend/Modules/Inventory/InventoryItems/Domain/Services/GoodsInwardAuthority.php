<?php

declare(strict_types=1);

namespace Modules\Inventory\InventoryItems\Domain\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\InventoryItems\Domain\Enums\GoodsInwardMode;

/**
 * "May THIS kind of document post inventory for THIS company?" — the G-1 authority.
 *
 * {@see InboundPostingGuard} answers a different question: has this specific document already
 * been posted. That guard is correct and stays, but it could only ever stop a document
 * duplicating ITSELF. Stopping a Goods Receipt and a Supplier Invoice from both posting the
 * SAME delivery required them to share a ledger reference, which in turn required
 * `supplier_invoices.auto_receipt_id` to be populated — and no production code path ever wrote
 * that column, so the cross-document protection never once fired outside a test fixture.
 *
 * This class removes the dependency on that link entirely. Rather than trying to recognise two
 * documents as the same delivery, the company declares which DOCUMENT TYPE owns goods-inward,
 * and the other type never posts. One authority per company means one posting per delivery,
 * with no matching problem left to solve.
 *
 * Read through the query builder rather than the Company model, deliberately — the same reason
 * {@see \Modules\Operations\Preparation\Application\Services\WaveEngine\CompanyTimezoneResolver}
 * does: this is consulted from console commands, queue workers and seeders where there is no
 * authenticated actor, and Company-scoped global scopes would silently return nothing.
 *
 * FAILS SAFE, NOT CLOSED. An unknown or unset mode resolves to `GoodsReceipt`, the schema
 * default and the traditional path, so an unconfigured company keeps working exactly as before.
 * Failing closed here would stop all stock movement on a missing setting, which is a worse
 * outcome than the well-defined default.
 */
final class GoodsInwardAuthority
{
    /** @var array<string, GoodsInwardMode> */
    private array $cache = [];

    public function modeFor(string $companyId): GoodsInwardMode
    {
        if ($companyId === '') {
            return GoodsInwardMode::default();
        }

        if (! array_key_exists($companyId, $this->cache)) {
            $value = DB::table('companies')
                ->where('id', $companyId)
                ->value('goods_inward_mode');

            $this->cache[$companyId] = GoodsInwardMode::tryFromValue(
                $value === null ? null : (string) $value,
            );
        }

        return $this->cache[$companyId];
    }

    /**
     * Drop the memoised mode for a company.
     *
     * Needed only by the Configuration endpoint that CHANGES the setting: it reads the old
     * value, writes the new one, and then re-presents the result within the same request, so
     * without this the response would echo the pre-change value back to the user. Business
     * callers never need it — they resolve once per request.
     */
    public function forget(string $companyId): void
    {
        unset($this->cache[$companyId]);
    }

    /** True when a Goods Receipt is this company's inventory-posting authority. */
    public function receiptMayPost(string $companyId): bool
    {
        return $this->modeFor($companyId) === GoodsInwardMode::GoodsReceipt;
    }

    /** True when a Supplier Invoice is this company's inventory-posting authority (Mode 3). */
    public function invoiceMayPost(string $companyId): bool
    {
        return $this->modeFor($companyId) === GoodsInwardMode::SupplierInvoice;
    }
}
