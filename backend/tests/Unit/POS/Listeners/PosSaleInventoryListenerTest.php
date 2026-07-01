<?php

declare(strict_types=1);

namespace Tests\Unit\POS\Listeners;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use Modules\Inventory\InventoryItems\Application\DTO\StockOperationDTO;
use Modules\Inventory\InventoryItems\Domain\Exceptions\InsufficientStockException;
use Modules\MasterData\Warehouses\Domain\Contracts\WarehouseRepositoryInterface;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\POS\Application\Contracts\StockIssuePortInterface;
use Modules\POS\Application\Listeners\PosSaleInventoryListener;
use Modules\POS\Sale\Domain\Contracts\SaleRepositoryInterface;
use Modules\POS\Sale\Domain\Events\SaleCompleted;
use Modules\POS\Sale\Domain\Models\Sale;
use Modules\POS\Sale\Domain\ValueObjects\SaleLine;
use Modules\POS\Shared\Domain\ValueObjects\Money;
use Modules\POS\Shared\Domain\ValueObjects\Quantity;
use Modules\POS\Terminal\Domain\Contracts\TerminalRepositoryInterface;
use Modules\POS\Terminal\Domain\Models\Terminal;
use Tests\TestCase;

/**
 * CRIT-003 — PosSaleInventoryListener unit tests.
 *
 * Uses Mockery to verify the listener delegates correctly to DirectIssueStockAction
 * and handles all failure modes without rethrowing.
 */
final class PosSaleInventoryListenerTest extends TestCase
{
    use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    private const SALE_ID        = 'sale-uuid-001';
    private const RECEIPT_NUMBER = 'RCP-2026-000001';
    private const TERMINAL_ID    = 'terminal-uuid-001';
    private const WAREHOUSE_ID   = 'warehouse-uuid-001';
    private const COMPANY_ID     = 'company-uuid-001';
    private const PRODUCT_A      = 'product-uuid-aaa';
    private const PRODUCT_B      = 'product-uuid-bbb';

    private MockInterface $saleRepo;
    private MockInterface $terminalRepo;
    private MockInterface $warehouseRepo;
    private MockInterface $stockIssue;
    private PosSaleInventoryListener $listener;

    protected function setUp(): void
    {
        parent::setUp();

        $this->saleRepo      = Mockery::mock(SaleRepositoryInterface::class);
        $this->terminalRepo  = Mockery::mock(TerminalRepositoryInterface::class);
        $this->warehouseRepo = Mockery::mock(WarehouseRepositoryInterface::class);
        $this->stockIssue    = Mockery::mock(StockIssuePortInterface::class);

        $this->listener = new PosSaleInventoryListener(
            $this->saleRepo,
            $this->terminalRepo,
            $this->stockIssue,
            $this->warehouseRepo,
        );
    }

    // ── Happy path ────────────────────────────────────────────────────────────

    public function test_decrements_stock_for_single_line_item(): void
    {
        $sale      = $this->buildSale([['product_id' => self::PRODUCT_A, 'qty' => 2.0]]);
        $terminal  = $this->buildTerminal();
        $warehouse = $this->buildWarehouse();

        $this->saleRepo->shouldReceive('findById')->with(self::SALE_ID)->andReturn($sale);
        $this->terminalRepo->shouldReceive('findById')->with(self::TERMINAL_ID)->andReturn($terminal);
        $this->warehouseRepo->shouldReceive('findById')->with(self::WAREHOUSE_ID)->andReturn($warehouse);

        $this->stockIssue
            ->shouldReceive('issue')
            ->once()
            ->withArgs(function (StockOperationDTO $dto) {
                return $dto->product_id     === self::PRODUCT_A
                    && $dto->warehouse_id   === self::WAREHOUSE_ID
                    && $dto->company_id     === self::COMPANY_ID
                    && $dto->quantity       === 2.0
                    && $dto->reference_type === 'pos_sale'
                    && $dto->reference_id   === self::SALE_ID;
            });

        $this->listener->handle($this->makeEvent());
    }

    public function test_decrements_stock_once_per_line_for_multi_item_sale(): void
    {
        $sale = $this->buildSale([
            ['product_id' => self::PRODUCT_A, 'qty' => 3.0],
            ['product_id' => self::PRODUCT_B, 'qty' => 1.0],
        ]);
        $terminal  = $this->buildTerminal();
        $warehouse = $this->buildWarehouse();

        $this->saleRepo->shouldReceive('findById')->andReturn($sale);
        $this->terminalRepo->shouldReceive('findById')->andReturn($terminal);
        $this->warehouseRepo->shouldReceive('findById')->andReturn($warehouse);

        $this->stockIssue->shouldReceive('issue')->twice();

        $this->listener->handle($this->makeEvent());
    }

    // ── Missing data guard-clauses ─────────────────────────────────────────────

    public function test_logs_error_and_returns_early_when_sale_not_found(): void
    {
        Log::shouldReceive('channel')->with('daily')->andReturnSelf();
        Log::shouldReceive('error')->once()->withArgs(fn ($msg) => str_contains($msg, 'Sale not found'));

        $this->saleRepo->shouldReceive('findById')->andReturn(null);
        $this->stockIssue->shouldNotReceive('issue');

        $this->listener->handle($this->makeEvent());
    }

    public function test_logs_error_and_returns_early_when_terminal_not_found(): void
    {
        $sale = $this->buildSale([['product_id' => self::PRODUCT_A, 'qty' => 1.0]]);

        Log::shouldReceive('channel')->with('daily')->andReturnSelf();
        Log::shouldReceive('error')->once()->withArgs(fn ($msg) => str_contains($msg, 'Terminal not found'));

        $this->saleRepo->shouldReceive('findById')->andReturn($sale);
        $this->terminalRepo->shouldReceive('findById')->andReturn(null);
        $this->stockIssue->shouldNotReceive('issue');

        $this->listener->handle($this->makeEvent());
    }

    public function test_logs_error_and_returns_early_when_warehouse_not_found(): void
    {
        $sale     = $this->buildSale([['product_id' => self::PRODUCT_A, 'qty' => 1.0]]);
        $terminal = $this->buildTerminal();

        Log::shouldReceive('channel')->with('daily')->andReturnSelf();
        Log::shouldReceive('error')->once()->withArgs(fn ($msg) => str_contains($msg, 'Warehouse not found'));

        $this->saleRepo->shouldReceive('findById')->andReturn($sale);
        $this->terminalRepo->shouldReceive('findById')->andReturn($terminal);
        $this->warehouseRepo->shouldReceive('findById')->andReturn(null);
        $this->stockIssue->shouldNotReceive('issue');

        $this->listener->handle($this->makeEvent());
    }

    // ── Insufficient stock ────────────────────────────────────────────────────

    public function test_logs_error_on_insufficient_stock_when_negative_not_allowed(): void
    {
        Config::set('pos.inventory.allow_negative_stock', false);

        $sale      = $this->buildSale([['product_id' => self::PRODUCT_A, 'qty' => 99.0]]);
        $terminal  = $this->buildTerminal();
        $warehouse = $this->buildWarehouse();

        $this->saleRepo->shouldReceive('findById')->andReturn($sale);
        $this->terminalRepo->shouldReceive('findById')->andReturn($terminal);
        $this->warehouseRepo->shouldReceive('findById')->andReturn($warehouse);

        $this->stockIssue
            ->shouldReceive('issue')
            ->andThrow(new InsufficientStockException(self::PRODUCT_A, self::WAREHOUSE_ID, 99.0, 10.0));

        Log::shouldReceive('channel')->with('daily')->andReturnSelf();
        Log::shouldReceive('error')->once()->withArgs(fn ($msg) => str_contains($msg, 'Insufficient stock'));

        // Must not throw
        $this->listener->handle($this->makeEvent());
    }

    public function test_logs_warning_on_insufficient_stock_when_negative_allowed(): void
    {
        Config::set('pos.inventory.allow_negative_stock', true);

        $sale      = $this->buildSale([['product_id' => self::PRODUCT_A, 'qty' => 5.0]]);
        $terminal  = $this->buildTerminal();
        $warehouse = $this->buildWarehouse();

        $this->saleRepo->shouldReceive('findById')->andReturn($sale);
        $this->terminalRepo->shouldReceive('findById')->andReturn($terminal);
        $this->warehouseRepo->shouldReceive('findById')->andReturn($warehouse);

        $this->stockIssue
            ->shouldReceive('issue')
            ->andThrow(new InsufficientStockException(self::PRODUCT_A, self::WAREHOUSE_ID, 5.0, 3.0));

        Log::shouldReceive('channel')->with('daily')->andReturnSelf();
        Log::shouldReceive('warning')->once()->withArgs(fn ($msg) => str_contains($msg, 'Insufficient stock'));

        // Must not throw
        $this->listener->handle($this->makeEvent());
    }

    // ── Unexpected exceptions ─────────────────────────────────────────────────

    public function test_logs_error_on_unexpected_exception_and_does_not_rethrow(): void
    {
        $sale      = $this->buildSale([['product_id' => self::PRODUCT_A, 'qty' => 1.0]]);
        $terminal  = $this->buildTerminal();
        $warehouse = $this->buildWarehouse();

        $this->saleRepo->shouldReceive('findById')->andReturn($sale);
        $this->terminalRepo->shouldReceive('findById')->andReturn($terminal);
        $this->warehouseRepo->shouldReceive('findById')->andReturn($warehouse);

        $this->stockIssue
            ->shouldReceive('issue')
            ->andThrow(new \RuntimeException('Database connection lost'));

        Log::shouldReceive('channel')->with('daily')->andReturnSelf();
        Log::shouldReceive('error')->once()->withArgs(fn ($msg) => str_contains($msg, 'Unexpected error'));

        // Must not throw
        $this->listener->handle($this->makeEvent());
    }

    public function test_processes_remaining_lines_when_one_fails(): void
    {
        $sale = $this->buildSale([
            ['product_id' => self::PRODUCT_A, 'qty' => 1.0],
            ['product_id' => self::PRODUCT_B, 'qty' => 2.0],
        ]);
        $terminal  = $this->buildTerminal();
        $warehouse = $this->buildWarehouse();

        $this->saleRepo->shouldReceive('findById')->andReturn($sale);
        $this->terminalRepo->shouldReceive('findById')->andReturn($terminal);
        $this->warehouseRepo->shouldReceive('findById')->andReturn($warehouse);

        // First line throws (insufficient stock), second line must still be issued.
        // Logging assertions for InsufficientStockException are in separate tests.
        $this->stockIssue
            ->shouldReceive('issue')
            ->times(2)
            ->andReturnUsing(function (StockOperationDTO $dto) {
                if ($dto->product_id === self::PRODUCT_A) {
                    throw new InsufficientStockException(self::PRODUCT_A, self::WAREHOUSE_ID, 1.0, 0.0);
                }
            });

        // Allow any log calls the listener makes — this test only cares that
        // both lines are attempted (verified by times(2) above).
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('error')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $this->listener->handle($this->makeEvent());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeEvent(): SaleCompleted
    {
        return SaleCompleted::now(
            saleId:        self::SALE_ID,
            receiptNumber: self::RECEIPT_NUMBER,
            totalAmount:   '100.00',
            amountPaid:    '100.00',
            changeGiven:   '0.00',
            currency:      'EGP',
        );
    }

    /**
     * Build a real Sale model with the given lines set on the `lines` attribute.
     * Sale is final — cannot be mocked — so we construct it directly.
     *
     * @param  array<int, array{product_id: string, qty: float}>  $lines
     */
    private function buildSale(array $lines): Sale
    {
        $saleLines = array_map(function (array $l): SaleLine {
            return new SaleLine(
                lineId:        'line-' . $l['product_id'],
                productId:     $l['product_id'],
                productName:   'Product ' . $l['product_id'],
                sku:           'SKU-' . $l['product_id'],
                quantity:      Quantity::of($l['qty']),
                unitPrice:     Money::of('50.00', 'EGP'),
                discountType:  null,
                discountValue: null,
                lineTotal:     Money::of((string) ($l['qty'] * 50), 'EGP'),
                sortOrder:     0,
            );
        }, $lines);

        $sale              = new Sale();
        $sale->id          = self::SALE_ID;
        $sale->terminal_id = self::TERMINAL_ID;
        $sale->lines       = array_map(fn(SaleLine $l) => $l->toArray(), $saleLines);

        return $sale;
    }

    /**
     * Build a real Terminal model.
     * Terminal is final — cannot be mocked — so we construct it directly.
     */
    private function buildTerminal(): Terminal
    {
        $terminal               = new Terminal();
        $terminal->id           = self::TERMINAL_ID;
        $terminal->warehouse_id = self::WAREHOUSE_ID;

        return $terminal;
    }

    /**
     * Build a real Warehouse model.
     */
    private function buildWarehouse(): Warehouse
    {
        $warehouse             = new Warehouse();
        $warehouse->id         = self::WAREHOUSE_ID;
        $warehouse->company_id = self::COMPANY_ID;

        return $warehouse;
    }
}
