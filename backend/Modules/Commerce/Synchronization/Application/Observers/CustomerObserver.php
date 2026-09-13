<?php

declare(strict_types=1);

namespace Modules\Commerce\Synchronization\Application\Observers;

use Modules\Commerce\Channels\Domain\Enums\ChannelLifecycleState;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\Synchronization\Application\Jobs\CustomerSyncJob;
use Modules\Crm\Customers\Domain\Models\Customer as CrmCustomer;
use Modules\Sales\Customers\Domain\Models\Customer as SalesCustomer;

/**
 * TASK-ECOS-V1.1-WOO-02-TENANT-SAFE-CUSTOMER-IDENTITY-044 §7 — registered
 * ADDITIVELY against both Customer classes (see SynchronizationServiceProvider),
 * so outbound Woo sync now also fires for writes made through the canonical Crm
 * class — previously it only fired for the legacy Sales class, so a CRM-authored
 * create/update never dispatched a CustomerSyncJob at all.
 *
 * One observer, not two: CustomerSyncJob itself is untouched and stays strictly
 * typed to the Sales class — its buildPayload()/resolveWooCommerceCustomerId()
 * logic reads plain column data, unrelated to which Eloquent class triggered the
 * write. A Crm-typed instance is re-resolved to the Sales-typed view of the SAME
 * physical row (one `customers` table — see CustomerAuthorityConsolidationTest)
 * before the job is constructed, rather than widening the job's own constructor
 * or building a second observer/job implementation.
 */
final class CustomerObserver
{
    public function created(CrmCustomer|SalesCustomer $customer): void
    {
        $this->dispatch($customer);
    }

    public function updated(CrmCustomer|SalesCustomer $customer): void
    {
        $this->dispatch($customer);
    }

    private function dispatch(CrmCustomer|SalesCustomer $customer): void
    {
        $salesCustomer = $customer instanceof SalesCustomer
            ? $customer
            : SalesCustomer::query()->find($customer->id);

        // Re-resolution found nothing (row gone between the event and here) — no
        // customer left to sync.
        if ($salesCustomer === null) {
            return;
        }

        Channel::query()
            ->with('credential')
            ->where('is_active', true)
            ->where('sync_customers', true)
            // TASK-...-WOO-04 — a channel not yet Live stays inert regardless of either flag
            // above (042A-R1 §5); see Channel::isLive()'s own docblock.
            ->where('lifecycle_state', ChannelLifecycleState::Live->value)
            ->get()
            ->each(function (Channel $channel) use ($salesCustomer): void {
                CustomerSyncJob::dispatch($channel, $salesCustomer);
            });
    }
}
