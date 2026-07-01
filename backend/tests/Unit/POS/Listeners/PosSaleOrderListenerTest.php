<?php

declare(strict_types=1);

namespace Tests\Unit\POS\Listeners;

use App\Core\Responses\OperationResult;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use Modules\Commerce\Orders\Application\DTO\OrderDTO;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\POS\Application\Contracts\OrderCreationPortInterface;
use Modules\POS\Application\Listeners\PosSaleOrderListener;
use Modules\POS\Sale\Domain\Contracts\SaleRepositoryInterface;
use Modules\POS\Sale\Domain\Events\SaleCompleted;
use Modules\POS\Sale\Domain\Models\Sale;
use Modules\POS\Sale\Domain\ValueObjects\SaleLine;
use Modules\POS\Shared\Domain\ValueObjects\Money;
use Modules\POS\Shared\Domain\ValueObjects\Quantity;
use Tests\TestCase;

/**
 * CRIT-004 — PosSaleOrderListener unit tests.
 *
 * Uses Mockery to verify the listener delegates correctly to CreateOrderAction
 * and handles all failure modes without rethrowing.
 */
final class PosSaleOrderListenerTest extends TestCase
{
    use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    private const SALE_ID        = 'sale-uuid-001';
    private const RECEIPT_NUMBER = 'RCP-2026-000001';
    private const CUSTOMER_ID    = 'customer-uuid-001';
    private const GUEST_ID       = 'guest-customer-uuid-001';
    private const PRODUCT_A      = 'product-uuid-aaa';
    private const PRODUCT_B      = 'product-uuid-bbb';
    private const ORDER_ID       = 'order-uuid-001';

    private MockInterface $saleRepo;
    private MockInterface $orderCreation;
    private PosSaleOrderListener $listener;

    protected function setUp(): void
    {
        parent::setUp();

        $this->saleRepo      = Mockery::mock(SaleRepositoryInterface::class);
        $this->orderCreation = Mockery::mock(OrderCreationPortInterface::class);

        $this->listener = new PosSaleOrderListener(
            $this->saleRepo,
            $this->orderCreation,
        );
    }

    // ── Happy path ────────────────────────────────────────────────────────────

    public function test_creates_order_for_sale_with_known_customer(): void
    {
        $sale = $this->buildSale(
            customerId: self::CUSTOMER_ID,
            lines: [['product_id' => self::PRODUCT_A, 'qty' => 2.0, 'price' => 50.0]],
        );

        $this->saleRepo->shouldReceive('findById')->with(self::SALE_ID)->andReturn($sale);

        $this->orderCreation
            ->shouldReceive('create')
            ->once()
            ->withArgs(function (OrderDTO $dto) {
                return $dto->customer_id       === self::CUSTOMER_ID
                    && $dto->status            === OrderStatus::Completed
                    && $dto->external_order_id === self::SALE_ID
                    && count($dto->lines)      === 1
                    && $dto->lines[0]['product_id'] === self::PRODUCT_A
                    && $dto->lines[0]['quantity']   === 2.0
                    && $dto->lines[0]['unit_price']  === 50.0;
            })
            ->andReturn(OperationResult::success($this->buildOrder()));

        $this->listener->handle($this->makeEvent());
    }

    public function test_creates_order_for_anonymous_sale_using_guest_customer(): void
    {
        Config::set('pos.erp.guest_customer_id', self::GUEST_ID);

        $sale = $this->buildSale(
            customerId: null,
            lines: [['product_id' => self::PRODUCT_A, 'qty' => 1.0, 'price' => 25.0]],
        );

        $this->saleRepo->shouldReceive('findById')->andReturn($sale);

        $this->orderCreation
            ->shouldReceive('create')
            ->once()
            ->withArgs(fn (OrderDTO $dto) => $dto->customer_id === self::GUEST_ID)
            ->andReturn(OperationResult::success($this->buildOrder()));

        $this->listener->handle($this->makeEvent());
    }

    public function test_creates_order_with_correct_line_count_for_multi_item_sale(): void
    {
        $sale = $this->buildSale(
            customerId: self::CUSTOMER_ID,
            lines: [
                ['product_id' => self::PRODUCT_A, 'qty' => 3.0, 'price' => 10.0],
                ['product_id' => self::PRODUCT_B, 'qty' => 1.0, 'price' => 200.0],
            ],
        );

        $this->saleRepo->shouldReceive('findById')->andReturn($sale);

        $this->orderCreation
            ->shouldReceive('create')
            ->once()
            ->withArgs(fn (OrderDTO $dto) => count($dto->lines) === 2)
            ->andReturn(OperationResult::success($this->buildOrder()));

        $this->listener->handle($this->makeEvent());
    }

    public function test_sets_external_order_id_to_sale_id_for_traceability(): void
    {
        $sale = $this->buildSale(
            customerId: self::CUSTOMER_ID,
            lines: [['product_id' => self::PRODUCT_A, 'qty' => 1.0, 'price' => 50.0]],
        );

        $this->saleRepo->shouldReceive('findById')->andReturn($sale);

        $this->orderCreation
            ->shouldReceive('create')
            ->once()
            ->withArgs(fn (OrderDTO $dto) => $dto->external_order_id === self::SALE_ID)
            ->andReturn(OperationResult::success($this->buildOrder()));

        $this->listener->handle($this->makeEvent());
    }

    public function test_order_status_is_completed_for_pos_sale(): void
    {
        $sale = $this->buildSale(
            customerId: self::CUSTOMER_ID,
            lines: [['product_id' => self::PRODUCT_A, 'qty' => 1.0, 'price' => 50.0]],
        );

        $this->saleRepo->shouldReceive('findById')->andReturn($sale);

        $this->orderCreation
            ->shouldReceive('create')
            ->once()
            ->withArgs(fn (OrderDTO $dto) => $dto->status === OrderStatus::Completed)
            ->andReturn(OperationResult::success($this->buildOrder()));

        $this->listener->handle($this->makeEvent());
    }

    // ── Guard-clauses ─────────────────────────────────────────────────────────

    public function test_logs_error_and_returns_early_when_sale_not_found(): void
    {
        Log::shouldReceive('channel')->with('daily')->andReturnSelf();
        Log::shouldReceive('error')->once()->withArgs(fn ($msg) => str_contains($msg, 'Sale not found'));

        $this->saleRepo->shouldReceive('findById')->andReturn(null);
        $this->orderCreation->shouldNotReceive('create');

        $this->listener->handle($this->makeEvent());
    }

    public function test_skips_order_when_no_customer_and_no_guest_configured(): void
    {
        Config::set('pos.erp.guest_customer_id', null);

        Log::shouldReceive('channel')->with('daily')->andReturnSelf();
        Log::shouldReceive('warning')->once()->withArgs(fn ($msg) => str_contains($msg, 'POS_GUEST_CUSTOMER_ID'));

        $sale = $this->buildSale(customerId: null, lines: [
            ['product_id' => self::PRODUCT_A, 'qty' => 1.0, 'price' => 50.0],
        ]);

        $this->saleRepo->shouldReceive('findById')->andReturn($sale);
        $this->orderCreation->shouldNotReceive('create');

        $this->listener->handle($this->makeEvent());
    }

    // ── Failure resilience ────────────────────────────────────────────────────

    public function test_logs_error_on_order_creation_failure_and_does_not_rethrow(): void
    {
        $sale = $this->buildSale(
            customerId: self::CUSTOMER_ID,
            lines: [['product_id' => self::PRODUCT_A, 'qty' => 1.0, 'price' => 50.0]],
        );

        $this->saleRepo->shouldReceive('findById')->andReturn($sale);

        $this->orderCreation
            ->shouldReceive('create')
            ->andThrow(new \RuntimeException('DB error'));

        Log::shouldReceive('channel')->with('daily')->andReturnSelf();
        Log::shouldReceive('error')->once()->withArgs(fn ($msg) => str_contains($msg, 'Failed to create ERP order'));

        // Must not throw
        $this->listener->handle($this->makeEvent());
    }

    public function test_notes_contain_receipt_number(): void
    {
        $sale = $this->buildSale(
            customerId: self::CUSTOMER_ID,
            lines: [['product_id' => self::PRODUCT_A, 'qty' => 1.0, 'price' => 50.0]],
        );

        $this->saleRepo->shouldReceive('findById')->andReturn($sale);

        $this->orderCreation
            ->shouldReceive('create')
            ->once()
            ->withArgs(fn (OrderDTO $dto) => str_contains((string) $dto->notes, self::RECEIPT_NUMBER))
            ->andReturn(OperationResult::success($this->buildOrder()));

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
     * Build a real Sale model with the given lines and customer.
     * Sale is final — cannot be mocked — so we construct it directly.
     *
     * @param  array<int, array{product_id: string, qty: float, price: float}>  $lines
     */
    private function buildSale(?string $customerId, array $lines): Sale
    {
        $saleLines = array_map(function (array $l): SaleLine {
            return new SaleLine(
                lineId:        'line-' . $l['product_id'],
                productId:     $l['product_id'],
                productName:   'Product ' . $l['product_id'],
                sku:           'SKU-' . $l['product_id'],
                quantity:      Quantity::of($l['qty']),
                unitPrice:     Money::of((string) $l['price'], 'EGP'),
                discountType:  null,
                discountValue: null,
                lineTotal:     Money::of((string) ($l['qty'] * $l['price']), 'EGP'),
                sortOrder:     0,
            );
        }, $lines);

        $sale              = new Sale();
        $sale->id          = self::SALE_ID;
        $sale->customer_id = $customerId;
        $sale->lines       = array_map(fn(SaleLine $l) => $l->toArray(), $saleLines);

        return $sale;
    }

    private function buildOrder(): Order
    {
        $order     = new Order();
        $order->id = self::ORDER_ID;

        return $order;
    }
}
