<?php

declare(strict_types=1);

namespace Modules\POS\Application\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\Inventory\InventoryItems\Application\DTO\StockOperationDTO;
use Modules\Inventory\InventoryItems\Domain\Exceptions\InsufficientStockException;
use Modules\MasterData\Warehouses\Domain\Contracts\WarehouseRepositoryInterface;
use Modules\POS\Application\Contracts\StockIssuePortInterface;
use Modules\POS\Sale\Domain\Contracts\SaleRepositoryInterface;
use Modules\POS\Sale\Domain\Events\SaleCompleted;
use Modules\POS\Terminal\Domain\Contracts\TerminalRepositoryInterface;

/**
 * CRIT-003 — Decrements inventory for every line item when a POS sale completes.
 *
 * Listens to SaleCompleted (the definitive post-transaction event).
 * Reloads the Sale from the repository to obtain line items and terminal_id,
 * then delegates each line to DirectIssueStockAction.
 *
 * Negative-stock behaviour is governed by pos.inventory.allow_negative_stock:
 *   false (default) — logs an error and skips the decrement.
 *   true            — logs a warning and skips the decrement (stock goes implicit negative).
 *
 * The listener NEVER throws — the sale is already committed and must not roll back.
 * All failures are logged to the 'daily' channel for operational alerting.
 *
 * ADR-006 §Listener Strategy: one listener per consuming module, typed to the
 * concrete event class. No queue dispatch — Phase B will add async retry.
 */
final class PosSaleInventoryListener
{
    public function __construct(
        private readonly SaleRepositoryInterface     $sales,
        private readonly TerminalRepositoryInterface $terminals,
        private readonly StockIssuePortInterface     $stockIssue,
        private readonly WarehouseRepositoryInterface $warehouses,
    ) {}

    public function handle(SaleCompleted $event): void
    {
        $sale = $this->sales->findById($event->saleId);

        if ($sale === null) {
            Log::channel('daily')->error('[POS][Inventory] Sale not found after SaleCompleted', [
                'sale_id'        => $event->saleId,
                'receipt_number' => $event->receiptNumber,
            ]);

            return;
        }

        $terminal = $this->terminals->findById((string) $sale->terminal_id);

        if ($terminal === null) {
            Log::channel('daily')->error('[POS][Inventory] Terminal not found — cannot resolve warehouse', [
                'sale_id'     => $event->saleId,
                'terminal_id' => $sale->terminal_id,
            ]);

            return;
        }

        $warehouse = $this->warehouses->findById((string) $terminal->warehouse_id);

        if ($warehouse === null) {
            Log::channel('daily')->error('[POS][Inventory] Warehouse not found — cannot resolve company', [
                'sale_id'      => $event->saleId,
                'terminal_id'  => $terminal->id,
                'warehouse_id' => $terminal->warehouse_id,
            ]);

            return;
        }

        $allowNegative = (bool) config('pos.inventory.allow_negative_stock', false);

        foreach ($sale->getLines() as $line) {
            try {
                $this->stockIssue->issue(new StockOperationDTO(
                    warehouse_id:   (string) $terminal->warehouse_id,
                    product_id:     $line->productId,
                    company_id:     (string) $warehouse->company_id,
                    quantity:       $line->quantity->toFloat(),
                    reference_type: 'pos_sale',
                    reference_id:   (string) $sale->id,
                    notes:          "POS #{$event->receiptNumber} · {$line->productName}",
                ));
            } catch (InsufficientStockException $e) {
                $level = $allowNegative ? 'warning' : 'error';

                Log::channel('daily')->{$level}('[POS][Inventory] Insufficient stock — inventory not decremented', [
                    'sale_id'       => $event->saleId,
                    'receipt_number' => $event->receiptNumber,
                    'product_id'    => $line->productId,
                    'sku'           => $line->sku,
                    'requested'     => $line->quantity->toFloat(),
                    'error'         => $e->getMessage(),
                ]);
            } catch (\Throwable $e) {
                Log::channel('daily')->error('[POS][Inventory] Unexpected error decreasing inventory', [
                    'sale_id'    => $event->saleId,
                    'product_id' => $line->productId,
                    'error'      => $e->getMessage(),
                    'trace'      => $e->getTraceAsString(),
                ]);
            }
        }
    }
}
