<?php

declare(strict_types=1);

namespace Modules\Commerce\Synchronization\Domain\Enums;

enum SyncEntityType: string
{
    case Product = 'product';
    case Inventory = 'inventory';
    case Order = 'order';
    case Customer = 'customer';
    case Price = 'price';
    // TASK-...-WOO-05 — webhook REGISTRATION lifecycle (register/deregister/re-register),
    // distinct from any of the above: it manages the channel's own webhook subscriptions,
    // not a Product/Order/Customer/Price sync event.
    case Webhook = 'webhook';
}
