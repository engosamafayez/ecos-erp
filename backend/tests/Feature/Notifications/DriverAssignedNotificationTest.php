<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Modules\Core\UserPreferences\Domain\Models\UserPreference;
use Modules\Logistics\Drivers\Domain\Models\Driver;
use Modules\Logistics\Vehicles\Domain\Models\Vehicle;
use Modules\Operations\Loading\Domain\Events\DriverAssigned;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-NOTIFICATIONS-FINAL-USER-REVIEW-REMEDIATION-010 §7.
 *
 * Proves the fix for "Drivers do not see notifications": DriverAssigned already fired
 * from AssignDriverAction with zero listeners before this task. These cases exercise
 * the new listener directly against the real event (not a re-test of AssignDriverAction
 * itself, which is unchanged, pre-existing, and out of this task's scope) — the
 * fixture only needs a Driver+Vehicle+Company, not a full VehicleAssignment workflow.
 */
final class DriverAssignedNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function driver(Company $company): Driver
    {
        $user = User::factory()->create(['company_id' => $company->id]);
        $suffix = strtoupper(substr(md5(uniqid('', true)), 0, 8));

        $driver = new Driver([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'driver_code' => 'DRV-'.$suffix,
            'full_name' => 'Driver '.$suffix,
            'mobile' => '01'.random_int(100000000, 999999999),
            'national_id' => (string) random_int(10000000000000, 99999999999999),
            'status' => Driver::STATUS_ACTIVE,
        ]);
        $driver->company_id = $company->id;
        $driver->save();

        return $driver->refresh();
    }

    private function vehicle(Company $company): Vehicle
    {
        $suffix = strtoupper(substr(md5(uniqid('', true)), 0, 6));

        return Vehicle::create([
            'company_id' => $company->id,
            'vehicle_code' => 'VEH-'.$suffix,
            'plate_number' => 'PL-'.$suffix,
            'type' => 'van',
            'capacity_orders' => 40,
            'status' => 'available',
        ]);
    }

    /** @return DatabaseNotification[] */
    private function notificationsFor(User $user): array
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey())
            ->get()
            ->all();
    }

    public function test_eligible_driver_receives_the_notification(): void
    {
        $company = Company::factory()->create();
        $driver = $this->driver($company);
        $vehicle = $this->vehicle($company);

        event(new DriverAssigned(
            companyId: $company->id,
            driverAssignmentId: (string) Str::uuid(),
            vehicleAssignmentId: (string) Str::uuid(),
            vehicleId: $vehicle->id,
            driverId: $driver->id,
            driverName: $driver->full_name,
            actorId: (string) User::factory()->create(['company_id' => $company->id])->id,
            occurredAt: now()->toIso8601String(),
        ));

        $rows = $this->notificationsFor($driver->user);
        $this->assertCount(1, $rows);
        $this->assertSame('driver_assigned', $rows[0]->data['type']);
        $this->assertSame($company->id, $rows[0]->company_id);
        $this->assertSame('assignment', $rows[0]->category);
    }

    public function test_unrelated_driver_in_the_same_company_does_not_receive_it(): void
    {
        $company = Company::factory()->create();
        $assignedDriver = $this->driver($company);
        $unrelatedDriver = $this->driver($company);
        $vehicle = $this->vehicle($company);

        event(new DriverAssigned(
            companyId: $company->id,
            driverAssignmentId: (string) Str::uuid(),
            vehicleAssignmentId: (string) Str::uuid(),
            vehicleId: $vehicle->id,
            driverId: $assignedDriver->id,
            driverName: $assignedDriver->full_name,
            actorId: (string) User::factory()->create(['company_id' => $company->id])->id,
            occurredAt: now()->toIso8601String(),
        ));

        $this->assertCount(1, $this->notificationsFor($assignedDriver->user));
        $this->assertCount(0, $this->notificationsFor($unrelatedDriver->user));
    }

    public function test_driver_in_a_different_company_does_not_receive_it(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $assignedDriver = $this->driver($company);
        $outsiderDriver = $this->driver($otherCompany);
        $vehicle = $this->vehicle($company);

        event(new DriverAssigned(
            companyId: $company->id,
            driverAssignmentId: (string) Str::uuid(),
            vehicleAssignmentId: (string) Str::uuid(),
            vehicleId: $vehicle->id,
            driverId: $assignedDriver->id,
            driverName: $assignedDriver->full_name,
            actorId: (string) User::factory()->create(['company_id' => $company->id])->id,
            occurredAt: now()->toIso8601String(),
        ));

        $this->assertCount(1, $this->notificationsFor($assignedDriver->user));
        $this->assertCount(0, $this->notificationsFor($outsiderDriver->user));
    }

    public function test_driver_who_disabled_the_type_does_not_receive_it(): void
    {
        $company = Company::factory()->create();
        $driver = $this->driver($company);
        $vehicle = $this->vehicle($company);

        UserPreference::query()->create([
            'user_id' => $driver->user->id,
            'category' => 'notifications',
            'payload' => ['type_overrides' => ['driver_assigned' => false]],
        ]);

        event(new DriverAssigned(
            companyId: $company->id,
            driverAssignmentId: (string) Str::uuid(),
            vehicleAssignmentId: (string) Str::uuid(),
            vehicleId: $vehicle->id,
            driverId: $driver->id,
            driverName: $driver->full_name,
            actorId: (string) User::factory()->create(['company_id' => $company->id])->id,
            occurredAt: now()->toIso8601String(),
        ));

        $this->assertCount(0, $this->notificationsFor($driver->user));
    }
}
