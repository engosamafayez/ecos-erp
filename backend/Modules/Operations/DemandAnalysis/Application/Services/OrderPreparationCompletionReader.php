<?php

declare(strict_types=1);

namespace Modules\Operations\DemandAnalysis\Application\Services;

use Illuminate\Support\Facades\DB;

/**
 * "Is this order fully prepared in this wave?" — the G-1 authority, read-only.
 *
 * THE RULE (G-1): an order is preparation-complete for a wave when EVERY product it
 * requires has `wave_product_demand.preparation_completed_at` set for that wave. One
 * unfinished product leaves the whole order incomplete.
 *
 * `wave_product_demand.preparation_completed_at` is the canonical fact and the only one
 * consulted here. It is operator-declared, reversible, and automatically withdrawn when
 * that product's Required moves (DemandReadRepository::clearCompletionWhereRequiredChanged),
 * and this reader inherits all three properties by construction.
 *
 * `orders.preparation_completed_at` is deliberately NOT read. It has a single writer that
 * cannot fire and no readers at all; treating it as authoritative would report every order
 * as unprepared. No order-level completion column is introduced either — completion is
 * DERIVED here, at the moment it is needed.
 *
 * FAILS CLOSED, in both directions that matter:
 *   • a product with no demand row for the wave counts as NOT completed (a missing row is
 *     not a completed one);
 *   • an order with no priced lines is never reported complete.
 * The consequence of a false negative is that an order is returned to In Progress and
 * prepared again — wasteful. The consequence of a false positive is an order that is
 * never prepared and never shipped. The asymmetry is the reason for the direction.
 */
final class OrderPreparationCompletionReader
{
    /**
     * The subset of $orderIds that are fully preparation-complete within $waveId.
     *
     * One query for the whole set: this runs at wave close, over every member of the
     * cycle at once.
     *
     * @param  list<string>  $orderIds
     * @return list<string>
     */
    public function fullyPreparedOrderIds(string $waveId, array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $rows = DB::table('order_lines as ol')
            ->leftJoin('wave_product_demand as wpd', function ($join) use ($waveId): void {
                $join->on('wpd.product_id', '=', 'ol.product_id')
                    ->where('wpd.preparation_wave_id', '=', $waveId);
            })
            ->whereIn('ol.order_id', $orderIds)
            // A zero-quantity line requires nothing, so it can neither be prepared nor
            // block the order from being complete.
            ->where('ol.quantity', '>', 0)
            ->groupBy('ol.order_id')
            ->selectRaw(
                'ol.order_id AS order_id, '
                .'COUNT(DISTINCT ol.product_id) AS required_products, '
                .'COUNT(DISTINCT CASE WHEN wpd.preparation_completed_at IS NOT NULL '
                .'THEN ol.product_id END) AS completed_products',
            )
            ->get();

        $complete = [];

        foreach ($rows as $row) {
            $required = (int) $row->required_products;

            if ($required > 0 && (int) $row->completed_products === $required) {
                $complete[] = (string) $row->order_id;
            }
        }

        return $complete;
    }

    /** Single-order convenience. Prefer {@see fullyPreparedOrderIds()} for a set. */
    public function isFullyPrepared(string $waveId, string $orderId): bool
    {
        return $this->fullyPreparedOrderIds($waveId, [$orderId]) !== [];
    }
}
