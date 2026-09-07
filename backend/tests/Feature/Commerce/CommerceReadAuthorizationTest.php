<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * CD-04 (TASK-ECOS-COMMERCE-PRE-USER-REVIEW-REMEDIATION-002 §5) — Commerce read authorization.
 *
 * Every Commerce read route was authentication-only, even though `sales.orders.view`,
 * `sales.channels.view`, `crm.customers.view` and `sales.customers.export` were all already
 * defined in config/permissions.php AND granted to roles — they were simply attached to no
 * route. Any authenticated tenant user could therefore list orders and customers, read an
 * order's immutable financial snapshot, and pull the full customer export (PII).
 *
 * These cases assert the three-way contract the fix establishes:
 *   - a holder of the correct permission is served
 *   - an authenticated actor WITHOUT it is refused 403 (not merely hidden in the UI)
 *   - an anonymous caller is refused 401
 *
 * plus the sensitive-read separation: ordinary customer view does NOT confer bulk export.
 */
final class CommerceReadAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $grantsBaselineAuthorization = false;

    /**
     * A user in $company holding exactly $permissions and nothing else.
     *
     * @param  list<string>  $permissions
     */
    private function userWith(array $permissions, ?Company $company = null): User
    {
        $company ??= Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        $role = Role::firstOrCreate(
            ['slug' => 'test-read-'.md5(implode('|', $permissions))],
            ['name' => 'Test Reader', 'is_system' => false],
        );

        foreach ($permissions as $name) {
            [$module, $resource, $action] = explode('.', $name);
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                ['module' => $module, 'resource' => $resource, 'action' => $action],
            );

            if (! $role->permissions()->where('permissions.id', $permission->id)->exists()) {
                $role->permissions()->attach($permission->id);
            }
        }

        $user->roles()->attach($role->id);
        $user->unsetRelation('roles');

        return $user;
    }

    // ── Customers: ordinary view ──────────────────────────────────────────────

    public function test_customer_list_is_served_to_a_holder_of_customers_view(): void
    {
        $this->actingAsUnprivileged($this->userWith(['crm.customers.view']));

        $this->getJson('/api/customers')->assertOk();
    }

    public function test_customer_list_is_refused_to_an_authenticated_actor_without_customers_view(): void
    {
        $this->actingAsUnprivileged($this->userWith(['sales.orders.view']));

        $this->getJson('/api/customers')->assertForbidden();
    }

    public function test_customer_detail_is_refused_without_customers_view(): void
    {
        $company = Company::factory()->create();
        $customer = Customer::factory()->create(['company_id' => $company->id]);

        $this->actingAsUnprivileged($this->userWith(['sales.orders.view'], $company));

        $this->getJson("/api/customers/{$customer->id}")->assertForbidden();
    }

    public function test_customer_phone_lookup_is_refused_without_customers_view(): void
    {
        $this->actingAsUnprivileged($this->userWith(['sales.orders.view']));

        $this->getJson('/api/customers/search-by-phone?phone=01000000000')->assertForbidden();
    }

    // ── Customers: sensitive read (bulk export) ───────────────────────────────

    public function test_customer_export_is_refused_to_a_holder_of_only_ordinary_customer_view(): void
    {
        // The whole point of the ordinary-vs-sensitive split: reading one customer is not
        // permission to egress the entire customer book.
        $this->actingAsUnprivileged($this->userWith(['crm.customers.view']));

        $this->getJson('/api/customers/export')->assertForbidden();
    }

    public function test_customer_export_is_served_to_a_holder_of_the_export_permission(): void
    {
        $this->actingAsUnprivileged($this->userWith(['sales.customers.export']));

        $this->getJson('/api/customers/export')->assertOk();
    }

    // ── Orders ────────────────────────────────────────────────────────────────

    public function test_order_list_is_served_to_a_holder_of_orders_view(): void
    {
        $this->actingAsUnprivileged($this->userWith(['sales.orders.view']));

        $this->getJson('/api/orders')->assertOk();
    }

    public function test_order_list_is_refused_to_an_authenticated_actor_without_orders_view(): void
    {
        $this->actingAsUnprivileged($this->userWith(['crm.customers.view']));

        $this->getJson('/api/orders')->assertForbidden();
    }

    public function test_order_detail_is_refused_without_orders_view(): void
    {
        $this->actingAsUnprivileged($this->userWith(['crm.customers.view']));

        // Refused before the order is ever resolved — authorization precedes existence.
        $this->getJson('/api/orders/00000000-0000-0000-0000-000000000000')->assertForbidden();
    }

    public function test_order_financial_snapshot_is_refused_without_orders_view(): void
    {
        $this->actingAsUnprivileged($this->userWith(['crm.customers.view']));

        $this->getJson('/api/orders/00000000-0000-0000-0000-000000000000/snapshot')->assertForbidden();
    }

    public function test_order_financial_snapshot_is_served_to_a_holder_of_orders_view(): void
    {
        $this->actingAsUnprivileged($this->userWith(['sales.orders.view']));

        // No snapshot exists for this id, so the controller answers `data: null` — a 200 with
        // no payload. What matters here is that authorization admitted the caller.
        $this->getJson('/api/orders/00000000-0000-0000-0000-000000000000/snapshot')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_order_activity_timeline_is_refused_without_orders_view(): void
    {
        $this->actingAsUnprivileged($this->userWith(['crm.customers.view']));

        $this->getJson('/api/orders/00000000-0000-0000-0000-000000000000/activities')->assertForbidden();
    }

    public function test_order_status_and_filter_lookups_are_refused_without_orders_view(): void
    {
        $this->actingAsUnprivileged($this->userWith(['crm.customers.view']));

        $this->getJson('/api/orders/statuses')->assertForbidden();
        $this->getJson('/api/orders/filter/payment-methods')->assertForbidden();
        $this->getJson('/api/orders/filter/shipping-companies')->assertForbidden();
    }

    // ── Channels ──────────────────────────────────────────────────────────────

    public function test_channel_list_is_refused_without_channels_view(): void
    {
        $this->actingAsUnprivileged($this->userWith(['sales.orders.view']));

        $this->getJson('/api/channels')->assertForbidden();
    }

    public function test_channel_detail_is_refused_without_channels_view(): void
    {
        $company = Company::factory()->create();
        $brand = Brand::factory()->create(['company_id' => $company->id]);
        $channel = Channel::factory()->create(['brand_id' => $brand->id]);

        $this->actingAsUnprivileged($this->userWith(['sales.orders.view'], $company));

        $this->getJson("/api/channels/{$channel->id}")->assertForbidden();
    }

    public function test_product_mapping_list_is_refused_without_channels_view(): void
    {
        $this->actingAsUnprivileged($this->userWith(['sales.orders.view']));

        $this->getJson('/api/product-mappings')->assertForbidden();
    }

    public function test_stock_sync_log_list_is_refused_without_channels_view(): void
    {
        $this->actingAsUnprivileged($this->userWith(['sales.orders.view']));

        $this->getJson('/api/stock-sync-logs')->assertForbidden();
    }

    // ── Anonymous ─────────────────────────────────────────────────────────────

    public function test_anonymous_access_to_commerce_reads_is_unauthenticated(): void
    {
        foreach ([
            '/api/orders',
            '/api/customers',
            '/api/customers/export',
            '/api/channels',
            '/api/product-mappings',
        ] as $endpoint) {
            $this->getJson($endpoint)->assertUnauthorized();
        }
    }

    // ── Super-admin semantics preserved ───────────────────────────────────────

    public function test_system_role_is_admitted_to_every_commerce_read_without_explicit_grants(): void
    {
        // RequirePermissionMiddleware asks AuthorizationGateway::decision(), which allows any
        // is_system role unconditionally. Wiring these permissions must not require a system
        // role to hold explicit grants.
        $this->actingAsUnprivileged($this->grantSystemRole(User::factory()->create([
            'company_id' => Company::factory()->create()->id,
        ])));

        $this->getJson('/api/orders')->assertOk();
        $this->getJson('/api/customers')->assertOk();
        $this->getJson('/api/customers/export')->assertOk();
        $this->getJson('/api/channels')->assertOk();
        $this->getJson('/api/product-mappings')->assertOk();
    }
}
