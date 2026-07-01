<?php

declare(strict_types=1);

namespace Modules\POS\Application\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\Commerce\Orders\Application\DTO\OrderDTO;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\POS\Application\Contracts\OrderCreationPortInterface;
use Modules\POS\Sale\Domain\Contracts\SaleRepositoryInterface;
use Modules\POS\Sale\Domain\Events\SaleCompleted;

/**
 * CRIT-004 — Creates a standard ERP Sales Order when a POS sale completes.
 *
 * Listens to SaleCompleted (the definitive post-transaction event).
 * Reloads the Sale from the repository to obtain line items and customer_id,
 * then delegates to CreateOrderAction — the same action used by every other
 * sales channel. The POS is an entry point into the ERP, not a parallel system.
 *
 * Customer resolution:
 *   - Known customer: $sale->customer_id is used directly.
 *   - Anonymous (walk-in): falls back to config('pos.erp.guest_customer_id').
 *     If unconfigured, order creation is skipped and a warning is logged.
 *     Set POS_GUEST_CUSTOMER_ID in .env to a valid Customer UUID to enable
 *     order creation for all walk-in sales.
 *
 * The order links back to the POS sale via external_order_id = $sale->id,
 * enabling bidirectional traceability between the POS and commerce layers.
 *
 * The listener NEVER throws — the sale is already committed and must not roll back.
 * All failures are logged to the 'daily' channel for operational alerting.
 *
 * ADR-006 §Listener Strategy: one listener per consuming module, typed to the
 * concrete event class. No queue dispatch — Phase B will add async retry.
 */
final class PosSaleOrderListener
{
    public function __construct(
        private readonly SaleRepositoryInterface   $sales,
        private readonly OrderCreationPortInterface $orderCreation,
    ) {}

    public function handle(SaleCompleted $event): void
    {
        $sale = $this->sales->findById($event->saleId);

        if ($sale === null) {
            Log::channel('daily')->error('[POS][Order] Sale not found after SaleCompleted', [
                'sale_id'        => $event->saleId,
                'receipt_number' => $event->receiptNumber,
            ]);

            return;
        }

        $customerId = $sale->customer_id ?? config('pos.erp.guest_customer_id');

        if (empty($customerId)) {
            Log::channel('daily')->warning(
                '[POS][Order] No customer on sale and POS_GUEST_CUSTOMER_ID is not configured — order not created',
                [
                    'sale_id'        => $event->saleId,
                    'receipt_number' => $event->receiptNumber,
                ],
            );

            return;
        }

        $lines = array_map(
            static fn ($line): array => [
                'product_id' => $line->productId,
                'quantity'   => $line->quantity->toFloat(),
                'unit_price' => (float) $line->unitPrice->amount,
            ],
            $sale->getLines(),
        );

        $dto = new OrderDTO(
            customer_id:       (string) $customerId,
            order_date:        now()->toDateString(),
            status:            OrderStatus::Completed,
            lines:             $lines,
            channel_id:        null,
            external_order_id: (string) $sale->id,
            notes:             "POS Sale #{$event->receiptNumber}",
        );

        try {
            $result = $this->orderCreation->create($dto);

            /** @var \Modules\Commerce\Orders\Domain\Models\Order|null $order */
            $order = $result->data();

            Log::channel('daily')->info('[POS][Order] ERP order created from POS sale', [
                'sale_id'        => $event->saleId,
                'receipt_number' => $event->receiptNumber,
                'order_id'       => $order?->id,
            ]);
        } catch (\Throwable $e) {
            Log::channel('daily')->error('[POS][Order] Failed to create ERP order from POS sale', [
                'sale_id'        => $event->saleId,
                'receipt_number' => $event->receiptNumber,
                'error'          => $e->getMessage(),
                'trace'          => $e->getTraceAsString(),
            ]);
        }
    }
}
