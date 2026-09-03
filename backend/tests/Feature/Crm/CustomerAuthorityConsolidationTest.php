<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Synchronization\Application\Jobs\CustomerSyncJob;
use Modules\Commerce\Synchronization\Application\Services\WooCommerceCustomerSyncer;
use Modules\Crm\Customers\Domain\Enums\CustomerType;
use Modules\Crm\Customers\Domain\Models\Customer as CrmCustomer;
use Modules\Crm\Customers\Domain\Services\CustomerService;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\POS\Customer\Infrastructure\Gateways\SalesCustomerGateway;
use Modules\Sales\Customers\Domain\Models\Customer as SalesCustomer;
use Tests\TestCase;

/**
 * TASK-ECOS-CRM-FOUNDATION-CUSTOMER-AUTHORITY-002-GATE-A.
 *
 * Verifies the consolidation onto Modules\Crm\Customers as the canonical Customer
 * authority: both Eloquent classes still map the ONE `customers` table, and every
 * repointed consumer (Order, CreateManualOrderAction, POS gateway, WooCommerce
 * sync) resolves identities consistently across both classes.
 *
 * TASK-ECOS-CRM-FINAL-SOURCE-CLOSURE-CURRENT-CANONICAL-RECONCILIATION-005 note:
 * Gate A originally introduced one shared CustomerCodeSequenceService as "the sole
 * code generator" and this file's own §2 tested it directly (8 cases, including a
 * real-PDO concurrency proof). Reconciling against current canonical found that
 * canonical never adopted that service — canonical independently carries FOUR
 * different, uncoordinated code-generation mechanisms across
 * Sales\Customers\CreateCustomerAction (CustomerCodeGeneratorService, sequential
 * per-company), Crm\Customers\CustomerService (random 8-hex CUST- suffix),
 * Commerce\Orders\CreateManualOrderAction, and Commerce\Synchronization /
 * Commerce\OrderImport's shared inline MAX-based CUS-NNN generator. Per this
 * task's §13/§29 (preserve current canonical behavior; do not fix unrelated
 * pre-existing inconsistencies as scope creep), Gate A's own service and its §2
 * tests were removed rather than carried forward — there is no single mechanism
 * left to assert against project-wide, and canonical's own existing multiplicity
 * is untouched, not a defect this task introduced or is asked to close.
 */
final class CustomerAuthorityConsolidationTest extends TestCase
{
    use RefreshDatabase;

    // ═══ 1. CUSTOMER AUTHORITY (5) ═══════════════════════════════════════════════

    public function test_crm_created_customer_is_visible_through_the_sales_class(): void
    {
        $company = Company::factory()->create();
        $crm = app(CustomerService::class)->create((string) $company->id, CustomerType::Individual, [
            'first_name' => 'Nour', 'last_name' => 'Adel', 'phone' => '01011112222',
        ]);

        $viaSales = SalesCustomer::find($crm->id);

        $this->assertNotNull($viaSales, 'A CRM-authored row must be readable through the legacy Sales class — same physical table.');
        $this->assertSame($crm->name, $viaSales->name);
        $this->assertSame($crm->phone, $viaSales->phone);
    }

    public function test_sales_created_customer_is_visible_through_the_crm_class(): void
    {
        $sales = SalesCustomer::factory()->create(['name' => 'Legacy Row', 'phone' => '01022223333']);

        $viaCrm = CrmCustomer::find($sales->id);

        $this->assertNotNull($viaCrm, 'A Sales-authored row must be readable through the canonical Crm class.');
        $this->assertSame('Legacy Row', $viaCrm->name);
    }

    public function test_customer_id_is_identical_across_both_class_views(): void
    {
        $company = Company::factory()->create();
        $crm = app(CustomerService::class)->create((string) $company->id, CustomerType::Individual, ['first_name' => 'Same', 'last_name' => 'Id']);

        $this->assertSame($crm->id, SalesCustomer::find($crm->id)?->id);
    }

    public function test_a_field_written_via_one_class_is_visible_via_the_other_after_a_fresh_query(): void
    {
        $sales = SalesCustomer::factory()->create(['name' => 'Before Update']);

        CrmCustomer::whereKey($sales->id)->update(['name' => 'Updated Via Crm']);

        $this->assertSame('Updated Via Crm', SalesCustomer::find($sales->id)?->name);
    }

    public function test_existing_customer_code_is_never_regenerated_by_either_class(): void
    {
        $sales = SalesCustomer::factory()->create(['code' => 'CUS-LEGACY-42']);

        $fresh = CrmCustomer::find($sales->id);
        $fresh->update(['name' => 'Renamed']);

        $this->assertSame('CUS-LEGACY-42', $fresh->fresh()->code);
    }

    // ═══ 3. ORDERS (3) ════════════════════════════════════════════════════════════

    public function test_order_customer_relation_resolves_to_the_crm_class(): void
    {
        $customer = app(CustomerService::class)->create((string) Company::factory()->create()->id, CustomerType::Individual, ['first_name' => 'Rel']);

        $order = Order::create([
            'order_number' => 'ORD-TEST-'.uniqid(),
            'customer_id' => $customer->id,
            'order_date' => now()->toDateString(),
            'status' => OrderStatus::InProgress->value,
            'subtotal' => 100,
            'total' => 100,
        ]);

        $resolved = $order->fresh()->customer;

        $this->assertInstanceOf(CrmCustomer::class, $resolved);
        $this->assertSame($customer->id, $resolved->id);
    }

    public function test_manual_order_creates_a_new_customer_with_a_canonical_code(): void
    {
        $company = Company::factory()->create();
        $product = \Modules\Inventory\Products\Domain\Models\Product::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        $response = $this->actingAs($user)->postJson('/api/orders/manual', [
            'company_id' => $company->id,
            'order_date' => now()->toDateString(),
            'customer_name' => 'Brand New Customer',
            'customer_phone' => '01099998888',
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        self::assertContains($response->status(), [200, 201], $response->getContent());

        $row = DB::table('customers')->where('phone', '01099998888')->first();
        $this->assertNotNull($row);
        // CreateManualOrderAction's own inline MAX-based generator (canonical,
        // unrelated to this task) pads to 5 digits, not 6.
        $this->assertMatchesRegularExpression('/^CUS-\d{5}$/', $row->code);
    }

    public function test_manual_order_reuses_an_existing_customer_by_phone_without_regenerating_its_code(): void
    {
        $company = Company::factory()->create();
        $existing = SalesCustomer::factory()->create(['company_id' => $company->id, 'phone' => '01055556666', 'code' => 'CUS-000900']);
        $product = \Modules\Inventory\Products\Domain\Models\Product::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        $response = $this->actingAs($user)->postJson('/api/orders/manual', [
            'company_id' => $company->id,
            'order_date' => now()->toDateString(),
            'customer_name' => 'Ignored Name', // an existing phone match wins — no new row
            'customer_phone' => '01055556666',
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        self::assertContains($response->status(), [200, 201], $response->getContent());
        $this->assertSame(1, DB::table('customers')->where('phone', '01055556666')->count());
        $this->assertSame('CUS-000900', $existing->fresh()->code);
    }

    // ═══ 4. POS (1) ═══════════════════════════════════════════════════════════════

    public function test_pos_gateway_resolves_a_crm_authored_customer(): void
    {
        $customer = app(CustomerService::class)->create((string) Company::factory()->create()->id, CustomerType::Individual, [
            'first_name' => 'Pos', 'last_name' => 'Shopper', 'phone' => '01077778888',
        ]);

        $gateway = new SalesCustomerGateway;
        $byId = $gateway->findById((string) $customer->id);
        $byPhone = $gateway->findByPhone('01077778888');
        $byCode = $gateway->findByCode($customer->code);

        $this->assertSame((string) $customer->id, $byId->customerId);
        $this->assertSame((string) $customer->id, $byPhone->customerId);
        $this->assertSame((string) $customer->id, $byCode->customerId);
    }

    // ═══ 5. SYNC (1) ══════════════════════════════════════════════════════════════

    public function test_woocommerce_syncer_assigns_a_canonical_code_to_a_new_inbound_customer(): void
    {
        $result = app(WooCommerceCustomerSyncer::class)->sync([
            'billing' => ['email' => 'inbound@shop.com', 'first_name' => 'Inbound', 'last_name' => 'Buyer', 'phone' => '01000000001'],
        ]);

        $this->assertSame('created', $result['action']);
        $row = DB::table('customers')->where('email', 'inbound@shop.com')->first();
        // WooCommerceCustomerSyncer's own nextCustomerCode() (canonical, unrelated
        // to this task) pads to 3 digits, not 6.
        $this->assertMatchesRegularExpression('/^CUS-\d{3}$/', $row->code);
    }

    // ═══ 6. CEP (1) ═══════════════════════════════════════════════════════════════

    public function test_conversation_derived_order_resolves_the_same_customer_identity_by_phone(): void
    {
        // CEP never imports either Customer class (confirmed by static scan) — it hands
        // off customer_phone/customer_email to the SAME order-creation path Commerce
        // already uses, exactly like CreateOrderFromConversationAction does. Proving the
        // hand-off shape resolves to the canonical Crm-visible identity is the whole of
        // what §15 requires, since CEP itself has nothing to repoint.
        $company = Company::factory()->create();
        $existing = app(CustomerService::class)->create((string) $company->id, CustomerType::Individual, [
            'first_name' => 'Conversation', 'last_name' => 'Customer', 'phone' => '01066667777',
        ]);
        $product = \Modules\Inventory\Products\Domain\Models\Product::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        $response = $this->actingAs($user)->postJson('/api/orders/manual', [
            'company_id' => $company->id,
            'order_date' => now()->toDateString(),
            'customer_phone' => $existing->phone, // the exact field CreateOrderFromConversationAction maps from the conversation
            'customer_name' => $existing->name,
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ]);

        self::assertContains($response->status(), [200, 201], $response->getContent());
        $orderId = $response->json('data.id') ?? $response->json('id');
        $this->assertSame($existing->id, DB::table('orders')->where('id', $orderId)->value('customer_id'));
    }

    // ═══ 7. CRM SATELLITES (1) ════════════════════════════════════════════════════

    public function test_crm_module_tree_has_no_reference_to_the_legacy_sales_class(): void
    {
        $dir = base_path('Modules/Crm');
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));

        foreach ($it as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            $this->assertStringNotContainsString(
                'Modules\\Sales\\Customers', $source,
                basename($file->getPathname()).' — every CRM satellite (Sales/Leads, Engagement timeline, Loyalty) must already reference only the canonical Crm Customer class.',
            );
        }
    }

    public function test_lead_service_resolves_with_the_new_customer_service_dependency(): void
    {
        // LeadService depends on CustomerService; this proves the container still
        // auto-wires CustomerService for every downstream CRM satellite consumer,
        // not just CustomerService itself.
        $this->assertInstanceOf(\Modules\Crm\Sales\Domain\Services\LeadService::class, app(\Modules\Crm\Sales\Domain\Services\LeadService::class));
    }

    // ═══ 8. FINANCE (1) ═══════════════════════════════════════════════════════════

    public function test_finance_module_has_no_coupling_to_either_customer_class(): void
    {
        $dir = base_path('Modules/Finance');
        if (! is_dir($dir)) {
            $this->markTestSkipped('Modules/Finance not present in this checkout.');
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));

        foreach ($it as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            foreach (['Modules\\Sales\\Customers\\Domain\\Models\\Customer', 'Modules\\Crm\\Customers\\Domain\\Models\\Customer'] as $needle) {
                $this->assertStringNotContainsString(
                    $needle, $source,
                    basename($file->getPathname()).' — Finance must only ever store a customer_id identifier, never depend on either Customer model class.',
                );
            }
        }
    }

    // ═══ 9. AUTHORIZATION (3) ═════════════════════════════════════════════════════

    /**
     * Route-introspection rather than live HTTP + permission-seeding: what §20/§29
     * actually requires proof of is that Gate A's diff left the ROUTE-LEVEL gating
     * exactly as it already was — not a claim about what a freshly-factoried user's
     * default IAM role happens to grant (untouched by this task and not seeded here).
     */
    private function middlewareFor(string $uri, string $method): array
    {
        $route = collect(app('router')->getRoutes())->first(function ($r) use ($uri, $method): bool {
            $stored = trim((string) $r->uri(), '/');

            return in_array($stored, [$uri, "api/{$uri}"], true) && in_array($method, $r->methods(), true);
        });
        $this->assertNotNull($route, "No route registered for {$method} {$uri}");

        return $route->gatherMiddleware();
    }

    public function test_sales_index_and_show_remain_ungated_by_permission(): void
    {
        $this->assertFalse(
            collect($this->middlewareFor('customers', 'GET'))->contains(fn ($m) => str_starts_with($m, 'permission:')),
            'Sales index/show must stay ungated — the pre-existing asymmetry with CRM, unchanged by Gate A.',
        );
    }

    public function test_crm_index_still_requires_the_view_permission(): void
    {
        $this->assertContains('permission:crm.customers.view', $this->middlewareFor('crm/customers', 'GET'));
    }

    public function test_sales_store_still_requires_the_create_permission_after_the_code_field_removal(): void
    {
        // Proves removing the client-supplied `code` validation rule from
        // StoreCustomerRequest did not also strip the route's permission gate.
        $this->assertContains('permission:crm.customers.create', $this->middlewareFor('customers', 'POST'));
    }

    // ═══ 10. QUEUE (1) ════════════════════════════════════════════════════════════

    public function test_customer_sync_job_serialization_round_trips_with_the_unchanged_sales_class(): void
    {
        // CustomerObserver/CustomerSyncJob/RetrySyncLogAction were deliberately left
        // pointed at the legacy Sales class (see report §4/§18) — this proves that
        // decision costs nothing: SerializesModels round-trips correctly because the
        // Sales class itself was never touched, and it maps the identical row a
        // CRM-authored read would also find.
        $channel = Channel::factory()->create();
        $customer = SalesCustomer::factory()->create(['name' => 'Queued Customer']);

        $job = new CustomerSyncJob($channel, $customer);
        /** @var CustomerSyncJob $restored */
        $restored = unserialize(serialize($job));

        $reflection = new \ReflectionProperty($restored, 'customer');
        $reflection->setAccessible(true);
        $restoredCustomer = $reflection->getValue($restored);

        $this->assertSame($customer->id, $restoredCustomer->id);
        $this->assertSame($customer->id, CrmCustomer::find($restoredCustomer->id)?->id);
    }
}
