<?php

declare(strict_types=1);

namespace Modules\Commerce\Synchronization\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Synchronization\Application\Commands\AuditWooCustomerLinkageCommand;
use Modules\Commerce\Synchronization\Application\Commands\RegisterWebhooksCommand;
use Modules\Commerce\Synchronization\Application\Observers\CustomerObserver;
use Modules\Commerce\Synchronization\Application\Observers\OrderObserver;
use Modules\Commerce\Synchronization\Application\Observers\ProductObserver;
use Modules\Commerce\Synchronization\Domain\Contracts\SyncLogRepositoryInterface;
use Modules\Commerce\Synchronization\Infrastructure\Repositories\EloquentSyncLogRepository;
use Modules\Crm\Customers\Domain\Models\Customer as CrmCustomer;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Sales\Customers\Domain\Models\Customer;

final class SynchronizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SyncLogRepositoryInterface::class, EloquentSyncLogRepository::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->commands([
            RegisterWebhooksCommand::class,
            AuditWooCustomerLinkageCommand::class,
        ]);

        Product::observe(ProductObserver::class);
        // TASK-...-CONSOLIDATED-REMEDIATION-001 §3/§5 — StockMovementObserver deleted: its
        // sole purpose was an independent Woo stock-dispatch trigger reading the legacy,
        // unreconciled StockBalance table. ChannelSynchronizationService (fed by
        // ReceiveStockAction/ShipStockAction/DirectIssueStockAction's domain events via the
        // canonical InventoryItem ledger) is now the one authority for that dispatch.
        Customer::observe(CustomerObserver::class);
        // TASK-...-044 §7 — ADDITIVE: the legacy Sales binding above is unchanged;
        // this registers the SAME observer against the canonical Crm class too, so
        // outbound Woo sync also fires for CRM-authored customer writes (see
        // CustomerObserver's own docblock for why one observer safely covers both).
        CrmCustomer::observe(CustomerObserver::class);
        Order::observe(OrderObserver::class);
    }
}
