<?php

declare(strict_types=1);

namespace Modules\Commerce\Synchronization\Application\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\Synchronization\Application\Services\SyncLogService;
use Modules\Commerce\Synchronization\Application\Services\WooOutboundCommandDispatcher;
use Modules\Commerce\Synchronization\Domain\Enums\SyncDirection;
use Modules\Commerce\Synchronization\Domain\Enums\SyncEntityType;
use Modules\Commerce\Synchronization\Domain\Enums\SyncStatus;
use Modules\Sales\Customers\Domain\Models\Customer;
use Throwable;

/**
 * TASK-...-CONSOLIDATED-REMEDIATION-001-R2-R1 §2/§3 — transport (direct Woo REST vs the paired
 * Connector plugin) is decided by WooOutboundCommandDispatcher, not here; this job only shapes the
 * ECOS-owned field payload, exactly like ProductSyncJob/PriceSyncJob/OrderStatusSyncJob.
 *
 * Previously this job called Woo's REST API directly with Http::withBasicAuth() using
 * consumer_key/consumer_secret — which are legitimately null for a Connector-mode Channel, so
 * outbound Customer sync failed for a correctly paired store. It now converges on the same
 * transport authority every other Woo outbound resource already uses: create/update is an upsert
 * keyed on the customer's email (the existing resolve-by-email linkage — ECOS stores no persistent
 * Woo customer id), and canSyncNow() eligibility is re-checked at the transport boundary so a job
 * queued before an explicit Connector disconnect cannot mutate Woo afterward.
 */
final class CustomerSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        private readonly Channel $channel,
        private readonly Customer $customer,
    ) {}

    public function handle(SyncLogService $logService, WooOutboundCommandDispatcher $dispatcher): void
    {
        $log = $logService->createLog(
            $this->channel,
            SyncEntityType::Customer,
            SyncDirection::Outbound,
            'customer.sync',
            $this->customer->id,
            SyncStatus::Processing,
            ['customer_id' => $this->customer->id, 'email' => $this->customer->email],
        );

        if ($this->channel->credential === null) {
            $logService->markFailed($log, 'No credentials configured for this channel.');

            return;
        }

        try {
            $result = $dispatcher->upsertCustomer(
                $this->channel,
                (string) ($this->customer->email ?? ''),
                $this->buildPayload(),
            );

            if ($result->ok) {
                $logService->markSuccess($log, ['wc_customer_id' => $this->extractWooCustomerId($result->data)], $this->channel);
            } else {
                $logService->markFailed($log, (string) $result->error, null, $this->channel);
            }
        } catch (Throwable $e) {
            $logService->markFailed($log, $e->getMessage(), null, $this->channel);
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(): array
    {
        $nameParts = explode(' ', trim($this->customer->name), 2);
        $firstName = $nameParts[0] ?? '';
        $lastName = $nameParts[1] ?? '';

        $billingShipping = [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'phone' => $this->customer->phone ?? $this->customer->mobile ?? '',
            'email' => $this->customer->email ?? '',
            'address_1' => $this->customer->address ?? '',
            'city' => $this->customer->city ?? '',
            'country' => $this->customer->country ?? '',
        ];

        return [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $this->customer->email ?? '',
            'billing' => $billingShipping,
            'shipping' => $billingShipping,
        ];
    }

    /**
     * The created/updated Woo customer id, read defensively from either transport's result shape:
     * the legacy direct-REST path returns Woo's customer object at the top level, while the
     * Connector plugin wraps its applied result under a `data` key. Preserves the prior job's
     * behavior of recording the resulting Woo customer id on a successful sync.
     *
     * @param  array<string, mixed>  $data
     */
    private function extractWooCustomerId(array $data): int|string|null
    {
        if (isset($data['id'])) {
            return $data['id'];
        }

        if (isset($data['data']) && is_array($data['data']) && isset($data['data']['id'])) {
            return $data['data']['id'];
        }

        return null;
    }
}
