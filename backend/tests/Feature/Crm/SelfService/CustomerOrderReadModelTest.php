<?php

declare(strict_types=1);

namespace Tests\Feature\Crm\SelfService;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Crm\Customers\Domain\Models\Customer;
use Modules\Crm\SelfService\Domain\Models\CustomerTrackingToken;
use Modules\Crm\SelfService\Domain\Services\CustomerOrderReadModel;
use Modules\Logistics\Distribution\Domain\Models\Trip;
use Modules\Logistics\Distribution\Domain\Models\TripOrder;
use Modules\Logistics\Drivers\Domain\Models\Driver;
use Modules\Logistics\Drivers\Domain\Models\DriverVehicleAssignment;
use Modules\Logistics\Vehicles\Domain\Models\Vehicle;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-V1.1-CRM-04-SECURE-SELF-SERVICE-BACKEND-IMPLEMENTATION-019 §28/§34 — proves the
 * customer-safe read model boundary (closure gate items H/I): the internal-only fields the
 * staff-facing OrderResource exposes are structurally absent here (never redacted after the
 * fact — never generated in the first place), and the approved driver-privacy policy holds.
 */
final class CustomerOrderReadModelTest extends TestCase
{
    use DatabaseTransactions;

    private function makeOrder(string $companyId, array $overrides = []): Order
    {
        $customer = Customer::create(['company_id' => $companyId, 'name' => 'Test Customer', 'email' => 'c@test.test']);

        return Order::create(array_merge([
            'company_id' => $companyId,
            'customer_id' => $customer->id,
            'order_number' => 'ORD-'.strtoupper(Str::random(8)),
            'order_date' => now()->toDateString(),
            'status' => 'awaiting_payment',
            'subtotal' => 200,
            'total' => 200,
            'hold_reason_code' => 'blocked_customer',
            'internal_notes' => 'a staff-only note that must never reach the customer',
        ], $overrides));
    }

    private function tokenFor(Order $order): CustomerTrackingToken
    {
        return CustomerTrackingToken::create([
            'customer_id' => $order->customer_id,
            'company_id' => $order->company_id,
            'brand_id' => null,
            'order_id' => $order->id,
            'channel' => 'email',
            'token_hash' => hash('sha256', Str::random(64)),
            'expires_at' => now()->addDays(7),
        ]);
    }

    public function test_internal_only_fields_are_structurally_absent(): void
    {
        $company = Company::factory()->create();
        $order = $this->makeOrder($company->id);

        $data = app(CustomerOrderReadModel::class)->build($this->tokenFor($order));

        $this->assertIsArray($data);
        $forbidden = [
            'internal_notes', 'hold_reason_code', 'assigned_warehouse_id', 'assigned_warehouse',
            'created_by_id', 'created_by_name', 'reservation_shortage_lines', 'actual_cogs_amount',
            'actual_margin_amount', 'actual_margin_percent', 'warehouse_assignment_source',
            'warehouse_assignment_failure_reason', 'previous_status', 'allowed_status_transitions',
        ];
        foreach ($forbidden as $key) {
            $this->assertArrayNotHasKey($key, $data, "customer-facing read model must never expose '{$key}'");
        }
    }

    public function test_no_driver_information_before_out_for_delivery(): void
    {
        $company = Company::factory()->create();
        $order = $this->makeOrder($company->id, ['status' => 'ready_for_dispatch']);
        $this->attachTripAndDriver($order, 'Ahmed Mostafa');

        $data = app(CustomerOrderReadModel::class)->build($this->tokenFor($order));

        $this->assertNull($data['delivery']['driver'], 'no driver identity may be shown before Out for Delivery, even if one is already assigned');
    }

    public function test_driver_first_name_only_once_out_for_delivery(): void
    {
        $company = Company::factory()->create();
        $order = $this->makeOrder($company->id, ['status' => 'out_for_delivery']);
        $this->attachTripAndDriver($order, 'Ahmed Mostafa');

        $data = app(CustomerOrderReadModel::class)->build($this->tokenFor($order));

        $this->assertSame(['first_name' => 'Ahmed'], $data['delivery']['driver']);
        $this->assertArrayNotHasKey('mobile', $data['delivery']['driver']);
        $this->assertArrayNotHasKey('full_name', $data['delivery']['driver']);
        $this->assertArrayNotHasKey('driver_code', $data['delivery']['driver']);
        // Nowhere in the payload — not just absent from the driver sub-array — is the driver's
        // real mobile number allowed to appear.
        $this->assertStringNotContainsString('01055500001', json_encode($data));
    }

    public function test_no_fabricated_timeline_event_when_the_source_timestamp_is_absent(): void
    {
        $company = Company::factory()->create();
        $order = $this->makeOrder($company->id); // confirmed_at, date_paid, preparation_completed_at all null

        $data = app(CustomerOrderReadModel::class)->build($this->tokenFor($order));

        $events = array_column($data['timeline'], 'event');
        $this->assertNotContains('confirmed', $events);
        $this->assertNotContains('payment_confirmed', $events);
        $this->assertNotContains('preparation_completed', $events);
        $this->assertContains('order_created', $events, 'created_at always exists and must always be present');
    }

    public function test_requested_delivery_date_is_preserved_verbatim(): void
    {
        $company = Company::factory()->create();
        $order = $this->makeOrder($company->id, ['requested_delivery_date' => '2026-10-01']);

        $data = app(CustomerOrderReadModel::class)->build($this->tokenFor($order));

        $this->assertSame('2026-10-01', $data['requested_delivery_date']);
    }

    private function attachTripAndDriver(Order $order, string $fullName): void
    {
        $driver = Driver::create([
            'uuid' => (string) Str::uuid(),
            'company_id' => $order->company_id,
            'driver_code' => 'DRV-'.strtoupper(Str::random(6)),
            'full_name' => $fullName,
            'mobile' => '01055500001',
            'national_id' => (string) random_int(10000000000000, 99999999999999),
        ]);

        $vehicle = Vehicle::create([
            'plate_number' => 'PLT-'.strtoupper(Str::random(6)),
        ]);

        $assignment = DriverVehicleAssignment::create([
            'driver_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            'assigned_at' => now(),
            'active_flag' => 1,
        ]);

        $trip = Trip::create([
            'uuid' => (string) Str::uuid(),
            'company_id' => $order->company_id,
            'trip_number' => 'TRIP-'.strtoupper(Str::random(6)),
            'driver_vehicle_assignment_id' => $assignment->id,
            'status' => 'dispatched',
        ]);

        TripOrder::create([
            'trip_id' => $trip->id,
            'order_id' => $order->id,
            'assigned_at' => now(),
        ]);
    }
}
