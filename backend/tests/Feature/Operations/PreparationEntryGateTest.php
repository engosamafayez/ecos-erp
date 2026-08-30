<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Core\FeatureFlags\FeatureFlagService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Enums\ReservationStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderLine;
use Modules\IAM\Domain\Models\Role;
use Modules\Inventory\InventoryItems\Domain\Models\InventoryItem;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Operations\Preparation\Domain\Models\PreparationSessionPolicy;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-GOLIVE-PREPARATION-ENTRY-GATE-REPAIR-002 — the authoritative Preparation
 * entry policy, proven at runtime through the real HTTP routes.
 *
 * THE RULE (ADR-042 §7)
 *   An order may enter Preparation only while its STATUS is one of the closed
 *   fulfilment-eligible set:
 *     in_progress, confirmed
 *   Every other status is refused. Reservation state never overrides the rule:
 *   a reserved order in a post-Preparation state is still refused.
 *
 * ON `confirm`
 *   The current OrderStatus enum has no `confirm` case, and none is invented here.
 *   `confirm_order` was renamed to `confirmed`
 *   (2026_07_13_000001_migrate_order_statuses_to_final_lifecycle.php:22) and then
 *   MERGED INTO `in_progress` by the V3 consolidation
 *   (2026_07_22_100000_simplify_order_lifecycle_v3.php:30 — "confirmed →
 *   in_progress (merged: was a separate confirmation step)"). ADR-042 (Order FSM V3
 *   Canonical) then REVERSED that merge: `confirmed` is RESTORED as a first-class
 *   OrderStatus and `new` is REMOVED. Confirm is an explicit operator action producing a
 *   real `confirmed` status — never a timestamp-only fact and never an entry status — so
 *   Preparation admits exactly `in_progress` and `confirmed`.
 *   test_confirmed_order_is_eligible() covers the real `confirmed` status explicitly.
 */
final class PreparationEntryGateTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Warehouse $warehouse;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $this->customer = Customer::factory()->create();

        $flags = app(FeatureFlagService::class);
        $flags->enable('modules.preparation_os', $this->company->id);
        $flags->enable('workflow.stages.preparation', $this->company->id);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function order(
        string $status,
        ?ReservationStatus $reservation = null,
        ?Company $company = null,
        ?bool $confirmed = false,
    ): Order {
        $order = Order::query()->create([
            'company_id' => ($company ?? $this->company)->id,
            'assigned_warehouse_id' => $this->warehouse->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-'.uniqid(),
            'order_date' => now()->toDateString(),
            'confirmed_at' => $confirmed ? now() : null,
            'status' => $status,
            'subtotal' => 100,
            'total' => 100,
            'shipping_total' => 0,
            'discount_total' => 0,
            'tax_total' => 0,
        ]);

        if ($confirmed) {
            // `confirmed_at` is not in Order::$fillable (only `customer_confirmed_at`
            // is), so mass assignment silently drops it.
            $order->forceFill(['confirmed_at' => now()])->save();
        }

        if ($reservation !== null) {
            // Set directly and deliberately: this suite proves that reservation
            // state does NOT grant eligibility, so the fixture must be able to
            // produce "reserved but ineligible" combinations the engine forbids.
            $order->forceFill(['reservation_status' => $reservation->value])->save();
        }

        $product = Product::factory()->create();

        OrderLine::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 2.0,
            'unit_price' => 50.0,
            'line_total' => 100.0,
        ]);

        InventoryItem::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $product->id,
            'company_id' => $order->company_id,
            'on_hand_qty' => 50,
            'reserved_qty' => 0,
        ]);

        return $order->refresh();
    }

    private function operator(?Company $company = null): User
    {
        $user = User::factory()->create(['company_id' => ($company ?? $this->company)->id]);

        $role = Role::firstOrCreate(
            ['slug' => 'sysadmin-entry-gate'],
            ['name' => 'System Admin', 'is_system' => true],
        );

        $user->roles()->attach($role->id);
        $user->unsetRelation('roles');

        return $user;
    }

    /** @param list<string> $orderIds */
    private function createWave(array $orderIds): TestResponse
    {
        return $this->postJson('/api/preparation/waves', [
            'planning_date' => now()->toDateString(),
            'warehouse_id' => $this->warehouse->id,
            'order_ids' => $orderIds,
        ]);
    }

    /** Every table a Preparation entry could mutate. */
    private function mutationFootprint(string $orderId): array
    {
        return [
            'wave_orders' => DB::table('preparation_wave_orders')->where('order_id', $orderId)->count(),
            'waves' => DB::table('preparation_waves')->count(),
            'wave_items' => DB::table('preparation_wave_items')->count(),
            'ledger' => DB::table('stock_ledger_entries')->count(),
            'fifo' => DB::table('inventory_layer_consumptions')->count(),
        ];
    }

    private function assertNoMutation(string $orderId, string $status): void
    {
        $f = $this->mutationFootprint($orderId);

        self::assertSame(0, $f['wave_orders'], "[{$status}] must not be attached to a wave.");
        self::assertSame(0, $f['waves'], "[{$status}] must not create a wave.");
        self::assertSame(0, $f['wave_items'], "[{$status}] must not create wave items.");
        self::assertSame(0, $f['ledger'], "[{$status}] must not write the inventory ledger.");
        self::assertSame(0, $f['fifo'], "[{$status}] must not consume FIFO layers.");
    }

    private function assertEligible(string $status, Order $order): void
    {
        $response = $this->createWave([$order->id]);

        fwrite(STDERR, sprintf(
            'ENTRY GATE  %-20s reservation=%-14s -> http=%d  attached=%d  EXPECT ALLOW%s',
            $status,
            $order->reservation_status?->value ?? 'null',
            $response->getStatusCode(),
            DB::table('preparation_wave_orders')->where('order_id', $order->id)->count(),
            PHP_EOL,
        ));

        $response->assertStatus(201);
        self::assertSame(
            1,
            DB::table('preparation_wave_orders')->where('order_id', $order->id)->count(),
            "[{$status}] is an authorised Preparation input and must be accepted.",
        );
    }

    private function assertRefused(string $status, Order $order): void
    {
        $response = $this->createWave([$order->id]);

        fwrite(STDERR, sprintf(
            'ENTRY GATE  %-20s reservation=%-14s -> http=%d  attached=%d  EXPECT REFUSE%s',
            $status,
            $order->reservation_status?->value ?? 'null',
            $response->getStatusCode(),
            DB::table('preparation_wave_orders')->where('order_id', $order->id)->count(),
            PHP_EOL,
        ));

        $response->assertStatus(422);
        $this->assertNoMutation($order->id, $status);
    }

    // ── Sanity: the policy itself ─────────────────────────────────────────────

    public function test_default_policy_is_a_closed_list_of_authorised_statuses(): void
    {
        $statuses = PreparationSessionPolicy::defaultEligibleStatuses();

        fwrite(STDERR, 'ENTRY GATE POLICY: '.implode(', ', $statuses).PHP_EOL);

        // ADR-042 §7 — exactly two fulfilment-eligible states.
        self::assertContains(OrderStatus::InProgress->value, $statuses);
        self::assertContains(OrderStatus::Confirmed->value, $statuses);
        self::assertNotContains('confirm_order', $statuses, 'The pre-V2 token must be gone.');
        self::assertNotContains('new', $statuses, 'ADR-042 removed `new` from the lifecycle.');
        self::assertCount(2, $statuses, 'The eligible set is closed at exactly in_progress + confirmed.');

        // Closed list: nothing post-Preparation may appear.
        foreach ([
            OrderStatus::ReadyForDispatch, OrderStatus::OutForDelivery, OrderStatus::Delivered,
            OrderStatus::AwaitingStock, OrderStatus::Cancelled, OrderStatus::Returned,
            OrderStatus::OnHold, OrderStatus::AwaitingPayment, OrderStatus::Scheduled,
        ] as $forbidden) {
            self::assertNotContains($forbidden->value, $statuses, "[{$forbidden->value}] must not be eligible.");
        }
    }

    // ── ALLOWED statuses ──────────────────────────────────────────────────────

    public function test_confirmed_order_is_eligible(): void
    {
        $this->actingAs($this->operator());
        $this->assertEligible('confirmed', $this->order(OrderStatus::Confirmed->value));
    }

    public function test_in_progress_order_is_eligible(): void
    {
        $this->actingAs($this->operator());
        $this->assertEligible('in_progress', $this->order(OrderStatus::InProgress->value));
    }

    /**
     * The V3 shape of "confirmed", retained deliberately.
     *
     * V3 merged `confirmed` into `in_progress` and declared that confirmation was
     * carried by the `confirmed_at` timestamp instead of by a status. ADR-042
     * supersedes that — `confirmed` is a real state again, covered by
     * test_confirmed_order_is_eligible() above.
     *
     * This case is kept rather than deleted because the combination it describes
     * (`in_progress` + a `confirmed_at` stamp) must remain eligible: nothing in
     * ADR-042 makes a timestamp disqualifying, and silently dropping the assertion
     * would leave that untested.
     */
    public function test_in_progress_order_with_a_confirmed_at_stamp_is_still_eligible(): void
    {
        $this->actingAs($this->operator());

        $order = $this->order(OrderStatus::InProgress->value, confirmed: true);
        self::assertNotNull($order->confirmed_at, 'Precondition: the order carries a confirmation stamp.');

        $this->assertEligible('in_progress (confirmed_at set)', $order);
    }

    public function test_an_eligible_order_is_accepted_even_when_not_reserved(): void
    {
        $this->actingAs($this->operator());

        $order = $this->order(OrderStatus::InProgress->value);
        self::assertNull($order->reservation_status, 'Precondition: no reservation.');

        // Reservation is not invented as a Preparation prerequisite.
        $this->assertEligible('in_progress (unreserved)', $order);
    }

    // ── REFUSED statuses — reservation must NOT override ──────────────────────

    public function test_awaiting_stock_is_refused_even_when_reserved(): void
    {
        $this->actingAs($this->operator());
        $this->assertRefused(
            'awaiting_stock',
            $this->order(OrderStatus::AwaitingStock->value, ReservationStatus::Reserved),
        );
    }

    public function test_ready_for_dispatch_is_refused_even_when_reserved(): void
    {
        $this->actingAs($this->operator());
        $this->assertRefused(
            'ready_for_dispatch',
            $this->order(OrderStatus::ReadyForDispatch->value, ReservationStatus::Reserved),
        );
    }

    public function test_out_for_delivery_is_refused_even_when_reserved(): void
    {
        $this->actingAs($this->operator());
        $this->assertRefused(
            'out_for_delivery',
            $this->order(OrderStatus::OutForDelivery->value, ReservationStatus::Reserved),
        );
    }

    public function test_delivered_is_refused_even_when_reserved(): void
    {
        $this->actingAs($this->operator());
        $this->assertRefused(
            'delivered',
            $this->order(OrderStatus::Delivered->value, ReservationStatus::Reserved),
        );
    }

    public function test_cancelled_is_refused_even_when_reserved(): void
    {
        $this->actingAs($this->operator());
        $this->assertRefused(
            'cancelled',
            $this->order(OrderStatus::Cancelled->value, ReservationStatus::Reserved),
        );
    }

    // ── Company isolation ─────────────────────────────────────────────────────

    public function test_an_order_from_another_company_is_refused_even_when_eligible_by_status(): void
    {
        $otherCompany = Company::factory()->create();
        $foreign = $this->order(OrderStatus::InProgress->value, company: $otherCompany);

        $this->actingAs($this->operator());

        $response = $this->createWave([$foreign->id]);

        fwrite(STDERR, sprintf(
            'ENTRY GATE  cross-company (status in_progress) -> http=%d attached=%d EXPECT REFUSE%s',
            $response->getStatusCode(),
            DB::table('preparation_wave_orders')->where('order_id', $foreign->id)->count(),
            PHP_EOL,
        ));

        $response->assertStatus(422);
        $this->assertNoMutation($foreign->id, 'cross-company');
    }

    // ── The second wave route must agree ──────────────────────────────────────

    public function test_recalculate_route_enforces_the_same_policy(): void
    {
        $this->actingAs($this->operator());

        $good = $this->order(OrderStatus::InProgress->value);
        $blocked = $this->order(OrderStatus::AwaitingStock->value, ReservationStatus::Reserved);

        $create = $this->createWave([$good->id]);
        $create->assertStatus(201);
        $waveId = $create->json('data.id') ?? $create->json('id');
        self::assertNotNull($waveId);

        // The real field is add_order_ids (RecalculateWaveRequest).
        $response = $this->postJson("/api/preparation/waves/{$waveId}/recalculate", [
            'add_order_ids' => [$blocked->id],
        ]);

        $attached = DB::table('preparation_wave_orders')->where('order_id', $blocked->id)->count();

        fwrite(STDERR, sprintf(
            'ENTRY GATE  recalculate(awaiting_stock) -> http=%d attached=%d EXPECT REFUSE%s',
            $response->getStatusCode(),
            $attached,
            PHP_EOL,
        ));

        $response->assertStatus(422);
        self::assertSame(0, $attached, 'Both wave routes must enforce the same policy.');
    }

    // ── Duplicate entry ───────────────────────────────────────────────────────

    public function test_duplicate_preparation_entry_remains_blocked(): void
    {
        $this->actingAs($this->operator());

        $order = $this->order(OrderStatus::InProgress->value);

        $this->createWave([$order->id])->assertStatus(201);

        $second = $this->createWave([$order->id]);

        fwrite(STDERR, sprintf(
            'ENTRY GATE  duplicate entry -> http=%d attached=%d EXPECT REFUSE (1 row)%s',
            $second->getStatusCode(),
            DB::table('preparation_wave_orders')->where('order_id', $order->id)->count(),
            PHP_EOL,
        ));

        $second->assertStatus(422);
        self::assertSame(
            1,
            DB::table('preparation_wave_orders')->where('order_id', $order->id)->count(),
            'The pre-existing exclusivity rule must still permit exactly one membership.',
        );
    }
}
