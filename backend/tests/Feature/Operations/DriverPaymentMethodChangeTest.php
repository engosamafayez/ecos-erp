<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-DRIVER-APP-PHASE-4-PAYMENT-METHOD-CLOSURE-001 — the driver payment-method endpoint.
 *
 * PATCH /api/driver/stops/{stopId}/payment-method bridges into the canonical order authority.
 * These pin the DRIVER concerns: ownership (own stop only), delivery-state eligibility (§4/§11),
 * the canonical five-value vocabulary (§5), that a valid change persists canonically, and that a
 * change the gate forbids is rejected without mutating the order (§7/§8).
 */
final class DriverPaymentMethodChangeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->customer = Customer::factory()->create();
    }

    private function url(string $stopUuid): string
    {
        return '/api/driver/stops/'.$stopUuid.'/payment-method';
    }

    private function orderMethod(string $orderId): ?string
    {
        return DB::table('orders')->where('id', $orderId)->value('payment_method_manual');
    }

    // ── happy path ────────────────────────────────────────────────────────────

    public function test_a_driver_changes_the_method_on_their_own_active_stop(): void
    {
        // instapay → cod: cod needs no proof, so the change is permitted on a fulfilling order.
        $s = $this->scenario('instapay');

        $this->actingAs($s['user'])->patchJson($this->url($s['stop_uuid']), [
            'payment_method' => 'cod',
        ])->assertOk();

        self::assertSame('cod', $this->orderMethod($s['order_id']));
    }

    // ── §7/§8 consistency: a forbidden change is rejected AND rolled back ────────

    public function test_switching_to_a_proof_required_method_on_a_fulfilling_order_is_rejected(): void
    {
        $s = $this->scenario('cod'); // unpaid, no verified proof, out for delivery

        $this->actingAs($s['user'])->patchJson($this->url($s['stop_uuid']), [
            'payment_method' => 'instapay',
        ])->assertStatus(422);

        // Rolled back — the order still carries the original method.
        self::assertSame('cod', $this->orderMethod($s['order_id']));
    }

    // ── §5 vocabulary ───────────────────────────────────────────────────────────

    public function test_an_unsupported_payment_method_is_rejected(): void
    {
        $s = $this->scenario('cod');

        $this->actingAs($s['user'])->patchJson($this->url($s['stop_uuid']), [
            'payment_method' => 'paypal',
        ])->assertStatus(422);

        self::assertSame('cod', $this->orderMethod($s['order_id']));
    }

    // ── §4/§11 eligibility ──────────────────────────────────────────────────────

    public function test_editing_is_refused_before_the_delivery_has_started(): void
    {
        $s = $this->scenario('instapay', stopStatus: 'pending');

        $this->actingAs($s['user'])->patchJson($this->url($s['stop_uuid']), [
            'payment_method' => 'cod',
        ])->assertStatus(422);

        self::assertSame('instapay', $this->orderMethod($s['order_id']));
    }

    public function test_editing_is_refused_on_a_settled_stop(): void
    {
        $s = $this->scenario('instapay', stopStatus: 'delivered');

        $this->actingAs($s['user'])->patchJson($this->url($s['stop_uuid']), [
            'payment_method' => 'cod',
        ])->assertStatus(422);

        self::assertSame('instapay', $this->orderMethod($s['order_id']));
    }

    // ── §4 ownership / auth ─────────────────────────────────────────────────────

    public function test_a_driver_cannot_change_another_drivers_stop(): void
    {
        $a = $this->scenario('instapay');
        $b = $this->scenario('cod');

        $this->actingAs($b['user'])->patchJson($this->url($a['stop_uuid']), [
            'payment_method' => 'cod',
        ])->assertStatus(404);

        self::assertSame('instapay', $this->orderMethod($a['order_id']));
    }

    public function test_a_non_driver_user_is_refused(): void
    {
        $s = $this->scenario('cod');
        $notADriver = User::factory()->create(['company_id' => $this->company->id]);

        $this->actingAs($notADriver)->patchJson($this->url($s['stop_uuid']), [
            'payment_method' => 'instapay',
        ])->assertStatus(403);
    }

    public function test_unauthenticated_is_denied(): void
    {
        $s = $this->scenario('cod');

        $this->patchJson($this->url($s['stop_uuid']), [
            'payment_method' => 'cod',
        ])->assertStatus(401);
    }

    /**
     * A driver on an on-the-road trip with ONE stop/order. No custody/loading needed — the
     * endpoint only resolves ownership, stop status, and the order's method.
     *
     * @return array{user: User, stop_uuid: string, order_id: string}
     */
    private function scenario(?string $orderMethod, string $orderStatus = 'out_for_delivery', string $stopStatus = 'in_progress'): array
    {
        $user = User::factory()->create(['company_id' => $this->company->id]);

        $driverId = (int) DB::table('logistics_drivers')->insertGetId([
            'company_id' => $this->company->id,
            'user_id' => $user->id,
            'driver_code' => 'DRV-'.substr(uniqid(), -6),
            'full_name' => 'Driver '.substr(uniqid(), -4),
            'mobile' => '0100'.random_int(1000000, 9999999),
            'national_id' => (string) random_int(10000000000000, 99999999999999),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $vehicleId = (int) DB::table('logistics_vehicles')->insertGetId([
            'company_id' => $this->company->id,
            'plate_number' => 'PL-'.strtoupper(substr(uniqid(), -6)),
            'name' => 'V-'.substr(uniqid(), -4),
            'capacity_orders' => 25,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $pairingId = (int) DB::table('logistics_driver_vehicle_assignments')->insertGetId([
            'driver_id' => $driverId,
            'vehicle_id' => $vehicleId,
            'assigned_at' => now(),
            'active_flag' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $tripId = (int) DB::table('distribution_trips')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->company->id,
            'trip_number' => 'TRP-'.substr(uniqid(), -6),
            'name' => 'delivery trip',
            'status' => 'out_for_delivery',
            'driver_vehicle_assignment_id' => $pairingId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $order = Order::query()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-'.strtoupper(substr(uniqid(), -8)),
            'order_date' => now()->toDateString(),
            'city' => 'Cairo', 'governorate' => 'Cairo', 'status' => $orderStatus,
            'subtotal' => 100, 'total' => 100, 'deposit_amount' => 0,
            'shipping_total' => 0, 'discount_total' => 0, 'tax_total' => 0,
            'payment_method_manual' => $orderMethod,
        ]);

        $stopUuid = (string) Str::uuid();
        DB::table('distribution_delivery_stops')->insert([
            'uuid' => $stopUuid,
            'trip_id' => $tripId,
            'order_id' => $order->id,
            'sequence' => 1,
            'status' => $stopStatus,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['user' => $user, 'stop_uuid' => $stopUuid, 'order_id' => (string) $order->id];
    }
}
