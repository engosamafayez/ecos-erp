<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Models\User;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Enums\PaymentProofState;
use Modules\Commerce\Orders\Domain\Enums\ReservationStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderLine;
use Modules\Commerce\Orders\Domain\Models\PaymentProof;
use Modules\Inventory\InventoryItems\Application\Actions\ReleaseStockAction;
use Modules\Inventory\InventoryItems\Application\DTO\StockOperationDTO;
use Modules\Inventory\InventoryItems\Domain\Models\InventoryItem;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Manufacturing\BillsOfMaterials\Domain\Models\Recipe;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-ORDERS-PREPARATION-PAYMENT-FINAL-FIX-001 — Part 2 (order edit / warehouse) and
 * Part 3 (payment guards).
 *
 * The reported defect: editing a RESERVED made-to-order order (customer/address only)
 * returned HTTP 422 "No inventory record found for the given warehouse and product",
 * because reserve recorded a commitment on `order_lines.reserved_qty` that it never
 * wrote to `inventory_items`, and release then could not find it.
 *
 * D3 (owner-approved): make RESERVE SYMMETRIC — the made-to-order branch now writes the
 * full commitment to inventory, permitted by the recipe-executability decision — and make
 * RELEASE TOLERANT so orders committed before the fix stay editable.
 *
 * The made-to-order fulfillability contract itself (ADR-027 §19) is NOT changed here and
 * is covered by OrderPreparationFulfillabilityContractTest.
 */
final class OrderEditReservationAndPaymentGuardsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Warehouse $warehouse;

    private Customer $customer;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $this->customer = Customer::factory()->create();
        $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
    }

    // ── Fixture ──────────────────────────────────────────────────────────────────

    private function finishedGood(): Product
    {
        return Product::factory()->finishedGood()->create(['brand_id' => $this->brand->id]);
    }

    private function rawMaterial(): Product
    {
        return Product::factory()->rawMaterial()->create([
            'brand_id' => $this->brand->id,
            'is_active' => true,
            'allow_negative_stock' => false,
        ]);
    }

    /** Give the finished good an executable preparation recipe. */
    private function recipeFor(Product $fg, Product $rm, float $qty = 1.0): void
    {
        $recipe = Recipe::create([
            'bom_number' => 'BOM-EDIT-'.uniqid(),
            'product_id' => $fg->id,
            'version' => '1.0',
            'bom_version_number' => 1,
            'is_active' => true,
        ]);
        $recipe->components()->create(['raw_material_id' => $rm->id, 'quantity' => $qty]);
    }

    private function stock(Product $product, float $onHand): InventoryItem
    {
        return InventoryItem::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $product->id,
            'company_id' => $this->company->id,
            'on_hand_qty' => $onHand,
            'reserved_qty' => 0,
        ]);
    }

    private function order(Product $fg, float $qty = 1.0, OrderStatus $status = OrderStatus::InProgress): Order
    {
        $order = Order::query()->create([
            'company_id' => $this->company->id,
            'assigned_warehouse_id' => $this->warehouse->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-'.uniqid(),
            'order_date' => now()->toDateString(),
            'status' => $status->value,
            'customer_name' => 'Original Name',
            'shipping_address' => 'Original Address',
            'subtotal' => 100,
            'total' => 100,
            'shipping_total' => 0,
            'discount_total' => 0,
            'tax_total' => 0,
        ]);

        OrderLine::query()->create([
            'order_id' => $order->id,
            'product_id' => $fg->id,
            'quantity' => $qty,
            'unit_price' => 100.0,
            'line_total' => 100.0 * $qty,
        ]);

        return $order;
    }

    private function actor(): User
    {
        return User::factory()->create(['company_id' => $this->company->id]);
    }

    /** Reserve through the real reservation action. */
    private function reserve(Order $order): void
    {
        app(\Modules\Commerce\Orders\Application\Actions\ReserveOrderInventoryAction::class)
            ->execute($order->fresh(['lines']));
    }

    /**
     * Edit the order the way the operator does: customer/address only, lines resubmitted
     * unchanged (the live UI always resends them).
     *
     * @param  array<string, mixed>  $overrides
     */
    private function editOrder(Order $order, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        $order->refresh();
        $lines = OrderLine::query()->where('order_id', $order->id)->get()
            ->map(fn (OrderLine $l) => [
                'product_id' => $l->product_id,
                'quantity' => (float) $l->quantity,
                'unit_price' => (float) $l->unit_price,
            ])->all();

        return $this->putJson("/api/orders/{$order->id}", array_merge([
            'customer_id' => $order->customer_id,
            'order_date' => $order->order_date instanceof DateTimeInterface
                ? $order->order_date->format('Y-m-d')
                : (string) $order->order_date,
            'status' => $order->status->value,
            'customer_name' => 'Edited Name',
            'shipping_address' => 'Edited Address 42',
            'lines' => $lines,
        ], $overrides));
    }

    // ── Scenario D — the reported defect: made-to-order reserved order ───────────

    public function test_scenario_d_editing_a_made_to_order_reserved_order_succeeds(): void
    {
        $fg = $this->finishedGood();          // zero FG stock, can_manufacture=false
        $rm = $this->rawMaterial();
        $this->recipeFor($fg, $rm);
        $this->stock($rm, 100.0);             // recipe executable
        $this->stock($fg, 0.0);

        $order = $this->order($fg);
        $this->reserve($order);
        $order->refresh();
        self::assertSame(ReservationStatus::Reserved, $order->reservation_status, 'precondition: made-to-order reserves');

        $this->actingAs($this->actor());
        $response = $this->editOrder($order);

        self::assertSame(200, $response->getStatusCode(), $response->getContent());
        self::assertStringNotContainsString('No inventory record found', (string) $response->getContent());

        $order->refresh();
        self::assertSame('Edited Name', $order->customer_name);
        self::assertSame(ReservationStatus::Reserved, $order->reservation_status, 'reservation survives the edit');
    }

    // ── Scenario A/B — reserved order, address-only edit, warehouse unchanged ────

    public function test_scenario_a_b_editing_address_only_keeps_the_warehouse_and_succeeds(): void
    {
        $fg = $this->finishedGood();
        $rm = $this->rawMaterial();
        $this->recipeFor($fg, $rm);
        $this->stock($rm, 100.0);
        $this->stock($fg, 0.0);

        $order = $this->order($fg);
        $this->reserve($order);

        $this->actingAs($this->actor());
        self::assertSame(200, $this->editOrder($order)->getStatusCode());

        $order->refresh();
        self::assertSame($this->warehouse->id, $order->assigned_warehouse_id, 'the canonical warehouse is untouched by an address edit');
    }

    // ── Scenario C — order fulfilled from physical FG stock ─────────────────────

    public function test_scenario_c_editing_a_physically_stocked_reserved_order_keeps_the_reservation(): void
    {
        $fg = $this->finishedGood();
        $this->stock($fg, 50.0);              // Case 1: physical FG stock

        $order = $this->order($fg, 5.0);
        $this->reserve($order);
        $order->refresh();
        self::assertSame(ReservationStatus::Reserved, $order->reservation_status);

        $this->actingAs($this->actor());
        self::assertSame(200, $this->editOrder($order)->getStatusCode());

        $order->refresh();
        self::assertSame(ReservationStatus::Reserved, $order->reservation_status);
    }

    // ── D3 — reserve is now symmetric: inventory records what the line claims ────

    public function test_made_to_order_reservation_is_written_to_inventory_not_only_to_the_line(): void
    {
        $fg = $this->finishedGood();
        $rm = $this->rawMaterial();
        $this->recipeFor($fg, $rm);
        $this->stock($rm, 100.0);
        $item = $this->stock($fg, 0.0);

        $order = $this->order($fg, 3.0);
        $this->reserve($order);

        $item->refresh();
        self::assertSame(3.0, (float) $item->reserved_qty, 'inventory must hold what the order line claims');
        // The negative availability IS the physical truth: 3 units still to be prepared.
        self::assertSame(-3.0, (float) $item->availableQty());
    }

    // ── D3 — release tolerates a commitment inventory never recorded (legacy rows) ──

    public function test_releasing_stock_with_no_inventory_row_is_a_no_op_not_an_error(): void
    {
        $fg = $this->finishedGood();   // deliberately NO inventory_items row

        $result = app(ReleaseStockAction::class)->execute(new StockOperationDTO(
            warehouse_id: $this->warehouse->id,
            product_id: $fg->id,
            company_id: $this->company->id,
            quantity: 1.0,
            reference_type: 'sales_order',
            reference_id: (string) \Illuminate\Support\Str::uuid(),
        ));

        self::assertTrue($result->isSuccess());
        self::assertNull($result->data());
    }

    // ── D4 — the canonical warehouse is resolvable by the UI ────────────────────

    public function test_order_api_exposes_the_assigned_warehouse_with_its_name(): void
    {
        $fg = $this->finishedGood();
        $this->stock($fg, 10.0);
        $order = $this->order($fg);

        $this->actingAs($this->actor());
        $response = $this->getJson("/api/orders/{$order->id}");

        $response->assertOk();
        $response->assertJsonPath('data.assigned_warehouse_id', $this->warehouse->id);
        $response->assertJsonPath('data.assigned_warehouse.id', $this->warehouse->id);
        $response->assertJsonPath('data.assigned_warehouse.name', $this->warehouse->name);
    }

    // ── D6 — deposit_amount is no longer a field edit ───────────────────────────

    public function test_deposit_amount_cannot_be_mass_assigned_through_the_order_update(): void
    {
        $fg = $this->finishedGood();
        $this->stock($fg, 10.0);
        $order = $this->order($fg);

        $this->actingAs($this->actor());
        $this->editOrder($order, ['deposit_amount' => 100])->assertStatus(200);

        $order->refresh();
        self::assertNotEquals(
            100.0,
            (float) $order->deposit_amount,
            'recording a payment must go through RecordOrderPaymentAction, not a field edit',
        );
    }

    // ── Payment-method catalogue is constrained on update, closing the gate bypass ──

    public function test_update_rejects_a_payment_method_outside_the_catalogue(): void
    {
        $fg = $this->finishedGood();
        $this->stock($fg, 10.0);
        $order = $this->order($fg);

        $this->actingAs($this->actor());
        $this->editOrder($order, ['payment_method_manual' => 'instapayy'])
            ->assertStatus(422);
    }

    public function test_update_accepts_a_catalogued_payment_method(): void
    {
        $fg = $this->finishedGood();
        $this->stock($fg, 10.0);
        $order = $this->order($fg);

        $this->actingAs($this->actor());
        $this->editOrder($order, ['payment_method_manual' => 'instapay'])->assertStatus(200);

        $order->refresh();
        self::assertSame('instapay', $order->payment_method_manual);
    }

    // ── D5 — the confirm gate reads the PaymentProof lifecycle ──────────────────
    //
    // History of the target, because it has now moved twice:
    //   IMPLEMENTATION-001 renamed `hasAcceptedPaymentProof` → `hasVerifiedPaymentProof` and
    //   dropped its legacy `orders.payment_proof_path` branch, making `payment_proofs` the
    //   only source of proof truth.
    //   IMPLEMENTATION-002 moved that helper out of ConfirmOrderWorkflow into
    //   `PaymentFulfillmentGate`, the SINGLE implementation the workflow and every creation
    //   path now share — the duplication was what let creation and confirmation drift apart.
    //
    // Every assertion below is UNCHANGED. Only the object under test moved, and because the
    // helper is public on the gate, these no longer need reflection to reach it. The four
    // cases they pin (no proof / verified+active / uploaded / verified+superseded) are
    // exactly the semantics the gate preserves.

    public function test_a_verified_payment_proof_satisfies_the_confirmation_gate(): void
    {
        $order = $this->order($this->finishedGood());

        $gate = app(\Modules\Commerce\Orders\Domain\Services\PaymentFulfillmentGate::class);

        self::assertFalse($gate->hasVerifiedProof($order->fresh()), 'no proof yet');

        PaymentProof::query()->create([
            'order_id' => $order->id,
            'company_id' => $this->company->id,
            'state' => PaymentProofState::Verified->value,
            'storage_disk' => 'local',
            'storage_path' => 'payment-proofs/x.jpg',
            'original_filename' => 'x.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'uploaded_at' => now(),
        ]);

        self::assertTrue($gate->hasVerifiedProof($order->fresh()), 'a verified, non-superseded proof is accepted');
    }

    public function test_an_unverified_or_superseded_proof_does_not_satisfy_the_gate(): void
    {
        $order = $this->order($this->finishedGood());

        // Merely uploaded — evidence submitted, not accepted.
        $uploaded = PaymentProof::query()->create([
            'order_id' => $order->id,
            'company_id' => $this->company->id,
            'state' => PaymentProofState::Uploaded->value,
            'storage_disk' => 'local',
            'storage_path' => 'payment-proofs/a.jpg',
            'original_filename' => 'a.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 10,
            'uploaded_at' => now(),
        ]);

        $gate = app(\Modules\Commerce\Orders\Domain\Services\PaymentFulfillmentGate::class);

        self::assertFalse($gate->hasVerifiedProof($order->fresh()), 'uploaded is not accepted');

        // Verified but superseded (replaced) — no longer the active proof.
        $uploaded->update(['state' => PaymentProofState::Verified->value, 'superseded_at' => now()]);

        self::assertFalse($gate->hasVerifiedProof($order->fresh()), 'a superseded proof is not the active one');
    }
}
