<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Commerce\Orders\Application\Actions\ResolveProductPricingAction;
use Modules\Commerce\Orders\Application\Listeners\ExecuteReservationOnWarehouseAssigned;
use Modules\Commerce\Orders\Application\Listeners\HandlePreparationWaveCancelled;
use Modules\Commerce\Orders\Application\Listeners\HandlePreparationWaveClosed;
use Modules\Commerce\Orders\Application\Listeners\HandlePreparationWaveCompleted;
use Modules\Commerce\Orders\Application\Listeners\HandlePreparationWavePreparationStarted;
use Modules\Commerce\Orders\Application\Listeners\HandlePreparationWaveStarted;
use Modules\Commerce\Orders\Application\Listeners\ProjectDeliveredQuantityFromAllocation;
use Modules\Commerce\Orders\Application\Listeners\ProjectReturnedQuantityFromCustomerReturn;
use Modules\Commerce\Orders\Application\Listeners\RetryReservationOnStockAvailableListener;
use Modules\Commerce\Orders\Application\Services\CreateOrderSnapshotService;
use Modules\Commerce\Orders\Domain\Contracts\OrderRepositoryInterface;
use Modules\Commerce\Orders\Infrastructure\Console\Commands\ActivateScheduledOrdersCommand;
use Modules\Commerce\Orders\Infrastructure\Console\Commands\ReprocessLegacyReservationsCommand;
use Modules\Commerce\Orders\Infrastructure\Repositories\EloquentOrderRepository;
use Modules\Common\Snapshots\Application\Services\SnapshotManager;
use Modules\Common\Snapshots\Domain\Engine\IntegrityEngine;
use Modules\CostManagement\Application\Services\CostCalculationEngine;
use Modules\Inventory\DomainEvents\Events\InventoryStockAdjusted;
use Modules\Inventory\DomainEvents\Events\InventoryStockReceived;
use Modules\Inventory\DomainEvents\Events\InventoryStockReleased;
use Modules\Inventory\DomainEvents\Events\ProductNegativeStockEnabled;
use Modules\Operations\Fulfillment\Domain\Events\OrderReturnedEvent;
use Modules\Operations\Loading\Domain\Events\ProductDeliveryRecorded;
use Modules\Operations\Preparation\Domain\Events\WarehouseAssigned;
use Modules\Operations\Preparation\Domain\Events\WaveCancelled;
use Modules\Operations\Preparation\Domain\Events\WaveClosed;
use Modules\Operations\Preparation\Domain\Events\WaveCompleted;
use Modules\Operations\Preparation\Domain\Events\WavePreparationStarted;
use Modules\Operations\Preparation\Domain\Events\WaveStarted;

final class OrderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OrderRepositoryInterface::class, EloquentOrderRepository::class);

        $this->app->singleton(CreateOrderSnapshotService::class, static function ($app) {
            return new CreateOrderSnapshotService(
                $app->make(CostCalculationEngine::class),
                $app->make(ResolveProductPricingAction::class),
                $app->make(SnapshotManager::class),
                $app->make(IntegrityEngine::class),
            );
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->commands([
            ActivateScheduledOrdersCommand::class,
            ReprocessLegacyReservationsCommand::class,
        ]);

        $events = $this->app->make('events');
        $events->listen(WaveStarted::class, HandlePreparationWaveStarted::class);
        $events->listen(WavePreparationStarted::class, HandlePreparationWavePreparationStarted::class);
        $events->listen(WaveCompleted::class, HandlePreparationWaveCompleted::class);
        $events->listen(WaveCancelled::class, HandlePreparationWaveCancelled::class);
        // Wave END — the engine's terminal event. WaveClosed is what the engine actually
        // publishes (WaveCompleted comes only from the manual CompleteWaveAction), and
        // until now nothing on the order side listened for it: a wave could end with no
        // consequence whatsoever for the orders it held. This is the carry-over decision.
        $events->listen(WaveClosed::class, HandlePreparationWaveClosed::class);
        // §7/§8 — every canonical Inventory event that RAISES availability re-evaluates
        // the orders it could unblock. These are the existing Inventory domain events on
        // the existing bus; nothing new is published. Release matters as much as receipt:
        // where all physical stock is already reserved, a release is the only thing that
        // can ever free a blocked order.
        $events->listen(
            InventoryStockReceived::class,
            [RetryReservationOnStockAvailableListener::class, 'handleStockReceived'],
        );
        $events->listen(
            InventoryStockReleased::class,
            [RetryReservationOnStockAvailableListener::class, 'handleStockReleased'],
        );
        $events->listen(
            InventoryStockAdjusted::class,
            [RetryReservationOnStockAvailableListener::class, 'handleStockAdjusted'],
        );
        // TASK-ORDER-PREPARATION-FULFILLABILITY-CONTRACT-001 §6B — turning Allow
        // Negative Stock ON can make a blocked preparation recipe executable, so the
        // same recovery re-opens the affected Awaiting Stock orders (company-wide, since
        // the policy is not warehouse-scoped). Reuses the existing retry listener; no
        // new recovery engine. There is deliberately NO can_manufacture recovery — that
        // flag no longer gates order fulfilment (ADR-027 §16 v1.5).
        $events->listen(
            ProductNegativeStockEnabled::class,
            [RetryReservationOnStockAvailableListener::class, 'handleNegativeStockEnabled'],
        );
        // NOT SUBSCRIBED: InventoryTransferred and WarehouseTransferCompleted.
        //
        // ADR-026 (committed, Status: Accepted) declares the transfer events deliberate
        // orphans in Phase A — "an approved architectural state, not a defect" — and its
        // Rationale-3 specifically forbids the synchronous coupling a listener here would
        // create. `OperationsIntegrationFinalCertTest::scenario_d_*_has_no_registered_listener`
        // is the committed guard for that boundary, and it has caught this exact breach
        // before (see the note at EventPlatformServiceProvider.php:171-175, recorded after
        // EPIC-FIN-INTEGRATION-004 crossed it).
        //
        // ADR-027 authorises reservation retry on WarehouseAssigned (H3) and on stock
        // receipt (M6) ONLY; neither it nor its pending amendments mention transfers.
        // Subscribing to them therefore requires a new ADR superseding ADR-026, not a
        // listener registration. The handlers remain on the listener, unreferenced, so
        // that such an ADR could enable them without rewriting the recovery logic.
        // ADR-027 §15 H3 — resume a reservation whose execution was postponed for a
        // missing warehouse. Subscribes to the CANONICAL WarehouseAssigned event.
        $events->listen(WarehouseAssigned::class, ExecuteReservationOnWarehouseAssigned::class);

        // TASK-DRIVER-04 (A) — project the canonical delivered quantity onto the order
        // line. allocation_records.quantity_delivered (ADR-015, sole writer
        // RecordProductDeliveryAction) is the source of truth; this re-derives
        // order_lines.delivered_qty := Σ quantity_delivered for the line. Order-side
        // reaction to an Operations delivery event, same shape as the wave/stock
        // listeners above. Writes only delivered_qty — never status/ledger/custody.
        $events->listen(ProductDeliveryRecorded::class, ProjectDeliveredQuantityFromAllocation::class);

        // TASK-OPERATIONAL-FULFILLMENT-RETURNS-RECONCILIATION-001 (§16) — project the
        // canonical returned quantity onto the order line. customer_return_lines.quantity_returned
        // (sole writer ReturnOrderWorkflow) is the source of truth; this re-derives
        // order_lines.returned_qty := Σ quantity_returned for the line. Single authority —
        // no Driver/Warehouse/Reconciliation/Delivery path writes returned_qty. Writes only
        // returned_qty — never status/ledger/custody. Same shape as the delivered projection.
        $events->listen(OrderReturnedEvent::class, ProjectReturnedQuantityFromCustomerReturn::class);
    }
}
