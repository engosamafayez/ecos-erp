<?php

declare(strict_types=1);

use Modules\Commerce\Orders\Domain\Enums\OrderStatus;

/**
 * Shipping Distribution Core — TASK-SHIPPING-DISTRIBUTION-CORE-001.
 *
 * Business times are configuration, never literals in code. The values below are
 * defaults so the module boots; the operating times are set per deployment.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Daily Distribution Window
    |--------------------------------------------------------------------------
    |
    | `opens_at` / `closes_at` are wall-clock times in the application timezone.
    | `closes_at` is the CUTOFF: it stops automatic ingestion only. It does not
    | freeze the plan — see DistributionWindowStatus.
    |
    */
    'window' => [
        'opens_at' => env('DISTRIBUTION_WINDOW_OPENS_AT', '00:00'),
        'closes_at' => env('DISTRIBUTION_WINDOW_CLOSES_AT', '23:59'),

        // Create the next day's Window automatically so post-cutoff Orders always
        // have somewhere to land. Without this, a late Order has no destination.
        'auto_create_next' => env('DISTRIBUTION_WINDOW_AUTO_CREATE_NEXT', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Eligible Order statuses
    |--------------------------------------------------------------------------
    |
    | A CLOSED list, agreeing with the contract established for Preparation in
    | PreparationSessionPolicy::defaultEligibleStatuses(). An unknown or future
    | status is NOT eligible.
    |
    | ADR-042 §7 — the two fulfilment-eligible states are `in_progress` and
    | `confirmed`. The earlier note here claimed confirmation was "carried by the
    | orders.confirmed_at timestamp"; that was never true — nothing wrote that
    | column. ADR-042 makes Confirm an explicit action with a real state behind it.
    |
    | `scheduled` and `awaiting_payment` are NOT eligible: they wait for their own
    | business triggers, which move them to `in_progress`.
    |
    | Deliberately declared here rather than imported from Preparation:
    | Distribution must not depend on the Preparation module, and eligibility is
    | required to be an explicit Distribution contract. The values are sourced from
    | the enum so a future status rename cannot silently empty this list.
    |
    */
    'eligible_order_statuses' => array_map(
        static fn (OrderStatus $s): string => $s->value,
        OrderStatus::fulfilmentEligible(),
    ),

    /*
    |--------------------------------------------------------------------------
    | Loading-eligible Order statuses — the OPERATIONAL read predicate
    |--------------------------------------------------------------------------
    |
    | A SECOND, WIDER list, used ONLY by the Group / Loading Preparation reads.
    | It answers a different question from the one above:
    |
    |   eligible_order_statuses         — "may this Order ENTER distribution
    |                                      planning?"  (ingestion + triage)
    |   loading_eligible_order_statuses — "is this Order part of the CURRENT
    |                                      operational departure?"  (Group reads)
    |
    | WHY THE TWO DIFFER. Starting a preparation wave runs MoveToPreparationWorkflow
    | for every Order in it, which sets `ready_for_dispatch` — the status whose own
    | meaning, stated in HandlePreparationWaveClosed, is "done, waiting to be
    | loaded". That is precisely the population Loading Preparation exists to serve,
    | and the narrow list above excludes it, so a Group emptied itself the moment
    | its work became preparable.
    |
    | THIS LIST IS NOT A REPLACEMENT. `eligible_order_statuses` is deliberately left
    | untouched, because it also gates two WRITE paths — DistributionCollectionService
    | (creates window membership) and OrderCityBinder (writes orders.logistics_city_id)
    | — plus the late-order triage read. Widening it would change ingestion semantics
    | for every consumer at once. See TASK-...-LP2-DECISION-001-REPORT.md §5.
    |
    | Sourced from the enum for the same reason as the list above: a future status
    | rename cannot silently empty it. Nothing beyond `ready_for_dispatch` is added —
    | `out_for_delivery` and `delivered` are past loading, not awaiting it.
    |
    */
    'loading_eligible_order_statuses' => array_values(array_unique(array_merge(
        array_map(
            static fn (OrderStatus $s): string => $s->value,
            OrderStatus::fulfilmentEligible(),
        ),
        [OrderStatus::ReadyForDispatch->value],
    ))),

    /*
    |--------------------------------------------------------------------------
    | Virtual Capacity Slot defaults
    |--------------------------------------------------------------------------
    |
    | Dimensions mirror Modules\Logistics\Network\Domain\Models\CapacitySlot.
    | A null dimension means "not constrained on this axis" — which is not the
    | same as a capacity of zero.
    |
    */
    'slot' => [
        'default_capacity_orders' => env('DISTRIBUTION_SLOT_DEFAULT_ORDERS'),
        'default_capacity_stops' => env('DISTRIBUTION_SLOT_DEFAULT_STOPS'),
        'default_capacity_weight_kg' => env('DISTRIBUTION_SLOT_DEFAULT_WEIGHT_KG'),
        'default_capacity_volume_m3' => env('DISTRIBUTION_SLOT_DEFAULT_VOLUME_M3'),

        // Utilisation ratio at which a Slot is reported as warning-level.
        'warn_threshold' => (float) env('DISTRIBUTION_SLOT_WARN_THRESHOLD', 0.85),
    ],

    /*
    |--------------------------------------------------------------------------
    | Redistribution suggestions
    |--------------------------------------------------------------------------
    |
    | Suggestions are advisory. They never mutate an assignment (§13, §17 of the
    | brief); a manager approves them explicitly.
    |
    */
    'redistribution' => [
        'max_suggestions_per_overflow' => (int) env('DISTRIBUTION_MAX_SUGGESTIONS', 25),
    ],
];
