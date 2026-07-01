<?php

declare(strict_types=1);

namespace Modules\POS\Application\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\POS\Application\Contracts\OrderCreationPortInterface;
use Modules\POS\Application\Contracts\StockIssuePortInterface;
use Modules\POS\Application\Infrastructure\Adapters\CommerceOrderCreationAdapter;
use Modules\POS\Application\Infrastructure\Adapters\DirectStockIssueAdapter;
use Modules\POS\Application\Listeners\PosSaleInventoryListener;
use Modules\POS\Application\Listeners\PosSaleOrderListener;
use Modules\POS\Sale\Domain\Events\SaleCompleted;

/**
 * Registers all POS domain-event listeners.
 *
 * Pattern mirrors Modules\Inventory\DomainEvents\Infrastructure\Providers\DomainEventServiceProvider.
 * One listener per consuming module (ADR-006 §Listener Strategy).
 *
 * Port → Adapter bindings follow the hexagonal-architecture contract:
 *   StockIssuePortInterface     → DirectStockIssueAdapter     (wraps DirectIssueStockAction)
 *   OrderCreationPortInterface  → CommerceOrderCreationAdapter (wraps CreateOrderAction)
 *
 * SaleCompleted listeners (both synchronous — Phase B will add queue dispatch):
 *   PosSaleInventoryListener  — CRIT-003: decrements stock via StockIssuePortInterface
 *   PosSaleOrderListener      — CRIT-004: creates ERP order via OrderCreationPortInterface
 */
final class EventServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(StockIssuePortInterface::class, DirectStockIssueAdapter::class);
        $this->app->bind(OrderCreationPortInterface::class, CommerceOrderCreationAdapter::class);
    }

    public function boot(): void
    {
        $events = $this->app->make('events');

        $events->listen(SaleCompleted::class, PosSaleInventoryListener::class);
        $events->listen(SaleCompleted::class, PosSaleOrderListener::class);
    }
}
