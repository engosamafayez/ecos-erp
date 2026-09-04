<?php

declare(strict_types=1);

namespace Modules\Finance\Integration\Application\Listeners;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Finance\Integration\Domain\Services\CommercialAccountingService;
use Modules\Operations\Fulfillment\Domain\Events\OrderDeliveredEvent;
use Throwable;

/**
 * Finance's own subscriber to the canonical commercial Delivered signal
 * (TASK-ECOS-FINANCE-COMMERCIAL-ACCOUNTING-006).
 *
 * ┌─ A SECOND LISTENER, NOT A REPLACEMENT ──────────────────────────────────┐
 * │ Modules\Operations\Fulfillment\Application\Listeners\HandleOrderDelivered │
 * │ already listens to this same event (audit log + BAE analytics publish) —  │
 * │ Laravel supports multiple listeners per event, so this is purely          │
 * │ additive; Fulfillment's own listener and its module are untouched.        │
 * │                                                                            │
 * │ Fires synchronously, after the order's own DB transaction commits         │
 * │ (FulfillmentEngine::run() dispatches events only post-commit) — so this    │
 * │ never runs inside, and can never roll back, that transaction. Revenue and │
 * │ COGS are each wrapped in their OWN try/catch, mirroring                   │
 * │ HandleOrderDelivered's exact per-effect style: a revenue failure must not  │
 * │ block attempting COGS, and neither may ever throw back into the delivery  │
 * │ itself (TASK §30/§31).                                                    │
 * │                                                                            │
 * │ customer_id is read as a plain column (not an Eloquent import of          │
 * │ Modules\Commerce\Orders\Domain\Models\Order) because OrderDeliveredEvent   │
 * │ does not carry it — the narrowest read that gets the one missing field.   │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class PostRevenueAndCogsOnOrderDelivered
{
    public function __construct(private readonly CommercialAccountingService $accounting) {}

    public function handle(OrderDeliveredEvent $event): void
    {
        $order = DB::table('orders')->where('id', $event->orderId)->first(['customer_id', 'tax_total']);

        if ($order === null) {
            Log::channel('daily')->error('[PostRevenueAndCogsOnOrderDelivered] Order not found', [
                'order_id' => $event->orderId,
            ]);

            return;
        }

        $actorId = $this->intOrNull($event->actorId);
        $deliveredAt = Carbon::parse($event->deliveredAt);
        $taxTotal = round((float) ($order->tax_total ?? 0.0), 4);

        try {
            $this->accounting->recognizeRevenue(
                companyId: $event->companyId,
                orderId: $event->orderId,
                orderNumber: $event->orderNumber,
                customerId: (string) $order->customer_id,
                grossRevenue: $event->revenue,
                taxTotal: $taxTotal,
                recognizedAt: $deliveredAt,
                actorId: $actorId,
            );
        } catch (Throwable $e) {
            Log::channel('daily')->error('[PostRevenueAndCogsOnOrderDelivered] Revenue recognition failed', [
                'order_id' => $event->orderId,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $this->accounting->recognizeCogs(
                companyId: $event->companyId,
                orderId: $event->orderId,
                orderNumber: $event->orderNumber,
                cogsAmount: $event->cogsAmount,
                recognizedAt: $deliveredAt,
                actorId: $actorId,
            );
        } catch (Throwable $e) {
            Log::channel('daily')->error('[PostRevenueAndCogsOnOrderDelivered] COGS recognition failed', [
                'order_id' => $event->orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function intOrNull(?string $value): ?int
    {
        return $value !== null && is_numeric($value) ? (int) $value : null;
    }
}
