<?php

declare(strict_types=1);

namespace Modules\Sales\Customers\Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Application\Actions\BlockCustomerOrPhoneAction;
use Modules\Sales\Customers\Domain\Models\Customer;
use Modules\Sales\Customers\Domain\Models\CustomerBlock;
use Modules\Sales\Customers\Domain\Services\BlockedCustomerPolicy;
use Modules\Sales\Customers\Domain\Services\PhoneNormalizer;
use Tests\TestCase;

/**
 * TASK-ECOS-COMMERCE-CUSTOMERS-BATCH-02-BLOCKED-CUSTOMERS-009.
 *
 * Covers the canonical Block Authority itself (§4-§12): phone-first identity, the
 * DB-level active-block uniqueness invariant (§9/§50), Customer binding (§10),
 * tenant isolation (§8/§37), phone normalization (§38), unblock/history (§6/§28),
 * and authorization (§35/§36). Order/fulfillment enforcement is covered separately
 * in BlockedOrderFulfillmentTest.
 *
 * Uses the REAL HTTP endpoints (routes -> middleware -> controllers -> actions),
 * the same philosophy OrderPaymentFulfillmentReevaluationTest already established
 * in this codebase, and the FULL (unrestricted) migration set via RefreshDatabase —
 * this suite genuinely needs the IAM roles/permissions tables (§36 authorization),
 * which the minimal hand-traced schema Task 3 used deliberately excludes. Paid
 * once per PHPUnit process (RefreshDatabaseState::$migrated), not once per test.
 */
final class CustomerBlockingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test-harness-only: restricts RefreshDatabase's migrate:fresh to exactly the
     * tables this suite (and its sibling BlockedOrderFulfillmentTest, run in the
     * same PHPUnit process so the cost is paid once) needs, instead of the whole
     * application's ~730 migrations — mirrors CustomerIntelligenceMetricsTest's
     * established override, extended with IAM (roles/permissions, §36
     * authorization), Warehouses/InventoryItems/StockLedgerEntries and the Orders
     * reservation/audit columns the fulfillment-integration suite needs, plus this
     * task's own new tables. The full unrestricted chain was tried first and
     * measured at ~2-3 completed migrations/minute in this environment (~53 done
     * after 20+ minutes) — infeasible within a bounded test run, consistent with
     * the container-degradation lesson from TASK-...-CUSTOMER-INTELLIGENCE-008.
     */
    protected function migrateFreshUsing()
    {
        return array_merge([
            '--drop-views' => $this->shouldDropViews(),
            '--drop-types' => $this->shouldDropTypes(),
            '--seed' => $this->shouldSeed(),
        ], [
            '--path' => [
                'database/migrations/0001_01_01_000000_create_users_table.php',
                'database/migrations/2026_07_07_000002_add_company_id_to_users_table.php',
                'Modules/Organization/Companies/Infrastructure/Database/Migrations',
                'Modules/IAM/Infrastructure/Database/Migrations',
                'Modules/MasterData/Units/Infrastructure/Database/Migrations',
                'Modules/MasterData/Categories/Infrastructure/Database/Migrations/2026_06_23_100100_create_categories_table.php',
                'database/migrations/2026_07_02_300000_add_type_to_categories_table.php',
                'Modules/MasterData/Categories/Infrastructure/Database/Migrations/2026_07_04_100000_refactor_categories_type_to_scope.php',
                'Modules/Organization/Brands/Infrastructure/Database/Migrations/2026_07_05_140000_create_brands_table.php',
                'Modules/Organization/Branches/Infrastructure/Database/Migrations/2026_06_22_130000_create_branches_table.php',
                'Modules/MasterData/Warehouses/Infrastructure/Database/Migrations/2026_06_23_100200_create_warehouses_table.php',
                'Modules/MasterData/Warehouses/Infrastructure/Database/Migrations/2026_07_05_160000_remove_branch_id_from_warehouses_table.php',
                'Modules/Inventory/Products/Infrastructure/Database/Migrations/2026_06_23_110000_create_products_table.php',
                'Modules/Inventory/Products/Infrastructure/Database/Migrations/2026_06_23_111000_add_enrichment_fields_to_products_table.php',
                'Modules/Inventory/Products/Infrastructure/Database/Migrations/2026_06_25_230002_add_cost_intelligence_to_products_table.php',
                'Modules/Inventory/Products/Infrastructure/Database/Migrations/2026_06_25_250002_add_current_fifo_cost_to_products_table.php',
                'Modules/Inventory/Products/Infrastructure/Database/Migrations/2026_06_29_000001_add_manufacturing_fields_to_products_table.php',
                'Modules/Sales/Customers/Tests/Support/Migrations/2026_07_06_000001_add_company_id_to_products_table_test_only.php',
                'Modules/Sales/Customers/Tests/Support/Migrations/2026_07_06_100001_migrate_products_to_brand_ownership_test_only.php',
                'Modules/Inventory/InventoryItems/Infrastructure/Database/Migrations/2026_06_24_800000_create_inventory_items_table.php',
                'Modules/Inventory/InventoryItems/Infrastructure/Database/Migrations/2026_06_24_810000_create_stock_ledger_entries_table.php',
                'Modules/Inventory/InventoryItems/Infrastructure/Database/Migrations/2026_07_20_100000_fix_inventory_items_soft_delete_unique.php',
                'Modules/Commerce/Channels/Infrastructure/Database/Migrations/2026_06_23_170000_create_channels_table.php',
                'Modules/Commerce/Channels/Infrastructure/Database/Migrations/2026_06_23_600000_add_sync_customers_and_webhook_ids_to_channels.php',
                // Not used directly by this suite — required only because OrderPreparationObserver
                // (an Eloquent observer registered on Order::updated(), unconditionally) and a
                // Product-side listener query these tables as a side effect of the real
                // FulfillmentEngine/Product-factory writes this suite performs. Base shape only.
                'Modules/Commerce/ProductMappings/Infrastructure/Database/Migrations/2026_06_23_180000_create_product_channel_mappings_table.php',
                'Modules/Operations/Preparation/Infrastructure/Database/Migrations/2026_07_05_100100_create_preparation_waves_table.php',
                'Modules/Operations/Preparation/Infrastructure/Database/Migrations/2026_07_05_100200_create_preparation_wave_orders_table.php',
                'Modules/Operations/Preparation/Infrastructure/Database/Migrations/2026_08_13_100000_add_postponed_at_to_preparation_wave_orders.php',
                'Modules/Operations/Preparation/Infrastructure/Database/Migrations/2026_08_15_100002_add_membership_release_to_preparation_wave_orders.php',
                'Modules/Operations/DemandAnalysis/Infrastructure/Database/Migrations/2026_07_16_200001_create_wave_material_demand_table.php',
                'database/migrations/2026_07_05_200100_create_feature_flags_table.php',
                'Modules/CostManagement/Infrastructure/Database/Migrations/2026_07_02_200004_create_pricing_reviews_table.php',
                'Modules/Manufacturing/BillsOfMaterials/Infrastructure/Database/Migrations/2026_06_23_220000_create_bills_of_materials_tables.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_15_000001_create_order_notes_table.php',
                // Same reason as the block above: side-effect tables touched by OrderResource's
                // relationship resolution (driver/trip) and the global enterprise-events sink —
                // not used by this suite's own assertions.
                'database/migrations/2026_07_16_000001_create_enterprise_events_table.php',
                'database/migrations/2026_07_16_000002_create_enterprise_event_processing_log_table.php',
                'database/migrations/2026_07_16_000003_create_enterprise_dead_letter_queue_table.php',
                'Modules/Operations/Preparation/Infrastructure/Database/Migrations/2026_07_06_210005_add_assignment_fields_to_orders_table.php',
                'Modules/Logistics/Distribution/Infrastructure/Database/Migrations/2026_07_16_000001_create_distribution_zones_table.php',
                'Modules/Logistics/ShippingCompanies/Infrastructure/Database/Migrations/2026_07_23_100000_create_logistics_shipping_companies_table.php',
                'Modules/Logistics/Drivers/Infrastructure/Database/Migrations/2026_07_24_100000_create_logistics_vehicles_table.php',
                'Modules/Logistics/Drivers/Infrastructure/Database/Migrations/2026_07_24_100001_create_logistics_drivers_table.php',
                'Modules/Logistics/Drivers/Infrastructure/Database/Migrations/2026_07_24_100003_create_logistics_driver_vehicle_assignments_table.php',
                'Modules/Logistics/Distribution/Infrastructure/Database/Migrations/2026_07_28_100000_create_distribution_trips_table.php',
                'Modules/Logistics/Distribution/Infrastructure/Database/Migrations/2026_07_28_100001_create_distribution_trip_orders_table.php',
                'Modules/Operations/Preparation/Infrastructure/Database/Migrations/2026_07_06_110000_create_preparation_sessions_table.php',
                'Modules/Operations/Preparation/Infrastructure/Database/Migrations/2026_07_06_210004_create_preparation_session_orders_table.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_06_23_200000_create_orders_table.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_06_23_200001_create_order_lines_table.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_14_100001_add_fulfillment_quantities_to_order_lines.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_06_23_201000_add_ecommerce_fields_to_orders_table.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_06_23_210000_add_billing_financials_fees_coupons_to_orders.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_06_25_220002_add_assigned_warehouse_to_orders_table.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_06_25_240000_add_inventory_lifecycle_to_orders_table.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_06_100003_add_manual_order_fields_to_orders_table.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_06_200000_add_location_to_orders_table.php',
                'database/migrations/2026_07_14_000001_add_enterprise_address_fields_to_orders.php',
                'database/migrations/2026_07_14_000002_add_customer_snapshot_fields_to_orders.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_06_300000_create_order_events_table.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_06_600000_add_delivery_fields_to_orders_table.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_10_000001_add_confirmed_at_to_orders_table.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_11_000001_add_customer_name_to_orders_table.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_14_100000_enhance_order_events_for_activity_timeline.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_14_100002_add_internal_notes_and_creator_to_orders.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_15_100000_extend_order_events_enterprise_audit.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_15_200000_add_actor_role_to_order_events.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_15_300000_add_actor_email_to_order_events.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_18_100000_add_reservation_status_to_orders_table.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_18_100001_create_order_reservation_audits_table.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_08_910002_add_preparation_completed_at_to_orders_table.php',
                'database/migrations/2026_07_13_000002_add_reschedule_fields_to_orders.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_09_15_100002_add_hold_reason_code_to_orders_table.php',
                'Modules/Sales/Customers/Infrastructure/Database/Migrations/2026_06_23_160000_create_customers_table.php',
                'Modules/Sales/Customers/Infrastructure/Database/Migrations/2026_07_06_100001_create_customer_addresses_table.php',
                'Modules/Sales/Customers/Infrastructure/Database/Migrations/2026_07_08_910001_add_company_id_to_customers_table.php',
                'Modules/Sales/Customers/Infrastructure/Database/Migrations/2026_07_22_200000_create_customer_brands_table.php',
                'Modules/Sales/Customers/Infrastructure/Database/Migrations/2026_09_15_100000_create_customer_blocks_table.php',
                'Modules/Sales/Customers/Infrastructure/Database/Migrations/2026_09_15_100001_create_order_block_overrides_table.php',
            ],
        ]);
    }

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function user(?Company $company = null): User
    {
        return User::factory()->create(['company_id' => ($company ?? $this->company)->id]);
    }

    private function customer(?Company $company = null, array $attrs = []): Customer
    {
        return Customer::factory()->create(array_merge([
            'company_id' => ($company ?? $this->company)->id,
        ], $attrs));
    }

    /** A user holding ONLY the given crm.customers.* actions — never the system role. */
    private function userWithPermissions(array $actions): User
    {
        $user = User::factory()->create(['company_id' => $this->company->id]);

        $role = Role::create(['slug' => 'blocking-test-role-'.uniqid(), 'name' => 'Blocking Test Role', 'is_system' => false]);

        foreach ($actions as $action) {
            $permission = Permission::firstOrCreate(
                ['name' => "crm.customers.{$action}"],
                ['module' => 'crm', 'resource' => 'customers', 'action' => $action, 'description' => $action],
            );
            $role->permissions()->attach($permission->id);
        }

        $user->roles()->attach($role->id);

        return $user;
    }

    private function activeBlock(string $companyId, string $normalizedPhone): ?CustomerBlock
    {
        return app(BlockedCustomerPolicy::class)->activeBlockForPhone($companyId, $normalizedPhone);
    }

    // ── §11 — Block an existing Customer ────────────────────────────────────

    public function test_block_existing_customer_by_id(): void
    {
        $customer = $this->customer(attrs: ['phone' => '01012345678']);

        $response = $this->actingAs($this->user())
            ->postJson("/api/customers/{$customer->id}/block", ['reason' => 'Repeated chargebacks']);

        $response->assertOk();
        $this->assertNotNull($this->activeBlock((string) $this->company->id, '201012345678'));

        $show = $this->actingAs($this->user())->getJson("/api/customers/{$customer->id}");
        $show->assertOk();
        $this->assertTrue($show->json('data.is_blocked'));
        $this->assertSame('Repeated chargebacks', $show->json('data.block_reason'));
    }

    public function test_block_requires_a_reason(): void
    {
        $customer = $this->customer(attrs: ['phone' => '01012345678']);

        $response = $this->actingAs($this->user())
            ->postJson("/api/customers/{$customer->id}/block", ['reason' => '']);

        $response->assertStatus(422);
    }

    // ── §12 — Block a phone before any Customer exists ──────────────────────

    public function test_block_phone_before_customer_exists(): void
    {
        $response = $this->actingAs($this->user())
            ->postJson('/api/customers/block-phone', ['phone' => '01099998888', 'reason' => 'Reported fraud']);

        $response->assertOk();

        $block = $this->activeBlock((string) $this->company->id, '201099998888');
        $this->assertNotNull($block);
        $this->assertNull($block->customer_id, 'No Customer record may be fabricated (§12).');
        $this->assertSame(0, Customer::query()->count());
    }

    // ── §10 — Customer binding ───────────────────────────────────────────────

    public function test_a_later_customer_with_the_previously_blocked_phone_is_recognized_as_blocked(): void
    {
        $this->actingAs($this->user())
            ->postJson('/api/customers/block-phone', ['phone' => '01099998888', 'reason' => 'Reported fraud'])
            ->assertOk();

        $customer = $this->customer(attrs: ['phone' => '01099998888']);

        // The read model matches by phone/mobile, not customer_id alone (§10) — a
        // Customer merely being VIEWED must show as blocked without needing the
        // opportunistic customer_id binding (a write-path side effect, see below)
        // to have run first.
        $show = $this->actingAs($this->user())->getJson("/api/customers/{$customer->id}");
        $show->assertOk();
        $this->assertTrue(
            $show->json('data.is_blocked'),
            'The existing phone-first block must remain authoritative without manual recreation (§10).',
        );
        $this->assertNull(
            $this->activeBlock((string) $this->company->id, '201099998888')->customer_id,
            'Merely viewing the Customer must not itself perform the binding write.',
        );

        // Opportunistic binding (§10) happens on the next WRITE that touches this
        // identity — here, blocking the same phone again (idempotent path).
        $this->actingAs($this->user())
            ->postJson('/api/customers/block-phone', ['phone' => '01099998888', 'reason' => 'Re-checked'])
            ->assertOk();

        $block = $this->activeBlock((string) $this->company->id, '201099998888');
        $this->assertSame((string) $customer->id, (string) $block->customer_id);
    }

    // ── §9 — Duplicate active block prevention (sequential) ─────────────────

    public function test_blocking_an_already_blocked_phone_is_idempotent_not_an_error(): void
    {
        $customer = $this->customer(attrs: ['phone' => '01012345678']);
        $actor = $this->user();

        $first = $this->actingAs($actor)->postJson("/api/customers/{$customer->id}/block", ['reason' => 'First reason']);
        $first->assertOk();
        $firstBlockId = $first->json('data.id');

        $second = $this->actingAs($actor)->postJson("/api/customers/{$customer->id}/block", ['reason' => 'Second reason']);
        $second->assertOk();

        $this->assertSame($firstBlockId, $second->json('data.id'), 'Must reuse the existing active block, not create a second one.');
        $this->assertSame(1, CustomerBlock::query()->where('is_active', true)->count());
    }

    // ── §39-A — Real concurrency: two concurrent blocks for the same phone ──

    /**
     * Same technique as CustomerCodeSequenceTest::test_concurrent_first_creates_for_the_
     * same_company_do_not_collide() — two independent MySQL sessions against the same
     * database, not two PHP threads/processes, sufficient to exercise genuine InnoDB
     * cross-transaction lock contention on customer_blocks.active_phone_key.
     *
     * Documents the MySQL behaviour §50 requires: a second transaction's INSERT of the
     * SAME active_phone_key value blocks behind the first transaction's still-
     * uncommitted insert (a real UNIQUE index, not a gap lock over zero rows — this is
     * NOT the same failure mode Task 2's count()+1 defect had), and once the first
     * commits, the second's insert must genuinely be refused as a duplicate.
     */
    public function test_two_concurrent_block_attempts_for_the_same_phone_yield_one_active_block(): void
    {
        $companyId = (string) $this->company->id;
        $normalized = app(PhoneNormalizer::class)->normalize('01055556666');

        config(['database.connections.mysql_secondary' => config('database.connections.mysql')]);
        $connB = DB::connection('mysql_secondary');
        $connB->statement('SET SESSION innodb_lock_wait_timeout = 1');

        DB::beginTransaction();
        DB::table('customer_blocks')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid7(),
            'company_id' => $companyId,
            'customer_id' => null,
            'normalized_phone' => $normalized,
            'is_active' => true,
            'block_reason' => 'Connection A',
            'blocked_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $blocked = false;

        try {
            $connB->beginTransaction();
            $connB->table('customer_blocks')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid7(),
                'company_id' => $companyId,
                'customer_id' => null,
                'normalized_phone' => $normalized,
                'is_active' => true,
                'block_reason' => 'Connection B',
                'blocked_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $connB->commit();
        } catch (QueryException $e) {
            $blocked = str_contains($e->getMessage(), 'Lock wait timeout exceeded');
            $connB->rollBack();
        }

        DB::commit();

        $this->assertTrue(
            $blocked,
            'Connection B must block on connection A\'s uncommitted insert of the same '
            .'active_phone_key, not race past it and create two active authorities.',
        );

        $this->assertSame(1, CustomerBlock::query()->where('is_active', true)->count());
    }

    // ── §39-B — block vs unblock race ────────────────────────────────────────

    /**
     * An uncommitted UNBLOCK (which clears active_phone_key by writing is_active=0)
     * must not let a concurrent NEW block for the same phone insert successfully before
     * the unblock actually commits — otherwise two active rows could momentarily exist
     * for the same identity the instant both commit.
     */
    public function test_block_vs_unblock_race_cannot_leave_contradictory_active_state(): void
    {
        $companyId = (string) $this->company->id;
        $normalized = app(PhoneNormalizer::class)->normalize('01077778888');

        $blockId = (string) \Illuminate\Support\Str::uuid7();
        DB::table('customer_blocks')->insert([
            'id' => $blockId,
            'company_id' => $companyId,
            'customer_id' => null,
            'normalized_phone' => $normalized,
            'is_active' => true,
            'block_reason' => 'Original block',
            'blocked_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        config(['database.connections.mysql_secondary' => config('database.connections.mysql')]);
        $connB = DB::connection('mysql_secondary');
        $connB->statement('SET SESSION innodb_lock_wait_timeout = 1');

        // Connection A: uncommitted UNBLOCK of the existing row.
        DB::beginTransaction();
        DB::table('customer_blocks')->where('id', $blockId)->update(['is_active' => false, 'updated_at' => now()]);

        $blocked = false;

        try {
            // Connection B: a brand-new block attempt for the SAME phone.
            $connB->beginTransaction();
            $connB->table('customer_blocks')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid7(),
                'company_id' => $companyId,
                'customer_id' => null,
                'normalized_phone' => $normalized,
                'is_active' => true,
                'block_reason' => 'Racing new block',
                'blocked_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $connB->commit();
        } catch (QueryException $e) {
            $blocked = str_contains($e->getMessage(), 'Lock wait timeout exceeded');
            $connB->rollBack();
        }

        DB::commit();

        $this->assertTrue(
            $blocked,
            'A concurrent new block must not be able to insert while the unblock of the '
            .'existing active row for the same phone is still uncommitted.',
        );

        // After A's unblock commits (B lost the race and rolled back), history is
        // consistent: exactly one row, now inactive, no orphaned second active row.
        $this->assertSame(0, CustomerBlock::query()->where('is_active', true)->count());
        $this->assertSame(1, CustomerBlock::query()->where('normalized_phone', $normalized)->count());
    }

    // ── §28 — Unblock: reason, history, no mass-release ──────────────────────

    public function test_unblock_records_reason_actor_and_timestamp(): void
    {
        $customer = $this->customer(attrs: ['phone' => '01012345678']);
        $blockResponse = $this->actingAs($this->user())
            ->postJson("/api/customers/{$customer->id}/block", ['reason' => 'Chargeback'])
            ->assertOk();
        $blockId = $blockResponse->json('data.id');

        $response = $this->actingAs($this->user())->postJson("/api/customers/{$customer->id}/unblock", [
            'block_id' => $blockId,
            'reason' => 'Dispute resolved in customer favor',
        ]);
        $response->assertOk();

        $block = CustomerBlock::query()->findOrFail($blockId);
        $this->assertFalse($block->is_active);
        $this->assertSame('Dispute resolved in customer favor', $block->unblock_reason);
        $this->assertNotNull($block->unblocked_at);
        $this->assertNotNull($block->unblocked_by);

        $show = $this->actingAs($this->user())->getJson("/api/customers/{$customer->id}");
        $this->assertFalse($show->json('data.is_blocked'));
    }

    public function test_block_history_lists_both_blocked_and_unblocked_episodes(): void
    {
        $customer = $this->customer(attrs: ['phone' => '01012345678']);
        $actor = $this->user();

        $block = $this->actingAs($actor)
            ->postJson("/api/customers/{$customer->id}/block", ['reason' => 'First episode'])
            ->assertOk();

        $this->actingAs($actor)->postJson("/api/customers/{$customer->id}/unblock", [
            'block_id' => $block->json('data.id'),
            'reason' => 'Resolved',
        ])->assertOk();

        $history = $this->actingAs($actor)->getJson("/api/customers/{$customer->id}/block-history");
        $history->assertOk();

        $this->assertCount(1, $history->json('data'));
        $this->assertSame('First episode', $history->json('data.0.block_reason'));
        $this->assertSame('Resolved', $history->json('data.0.unblock_reason'));
    }

    public function test_unblock_does_not_mass_release_or_touch_orders(): void
    {
        $customer = $this->customer(attrs: ['phone' => '01012345678']);
        $orderId = (string) \Illuminate\Support\Str::uuid7();
        DB::table('orders')->insert([
            'id' => $orderId,
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'order_number' => 'ORD-HELD-1',
            'status' => 'on_hold',
            'hold_reason_code' => 'blocked_customer',
            'total' => 100,
            'order_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $actor = $this->user();
        $block = $this->actingAs($actor)
            ->postJson("/api/customers/{$customer->id}/block", ['reason' => 'Fraud'])
            ->assertOk();

        $this->actingAs($actor)->postJson("/api/customers/{$customer->id}/unblock", [
            'block_id' => $block->json('data.id'),
            'reason' => 'Resolved',
        ])->assertOk();

        $this->assertSame(
            'on_hold',
            (string) DB::table('orders')->where('id', $orderId)->value('status'),
            'Unblock must never itself move an Order out of On Hold (§28) — that requires an explicit per-Order decision.',
        );
    }

    // ── §8/§37 — Tenant isolation ─────────────────────────────────────────────

    public function test_company_a_cannot_see_or_unblock_company_bs_blocked_phone(): void
    {
        $companyB = Company::factory()->create();
        $customerB = $this->customer($companyB, ['phone' => '01055551234']);

        $blockB = $this->actingAs($this->user($companyB))
            ->postJson("/api/customers/{$customerB->id}/block", ['reason' => 'Company B fraud'])
            ->assertOk();

        // Company A's own actor cannot reach Company B's Customer record at all.
        $this->actingAs($this->user())
            ->getJson("/api/customers/{$customerB->id}")
            ->assertStatus(404);

        // ...and cannot unblock Company B's block id even if it somehow obtained it.
        $foreignUnblock = $this->actingAs($this->user())
            ->postJson("/api/customers/{$customerB->id}/unblock", [
                'block_id' => $blockB->json('data.id'),
                'reason' => 'Attempted cross-tenant unblock',
            ]);
        $this->assertContains($foreignUnblock->status(), [403, 404]);

        $block = CustomerBlock::query()->findOrFail($blockB->json('data.id'));
        $this->assertTrue($block->is_active, 'Company B\'s block must remain untouched by Company A\'s request.');
    }

    public function test_same_phone_may_have_independent_block_state_across_companies(): void
    {
        $companyB = Company::factory()->create();
        $phone = '01066667777';

        $this->customer(attrs: ['phone' => $phone]);
        $this->customer($companyB, ['phone' => $phone]);

        $this->actingAs($this->user())->postJson('/api/customers/block-phone', ['phone' => $phone, 'reason' => 'A only'])->assertOk();

        $normalized = app(PhoneNormalizer::class)->normalize($phone);
        $this->assertNotNull($this->activeBlock((string) $this->company->id, $normalized));
        $this->assertNull($this->activeBlock((string) $companyB->id, $normalized));
    }

    // ── §38 — Normalization ───────────────────────────────────────────────────

    public function test_equivalent_phone_formats_resolve_to_the_same_block_identity(): void
    {
        $normalizer = app(PhoneNormalizer::class);

        $this->assertSame('201012345678', $normalizer->normalize('01012345678'));
        $this->assertSame('201012345678', $normalizer->normalize('+201012345678'));
        $this->assertSame($normalizer->normalize('01012345678'), $normalizer->normalize('+201012345678'));

        $this->actingAs($this->user())
            ->postJson('/api/customers/block-phone', ['phone' => '01012345678', 'reason' => 'Blocked via local format'])
            ->assertOk();

        $this->assertNotNull(
            $this->activeBlock((string) $this->company->id, $normalizer->normalize('+201012345678')),
            'A block created via the local format must be found via the +20 international format — same normalized identity.',
        );
    }

    // ── §35/§36 — Authorization ───────────────────────────────────────────────

    public function test_unauthorized_user_cannot_block_a_customer(): void
    {
        $customer = $this->customer(attrs: ['phone' => '01012345678']);
        $viewOnly = $this->userWithPermissions(['view']);

        $response = $this->actingAsUnprivileged($viewOnly)
            ->postJson("/api/customers/{$customer->id}/block", ['reason' => 'Attempted']);

        $response->assertStatus(403);
        $this->assertNull($this->activeBlock((string) $this->company->id, '201012345678'));
    }

    public function test_unauthorized_user_cannot_unblock(): void
    {
        $customer = $this->customer(attrs: ['phone' => '01012345678']);
        $blocker = $this->user();
        $block = $this->actingAs($blocker)
            ->postJson("/api/customers/{$customer->id}/block", ['reason' => 'Fraud'])
            ->assertOk();

        $viewOnly = $this->userWithPermissions(['view']);
        $response = $this->actingAsUnprivileged($viewOnly)
            ->postJson("/api/customers/{$customer->id}/unblock", [
                'block_id' => $block->json('data.id'),
                'reason' => 'Attempted',
            ]);

        $response->assertStatus(403);
        $this->assertTrue(CustomerBlock::query()->findOrFail($block->json('data.id'))->is_active);
    }

    public function test_authorized_block_only_role_can_block_but_not_override(): void
    {
        $customer = $this->customer(attrs: ['phone' => '01012345678']);
        $blockOnly = $this->userWithPermissions(['view', 'block', 'unblock']);

        $response = $this->actingAsUnprivileged($blockOnly)
            ->postJson("/api/customers/{$customer->id}/block", ['reason' => 'Authorized block']);

        $response->assertOk();
    }

    // ── §32 — narrow policy sanity (unit-level, no HTTP) ─────────────────────

    public function test_blocked_customer_policy_action_is_idempotent_when_called_directly(): void
    {
        $customer = $this->customer(attrs: ['phone' => '01012345678']);
        $action = app(BlockCustomerOrPhoneAction::class);

        $first = $action->execute((string) $this->company->id, (string) $customer->id, null, 'Reason A', null);
        $second = $action->execute((string) $this->company->id, (string) $customer->id, null, 'Reason B', null);

        $this->assertSame($first->data()->id, $second->data()->id);
        $this->assertSame(1, CustomerBlock::query()->where('is_active', true)->count());
    }
}
