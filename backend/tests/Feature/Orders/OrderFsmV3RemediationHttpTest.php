<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * ADR-042 Order FSM V3 — HTTP-surface coverage the certification found MISSING.
 *
 * TASK-ECOS-ADR-042-TARGETED-REMEDIATION-001 (D6):
 *   - §12: GET /api/orders/statuses is served FROM the enum — its `all` list must equal
 *     OrderStatus::cases() and never carry pre-V3 vocabulary.
 *   - §6.1 (operator side): an order with no resolved warehouse is still Confirmable by the
 *     operator. Warehouse assignment is a preparation concern, not a Confirm gate.
 *
 * `DatabaseTransactions`, not `RefreshDatabase`: `ecos_dev_test` is shared and contended
 * (see OrdersFinalCertificationHttpTest). Orders are created through the real POST
 * /api/orders/manual surface, never Order::create().
 *
 * TEST EXECUTION DEFERRED — added under the ADR-042 remediation but NOT run (project freeze).
 */
final class OrderFsmV3RemediationHttpTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;

    private Customer $customer;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->brand = Brand::create([
            'company_id' => $this->company->id,
            'code' => 'BR'.substr(uniqid(), -8),
            'name' => 'Brand '.uniqid(),
            'slug' => 'brand-'.Str::random(8),
            'is_active' => true,
        ]);
    }

    private function user(): User
    {
        return User::factory()->create(['company_id' => $this->company->id]);
    }

    private function product(): Product
    {
        return Product::factory()->finishedGood()->create([
            'brand_id' => $this->brand->id,
            'company_id' => $this->company->id,
            'can_manufacture' => false,
        ]);
    }

    // ── §12 — GET /orders/statuses is served from the enum ──────────────────────

    /**
     * The endpoint's `all` list is the enum, in enum order, with the canonical labels —
     * not a hand-maintained array that can drift from OrderStatus.
     */
    public function test_orders_statuses_endpoint_is_served_from_the_enum(): void
    {
        $expected = array_map(
            static fn (OrderStatus $s): array => ['value' => $s->value, 'label' => $s->label()],
            OrderStatus::cases(),
        );

        $response = $this->actingAs($this->user())
            ->getJson('/api/orders/statuses')
            ->assertOk();

        self::assertSame($expected, $response->json('data.all'), 'statuses must be served verbatim from OrderStatus::cases()');
    }

    /** §8 corollary over HTTP — no pre-V3 value is ever exposed by the surface. */
    public function test_orders_statuses_endpoint_exposes_no_legacy_vocabulary(): void
    {
        $response = $this->actingAs($this->user())
            ->getJson('/api/orders/statuses')
            ->assertOk();

        $values = array_column($response->json('data.all'), 'value');

        foreach (['new', 'pending', 'processing', 'preparing', 'completed', 'review', 'rescheduled'] as $legacy) {
            self::assertNotContains($legacy, $values, "legacy status '{$legacy}' leaked into the statuses surface");
        }
    }

    /** §3 — the manual entry options offer the three entry states, never `confirmed`. */
    public function test_orders_statuses_manual_entry_options_exclude_confirmed(): void
    {
        $response = $this->actingAs($this->user())
            ->getJson('/api/orders/statuses')
            ->assertOk();

        $manual = array_column($response->json('data.entry_options.manual'), 'value');

        sort($manual);
        self::assertSame(['awaiting_payment', 'in_progress', 'scheduled'], $manual);
        self::assertNotContains('confirmed', $manual, 'confirmed is reachable only through the Confirm action (§3/§5)');
    }

    // ── §6.1 (operator side) — Confirm does not require a warehouse ──────────────

    /**
     * An order created without resolvable geography carries a null warehouse (CASE 6 / RC-10:
     * the reservation is postponed, the status is untouched). The operator must still be able
     * to Confirm it; warehouse assignment happens later, in preparation.
     */
    public function test_operator_can_confirm_an_order_with_no_resolved_warehouse(): void
    {
        $product = $this->product();

        // Manual creation WITHOUT governorate/area → BranchAssignmentEngine resolves no
        // warehouse (the real RC-10 path, not a hand-nulled column).
        $create = $this->actingAs($this->user())->postJson('/api/orders/manual', [
            'customer_id' => $this->customer->id,
            'company_id' => $this->company->id,
            'order_date' => now()->toDateString(),
            'status' => OrderStatus::InProgress->value,
            'lines' => [[
                'product_id' => $product->id,
                'quantity' => 5.0,
                'unit_price' => 100,
            ]],
        ]);
        $create->assertSuccessful();

        $order = Order::whereKey($create->json('data.id'))->first();
        self::assertNotNull($order, 'order was not persisted by the HTTP surface');
        self::assertNull($order->assigned_warehouse_id, 'precondition: no warehouse resolved');
        self::assertSame(OrderStatus::InProgress, $order->status);

        // The operator Confirms through the canonical fulfillment surface.
        $response = $this->actingAs($this->user())
            ->postJson("/api/fulfillment/orders/{$order->id}/confirm")
            ->assertOk();

        $persisted = $order->refresh();
        self::assertSame(OrderStatus::Confirmed->value, $response->json('status'));
        self::assertSame(OrderStatus::Confirmed, $persisted->status, 'a missing warehouse must not block Confirm');
        self::assertNull($persisted->assigned_warehouse_id, 'Confirm did not fabricate a warehouse assignment');
    }
}
