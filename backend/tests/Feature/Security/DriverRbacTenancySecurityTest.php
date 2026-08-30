<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\Logistics\Distribution\Domain\Models\DeliveryStop;
use Modules\Logistics\Distribution\Domain\Models\PaymentCollection;
use Modules\Logistics\Distribution\Domain\Models\Trip;
use Modules\Logistics\Drivers\Domain\Models\Driver;
use Modules\Logistics\Vehicles\Domain\Models\Vehicle;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-DRIVER-02 — Driver RBAC & Tenancy Security Repair.
 *
 * ┌─ WHY THIS SUITE EXISTS, AND WHY IT DOES NOT USE actingAs() ────────────────┐
 * │ `TestCase::actingAs()` auto-grants the `is_system` role, which              │
 * │ `RequirePermissionMiddleware` passes unconditionally. Every existing driver │
 * │ test therefore proves only that the ROUTE resolves — never that the         │
 * │ `driver` role can reach it. That is precisely how the audit's headline      │
 * │ defect survived: the seeded `driver` role held no runtime permission and    │
 * │ got 403 on every endpoint, while the suite stayed green.                    │
 * │                                                                            │
 * │ Every case here uses `actingAsUnprivileged()` on a user wearing a role      │
 * │ materialised from the REAL `config/permissions.php` grant list. If the      │
 * │ catalogue changes, these tests change with it.                              │
 * └────────────────────────────────────────────────────────────────────────────┘
 *
 * DatabaseTransactions (not RefreshDatabase) so the seeded roles/permissions are present,
 * matching the sibling ShippingDriverClosureTest / DriverModuleTest suites. Every fixture
 * is created inside the transaction and rolled back — no business data is touched.
 */
final class DriverRbacTenancySecurityTest extends TestCase
{
    use DatabaseTransactions;

    private const DRIVER_RUNTIME = 'loading.driver.operate';

    private const DISPATCHER_WRITE = 'logistics.distribution.update';

    private const DISPATCHER_READ = 'logistics.distribution.view';

    // ─────────────────────────────────────────────────────────────────────────────
    // Fixtures — real roles, real grants
    // ─────────────────────────────────────────────────────────────────────────────

    /** @return list<string> the full permission names a role holds in the catalogue. */
    private function catalogueGrants(string $roleSlug): array
    {
        $out = [];

        foreach ((array) (config('permissions.role_permissions')[$roleSlug] ?? []) as $resource => $actions) {
            foreach ((array) $actions as $action) {
                $out[] = $resource.'.'.$action;
            }
        }

        return $out;
    }

    /**
     * A user wearing a real, non-system role holding exactly `$names`.
     *
     * The role row is reused when it already exists (the seeded `driver` row does), and its
     * grants are re-synced from the catalogue so the test asserts against configuration
     * rather than against whenever the seeder last ran.
     */
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

    /** A user wearing the real seeded `driver` role, with its real catalogue grants. */
    private function driverUser(Company $company): User
    {
        return $this->userWithGrants($company, 'driver', $this->catalogueGrants('driver'));
    }

    private function makeDriver(Company $company, ?User $user = null): Driver
    {
        return Driver::create([
            'company_id' => $company->id,
            'driver_code' => 'DRV-'.strtoupper(substr(uniqid('', true), -8)),
            'user_id' => $user?->id,
            'full_name' => 'Driver '.uniqid(),
            'mobile' => '0100'.random_int(1000000, 9999999),
            'national_id' => 'NID-'.strtoupper(substr(uniqid('', true), -10)),
        ]);
    }

    /** A trip owned by `$company`, paired to `$driver` through the canonical assignment ledger. */
    private function makeTrip(Company $company, ?Driver $driver = null): Trip
    {
        $assignmentId = null;

        if ($driver !== null) {
            $vehicle = Vehicle::create([
                'company_id' => $company->id,
                'vehicle_code' => 'VEH-'.strtoupper(substr(uniqid('', true), -8)),
                'plate_number' => 'PL-'.strtoupper(substr(uniqid('', true), -8)),
                'type' => 'van',
                'capacity_orders' => 40,
            ]);

            $assignmentId = DB::table('logistics_driver_vehicle_assignments')->insertGetId([
                'driver_id' => $driver->id,
                'vehicle_id' => $vehicle->id,
                'assigned_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return Trip::create([
            'company_id' => $company->id,
            'trip_number' => 'TRP-'.strtoupper(substr(uniqid('', true), -8)),
            'name' => 'Trip '.uniqid(),
            'driver_vehicle_assignment_id' => $assignmentId,
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════════════
    // A. The driver role can actually reach the driver runtime
    // ═════════════════════════════════════════════════════════════════════════════

    /** The catalogue contract — asserted directly so a silent grant change fails here first. */
    public function test_a1_the_driver_role_holds_the_runtime_permission_and_no_dispatcher_authority(): void
    {
        $grants = $this->catalogueGrants('driver');

        self::assertContains(self::DRIVER_RUNTIME, $grants, 'The driver role must be able to operate the driver runtime.');
        self::assertNotContains(self::DISPATCHER_WRITE, $grants, 'A driver must not hold dispatcher write authority — that is the cash-ledger path.');
        self::assertNotContains(self::DISPATCHER_READ, $grants, 'A driver must not hold the dispatcher read verb.');
    }

    /**
     * THE headline defect. Before this task the seeded driver role held no runtime
     * permission, so a real driver received 403 on every `/api/driver/*` endpoint.
     */
    public function test_a2_a_real_driver_role_reaches_the_driver_runtime(): void
    {
        $company = Company::factory()->create();
        $user = $this->driverUser($company);
        $this->makeDriver($company, $user);

        $this->actingAsUnprivileged($user)
            ->getJson('/api/driver/trips')
            ->assertOk()
            ->assertExactJson([]);
    }

    /** B — a user without the runtime permission is refused by the middleware. */
    public function test_a3_a_user_without_the_runtime_permission_is_refused(): void
    {
        $company = Company::factory()->create();
        $user = $this->userWithGrants($company, 'test-no-driver-perm', ['logistics.shipping.view']);
        $this->makeDriver($company, $user);

        $this->actingAsUnprivileged($user)->getJson('/api/driver/trips')->assertForbidden();
    }

    /** Holding the permission is not enough — the actor must also BE a driver. */
    public function test_a4_a_permitted_user_who_is_not_a_driver_is_still_refused(): void
    {
        $company = Company::factory()->create();
        $user = $this->driverUser($company);

        $this->actingAsUnprivileged($user)->getJson('/api/driver/trips')->assertForbidden();
    }

    // ═════════════════════════════════════════════════════════════════════════════
    // B. Trip tenancy and assignment scope on the driver runtime
    // ═════════════════════════════════════════════════════════════════════════════

    /** C — cross-company. A driver must never reach another company's trip by uuid. */
    public function test_b1_a_driver_cannot_reach_another_companys_trip(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        $userA = $this->driverUser($companyA);
        $driverA = $this->makeDriver($companyA, $userA);
        $this->makeTrip($companyA, $driverA);

        $foreignTrip = $this->makeTrip($companyB, $this->makeDriver($companyB));

        $this->actingAsUnprivileged($userA)
            ->getJson('/api/driver/trips/'.$foreignTrip->uuid)
            ->assertNotFound();
    }

    /** D — same company, different driver. Assignment scope, not just tenancy. */
    public function test_b2_a_driver_cannot_reach_another_drivers_trip_in_the_same_company(): void
    {
        $company = Company::factory()->create();

        $userA = $this->driverUser($company);
        $this->makeDriver($company, $userA);

        $otherTrip = $this->makeTrip($company, $this->makeDriver($company));

        $this->actingAsUnprivileged($userA)
            ->getJson('/api/driver/trips/'.$otherTrip->uuid)
            ->assertNotFound();
    }

    /** E — an unassigned trip is not reachable by guessing a uuid. */
    public function test_b3_a_driver_cannot_reach_an_unassigned_trip(): void
    {
        $company = Company::factory()->create();
        $userA = $this->driverUser($company);
        $this->makeDriver($company, $userA);

        $unassigned = $this->makeTrip($company);

        $this->actingAsUnprivileged($userA)
            ->getJson('/api/driver/trips/'.$unassigned->uuid)
            ->assertNotFound();
    }

    /** …and the driver's own trip IS reachable, so the guard is not simply refusing everything. */
    public function test_b4_a_driver_reaches_their_own_trip(): void
    {
        $company = Company::factory()->create();
        $userA = $this->driverUser($company);
        $driverA = $this->makeDriver($company, $userA);
        $own = $this->makeTrip($company, $driverA);

        $this->actingAsUnprivileged($userA)
            ->getJson('/api/driver/trips/'.$own->uuid)
            ->assertOk();
    }

    // ═════════════════════════════════════════════════════════════════════════════
    // C. Dispatcher surface — tenant isolation (the unscoped resolveTrip defect)
    // ═════════════════════════════════════════════════════════════════════════════

    /**
     * `SettlementController::resolveTrip()` was `Trip::where('uuid',…)->firstOrFail()`.
     * Every method on that controller funnels through it, so a uuid was a bearer token
     * to another company's payment ledger and cash position.
     */
    public function test_c1_the_settlement_payments_read_is_company_scoped(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        $dispatcherA = $this->userWithGrants($companyA, 'test-dispatcher', [self::DISPATCHER_READ, self::DISPATCHER_WRITE]);
        $foreignTrip = $this->makeTrip($companyB);

        $this->actingAsUnprivileged($dispatcherA)
            ->getJson('/api/logistics/distribution/trips/'.$foreignTrip->uuid.'/payments')
            ->assertNotFound();
    }

    /** The financial summary — the cash position — is scoped the same way. */
    public function test_c2_the_financial_summary_is_company_scoped(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        $dispatcherA = $this->userWithGrants($companyA, 'test-dispatcher', [self::DISPATCHER_READ, self::DISPATCHER_WRITE]);
        $foreignTrip = $this->makeTrip($companyB);

        $this->actingAsUnprivileged($dispatcherA)
            ->getJson('/api/logistics/distribution/trips/'.$foreignTrip->uuid.'/financial-summary')
            ->assertNotFound();
    }

    /** The delivery surface shared the identical defect — stops carry order data. */
    public function test_c3_the_delivery_stops_read_is_company_scoped(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        $dispatcherA = $this->userWithGrants($companyA, 'test-dispatcher', [self::DISPATCHER_READ, self::DISPATCHER_WRITE]);
        $foreignTrip = $this->makeTrip($companyB);

        $this->actingAsUnprivileged($dispatcherA)
            ->getJson('/api/logistics/distribution/trips/'.$foreignTrip->uuid.'/stops')
            ->assertNotFound();
    }

    // ═════════════════════════════════════════════════════════════════════════════
    // D. Settlement reads are no longer unauthenticated-by-permission
    // ═════════════════════════════════════════════════════════════════════════════

    /**
     * Before this task these three reads carried bare `auth:sanctum` — any authenticated
     * user could read a trip's payment ledger, settlement and cash position with a uuid.
     */
    public function test_d1_settlement_reads_require_the_distribution_read_permission(): void
    {
        $company = Company::factory()->create();
        $trip = $this->makeTrip($company);
        $nobody = $this->userWithGrants($company, 'test-no-logistics', ['logistics.shipping.view']);

        foreach (['payments', 'settlement', 'financial-summary'] as $segment) {
            $this->actingAsUnprivileged($nobody)
                ->getJson('/api/logistics/distribution/trips/'.$trip->uuid.'/'.$segment)
                ->assertForbidden();
        }
    }

    /** And a driver — who no longer holds the dispatcher read verb — is refused too. */
    public function test_d2_a_driver_cannot_read_the_dispatcher_settlement_surface(): void
    {
        $company = Company::factory()->create();
        $user = $this->driverUser($company);
        $driver = $this->makeDriver($company, $user);
        $trip = $this->makeTrip($company, $driver);

        $this->actingAsUnprivileged($user)
            ->getJson('/api/logistics/distribution/trips/'.$trip->uuid.'/payments')
            ->assertForbidden();
    }

    /** A driver can no longer reach the cash WRITE path at all. */
    public function test_d3_a_driver_cannot_record_a_payment_on_the_dispatcher_surface(): void
    {
        $company = Company::factory()->create();
        $user = $this->driverUser($company);
        $driver = $this->makeDriver($company, $user);
        $trip = $this->makeTrip($company, $driver);

        $this->actingAsUnprivileged($user)
            ->postJson('/api/logistics/distribution/trips/'.$trip->uuid.'/stops/1/payments', [
                'payment_type' => 'cash',
                'amount' => 100,
            ])
            ->assertForbidden();
    }

    // ═════════════════════════════════════════════════════════════════════════════
    // E. Maker-checker on the cash ledger
    // ═════════════════════════════════════════════════════════════════════════════

    /**
     * A real order for the company — `distribution_delivery_stops.order_id` carries a
     * genuine FK to `orders`, so a synthetic uuid cannot stand in for one.
     */
    private function makeOrder(Company $company): Order
    {
        return Order::query()->create([
            'company_id' => $company->id,
            'customer_id' => Customer::factory()->create()->id,
            'order_number' => 'ORD-SEC-'.strtoupper(substr(uniqid('', true), -8)),
            'order_date' => now()->toDateString(),
            'status' => 'in_progress',
            'payment_method_manual' => 'cod',
            'subtotal' => 100,
            'total' => 100,
        ]);
    }

    /** @return array{Company, User, PaymentCollection} */
    private function collectionRecordedBy(User $collector, Company $company): array
    {
        $trip = $this->makeTrip($company);

        $stop = DeliveryStop::create([
            'trip_id' => $trip->id,
            'order_id' => $this->makeOrder($company)->id,
            'sequence' => 1,
        ]);

        $payment = PaymentCollection::create([
            'trip_id' => $trip->id,
            'stop_id' => $stop->id,
            'payment_type' => 'cash',
            'amount' => 250,
            'collected_by' => $collector->id,
        ]);

        return [$company, $collector, $payment];
    }

    /**
     * THE control. Enforced by IDENTITY in the domain, so it binds after the middleware
     * has already let the actor through — a single user holding both halves, or any
     * `is_system` role, is subject to it exactly like every other actor.
     */
    public function test_e1_the_collector_cannot_verify_their_own_collection(): void
    {
        $company = Company::factory()->create();
        $collector = $this->userWithGrants($company, 'test-dispatcher', [self::DISPATCHER_READ, self::DISPATCHER_WRITE]);
        [, , $payment] = $this->collectionRecordedBy($collector, $company);

        $this->actingAsUnprivileged($collector)
            ->patchJson('/api/logistics/distribution/trips/'.$payment->trip->uuid.'/payments/'.$payment->id.'/verify')
            ->assertForbidden();

        self::assertSame(
            PaymentCollection::STATUS_RECORDED,
            (string) DB::table('distribution_payment_collections')->where('id', $payment->id)->value('status'),
            'A refused self-verification must leave the collection untouched.',
        );
    }

    /** Reject is the other half of one reviewer act and is refused the same way. */
    public function test_e2_the_collector_cannot_reject_their_own_collection(): void
    {
        $company = Company::factory()->create();
        $collector = $this->userWithGrants($company, 'test-dispatcher', [self::DISPATCHER_READ, self::DISPATCHER_WRITE]);
        [, , $payment] = $this->collectionRecordedBy($collector, $company);

        $this->actingAsUnprivileged($collector)
            ->patchJson('/api/logistics/distribution/trips/'.$payment->trip->uuid.'/payments/'.$payment->id.'/reject', ['notes' => 'x'])
            ->assertForbidden();

        self::assertSame(
            PaymentCollection::STATUS_RECORDED,
            (string) DB::table('distribution_payment_collections')->where('id', $payment->id)->value('status'),
        );
    }

    /** A different authorised reviewer is unaffected — the rule separates people, not work. */
    public function test_e3_a_different_reviewer_may_verify(): void
    {
        $company = Company::factory()->create();
        $collector = $this->userWithGrants($company, 'test-dispatcher', [self::DISPATCHER_READ, self::DISPATCHER_WRITE]);
        [, , $payment] = $this->collectionRecordedBy($collector, $company);

        $reviewer = $this->userWithGrants($company, 'test-dispatcher', [self::DISPATCHER_READ, self::DISPATCHER_WRITE]);

        $this->actingAsUnprivileged($reviewer)
            ->patchJson('/api/logistics/distribution/trips/'.$payment->trip->uuid.'/payments/'.$payment->id.'/verify')
            ->assertOk();

        self::assertSame(
            PaymentCollection::STATUS_VERIFIED,
            (string) DB::table('distribution_payment_collections')->where('id', $payment->id)->value('status'),
        );
    }

    /** Cross-company: a collection on another company's trip is unreachable, not merely unverifiable. */
    public function test_e4_a_collection_on_another_companys_trip_is_unreachable(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        $collectorB = $this->userWithGrants($companyB, 'test-dispatcher-b', [self::DISPATCHER_READ, self::DISPATCHER_WRITE]);
        [, , $payment] = $this->collectionRecordedBy($collectorB, $companyB);

        $reviewerA = $this->userWithGrants($companyA, 'test-dispatcher', [self::DISPATCHER_READ, self::DISPATCHER_WRITE]);

        $this->actingAsUnprivileged($reviewerA)
            ->patchJson('/api/logistics/distribution/trips/'.$payment->trip->uuid.'/payments/'.$payment->id.'/verify')
            ->assertNotFound();
    }

    // ═════════════════════════════════════════════════════════════════════════════
    // F. image_path is not a trusted proof
    // ═════════════════════════════════════════════════════════════════════════════

    /**
     * `image_path` is a client-supplied string, not an upload. Reconciling it with the
     * canonical `payment_proofs` lifecycle is a redesign and is STOPPED for an owner
     * decision. What is enforced here is the part that needs no decision: it cannot
     * carry a URL scheme, an absolute path, a UNC path, or `..` traversal.
     */
    public function test_f1_a_scheme_or_traversal_image_path_is_rejected(): void
    {
        $company = Company::factory()->create();
        $dispatcher = $this->userWithGrants($company, 'test-dispatcher', [self::DISPATCHER_READ, self::DISPATCHER_WRITE]);
        $trip = $this->makeTrip($company);

        $stop = DeliveryStop::create([
            'trip_id' => $trip->id,
            'order_id' => $this->makeOrder($company)->id,
            'sequence' => 1,
        ]);

        foreach (['javascript:alert(1)', 'http://evil.example/x.png', '/etc/passwd', '../../secret.png'] as $bad) {
            $this->actingAsUnprivileged($dispatcher)
                ->postJson('/api/logistics/distribution/trips/'.$trip->uuid.'/stops/'.$stop->id.'/payments', [
                    'payment_type' => 'bank_transfer',
                    'amount' => 10,
                    'image_path' => $bad,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('image_path');
        }
    }

    // ═════════════════════════════════════════════════════════════════════════════
    // G. No regression in the canonical payment contract
    // ═════════════════════════════════════════════════════════════════════════════

    /**
     * The canonical proof lifecycle is untouched by this task, and the two systems remain
     * separate: the distribution cash ledger writes no `payment_proofs` row, so it can
     * never satisfy `PaymentFulfillmentGate`.
     */
    public function test_g1_recording_a_collection_creates_no_canonical_payment_proof(): void
    {
        $company = Company::factory()->create();
        $collector = $this->userWithGrants($company, 'test-dispatcher', [self::DISPATCHER_READ, self::DISPATCHER_WRITE]);

        $before = DB::table('payment_proofs')->count();
        $this->collectionRecordedBy($collector, $company);

        self::assertSame($before, DB::table('payment_proofs')->count());
    }

    /** The canonical maker-checker predicate is unchanged and still refuses a self-review. */
    public function test_g2_the_canonical_payment_proof_maker_checker_is_intact(): void
    {
        $proof = new \Modules\Commerce\Orders\Domain\Models\PaymentProof(['uploaded_by' => '7']);

        self::assertTrue($proof->isSelfReviewBy('7'));
        self::assertFalse($proof->isSelfReviewBy('8'));
        self::assertFalse($proof->isSelfReviewBy(null));
    }
}
