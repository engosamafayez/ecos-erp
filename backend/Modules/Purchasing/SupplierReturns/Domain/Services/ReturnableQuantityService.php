<?php

declare(strict_types=1);

namespace Modules\Purchasing\SupplierReturns\Domain\Services;

use Illuminate\Support\Facades\DB;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceiptLine;
use Modules\Purchasing\SupplierReturns\Domain\Enums\SupplierReturnStatus;

/**
 * How much of a Goods Receipt Line may still be returned — the SR-2 ceiling authority.
 *
 *     Returnable = Received − Previously Approved Returned
 *
 * SR-2 names `goods_receipt_line_id` as the canonical identity, so the ceiling is computed
 * per receipt line and nowhere else. That choice is what the schema was always shaped for:
 * `supplier_return_lines` already carries `goods_receipt_line_id`, `original_received_qty`
 * and `original_unit_cost` on one row.
 *
 * `original_received_qty` is deliberately NOT used as the received quantity. It is supplied
 * by the client in the create request, so trusting it would let the caller declare its own
 * ceiling. Received always comes from the receipt line itself, via
 * `GoodsReceiptLine::effectiveReceivedQty()` — the same accessor the certified inbound path
 * uses, so what can be returned is bounded by exactly what was stocked.
 */
final class ReturnableQuantityService
{
    /**
     * Return states that have already consumed inventory and therefore count against
     * the ceiling.
     *
     * Approval is the point of no return: everything from Approved onwards has had stock
     * removed. Draft and WaitingApproval have not, so they must NOT reserve allowance —
     * an abandoned draft would otherwise permanently reduce what can be returned.
     * Cancelled and Rejected never mutated anything.
     *
     * @return list<string>
     */
    public static function consumingStatuses(): array
    {
        return [
            SupplierReturnStatus::Approved->value,
            SupplierReturnStatus::Sent->value,
            SupplierReturnStatus::CreditPending->value,
            SupplierReturnStatus::Completed->value,
        ];
    }

    /** Quantity received against this receipt line — the canonical inbound quantity. */
    public function received(GoodsReceiptLine $line): float
    {
        return $line->effectiveReceivedQty();
    }

    /**
     * Quantity already returned against this receipt line under a consuming status.
     *
     * Company-scoped: a Company B return must never reduce a Company A allowance, and must
     * never be visible to this calculation at all.
     *
     * SCOPING DELIBERATELY DOES NOT TRUST `supplier_returns.company_id`. That column is
     * nullable and the create endpoint never populates it — `store()` persists
     * `$request->safe()->except('lines')`, and `company_id` is not among the validated keys,
     * so every return created through the API carries NULL. Filtering on it directly made
     * `NULL = '<uuid>'` evaluate to UNKNOWN for every production row, the sum came back 0,
     * and the ceiling silently degenerated to `Returnable = Received` — SR-2 enforcing
     * nothing at all while the tests, whose fixtures set the column explicitly, stayed green.
     *
     * The warehouse is the canonical authority instead: `supplier_returns.warehouse_id` is
     * NOT NULL, and the column's own backfill migration defines company exactly this way
     * (`SET sr.company_id = w.company_id`). COALESCE keeps backfilled rows working and makes
     * the calculation correct for populated and NULL rows alike.
     */
    public function alreadyReturned(string $goodsReceiptLineId, string $companyId, ?string $excludeReturnId = null): float
    {
        return (float) DB::table('supplier_return_lines as srl')
            ->join('supplier_returns as sr', 'sr.id', '=', 'srl.supplier_return_id')
            ->join('warehouses as srw', 'srw.id', '=', 'sr.warehouse_id')
            ->where('srl.goods_receipt_line_id', $goodsReceiptLineId)
            ->whereRaw('COALESCE(sr.company_id, srw.company_id) = ?', [$companyId])
            ->whereNull('sr.deleted_at')
            // Status OR the posting marker.
            //
            // Status alone is not sufficient: `Approved -> Cancelled` is a legal transition
            // and Cancelled is (correctly) not a consuming status, so cancelling an approved
            // return handed its allowance straight back — while the stock it removed was
            // never restored, because no restock path exists. The quantity could then be
            // returned again.
            //
            // `inventory_restocked` is the exact discriminator that status cannot express: it
            // separates "cancelled after the stock moved" from "cancelled before it ever
            // did", and it is written in the same transaction as the movement itself. The OR
            // keeps this a superset, so legacy rows carrying a consuming status still count
            // even if the marker predates them.
            ->where(function ($q): void {
                $q->whereIn('sr.status', self::consumingStatuses())
                    ->orWhere('sr.inventory_restocked', true);
            })
            ->when($excludeReturnId !== null, fn ($q) => $q->where('sr.id', '!=', $excludeReturnId))
            ->sum('srl.return_quantity');
    }

    /** Remaining returnable quantity, never negative. */
    public function returnable(GoodsReceiptLine $line, string $companyId, ?string $excludeReturnId = null): float
    {
        return max(
            0.0,
            round($this->received($line) - $this->alreadyReturned($line->id, $companyId, $excludeReturnId), 4),
        );
    }
}
