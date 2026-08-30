<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Application\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\Commerce\Orders\Domain\Events\OrderGeographyChanged;
use Modules\Logistics\Distribution\Domain\Exceptions\DistributionException;
use Modules\Logistics\Distribution\Domain\Models\DistributionWindowOrder;
use Modules\Logistics\Distribution\Domain\Services\ManualAssignmentService;
use Modules\Logistics\Distribution\Domain\Services\OrderZoneResolver;
use Modules\Logistics\Geography\Domain\Services\OrderCityBinder;
use Throwable;

/**
 * Keeps a Distribution assignment's Zone true to its Order's CURRENT address.
 *
 * ┌─ THE CHAIN, AND WHO OWNS EACH LINK ──────────────────────────────────────┐
 * │ Order address   Orders      — announces the change, nothing more          │
 * │ city text → id  Geography   — OrderCityBinder::rebindOrder()               │
 * │ city id → zone  Distribution— OrderZoneResolver                            │
 * │ zone → assignment Distribution — ManualAssignmentService::changeOrderZone() │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * THIS LISTENER IS THE BOUNDARY, and it exists so that Orders is not. Orders
 * dispatches a fact about its own aggregate and references no other module;
 * everything downstream is decided here, by the module that owns the assignment.
 * No new resolver, no new store, and no second definition of "which zone" — each
 * step calls the service that already owns it.
 *
 * WHY NOT reconcileUnzoned(). That path is deliberately NULL-zone-only — *"an
 * Order that already has a Zone is never moved, including one a manager moved by
 * hand"* — so it cannot repair the very case this handles: an Order that HAS a
 * zone which its new address no longer justifies. Using it here would either
 * silently do nothing or force that contract to be relaxed. `changeOrderZone()`
 * is the approved path for moving an Order's zone, and it is the one used.
 *
 * WHY THE MOVE IS STAMPED `manual_move`. `DistributionAssignmentSource` has three
 * cases and none of them means "the address changed"; inventing a fourth would
 * change an approved enum. The change IS operator-initiated, so `manual_move` is
 * the truthful choice among what exists, and the `reason` string carries the
 * detail an auditor actually needs.
 *
 * NEVER FAILS THE OPERATOR'S EDIT. The address change is already committed by the
 * time this runs. A Distribution problem — a closed window refusing manual work,
 * a Group at capacity — must not roll back or appear to reject an edit that
 * succeeded, so it is logged and swallowed. The workspace then shows the Order
 * with its true city and, if the re-zone could not be applied, its old zone plus
 * the reason in the log. Silently corrupting the address to keep a zone would be
 * the worse trade.
 */
final class SyncOrderGeographyListener
{
    public function __construct(
        private readonly OrderCityBinder $cities,
        private readonly OrderZoneResolver $zones,
        private readonly ManualAssignmentService $manual,
    ) {}

    public function handle(OrderGeographyChanged $event): void
    {
        // 1. GEOGRAPHY re-resolves the city. It owns `logistics_city_id`, and it
        //    is asked rather than bypassed. An unresolvable city clears the id.
        $resolution = $this->cities->rebindOrder($event->orderId, $event->companyId);

        // 2. The Zone the NEW city implies. Null is a legitimate answer: the city
        //    may be unknown, or known but not attached to any zone. The resolver
        //    already returns null for a null city, so it is passed straight through.
        $zoneId = $this->zones->resolve($resolution['city_id']);

        // 3. Only Distribution's own row is touched, and only if there is one.
        //    An Order that was never collected has nothing to re-zone; it will be
        //    stamped correctly the first time it is, from the city just written.
        $assignment = DistributionWindowOrder::query()
            ->where('order_id', $event->orderId)
            ->where('company_id', $event->companyId)
            ->first();

        if ($assignment === null) {
            return;
        }

        $currentZoneId = $assignment->distribution_zone_id === null
            ? null
            : (int) $assignment->distribution_zone_id;

        if ($currentZoneId === $zoneId) {
            // Already correct — re-stamping would invent an audit entry for a
            // change that did not happen, and would rewrite assignment_source for
            // nothing. Null-safe on purpose: null === null is the "both unzoned"
            // case and must also short-circuit.
            return;
        }

        try {
            $this->manual->changeOrderZone(
                $assignment,
                $zoneId,
                $event->actorId,
                $this->reason($event, $zoneId),
            );
        } catch (DistributionException|Throwable $e) {
            Log::channel('daily')->warning('[DistributionGeographySync] re-zone not applied', [
                'order_id' => $event->orderId,
                'city' => $event->city,
                'resolved_city_id' => $resolution['city_id'],
                'resolved_zone_id' => $zoneId,
                'reason' => $resolution['reason'],
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** A sentence an auditor can read without joining three tables. */
    private function reason(OrderGeographyChanged $event, ?int $zoneId): string
    {
        $from = $event->previousCity ?? '—';
        $to = $event->city ?? '—';

        return $zoneId === null
            ? "City changed from [{$from}] to [{$to}]; no zone resolves for the new city."
            : "City changed from [{$from}] to [{$to}]; zone re-resolved.";
    }
}
