<?php

declare(strict_types=1);

namespace Modules\POS\Application\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\POS\Application\Contracts\AccountingPortInterface;
use Modules\POS\Application\Events\SaleFinalized;

/**
 * Subscriber 3 — Accounting
 *
 * Records the completed POS sale in the accounting system.
 *
 * Responsibilities:
 *   - Create accounting journal entry for the sale
 *   - Register payment method breakdown (cash / card / etc.)
 *   - Register cash movement for cash tenders
 *   - Generate accounting events for the finance team
 *
 * Implementation:
 *   - Delegates to AccountingPortInterface (port/adapter pattern).
 *   - Current adapter: NullAccountingAdapter (no Accounting module yet).
 *   - When Accounting module is built, swap adapter in EventServiceProvider.
 *
 * Safety: NEVER throws — the sale is already committed and must not be affected.
 */
final class PosAccountingListener
{
    public function __construct(
        private readonly AccountingPortInterface $accounting,
    ) {}

    public function handle(SaleFinalized $event): void
    {
        try {
            $this->accounting->recordSale($event);
        } catch (\Throwable $e) {
            Log::channel('daily')->error('[POS][Accounting] Failed to record sale in accounting system', [
                'sale_id'        => $event->saleId,
                'receipt_number' => $event->receiptNumber,
                'error'          => $e->getMessage(),
                'trace'          => $e->getTraceAsString(),
            ]);
        }
    }
}
