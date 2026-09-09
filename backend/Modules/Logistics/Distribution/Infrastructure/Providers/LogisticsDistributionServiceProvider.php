<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Infrastructure\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Commerce\Orders\Domain\Events\OrderGeographyChanged;
use Modules\Logistics\Distribution\Application\Listeners\CloseWaveDistributionGroupsListener;
use Modules\Logistics\Distribution\Application\Listeners\CloseWaveLoadingCustodyListener;
use Modules\Logistics\Distribution\Application\Listeners\ReleaseOrderOnRetryableOutcomeListener;
use Modules\Logistics\Distribution\Application\Listeners\SettleDriverTripMovementsOnTripSettled;
use Modules\Logistics\Distribution\Application\Listeners\StartWaveDistributionGroupsListener;
use Modules\Logistics\Distribution\Application\Listeners\SyncOrderGeographyListener;
use Modules\Logistics\Distribution\Domain\Events\DeliveryStopCompleted;
use Modules\Logistics\Distribution\Domain\Events\TripSettled;
use Modules\Operations\Preparation\Domain\Events\WaveClosed;
use Modules\Operations\Preparation\Domain\Events\WavePreparationStarted;
use Modules\Operations\Preparation\Domain\Events\WaveStarted;

final class LogisticsDistributionServiceProvider extends ServiceProvider
{
    /**
     * Cross-module reactions this module subscribes to.
     *
     * Registered here, in the SUBSCRIBER's provider, following the pattern
     * Modules\Logistics\Automation already uses. The direction matters: Orders
     * announces a fact about its own aggregate and knows nothing about
     * Distribution, and Distribution decides what that fact means for its own
     * rows. Wiring it the other way — Orders calling a Distribution service —
     * would put Distribution's assignment rules inside the Orders module.
     *
     * `Event::listen` is correct for these: Orders dispatches with the standard
     * `event(new ...)` helper, so the framework dispatcher delivers them. (The
     * EnterpriseEventBus caveat applies to Inventory domain events, which are
     * published through their own bus and are not what is subscribed to here.)
     *
     * A value may be a listener class (its `handle()` is invoked) or a
     * [class, method] pair when one listener answers more than one event.
     *
     * @var array<class-string, class-string|array{0: class-string, 1: string}>
     */
    private const LISTENERS = [
        OrderGeographyChanged::class => SyncOrderGeographyListener::class,
        /*
         * TASK-003 / TASK-FINAL-SYNC §GAP-1 — the Wave lifecycle owns WHEN;
         * Distribution owns WHAT IT MEANS.
         *
         * Preparation announces a Wave start along TWO paths, and Distribution's reaction
         * is the same for both, so both funnel into ONE listener:
         *   WaveStarted            -> handle()                  (manual StartPreparationAction)
         *   WavePreparationStarted -> handlePreparationStarted() (automated Wave Engine)
         * Subscribing here is what keeps Distribution from polling and guessing which Wave
         * is active. All three events are dispatched with the standard `event(new ...)`
         * helper, so the note above about Event::listen applies to them too — Orders already
         * subscribes to this same set.
         */
        WaveStarted::class => StartWaveDistributionGroupsListener::class,
        WavePreparationStarted::class => [StartWaveDistributionGroupsListener::class, 'handlePreparationStarted'],
        WaveClosed::class => CloseWaveDistributionGroupsListener::class,

        /*
         * TASK-OPERATIONS-DRIVER-TRIP-MOVEMENT-APPROVAL-001 §19 — settlement finalization is the
         * canonical closing boundary at which a trip's APPROVED driver movements become Settled.
         * TripSettled is dispatched with the standard helper, so Event::listen delivers it.
         */
        TripSettled::class => SettleDriverTripMovementsOnTripSettled::class,

        /*
         * TASK-ECOS-POST-DRIVER-RETURN-WAREHOUSE-RETURNS-FINAL-IMPLEMENTATION-002 §3/§4 — a
         * canonically-closed, retryable delivery outcome (No Answer / Postponed) releases the
         * stale Trip/Order association and bridges into Fulfillment, immediately — no wait for
         * physical goods return. DeliveryStopCompleted is dispatched with the standard helper
         * (DeliveryService::completeStop(), same module) so Event::listen delivers it.
         */
        DeliveryStopCompleted::class => ReleaseOrderOnRetryableOutcomeListener::class,
    ];

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        foreach (self::LISTENERS as $event => $listener) {
            Event::listen($event, $listener);
        }

        // TASK-ECOS-OPERATIONS-DISTRIBUTION-AND-LOADING-FINAL-022 §E/§F — a SECOND
        // listener on WaveClosed. Registered directly rather than added to the
        // LISTENERS map above: that map is `array<event, ONE listener>`, and
        // WaveClosed already has an entry (CloseWaveDistributionGroupsListener).
        // Laravel itself supports any number of listeners per event; only this
        // file's own convenience-map shape does not, so this one exception is
        // registered explicitly instead of forcing the map into a second shape
        // for its only user.
        Event::listen(WaveClosed::class, CloseWaveLoadingCustodyListener::class);
    }
}
