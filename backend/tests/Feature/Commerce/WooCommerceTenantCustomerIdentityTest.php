<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\Channels\Domain\Models\ChannelCredential;
use Modules\Commerce\OrderImport\Application\Services\WooCommerceOrderImporter;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Synchronization\Application\Jobs\CustomerSyncJob;
use Modules\Commerce\Synchronization\Application\Services\WooCommerceCustomerSyncer;
use Modules\Crm\Customers\Domain\Models\Customer;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-ECOS-V1.1-WOO-02-TENANT-SAFE-CUSTOMER-IDENTITY-044 — mandatory test matrix
 * (§18-ish): tenant isolation, no-match/replay, company-resolution-failure, guest
 * checkout, and the observer's additive Crm-class dispatch. The historical-audit
 * remediation cases live separately in WooCustomerLinkageAuditTest — a different
 * class (AuditWooCustomerCompanyLinkageAction) with different setup needs (raw
 * Order/Customer rows simulating pre-existing mismatches, not live sync).
 */
final class WooCommerceTenantCustomerIdentityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{Company, Brand, Channel}
     */
    private function makeCompanyChannel(): array
    {
        $company = Company::factory()->create();
        $brand = Brand::factory()->create(['company_id' => $company->id]);
        $channel = Channel::factory()->create(['brand_id' => $brand->id]);

        return [$company, $brand, $channel];
    }

    /**
     * @param  array<string, mixed>  $billingOverrides
     * @return array<string, mixed>
     */
    private function makeWooOrder(string $sku, int $wooId, array $billingOverrides = []): array
    {
        return [
            'id' => $wooId,
            'number' => (string) $wooId,
            'status' => 'processing',
            'date_created' => '2026-06-25T10:00:00',
            'customer_note' => '',
            'total' => '100.00',
            'shipping_total' => '0',
            'discount_total' => '0',
            'total_tax' => '0',
            'billing' => array_merge([
                'first_name' => 'Ahmed',
                'last_name' => 'Ali',
                'email' => 'ahmed@example.com',
                'phone' => '01012345678',
                'country' => 'EG',
                'city' => 'Cairo',
                'address_1' => '1 Tahrir Square',
                'company' => '',
                'state' => '',
                'address_2' => '',
                'postcode' => '',
            ], $billingOverrides),
            'shipping' => [],
            'shipping_lines' => [],
            'payment_method' => 'bacs',
            'payment_method_title' => 'Direct bank transfer',
            'transaction_id' => '',
            'date_paid' => '',
            'line_items' => [
                [
                    'product_id' => 99,
                    'sku' => $sku,
                    'name' => 'Test Product',
                    'quantity' => 1,
                    'price' => '100.00',
                    'subtotal' => '100.00',
                    'total' => '100.00',
                ],
            ],
            'fee_lines' => [],
            'coupon_lines' => [],
            'tax_lines' => [],
        ];
    }

    // ═══ TENANT ISOLATION ═══════════════════════════════════════════════════

    public function test_two_companies_with_the_same_phone_do_not_cross_match(): void
    {
        [$companyA, , $channelA] = $this->makeCompanyChannel();
        [$companyB, , $channelB] = $this->makeCompanyChannel();
        $product = Product::factory()->create(['sku' => 'SKU-TEN-PHONE']);

        $importer = app(WooCommerceOrderImporter::class);

        $this->assertTrue($importer->importSingle($channelA, $this->makeWooOrder('SKU-TEN-PHONE', 5001, [
            'phone' => '01011112222', 'email' => 'phone-a@example.test',
        ])));
        $this->assertTrue($importer->importSingle($channelB, $this->makeWooOrder('SKU-TEN-PHONE', 5002, [
            'phone' => '01011112222', 'email' => 'phone-b@example.test',
        ])));

        $orderA = Order::query()->where('external_order_id', '5001')->firstOrFail();
        $orderB = Order::query()->where('external_order_id', '5002')->firstOrFail();

        $this->assertNotSame($orderA->customer_id, $orderB->customer_id);

        $customerA = Customer::find($orderA->customer_id);
        $customerB = Customer::find($orderB->customer_id);

        $this->assertSame($companyA->id, $customerA->company_id);
        $this->assertSame($companyB->id, $customerB->company_id);
        $this->assertSame(2, DB::table('customers')->where('phone', '201011112222')->count());
    }

    public function test_two_companies_with_the_same_email_do_not_cross_match(): void
    {
        [$companyA, , $channelA] = $this->makeCompanyChannel();
        [$companyB, , $channelB] = $this->makeCompanyChannel();
        $product = Product::factory()->create(['sku' => 'SKU-TEN-EMAIL']);

        $importer = app(WooCommerceOrderImporter::class);

        $this->assertTrue($importer->importSingle($channelA, $this->makeWooOrder('SKU-TEN-EMAIL', 5101, [
            'phone' => '', 'email' => 'shared@example.test',
        ])));
        $this->assertTrue($importer->importSingle($channelB, $this->makeWooOrder('SKU-TEN-EMAIL', 5102, [
            'phone' => '', 'email' => 'shared@example.test',
        ])));

        $orderA = Order::query()->where('external_order_id', '5101')->firstOrFail();
        $orderB = Order::query()->where('external_order_id', '5102')->firstOrFail();

        $this->assertNotSame($orderA->customer_id, $orderB->customer_id);

        $customerA = Customer::find($orderA->customer_id);
        $customerB = Customer::find($orderB->customer_id);

        $this->assertSame($companyA->id, $customerA->company_id);
        $this->assertSame($companyB->id, $customerB->company_id);
        $this->assertSame(2, DB::table('customers')->where('email', 'shared@example.test')->count());
    }

    public function test_syncer_two_companies_with_the_same_email_do_not_cross_match(): void
    {
        [$companyA, , $channelA] = $this->makeCompanyChannel();
        [$companyB, , $channelB] = $this->makeCompanyChannel();

        $syncer = app(WooCommerceCustomerSyncer::class);

        $resultA = $syncer->sync($channelA, [
            'billing' => ['email' => 'dup@example.test', 'first_name' => 'A', 'last_name' => 'One'],
        ]);
        $resultB = $syncer->sync($channelB, [
            'billing' => ['email' => 'dup@example.test', 'first_name' => 'B', 'last_name' => 'Two'],
        ]);

        $this->assertSame('created', $resultA['action']);
        $this->assertSame('created', $resultB['action']);
        $this->assertNotSame($resultA['customer_id'], $resultB['customer_id']);

        $customerA = Customer::find($resultA['customer_id']);
        $customerB = Customer::find($resultB['customer_id']);

        $this->assertSame($companyA->id, $customerA->company_id);
        $this->assertSame($companyB->id, $customerB->company_id);
    }

    // ═══ NO-MATCH / REPLAY IDEMPOTENCY ══════════════════════════════════════

    public function test_no_match_creates_a_new_customer_scoped_to_the_resolved_company(): void
    {
        [$company, , $channel] = $this->makeCompanyChannel();
        $product = Product::factory()->create(['sku' => 'SKU-TEN-NEW']);

        $imported = app(WooCommerceOrderImporter::class)->importSingle($channel, $this->makeWooOrder('SKU-TEN-NEW', 6001, [
            'phone' => '01033334444', 'email' => 'fresh@example.test',
        ]));

        $this->assertTrue($imported);

        $order = Order::query()->where('external_order_id', '6001')->firstOrFail();
        $customer = Customer::find($order->customer_id);

        $this->assertNotNull($customer);
        $this->assertSame($company->id, $customer->company_id);
        $this->assertSame('201033334444', $customer->phone);
    }

    public function test_replaying_the_same_customer_payload_does_not_duplicate(): void
    {
        [, , $channel] = $this->makeCompanyChannel();
        $syncer = app(WooCommerceCustomerSyncer::class);

        $payload = ['billing' => [
            'email' => 'replay@example.test', 'phone' => '01044445555', 'first_name' => 'Re', 'last_name' => 'Play',
        ]];

        $first = $syncer->sync($channel, $payload);
        $second = $syncer->sync($channel, $payload);

        $this->assertSame('created', $first['action']);
        $this->assertSame('updated', $second['action']);
        $this->assertSame($first['customer_id'], $second['customer_id']);
        $this->assertSame(1, DB::table('customers')->where('email', 'replay@example.test')->count());
    }

    // ═══ COMPANY-RESOLUTION FAILURE (FAIL-CLOSED) ═══════════════════════════

    public function test_batch_import_fails_closed_when_channel_has_no_resolvable_company(): void
    {
        $channel = Channel::factory()->create(['brand_id' => null]);
        ChannelCredential::query()->create([
            'channel_id' => $channel->id, 'consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test',
        ]);

        $result = app(WooCommerceOrderImporter::class)->import($channel);

        $this->assertSame(0, $result->imported_orders);
        $this->assertSame(0, $result->created_customers);
        $this->assertNotEmpty($result->errors);
        $this->assertStringContainsString('resolves to no owning company', $result->errors[0]);
        $this->assertSame(0, DB::table('customers')->count());
    }

    public function test_import_single_fails_closed_when_channel_has_no_resolvable_company(): void
    {
        $channel = Channel::factory()->create(['brand_id' => null]);
        Product::factory()->create(['sku' => 'SKU-TEN-FAIL']);

        $threw = false;

        try {
            app(WooCommerceOrderImporter::class)->importSingle($channel, $this->makeWooOrder('SKU-TEN-FAIL', 7001));
        } catch (RuntimeException $e) {
            $threw = true;
            $this->assertStringContainsString('resolves to no owning company', $e->getMessage());
        }

        $this->assertTrue($threw);
        $this->assertDatabaseMissing('orders', ['external_order_id' => '7001']);
        $this->assertSame(0, DB::table('customers')->count());
    }

    public function test_syncer_fails_closed_when_channel_has_no_resolvable_company(): void
    {
        $channel = Channel::factory()->create(['brand_id' => null]);

        $threw = false;

        try {
            app(WooCommerceCustomerSyncer::class)->sync($channel, [
                'billing' => ['email' => 'shouldnotexist@example.test'],
            ]);
        } catch (RuntimeException $e) {
            $threw = true;
            $this->assertStringContainsString('resolves to no owning company', $e->getMessage());
        }

        $this->assertTrue($threw);
        $this->assertDatabaseMissing('customers', ['email' => 'shouldnotexist@example.test']);
    }

    // ═══ GUEST CHECKOUT ══════════════════════════════════════════════════════

    public function test_guest_checkout_order_resolves_customer_scoped_to_company(): void
    {
        [$company, , $channel] = $this->makeCompanyChannel();
        $product = Product::factory()->create(['sku' => 'SKU-TEN-GUEST']);

        $wooOrder = $this->makeWooOrder('SKU-TEN-GUEST', 8001, [
            'phone' => '01055556666', 'email' => 'guest@example.test',
        ]);
        // Woo's own markers for an unauthenticated, no-account checkout — the
        // importer never reads either field (confirmed: it only reads billing.*),
        // so a guest order resolves through the identical company-scoped
        // phone-then-email-then-create chain as an account-holder's order.
        $wooOrder['customer_id'] = 0;
        $wooOrder['created_via'] = 'checkout';

        $imported = app(WooCommerceOrderImporter::class)->importSingle($channel, $wooOrder);
        $this->assertTrue($imported);

        $order = Order::query()->where('external_order_id', '8001')->firstOrFail();
        $customer = Customer::find($order->customer_id);

        $this->assertNotNull($customer);
        $this->assertSame($company->id, $customer->company_id);
        $this->assertSame('guest@example.test', $customer->email);
    }

    // ═══ OBSERVER: ADDITIVE CRM DISPATCH, NO DUPLICATE / NO REGRESSION ══════

    public function test_creating_a_crm_customer_dispatches_exactly_one_customer_sync_job(): void
    {
        Bus::fake([CustomerSyncJob::class]);

        [$company, $brand] = $this->makeCompanyChannel();
        Channel::factory()->create(['brand_id' => $brand->id, 'is_active' => true, 'sync_customers' => true]);

        Customer::query()->create([
            'code' => 'CUS-OBS-CRM-001',
            'company_id' => $company->id,
            'name' => 'Observer Crm Test',
            'email' => 'observer-crm@example.test',
            'is_active' => true,
        ]);

        Bus::assertDispatchedTimes(CustomerSyncJob::class, 1);
    }

    public function test_creating_a_sales_customer_still_dispatches_exactly_one_customer_sync_job(): void
    {
        Bus::fake([CustomerSyncJob::class]);

        [$company, $brand] = $this->makeCompanyChannel();
        Channel::factory()->create(['brand_id' => $brand->id, 'is_active' => true, 'sync_customers' => true]);

        \Modules\Sales\Customers\Domain\Models\Customer::factory()->create(['company_id' => $company->id]);

        Bus::assertDispatchedTimes(CustomerSyncJob::class, 1);
    }

    public function test_updating_a_crm_customer_through_an_inactive_channel_dispatches_nothing(): void
    {
        Bus::fake([CustomerSyncJob::class]);

        [$company, $brand] = $this->makeCompanyChannel();
        // No active+sync_customers channel exists for this company at all.
        Channel::factory()->create(['brand_id' => $brand->id, 'is_active' => false, 'sync_customers' => true]);

        Customer::query()->create([
            'code' => 'CUS-OBS-INACTIVE-001',
            'company_id' => $company->id,
            'name' => 'No Dispatch Expected',
            'email' => 'no-dispatch@example.test',
            'is_active' => true,
        ]);

        Bus::assertNotDispatched(CustomerSyncJob::class);
    }
}
