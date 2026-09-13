<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\Logistics\Distribution\Domain\Enums\TripType;
use Modules\Logistics\Distribution\Domain\Models\DeliveryStop;
use Modules\Logistics\Distribution\Domain\Models\Trip;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-V1.1-OPS-02-CLOSURE — Shipping Orders' nav entry has always
 * required `logistics.shipping.view`; the API underneath it required
 * `logistics.distribution.view` instead. The canonical `Moderation` role
 * grants the former and explicitly denies the latter (§17.8 of its own
 * catalogue definition), so a Moderation user saw the link and got a 403.
 * This suite proves the API is now aligned to the page's own authority.
 *
 * Deliberately uses actingAsUnprivileged() with a role built from an EXACT,
 * explicit permission set (never actingAs()'s auto system-role bypass) — same
 * discipline as Tests\Feature\Security\DriverRbacTenancySecurityTest, for the
 * same reason: actingAs() would prove only that the route resolves, not that
 * the intended permission actually gates it.
 */
final class ShippingOrderAuthorizationTest extends TestCase
{
    use DatabaseTransactions;

    private const BASE = '/api/operations/shipping-orders';

    private const SHIPPING_VIEW = 'logistics.shipping.view';

    /** A user wearing a real, non-system role holding exactly $names. */
    private function userWithGrants(Company $company, string $roleSlug, array $names): User
    {
        $role = Role::firstOrCreate(['slug' => $roleSlug], ['name' => $roleSlug, 'is_system' => false]);

        $pivot = [];
        foreach ($names as $name) {
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                ['module' => Str::before($name, '.'), 'action' => Str::afterLast($name, '.')],
            );
            $pivot[$permission->id] = ['effect' => 'allow', 'data_scope' => 'all'];
        }
        $role->permissions()->sync($pivot);

        $user = User::factory()->create(['company_id' => $company->id]);
        $user->roles()->attach($role->id);

        return $user;
    }

    private function makeOrder(Company $company): string
    {
        $customerId = (string) Str::uuid();
        DB::table('customers')->insert([
            'id' => $customerId,
            'code' => 'CUS-'.substr(md5($customerId), 0, 8),
            'name' => 'Auth Test Customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orderId = (string) Str::uuid();
        DB::table('orders')->insert([
            'id' => $orderId,
            'company_id' => $company->id,
            'customer_id' => $customerId,
            'order_number' => 'ORD-'.substr(md5($orderId), 0, 8),
            'order_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $orderId;
    }

    /** A real, eligible (out_for_delivery) Shipping Orders row for $company. */
    private function eligibleRow(Company $company, User $creator): void
    {
        $trip = Trip::create([
            'company_id' => $company->id,
            'trip_number' => 'TRP-'.substr(md5(uniqid('', true)), 0, 6),
            'name' => 'Auth Test Trip',
            'type' => TripType::CompanyVehicle->value,
            'capacity' => 10,
            'created_by' => $creator->id,
        ]);

        DeliveryStop::create([
            'trip_id' => $trip->id,
            'order_id' => $this->makeOrder($company),
            'sequence' => 1,
            'status' => 'in_progress',
        ]);
    }

    public function test_a_shipping_view_only_user_can_read_company_scoped_shipping_orders(): void
    {
        $company = Company::factory()->create();
        $user = $this->userWithGrants($company, 'shipping-view-only-test', [self::SHIPPING_VIEW]);
        $this->eligibleRow($company, $user);

        $response = $this->actingAsUnprivileged($user)->getJson(self::BASE);

        $response->assertOk();
        self::assertCount(1, $response->json('data.items'), 'a shipping-view-only user must be able to read the page it navigates to.');
    }

    public function test_a_user_lacking_the_permission_is_denied(): void
    {
        $company = Company::factory()->create();
        // Real role, real grant set — deliberately WITHOUT logistics.shipping.view
        // or logistics.distribution.view, mirroring a role with no shipping authority.
        $user = $this->userWithGrants($company, 'no-shipping-authority-test', ['crm.customers.view']);
        $this->eligibleRow($company, $user);

        $this->actingAsUnprivileged($user)->getJson(self::BASE)->assertStatus(403);
    }

    public function test_cross_company_rows_stay_excluded_even_for_an_authorized_user(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();

        $user = $this->userWithGrants($company, 'shipping-view-only-test-2', [self::SHIPPING_VIEW]);
        $otherUser = User::factory()->create(['company_id' => $otherCompany->id]);

        // A row belongs to the OTHER company only.
        $this->eligibleRow($otherCompany, $otherUser);

        $response = $this->actingAsUnprivileged($user)->getJson(self::BASE);

        $response->assertOk();
        self::assertSame([], $response->json('data.items'), 'another company\'s shipping orders must never leak to an authorized user of a different company.');
    }

    /**
     * Regression guard: every role in config/permissions.php that grants
     * logistics.distribution.view ALSO grants logistics.shipping.view, with the
     * one already-known, already-nav-invisible exception (fulfillment-supervisor,
     * documented in this route's own comment in routes/api.php). If the catalogue
     * ever adds a NEW role with distribution.view but not shipping.view, this
     * test fails and forces a conscious decision instead of a silent regression.
     */
    public function test_every_distribution_view_role_except_the_known_exception_also_holds_shipping_view(): void
    {
        $knownException = 'fulfillment-supervisor';

        foreach ((array) config('permissions.role_permissions', []) as $roleSlug => $grants) {
            $hasDistributionView = in_array('view', (array) ($grants['logistics.distribution'] ?? []), true);

            if (! $hasDistributionView || $roleSlug === $knownException) {
                continue;
            }

            $hasShippingView = in_array('view', (array) ($grants['logistics.shipping'] ?? []), true);

            self::assertTrue(
                $hasShippingView,
                "role '{$roleSlug}' holds logistics.distribution.view but not logistics.shipping.view — ".
                'it will lose Shipping Orders API access under the new alignment; this needs a conscious decision, not a silent regression.',
            );
        }
    }
}
