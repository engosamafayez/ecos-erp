<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\OrderImport\Application\Services\WooCommerceOrderImporter;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

class OrderImportWarehouseTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Warehouse $warehouse;
    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company   = Company::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $this->channel   = Channel::factory()->create([
            'company_id'           => $this->company->id,
            'default_warehouse_id' => $this->warehouse->id,
        ]);
    }

    private function makeWooOrder(string $sku, int $wooId = 1001): array
    {
        return [
            'id'             => $wooId,
            'number'         => (string) $wooId,
            'status'         => 'processing',
            'date_created'   => '2026-06-25T10:00:00',
            'customer_note'  => '',
            'total'          => '100.00',
            'shipping_total' => '0',
            'discount_total' => '0',
            'total_tax'      => '0',
            'billing'  => [
                'first_name' => 'Ahmed',
                'last_name'  => 'Ali',
                'email'      => 'ahmed@example.com',
                'phone'      => '01012345678',
                'country'    => 'EG',
                'city'       => 'Cairo',
                'address_1'  => '1 Tahrir Square',
                'company'    => '',
                'state'      => '',
                'address_2'  => '',
                'postcode'   => '',
            ],
            'shipping' => [],
            'shipping_lines' => [],
            'payment_method'       => 'bacs',
            'payment_method_title' => 'Direct bank transfer',
            'transaction_id'       => '',
            'date_paid'            => '',
            'line_items' => [
                [
                    'product_id' => 99,
                    'sku'        => $sku,
                    'name'       => 'Test Product',
                    'quantity'   => 2,
                    'price'      => '50.00',
                    'subtotal'   => '100.00',
                    'total'      => '100.00',
                ],
            ],
            'fee_lines'    => [],
            'coupon_lines' => [],
            'tax_lines'    => [],
        ];
    }

    public function test_order_gets_assigned_warehouse_from_channel(): void
    {
        $product = Product::factory()->create(['sku' => 'SKU-WH-001']);

        $importer = app(WooCommerceOrderImporter::class);
        $imported = $importer->importSingle($this->channel, $this->makeWooOrder('SKU-WH-001'));

        $this->assertTrue($imported);

        $order = Order::query()->where('external_order_id', '1001')->firstOrFail();
        $this->assertEquals($this->warehouse->id, $order->assigned_warehouse_id);
    }

    public function test_assigned_warehouse_null_when_channel_has_no_default(): void
    {
        $channelWithoutWarehouse = Channel::factory()->create([
            'company_id'           => $this->company->id,
            'default_warehouse_id' => null,
        ]);

        $product = Product::factory()->create(['sku' => 'SKU-WH-002']);

        $importer = app(WooCommerceOrderImporter::class);
        $imported = $importer->importSingle($channelWithoutWarehouse, $this->makeWooOrder('SKU-WH-002', wooId: 1002));

        $this->assertTrue($imported);

        $order = Order::query()->where('external_order_id', '1002')->firstOrFail();
        $this->assertNull($order->assigned_warehouse_id);
    }
}
