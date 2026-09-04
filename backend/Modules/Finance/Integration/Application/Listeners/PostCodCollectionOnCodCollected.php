<?php

declare(strict_types=1);

namespace Modules\Finance\Integration\Application\Listeners;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Finance\Integration\Domain\Services\CommercialAccountingService;
use Modules\Logistics\Delivery\Domain\Events\CodCollected;
use Throwable;

/**
 * Finance's subscriber to Logistics/Delivery's COD-collected signal
 * (TASK-ECOS-FINANCE-COMMERCIAL-ACCOUNTING-006).
 *
 * CodCollected fires at the CodRecord Due → Collected transition and — per
 * this task's own research pass — currently has zero subscribers anywhere in
 * the codebase; this is the first. It settles the receivable a prior
 * Delivered event already recognised (recognizeRevenue()); it does not
 * itself decide delivery timing or revenue recognition (TASK §16/§17) — cash
 * physically collected by a driver lands in a clearing account, not bank,
 * because it has not yet been banked (Task 7's own reconciliation).
 *
 * Dispatched after (not inside) SettlementService's local DB::transaction —
 * safe to act on immediately. company_id/customer_id are read as plain
 * columns (not an Eloquent Order import) for the same reason as the sibling
 * Delivered listener: CodCollected's payload does not carry them.
 */
final class PostCodCollectionOnCodCollected
{
    public function __construct(private readonly CommercialAccountingService $accounting) {}

    public function handle(CodCollected $event): void
    {
        $orderId = (string) $event->delivery->order_id;
        $order = DB::table('orders')->where('id', $orderId)->first(['customer_id', 'company_id']);

        if ($order === null) {
            Log::channel('daily')->error('[PostCodCollectionOnCodCollected] Order not found', [
                'order_id' => $orderId,
            ]);

            return;
        }

        try {
            $this->accounting->recognizeCodCollection(
                companyId: (string) $order->company_id,
                orderId: $orderId,
                customerId: (string) $order->customer_id,
                codRecordId: (string) $event->cod->uuid,
                amountCollected: (float) $event->cod->amount_collected,
                collectedAt: $event->cod->collected_at !== null ? Carbon::parse($event->cod->collected_at) : Carbon::now(),
                actorId: $event->actor !== null && is_numeric($event->actor) ? (int) $event->actor : null,
            );
        } catch (Throwable $e) {
            Log::channel('daily')->error('[PostCodCollectionOnCodCollected] COD settlement failed', [
                'order_id' => $orderId,
                'cod_record_id' => $event->cod->uuid ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
