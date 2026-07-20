<?php

/**
 * TASK-INV-CRITICAL-FOUNDATION-001 — Direct Verification Script
 *
 * Bootstraps Laravel and directly tests the three critical fixes.
 * Run: php artisan tinker --execute="require 'verify_foundation.php';"
 * Or:  php -r "define('LARAVEL_START', microtime(true)); require 'verify_foundation.php';" (from /var/www/html)
 *
 * No PHPUnit required. Uses DB transactions that are rolled back after each test.
 */

require_once __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Commerce\Orders\Application\Actions\ReserveOrderInventoryAction;
use Modules\Commerce\Orders\Application\Actions\ShipOrderInventoryAction;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Enums\ReservationStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderLine;
use Modules\Commerce\Orders\Domain\Models\OrderReservationAudit;
use Modules\Inventory\InventoryItems\Domain\Contracts\InventoryItemRepositoryInterface;
use Modules\Inventory\InventoryItems\Domain\Models\InventoryItem;
use Modules\Inventory\InventoryItems\Domain\Models\StockLedgerEntry;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Inventory\ReceiptLayers\Application\Services\InventoryLayerConsumptionService;
use Modules\Inventory\ReceiptLayers\Domain\Models\InventoryReceiptLayer;
use Modules\Inventory\StockLedger\Application\Actions\AddManualStockAction;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceipt;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceiptLine;
use Modules\Purchasing\Suppliers\Domain\Models\Supplier;
use Modules\Sales\Customers\Domain\Models\Customer;

$passed = 0;
$failed = 0;
$results = [];

function runTest(string $name, Closure $fn, &$passed, &$failed, array &$results): void
{
    DB::beginTransaction();
    try {
        $fn();
        DB::rollBack();
        $passed++;
        $results[] = ['name' => $name, 'status' => 'PASS'];
        echo "  ✓ $name\n";
    } catch (Throwable $e) {
        DB::rollBack();
        $failed++;
        $results[] = ['name' => $name, 'status' => 'FAIL', 'error' => $e->getMessage()];
        echo "  ✗ $name\n    → {$e->getMessage()}\n";
    }
}

function assert_equals($expected, $actual, string $msg = ''): void
{
    if ((string) $expected !== (string) $actual) {
        throw new RuntimeException("Assert equals failed. Expected: $expected, Actual: $actual. $msg");
    }
}

function assert_not_null($value, string $msg = ''): void
{
    if ($value === null) {
        throw new RuntimeException("Assert not null failed. $msg");
    }
}

// ── Schema prerequisite check ─────────────────────────────────────────────────
echo "\n=== SCHEMA PREREQUISITE CHECK ===\n";

$hasColumn = Schema::hasColumn('inventory_receipt_layers', 'company_id');
echo ($hasColumn ? "✓" : "✗") . " company_id column in inventory_receipt_layers: " . ($hasColumn ? "EXISTS" : "MISSING") . "\n";

if (!$hasColumn) {
    echo "\nFATAL: Run migration first:\n";
    echo "  php artisan migrate\n\n";
    exit(1);
}

// ── Test fixtures ─────────────────────────────────────────────────────────────
$company   = Company::factory()->create();
$warehouse = Warehouse::factory()->create(['company_id' => $company->id]);
$customer  = Customer::factory()->create();
$supplier  = Supplier::factory()->create();

function makeProduct(): Product {
    return Product::factory()->create(['regular_price' => 200.0, 'sale_price' => 200.0]);
}

function seedItem(Product $product, Warehouse $warehouse, Company $company, float $onHand, float $reserved = 0.0): InventoryItem {
    return InventoryItem::query()->create([
        'warehouse_id' => $warehouse->id,
        'product_id'   => $product->id,
        'company_id'   => $company->id,
        'on_hand_qty'  => $onHand,
        'reserved_qty' => $reserved,
    ]);
}

function addLayer(InventoryItem $item, Warehouse $warehouse, Company $company, Supplier $supplier, float $qty, float $cost): InventoryReceiptLayer {
    $gr    = GoodsReceipt::factory()->create(['warehouse_id' => $warehouse->id]);
    $grLine = GoodsReceiptLine::factory()->create(['goods_receipt_id' => $gr->id, 'product_id' => $item->product_id]);
    return InventoryReceiptLayer::query()->create([
        'company_id'            => $company->id,
        'supplier_id'           => $supplier->id,
        'product_id'            => $item->product_id,
        'goods_receipt_id'      => $gr->id,
        'goods_receipt_line_id' => $grLine->id,
        'warehouse_id'          => $warehouse->id,
        'received_qty'          => $qty,
        'remaining_qty'         => $qty,
        'landed_unit_cost'      => $cost,
        'receipt_date'          => now()->toDateString(),
    ]);
}

function makeOrder(Warehouse $warehouse, Customer $customer, float $total = 500.0): Order {
    return Order::query()->create([
        'assigned_warehouse_id' => $warehouse->id,
        'customer_id'           => $customer->id,
        'order_number'          => 'VFY-' . uniqid(),
        'order_date'            => now()->toDateString(),
        'status'                => OrderStatus::Processing->value,
        'subtotal'              => $total,
        'total'                 => $total,
        'shipping_total'        => 0,
        'discount_total'        => 0,
        'tax_total'             => 0,
    ]);
}

// ═════════════════════════════════════════════════════════════════════════════
echo "\n=== F-INV-C1: FIFO SCHEMA FIX ===\n";

runTest('C1-01: receipt layer stores company_id', function() use ($company, $warehouse, $supplier) {
    $p = makeProduct();
    $item = seedItem($p, $warehouse, $company, 10.0);
    $layer = addLayer($item, $warehouse, $company, $supplier, 10.0, 100.0);
    assert_not_null($layer->company_id, 'company_id should not be null');
    assert_equals($company->id, $layer->company_id, 'company_id should match warehouse company');
}, $passed, $failed, $results);

runTest('C1-02: consume() succeeds (no SQL column error)', function() use ($company, $warehouse, $supplier) {
    $p = makeProduct();
    $item = seedItem($p, $warehouse, $company, 10.0);
    addLayer($item, $warehouse, $company, $supplier, 10.0, 100.0);
    $result = app(InventoryLayerConsumptionService::class)->consume(
        inventoryItemId: $item->id, productId: $p->id,
        warehouseId: $warehouse->id, companyId: $company->id, quantity: 10.0,
    );
    assert_equals(10.0, $result->totalQuantity);
    assert_equals(1000.0, $result->totalCost);
}, $passed, $failed, $results);

runTest('C1-03: tenant isolation — Company B cannot consume Company A layers', function() use ($company, $warehouse, $supplier) {
    $companyB  = Company::factory()->create();
    $warehouseB = Warehouse::factory()->create(['company_id' => $companyB->id]);
    $p = makeProduct();
    $itemA = seedItem($p, $warehouse, $company, 10.0);
    addLayer($itemA, $warehouse, $company, $supplier, 10.0, 100.0);
    $itemB = seedItem($p, $warehouseB, $companyB, 10.0);
    $threw = false;
    try {
        app(InventoryLayerConsumptionService::class)->consume(
            inventoryItemId: $itemB->id, productId: $p->id,
            warehouseId: $warehouseB->id, companyId: $companyB->id, quantity: 5.0,
        );
    } catch (\Modules\Inventory\InventoryItems\Domain\Exceptions\InsufficientStockException $e) {
        $threw = true;
    }
    if (!$threw) throw new RuntimeException('Expected InsufficientStockException but none thrown');
}, $passed, $failed, $results);

// ═════════════════════════════════════════════════════════════════════════════
echo "\n=== F-INV-C2: MANUAL STOCK WRITE PATH ===\n";

runTest('C2-01: manual add updates InventoryItem.on_hand_qty', function() use ($company, $warehouse) {
    $p = makeProduct();
    $item = seedItem($p, $warehouse, $company, 0.0);
    app(AddManualStockAction::class)->execute($p, $warehouse, 10.0, ['unit_cost' => 50.0]);
    assert_equals(10.0, (float) $item->fresh()->on_hand_qty);
}, $passed, $failed, $results);

runTest('C2-02: manual add creates StockLedgerEntry (adjustment_in)', function() use ($company, $warehouse) {
    $p = makeProduct();
    seedItem($p, $warehouse, $company, 0.0);
    app(AddManualStockAction::class)->execute($p, $warehouse, 10.0, ['unit_cost' => 50.0]);
    $entry = StockLedgerEntry::query()
        ->where('product_id', $p->id)->where('warehouse_id', $warehouse->id)->latest()->first();
    assert_not_null($entry, 'StockLedgerEntry should exist');
    assert_equals('adjustment_in', $entry->movement_type);
    assert_equals(10.0, (float) $entry->quantity);
    assert_equals($company->id, $entry->company_id);
}, $passed, $failed, $results);

runTest('C2-03: manual add creates FIFO layer with company_id', function() use ($company, $warehouse) {
    $p = makeProduct();
    seedItem($p, $warehouse, $company, 0.0);
    app(AddManualStockAction::class)->execute($p, $warehouse, 5.0, ['unit_cost' => 80.0]);
    $layer = InventoryReceiptLayer::query()
        ->where('product_id', $p->id)->where('warehouse_id', $warehouse->id)
        ->whereNull('goods_receipt_id')->first();
    assert_not_null($layer, 'FIFO layer should exist');
    assert_equals($company->id, $layer->company_id);
    assert_equals(5.0, (float) $layer->received_qty);
    assert_equals(80.0, (float) $layer->landed_unit_cost);
}, $passed, $failed, $results);

runTest('C2-04: manually added stock is FIFO-consumable', function() use ($company, $warehouse) {
    $p = makeProduct();
    seedItem($p, $warehouse, $company, 0.0);
    app(AddManualStockAction::class)->execute($p, $warehouse, 10.0, ['unit_cost' => 60.0]);
    $item = app(InventoryItemRepositoryInterface::class)->findByWarehouseAndProduct($warehouse->id, $p->id);
    $result = app(InventoryLayerConsumptionService::class)->consume(
        inventoryItemId: $item->id, productId: $p->id,
        warehouseId: $warehouse->id, companyId: $company->id, quantity: 10.0,
    );
    assert_equals(10.0, $result->totalQuantity);
    assert_equals(600.0, $result->totalCost);
}, $passed, $failed, $results);

runTest('C2-05: legacy stock_movements not written', function() use ($company, $warehouse) {
    $p = makeProduct();
    seedItem($p, $warehouse, $company, 0.0);
    $countBefore = DB::table('stock_movements')->count();
    app(AddManualStockAction::class)->execute($p, $warehouse, 5.0, ['unit_cost' => 40.0]);
    $countAfter = DB::table('stock_movements')->count();
    assert_equals($countBefore, $countAfter, 'stock_movements must not be written by AddManualStockAction');
}, $passed, $failed, $results);

// ═════════════════════════════════════════════════════════════════════════════
echo "\n=== F-INV-H6: ATOMICITY — AUDIT INSIDE TRANSACTION ===\n";

runTest('H6-01: audit record exists after shipment', function() use ($company, $warehouse, $customer, $supplier) {
    $p = makeProduct();
    $item = seedItem($p, $warehouse, $company, 10.0, 10.0);
    addLayer($item, $warehouse, $company, $supplier, 10.0, 100.0);
    $order = makeOrder($warehouse, $customer, 1000.0);
    OrderLine::query()->create(['order_id' => $order->id, 'product_id' => $p->id, 'quantity' => 10.0, 'unit_price' => 100.0, 'line_total' => 1000.0]);
    app(ReserveOrderInventoryAction::class)->execute($order);
    app(ShipOrderInventoryAction::class)->execute($order->refresh());
    $audit = OrderReservationAudit::query()
        ->where('order_id', $order->id)
        ->where('to_status', ReservationStatus::Transferred->value)
        ->first();
    assert_not_null($audit, 'Audit record must exist after shipment');
}, $passed, $failed, $results);

runTest('H6-02: COGS stamped correctly', function() use ($company, $warehouse, $customer, $supplier) {
    $p = makeProduct();
    $item = seedItem($p, $warehouse, $company, 10.0, 10.0);
    addLayer($item, $warehouse, $company, $supplier, 10.0, 80.0);
    $order = makeOrder($warehouse, $customer, 1000.0);
    OrderLine::query()->create(['order_id' => $order->id, 'product_id' => $p->id, 'quantity' => 10.0, 'unit_price' => 100.0, 'line_total' => 1000.0]);
    app(ReserveOrderInventoryAction::class)->execute($order);
    app(ShipOrderInventoryAction::class)->execute($order->refresh());
    $order->refresh();
    assert_equals(800.0, (float) $order->actual_cogs_amount, 'COGS = 10 × 80 = 800');
    assert_equals(200.0, (float) $order->actual_margin_amount, 'margin = 1000 - 800 = 200');
}, $passed, $failed, $results);

// ── Cleanup test fixtures ────────────────────────────────────────────────────
$company->delete();
$customer->delete();
$supplier->delete();

// ═════════════════════════════════════════════════════════════════════════════
echo "\n=== RESULTS ===\n";
$total = $passed + $failed;
echo "Tests: $total  Passed: $passed  Failed: $failed\n";
foreach ($results as $r) {
    $icon = $r['status'] === 'PASS' ? '✓' : '✗';
    echo "  $icon {$r['name']}";
    if (isset($r['error'])) echo " — {$r['error']}";
    echo "\n";
}

echo "\nVERDICT: " . ($failed === 0 ? 'ALL TESTS PASS' : "$failed TESTS FAILED") . "\n\n";
exit($failed > 0 ? 1 : 0);
