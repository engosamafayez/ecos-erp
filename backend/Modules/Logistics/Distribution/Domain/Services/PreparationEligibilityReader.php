<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Reads — and only reads — Preparation's answer to "may this Order be worked now?".
 *
 * ┌─ WHY DISTRIBUTION MAY NOT ANSWER THIS ITSELF ────────────────────────────┐
 * │ Distribution used to decide eligibility from `orders.status` alone. That  │
 * │ is HALF the rule. An Order can hold an eligible status and still have     │
 * │ been POSTPONED out of the current preparation cycle by an operator — and  │
 * │ a status-only filter cannot see that, so the Order stayed in the          │
 * │ distribution pool, kept its Zone, and counted toward a Distribution       │
 * │ Group that a warehouse was never going to prepare.                        │
 * │                                                                           │
 * │ Both halves of the rule belong to Preparation. This class restates        │
 * │ neither: it composes them from the same columns Preparation's own         │
 * │ collector uses, in ONE place, so Distribution has exactly one opinion and │
 * │ it is Preparation's.                                                      │
 * └───────────────────────────────────────────────────────────────────────────┘
 *
 * THE TWO PREPARATION PREDICATES, quoted from `WaveMembershipService`:
 *
 *   • ACTIVE membership is `released_at IS NULL` — *"not 'has ever been a
 *     member'"*. A released row is history and excludes nothing.
 *   • A POSTPONED row *"is NOT released, so it still excludes the order:
 *     postponing must not be undone by the collector 60 seconds later"*.
 *
 * So an Order is out of the current cycle exactly when it holds an ACTIVE
 * membership that is POSTPONED. `uq_prep_wave_orders_company_order_active`
 * guarantees at most one active membership per Order, so that question has one
 * answer rather than one per wave — which is why this reader stays correct even
 * when several waves run at once.
 *
 * ORDERS WITH NO MEMBERSHIP REMAIN ELIGIBLE. An Order that has not yet been
 * collected into a wave has not been excluded from anything; it is simply
 * early. Treating "no row" as "not eligible" would empty the distribution pool
 * every time a wave rolled, which is the opposite of the intent.
 *
 * NOTHING HERE WRITES. No Preparation table is modified, no Preparation service
 * is called, and no Preparation contract is changed — the columns are read the
 * way Commerce\Orders' own wave listeners already read them.
 */
final class PreparationEligibilityReader
{
    /**
     * Exclude Orders that Preparation has postponed out of the current cycle.
     *
     * Applied to any query that has the Orders table joined under `$alias`. It
     * adds one NOT EXISTS and nothing else, so it composes with every existing
     * filter instead of replacing any of them.
     *
     * @param  Builder  $query  a query with `$alias` resolving to `orders`
     * @param  string  $alias  how the Orders table is named in that query
     */
    public function excludePostponed(Builder $query, string $alias = 'orders'): Builder
    {
        return $query->whereNotExists(function (Builder $sub) use ($alias): void {
            $sub->select(DB::raw(1))
                ->from('preparation_wave_orders as pwo_elig')
                ->whereColumn('pwo_elig.order_id', $alias.'.id')
                // ACTIVE membership — a released row is history.
                ->whereNull('pwo_elig.released_at')
                // ...and postponed out of the cycle.
                ->whereNotNull('pwo_elig.postponed_at');
        });
    }

    /**
     * Both halves of eligibility, for READ paths.
     *
     * `excludePostponed()` alone is not enough on the read side. Collection filters
     * status itself and then never looks again — so an Order collected while
     * `in_progress` and later CANCELLED kept its assignment row and stayed in the
     * pool, in its Zone, and in its Group's totals. Requirement C ("no longer
     * eligible -> disappears") needs the status re-checked at read time, because
     * that is the only moment a later status change is observed.
     *
     * The status list is ADR-042's, read through the Distribution config that
     * derives from it — not restated here.
     *
     * @param  Builder  $query  a query with `$alias` resolving to `orders`
     */
    public function constrainToEligible(Builder $query, string $alias = 'orders'): Builder
    {
        /** @var list<string> $statuses */
        $statuses = (array) config('distribution.eligible_order_statuses', []);

        return $this->excludePostponed(
            $query->whereIn($alias.'.status', $statuses),
            $alias,
        );
    }

    /**
     * The OPERATIONAL half of eligibility — for Group / Loading Preparation reads.
     *
     * ┌─ WHY A SECOND PREDICATE RATHER THAN A WIDER FIRST ONE ───────────────────┐
     * │ Starting a preparation wave runs MoveToPreparationWorkflow for every      │
     * │ Order in it, setting `ready_for_dispatch`. That status is not an exit from │
     * │ fulfilment — HandlePreparationWaveClosed labels it "done, waiting to be    │
     * │ loaded", which is exactly the population Loading Preparation serves. Under │
     * │ constrainToEligible() a Distribution Group therefore emptied itself at the │
     * │ moment its work became loadable.                                          │
     * │                                                                           │
     * │ Widening `eligible_order_statuses` instead would have changed NINE         │
     * │ consumers at once, two of which are WRITES — DistributionCollectionService │
     * │ (creates window membership) and OrderCityBinder (writes an Orders column). │
     * │ Ingestion must keep asking the narrower question, so the two questions get │
     * │ two predicates rather than one predicate and a compromise.                │
     * └───────────────────────────────────────────────────────────────────────────┘
     *
     * ONLY THE STATUS LIST DIFFERS. This composes the SAME `excludePostponed()`,
     * so both halves of Preparation's rule survive verbatim: an Order holding an
     * ACTIVE membership that is POSTPONED is out of the cycle here exactly as it is
     * there. Cancelled, returned, on-hold, awaiting-payment, awaiting-stock and
     * scheduled Orders are in neither list. Nothing is restated; nothing is relaxed
     * except the one status the approved lifecycle requires.
     *
     * WHAT THIS DOES NOT DO: it does not scope by warehouse, company or Group.
     * Those remain the caller's `scopeWarehouse()` / window / slot predicates,
     * applied outside this wrapper — so this composes with them instead of
     * becoming a second definition of any of them.
     *
     * @param  Builder  $query  a query with `$alias` resolving to `orders`
     * @param  string  $alias  how the Orders table is named in that query
     */
    public function constrainToLoadingEligible(Builder $query, string $alias = 'orders'): Builder
    {
        /** @var list<string> $statuses */
        $statuses = (array) config('distribution.loading_eligible_order_statuses', []);

        return $this->excludePostponed(
            $query->whereIn($alias.'.status', $statuses),
            $alias,
        );
    }

    /**
     * Is this one Order currently workable by Preparation?
     *
     * Status AND cycle membership, both required. Used on the single-Order paths
     * (manual late assignment) where there is no query to constrain.
     */
    public function isEligible(string $orderId): bool
    {
        /** @var list<string> $statuses */
        $statuses = (array) config('distribution.eligible_order_statuses', []);

        if ($statuses === []) {
            return false;
        }

        $query = DB::table('orders')
            ->where('id', $orderId)
            ->whereIn('status', $statuses)
            ->whereNull('deleted_at');

        return $this->excludePostponed($query)->exists();
    }
}
