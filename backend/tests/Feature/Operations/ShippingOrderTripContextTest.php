<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\IAM\Domain\Models\Role;
use Modules\Logistics\Distribution\Domain\Enums\TripType;
use Modules\Logistics\Distribution\Domain\Models\DeliveryStop;
use Modules\Logistics\Distribution\Domain\Models\Trip;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-SHIPPING-OS-REDESIGN-003 §12/§15/§21 — the Trip/stop-position
 * context added to the Shipping Orders read model this task
 * (`ShippingOrderReadModel`'s `trip_number`/`ds.sequence`/
 * `TRIP_STOPS_TOTAL_SQL`, exposed via `ShippingOrderResource::resolveTrip()`)
 * and the new `trip_id` filter.
 *
 * Fixture deliberately uses the `in_progress` DeliveryStop status (→
 * classification 'out_for_delivery'), which needs no custody/LoadingTask
 * evidence in `ShippingOrderReadModel::classificationSql()` — the shortest
 * real path to an ELIGIBLE row (Architecture-001 §4/§5's population
 * boundary: any row this page returns at all already has a DeliveryStop),
 * keeping this test focused on the trip/stop fields rather than re-proving
 * the custody chain another test file already owns.
 */
class ShippingOrderTripContextTest extends TestCase
{
    use DatabaseTransactions;

    private const BASE = '/api/operations/shipping-orders';

    private User $user;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->user = User::factory()->create(['company_id' => $this->company->id]);

        $role = Role::create([
            'name' => 'Shipping Orders Test Admin',
            'slug' => 'shipping-orders-test-admin-'.substr(md5(uniqid('', true)), 0, 8),
            'is_system' => true,
        ]);
        $this->user->roles()->attach($role->id);
    }

    private function auth(): static
    {
        return $this->actingAs($this->user);
    }

    private function makeOrder(): string
    {
        $customerId = (string) Str::uuid();
        DB::table('customers')->insert([
            'id' => $customerId,
            'code' => 'CUS-'.substr(md5($customerId), 0, 8),
            'name' => 'Trip Context Test Customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orderId = (string) Str::uuid();
        DB::table('orders')->insert([
            'id' => $orderId,
            'company_id' => $this->company->id,
            'customer_id' => $customerId,
            'order_number' => 'ORD-'.substr(md5($orderId), 0, 8),
            'order_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $orderId;
    }

    /** A real Trip with $count real DeliveryStops, all 'in_progress' — no custody needed. */
    private function tripWithStops(int $count): Trip
    {
        $trip = Trip::create([
            'company_id' => $this->company->id,
            'trip_number' => 'TRP-'.substr(md5(uniqid('', true)), 0, 6),
            'name' => 'Trip Context Test Run',
            'type' => TripType::CompanyVehicle->value,
            'capacity' => 10,
            'created_by' => $this->user->id,
        ]);

        for ($i = 1; $i <= $count; $i++) {
            DeliveryStop::create([
                'trip_id' => $trip->id,
                'order_id' => $this->makeOrder(),
                'sequence' => $i,
                'status' => 'in_progress',
            ]);
        }

        return $trip;
    }

    public function test_shipping_orders_expose_trip_number_and_stop_position(): void
    {
        $trip = $this->tripWithStops(2);

        $response = $this->auth()->getJson(self::BASE);
        $response->assertOk();

        $items = collect($response->json('data.items'));
        self::assertCount(2, $items, 'both in_progress stops must be eligible (out_for_delivery)');

        $bySequence = $items->keyBy(fn ($row) => $row['trip']['stop_sequence']);

        self::assertSame($trip->trip_number, $bySequence[1]['trip']['number']);
        self::assertSame(2, $bySequence[1]['trip']['stop_total'], 'stop_total must count BOTH stops on the trip');
        self::assertSame(1, $bySequence[1]['trip']['stop_sequence']);
        self::assertSame(2, $bySequence[2]['trip']['stop_sequence']);
        self::assertSame('out_for_delivery', $bySequence[1]['shipping_classification']);
    }

    public function test_shipping_orders_trip_is_null_not_fabricated_before_a_stop_has_a_trip(): void
    {
        // No Trip/DeliveryStop exists for this order at all — it must not appear
        // on the page (Architecture-001 population boundary), so this proves the
        // ABSENCE is a clean empty result, never a row with an invented trip.
        $this->makeOrder();

        $response = $this->auth()->getJson(self::BASE);
        $response->assertOk();

        self::assertSame([], $response->json('data.items'));
    }

    public function test_trip_id_filter_narrows_to_exactly_that_trips_orders(): void
    {
        $tripA = $this->tripWithStops(1);
        $tripB = $this->tripWithStops(1);

        $response = $this->auth()->getJson(self::BASE.'?trip_id='.$tripA->id);
        $response->assertOk();

        $items = collect($response->json('data.items'));
        self::assertCount(1, $items);
        self::assertSame($tripA->trip_number, $items->first()['trip']['number']);
        self::assertNotSame($tripB->trip_number, $items->first()['trip']['number']);
    }

    public function test_shipping_orders_are_scoped_to_the_acting_company(): void
    {
        $this->tripWithStops(1);

        $outsider = User::factory()->create(['company_id' => Company::factory()->create()->id]);
        $role = Role::create([
            'name' => 'Outsider Shipping Orders Admin',
            'slug' => 'outsider-shipping-orders-admin-'.substr(md5(uniqid('', true)), 0, 8),
            'is_system' => true,
        ]);
        $outsider->roles()->attach($role->id);

        $response = $this->actingAs($outsider)->getJson(self::BASE);
        $response->assertOk();

        self::assertSame([], $response->json('data.items'), 'another company\'s shipping orders must not leak');
    }
}
