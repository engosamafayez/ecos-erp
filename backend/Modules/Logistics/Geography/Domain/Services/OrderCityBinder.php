<?php

declare(strict_types=1);

namespace Modules\Logistics\Geography\Domain\Services;

use Illuminate\Support\Facades\DB;

/**
 * Persists the resolved Logistics City onto Orders that do not yet carry one.
 *
 * ┌─ THE GAP THIS CLOSES ────────────────────────────────────────────────────┐
 * │ `orders.logistics_city_id` is the ONLY field OrderZoneResolver reads. The │
 * │ column has existed since 2026_07_16_000004, but the only thing that ever  │
 * │ populated it was that migration's ONE-TIME backfill. No runtime writer    │
 * │ existed, so every Order created afterwards carried NULL and could never   │
 * │ be zoned — 100% of Orders at the time of the audit.                       │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * IDEMPOTENT BY CONSTRUCTION, NOT BY CHECKING:
 *   • only rows WHERE logistics_city_id IS NULL are considered, so a second run
 *     cannot revisit a row the first run decided;
 *   • an already-bound Order is never re-examined and never re-written, so a
 *     later geography edit cannot silently move an Order that operators have
 *     already planned around;
 *   • unresolvable Orders stay NULL and are counted, not guessed at.
 *
 * WHAT IT DELIBERATELY DOES NOT TOUCH: order status, reservations, inventory,
 * totals, `updated_at`. The write is a targeted query-builder UPDATE of one
 * column — it does not load the Eloquent model, so no observer, no lifecycle
 * transition and no "last updated" bump can be triggered as a side effect. That
 * matters because `updated_at` is surfaced to operators as Last Updated: binding
 * a city is bookkeeping, not an edit anyone made to the Order.
 */
final class OrderCityBinder
{
    public function __construct(private readonly OrderCityResolver $resolver) {}

    /**
     * Bind every unbound, distribution-eligible Order for one company.
     *
     * Eligibility is read from the Distribution contract (ADR-042 via
     * `config('distribution.eligible_order_statuses')`) rather than restated
     * here: binding must consider exactly the Orders that distribution planning
     * will ask about, and one list has to own that.
     *
     * @return array{examined:int, bound:int, unresolved:int, reasons:array<string,int>}
     */
    public function bindForCompany(string $companyId): array
    {
        /** @var list<string> $statuses */
        $statuses = (array) config('distribution.eligible_order_statuses', []);

        $result = ['examined' => 0, 'bound' => 0, 'unresolved' => 0, 'reasons' => []];

        if ($statuses === []) {
            return $result;
        }

        $candidates = DB::table('orders')
            ->where('company_id', $companyId)
            ->whereIn('status', $statuses)
            ->whereNull('deleted_at')
            ->whereNull('logistics_city_id')
            ->select(['id', 'city', 'governorate'])
            ->get();

        $result['examined'] = $candidates->count();

        foreach ($candidates as $order) {
            $resolution = $this->resolver->resolve($order->city, $order->governorate);

            if ($resolution['city_id'] === null) {
                $result['unresolved']++;
                $reason = (string) $resolution['reason'];
                $result['reasons'][$reason] = ($result['reasons'][$reason] ?? 0) + 1;

                continue;
            }

            // Re-assert both the tenant and the NULL precondition in the UPDATE
            // itself. Between the SELECT above and this write another process may
            // have bound the same row; the WHERE makes that a no-op instead of an
            // overwrite.
            $written = DB::table('orders')
                ->where('id', $order->id)
                ->where('company_id', $companyId)
                ->whereNull('logistics_city_id')
                ->update(['logistics_city_id' => $resolution['city_id']]);

            if ($written > 0) {
                $result['bound']++;
            }
        }

        return $result;
    }

    /**
     * Re-resolve ONE Order's city because an operator changed its address.
     *
     * ┌─ WHY THIS IS NOT bindForCompany() ───────────────────────────────────────┐
     * │ The sweep above is deliberately NULL-only, and that stays true: *"an     │
     * │ already-bound Order is never re-examined and never re-written, so a      │
     * │ later geography edit cannot silently move an Order that operators have   │
     * │ already planned around."* That contract governs the AUTOMATIC pass, and  │
     * │ it is not relaxed by one line here.                                      │
     * │                                                                          │
     * │ This method is the opposite situation: an operator has just changed this  │
     * │ Order's city ON PURPOSE. Nothing is silent, nothing is a sweep, and the  │
     * │ Order they are moving is the one they are looking at. Refusing to re-bind │
     * │ here is what left `logistics_city_id` pointing at the old city forever.  │
     * └──────────────────────────────────────────────────────────────────────────┘
     *
     * NO SECOND RESOLVER. It calls the SAME OrderCityResolver the sweep calls, so
     * "what city is this text?" still has exactly one implementation, with the same
     * exact-match rules and the same refusal to guess.
     *
     * AN UNRESOLVABLE CITY CLEARS THE BINDING. This is the honest outcome, not a
     * failure: the stored id was justified by the OLD text, and once that text is
     * gone the id is an assertion nothing supports. Leaving it would keep the Order
     * in a zone its address no longer implies — the exact defect this closes. The
     * Order becomes unzoned and visible as such, which the workspace already
     * reports with a reason.
     *
     * WRITES ONE COLUMN, exactly like the sweep: a targeted query-builder UPDATE
     * that does not load the Eloquent model, so no observer, no lifecycle
     * transition and no `updated_at` bump fires. Binding a city is bookkeeping.
     *
     * @return array{city_id: int|null, reason: string|null, changed: bool}
     */
    public function rebindOrder(string $orderId, string $companyId): array
    {
        $order = DB::table('orders')
            ->where('id', $orderId)
            ->where('company_id', $companyId)
            ->select(['id', 'city', 'governorate', 'logistics_city_id'])
            ->first();

        if ($order === null) {
            // Outside the tenant boundary, or gone. Reported, never guessed at.
            return ['city_id' => null, 'reason' => 'order_not_found', 'changed' => false];
        }

        $resolution = $this->resolver->resolve($order->city, $order->governorate);

        $previous = $order->logistics_city_id === null ? null : (int) $order->logistics_city_id;
        $resolved = $resolution['city_id'];

        if ($previous === $resolved) {
            return ['city_id' => $resolved, 'reason' => $resolution['reason'], 'changed' => false];
        }

        DB::table('orders')
            ->where('id', $orderId)
            ->where('company_id', $companyId)
            ->update(['logistics_city_id' => $resolved]);

        return ['city_id' => $resolved, 'reason' => $resolution['reason'], 'changed' => true];
    }
}
