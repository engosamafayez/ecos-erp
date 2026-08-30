<?php

declare(strict_types=1);

namespace Tests\Feature\Logistics;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderLine;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Operations\Loading\Domain\Models\LoadingTask;
use Modules\Operations\Loading\Domain\Models\LoadingTaskAdjustment;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-LOADING-WAREHOUSE-DRIVER-CUSTODY-IMPLEMENTATION-001.
 *
 * ┌─ THE INVARIANTS THIS SUITE DEFENDS ──────────────────────────────────────┐
 * │ Remaining = Required − LOADED  (never Required − Prepared)                 │
 * │ Prepared never becomes Loaded                                             │
 * │ A DRIVER ACTION NEVER MOVES `quantity_loaded` — not confirming, and not    │
 * │ requesting an adjustment. Only a warehouse decision moves it.             │
 * │ No overwrite without trace: round 1 survives round 2.                     │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
class LoadingCustodyWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const DIST = '/api/logistics/distribution';

    private const LOADING = '/api/loading';

    private Company $company;

    private Customer $customer;

    private Warehouse $warehouse;

    private int $zone;

    private Product $honey;

    /** A second product, so the completion gate can be tested across a mixed shipment. */
    private Product $coffee;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('distribution.window.opens_at', '00:00');
        config()->set('distribution.window.closes_at', '23:59');

        $this->company = Company::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);

        $gov = (int) DB::table('logistics_governorates')->insertGetId([
            'country_id' => 1, 'name_ar' => 'Cairo', 'name_en' => 'Cairo',
            'default_shipping_price' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->zone = (int) DB::table('distribution_zones')->insertGetId([
            'code' => 'CU-'.substr(uniqid(), -6), 'name_ar' => 'Maadi', 'name_en' => 'Maadi',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $cityId = (int) DB::table('logistics_cities')->insertGetId([
            'governorate_id' => $gov, 'name_ar' => 'Maadi', 'name_en' => 'Maadi',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('logistics_cities')->where('id', $cityId)->update(['distribution_zone_id' => $this->zone]);

        $this->honey = Product::factory()->create();
        $this->coffee = Product::factory()->create();

        // Driver confirmation now performs a CANONICAL warehouse→vehicle stock movement
        // (TASK-DRIVER-CUSTODY-INVENTORY-TRANSFER-001): confirming receipt issues the
        // loaded quantity out of the source warehouse via ShipStockAction. So a product
        // the driver confirms must have real, reserved warehouse stock to move — the same
        // precondition production has. This is a fixture completion, not a weakened rule:
        // no assertion below is relaxed, the products simply now own the stock they ship.
        $this->seedStock($this->honey->id, onHand: 1000, reserved: 1000);
        $this->seedStock($this->coffee->id, onHand: 1000, reserved: 1000);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Warehouse: record + confirm
    // ─────────────────────────────────────────────────────────────────────────

    public function test_warehouse_confirms_loaded_and_remaining_is_required_minus_loaded(): void
    {
        $slot = $this->startedGroup(required: 10);
        $this->setPrepared($slot, 10);

        // Prepared is 10 and Loaded is still 0 — Remaining must be 10, NOT 0.
        $before = $this->productRow($this->manifest($slot));
        self::assertSame(10.0, $this->num($before['quantity_required']));
        self::assertSame(10.0, $this->num($before['quantity_prepared']));
        self::assertSame(0.0, $this->num($before['quantity_loaded']));
        self::assertSame(10.0, $this->num($before['quantity_remaining']));
        self::assertSame('pending_loading', $before['workflow_state']);

        $this->warehouseConfirm($slot, 6)->assertOk();

        $row = $this->productRow($this->manifest($slot));
        self::assertSame(6.0, $this->num($row['quantity_loaded']));
        self::assertSame(4.0, $this->num($row['quantity_remaining']));
        self::assertSame(10.0, $this->num($row['quantity_prepared']), 'Prepared must be untouched by loading');
        self::assertNotNull($row['warehouse_confirmed_at']);
        self::assertSame('awaiting_driver_confirmation', $row['workflow_state']);
    }

    public function test_over_loading_is_still_refused(): void
    {
        $slot = $this->startedGroup(required: 3);

        // The existing ceiling is untouched: loaded may fall short, never exceed.
        $this->warehouseConfirm($slot, 4)->assertStatus(422);

        self::assertSame(0.0, $this->num($this->productRow($this->manifest($slot))['quantity_loaded']));
    }

    public function test_warehouse_confirm_is_idempotent(): void
    {
        $slot = $this->startedGroup(required: 3);

        $this->warehouseConfirm($slot, 3)->assertOk();
        $this->warehouseConfirm($slot, 3)->assertOk();

        self::assertSame(1, LoadingTask::query()->count(), 'a repeated confirm must not create a second task');
        self::assertSame(3.0, $this->num($this->productRow($this->manifest($slot))['quantity_loaded']));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Driver: confirm received
    // ─────────────────────────────────────────────────────────────────────────

    public function test_driver_confirmation_does_not_modify_loaded(): void
    {
        $slot = $this->startedGroup(required: 3);
        $this->warehouseConfirm($slot, 3)->assertOk();

        $this->driverConfirm(3)->assertOk();

        $row = $this->productRow($this->manifest($slot));
        self::assertSame(3.0, $this->num($row['quantity_loaded']), 'the driver must never move Loaded');
        self::assertSame(3.0, $this->num($row['quantity_driver_received']));
        self::assertNotNull($row['driver_confirmed_at']);
        self::assertSame('driver_confirmed', $row['workflow_state']);
    }

    /**
     * Confirming receipt issues the loaded quantity out of the source warehouse through
     * the CANONICAL Stock Ledger — the warehouse→vehicle custody transfer, end to end
     * over the real driver endpoint (TASK-DRIVER-CUSTODY-INVENTORY-TRANSFER-001).
     */
    public function test_driver_confirmation_transfers_the_loaded_stock_out_of_the_warehouse(): void
    {
        $slot = $this->startedGroup(required: 3);
        $this->warehouseConfirm($slot, 3)->assertOk();

        $before = (float) DB::table('inventory_items')
            ->where('product_id', $this->honey->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->value('on_hand_qty');

        $this->driverConfirm(3)->assertOk();

        $after = (float) DB::table('inventory_items')
            ->where('product_id', $this->honey->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->value('on_hand_qty');

        self::assertSame($before - 3.0, $after, 'confirming receipt issued exactly 3 out of the warehouse');

        // A canonical ledger movement was written, keyed to the loading task.
        $task = LoadingTask::query()->where('product_id', $this->honey->id)->firstOrFail();
        $entry = DB::table('stock_ledger_entries')
            ->where('reference_type', 'vehicle_custody_transfer')
            ->where('reference_id', $task->id)
            ->first();

        self::assertNotNull($entry, 'a canonical custody-transfer ledger row was written');
        self::assertSame('sales_issue', (string) $entry->movement_type);
        self::assertSame(3.0, (float) $entry->quantity);

        // Reconciliation: the warehouse decrement equals the vehicle custody credit.
        $custody = (float) DB::table('vehicle_inventory_items')
            ->where('product_id', $this->honey->id)
            ->value('quantity_loaded');
        self::assertSame(3.0, $custody, 'the vehicle holds the 3 the warehouse issued — the two reconcile');

        // No unrelated inventory is touched — a product not on this confirmation keeps
        // its full seeded balance.
        self::assertSame(
            1000.0,
            (float) DB::table('inventory_items')
                ->where('product_id', $this->coffee->id)
                ->where('warehouse_id', $this->warehouse->id)
                ->value('on_hand_qty'),
            'an unrelated product is untouched by this transfer',
        );
    }

    /**
     * If the canonical movement cannot proceed — insufficient stock and the product does
     * NOT permit negative stock — the driver confirmation is REFUSED and rolls back, so
     * the receipt is never falsely completed (requirement 10). No stock moves.
     */
    public function test_a_confirmation_that_cannot_move_stock_is_refused_and_does_not_complete(): void
    {
        $slot = $this->startedGroup(required: 3);
        $this->warehouseConfirm($slot, 3)->assertOk();

        // The warehouse cannot cover it, and this product forbids negative stock. Reserved
        // stays high so the block is the on_hand rule, not the reservation guard.
        DB::table('products')->where('id', $this->honey->id)->update(['allow_negative_stock' => false]);
        DB::table('inventory_items')
            ->where('product_id', $this->honey->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->update(['on_hand_qty' => 0]);

        $this->driverConfirm(3)->assertStatus(422);

        // The confirmation rolled back with the refused transfer.
        self::assertNull(
            $this->productRow($this->manifest($slot))['driver_confirmed_at'],
            'the driver confirmation is not falsely completed when the stock cannot move',
        );
        self::assertSame(
            0.0,
            (float) DB::table('inventory_items')
                ->where('product_id', $this->honey->id)
                ->where('warehouse_id', $this->warehouse->id)
                ->value('on_hand_qty'),
            'and no stock moved',
        );
        self::assertSame(
            0,
            DB::table('stock_ledger_entries')->where('reference_type', 'vehicle_custody_transfer')->count(),
            'no custody-transfer ledger row was written',
        );
    }

    /**
     * A product that permits negative stock overdrafts the warehouse instead of being
     * refused — the operational overdraft ECOS supports, offset by a later receipt.
     */
    public function test_a_negative_stock_product_overdrafts_the_warehouse_on_confirmation(): void
    {
        $slot = $this->startedGroup(required: 3);
        $this->warehouseConfirm($slot, 3)->assertOk();

        DB::table('products')->where('id', $this->honey->id)->update(['allow_negative_stock' => true]);
        DB::table('inventory_items')
            ->where('product_id', $this->honey->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->update(['on_hand_qty' => 0]);

        $this->driverConfirm(3)->assertOk();

        self::assertSame(
            -3.0,
            (float) DB::table('inventory_items')
                ->where('product_id', $this->honey->id)
                ->where('warehouse_id', $this->warehouse->id)
                ->value('on_hand_qty'),
            'the balance went negative, to be offset by a later goods receipt',
        );
    }

    /** Repeated confirmation is idempotent: the warehouse is deducted once, never twice. */
    public function test_repeated_confirmation_does_not_deduct_the_warehouse_twice(): void
    {
        $slot = $this->startedGroup(required: 3);
        $this->warehouseConfirm($slot, 3)->assertOk();

        $before = (float) DB::table('inventory_items')
            ->where('product_id', $this->honey->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->value('on_hand_qty');

        $this->driverConfirm(3)->assertOk();
        $this->driverConfirm(3)->assertOk();

        $after = (float) DB::table('inventory_items')
            ->where('product_id', $this->honey->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->value('on_hand_qty');

        self::assertSame($before - 3.0, $after, 'deducted once for the two confirmations');
        self::assertSame(
            1,
            DB::table('stock_ledger_entries')->where('reference_type', 'vehicle_custody_transfer')->count(),
            'exactly one custody-transfer ledger row',
        );
    }

    /**
     * The DRIVER manifest must still list the product once a task exists.
     *
     * Added after a real regression: the manifest builder reused the variable holding
     * the Group's product rows for the open-adjustment query, so the entire driver
     * manifest emptied the moment a loading task appeared. The warehouse manifest was
     * unaffected, which is exactly why this suite missed it — it only read that side.
     */
    public function test_the_driver_manifest_still_lists_products_after_a_warehouse_confirm(): void
    {
        $slot = $this->startedGroup(required: 4);
        $this->warehouseConfirm($slot, 4)->assertOk();

        $items = $this->actingAs($this->driverUser())
            ->getJson('/api/driver/loading')
            ->assertOk()
            ->json('data.items');

        self::assertNotEmpty($items, 'the driver manifest must not empty once a task exists');

        $row = collect($items)->firstWhere('product_id', $this->honey->id);
        self::assertNotNull($row);
        self::assertSame(4.0, $this->num($row['quantity_loaded']));
        self::assertSame('awaiting_driver_confirmation', $row['workflow_state']);
    }

    public function test_driver_cannot_confirm_before_the_warehouse_has(): void
    {
        $this->startedGroup(required: 3);

        // No warehouse confirmation yet, so there is no task at all to confirm.
        $this->driverConfirm(3)->assertStatus(404);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Adjustment: request → accept / edit / reject
    // ─────────────────────────────────────────────────────────────────────────

    public function test_adjustment_request_does_not_modify_loaded(): void
    {
        $slot = $this->startedGroup(required: 3);
        $this->warehouseConfirm($slot, 3)->assertOk();

        $this->driverRequestAdjustment(2)->assertOk();

        $row = $this->productRow($this->manifest($slot));
        self::assertSame(3.0, $this->num($row['quantity_loaded']), 'a request is not a change');
        self::assertSame(2.0, $this->num($row['quantity_driver_received']));
        self::assertSame('adjustment_requested', $row['workflow_state']);
        self::assertSame(2.0, $this->num($row['open_adjustment']['driver_reported_qty']));
    }

    public function test_adjustment_request_is_idempotent(): void
    {
        $slot = $this->startedGroup(required: 3);
        $this->warehouseConfirm($slot, 3)->assertOk();

        $this->driverRequestAdjustment(2)->assertOk();
        $this->driverRequestAdjustment(2)->assertOk();

        self::assertSame(
            1,
            LoadingTaskAdjustment::query()->where('status', LoadingTaskAdjustment::STATUS_OPEN)->count(),
            'a repeated request must not open a rival record',
        );
    }

    public function test_driver_cannot_confirm_while_an_adjustment_is_open(): void
    {
        $slot = $this->startedGroup(required: 3);
        $this->warehouseConfirm($slot, 3)->assertOk();
        $this->driverRequestAdjustment(2)->assertOk();

        $this->driverConfirm(3)->assertStatus(422);
    }

    public function test_warehouse_accepts_the_driver_quantity_and_driver_must_reconfirm(): void
    {
        $slot = $this->startedGroup(required: 3);
        $this->warehouseConfirm($slot, 3)->assertOk();
        $this->driverConfirm(3)->assertOk();          // driver agrees at 3 …
        $this->driverRequestAdjustment(2)->assertOk(); // … then recounts and disputes

        $open = $this->productRow($this->manifest($slot))['open_adjustment'];
        $this->warehouseResolve($slot, $open['id'], 'accept')->assertOk();

        $row = $this->productRow($this->manifest($slot));

        // Required 3, Loaded 2, Remaining 1 — the brief's worked example.
        self::assertSame(3.0, $this->num($row['quantity_required']));
        self::assertSame(2.0, $this->num($row['quantity_loaded']));
        self::assertSame(1.0, $this->num($row['quantity_remaining']));

        // The earlier driver confirmation is now STALE — purely by timestamp order.
        self::assertSame('awaiting_driver_reconfirmation', $row['workflow_state']);

        $this->driverConfirm(2)->assertOk();
        self::assertSame('driver_confirmed', $this->productRow($this->manifest($slot))['workflow_state']);
    }

    public function test_warehouse_edits_to_a_third_quantity(): void
    {
        $slot = $this->startedGroup(required: 5);
        $this->warehouseConfirm($slot, 5)->assertOk();
        $this->driverRequestAdjustment(2)->assertOk();

        $open = $this->productRow($this->manifest($slot))['open_adjustment'];
        // The warehouse recounts and finds 3 — neither its own 5 nor the driver's 2.
        $this->warehouseResolve($slot, $open['id'], 'edit', 3)->assertOk();

        $row = $this->productRow($this->manifest($slot));
        self::assertSame(3.0, $this->num($row['quantity_loaded']));
        self::assertSame(2.0, $this->num($row['quantity_remaining']));
    }

    public function test_warehouse_rejects_and_the_loaded_quantity_is_unchanged(): void
    {
        $slot = $this->startedGroup(required: 3);
        $this->warehouseConfirm($slot, 3)->assertOk();
        $this->driverRequestAdjustment(2)->assertOk();

        $open = $this->productRow($this->manifest($slot))['open_adjustment'];
        $this->warehouseResolve($slot, $open['id'], 'reject')->assertOk();

        $row = $this->productRow($this->manifest($slot));

        // Reject exists precisely so a correct number need not be altered.
        self::assertSame(3.0, $this->num($row['quantity_loaded']));
        self::assertNull($row['open_adjustment']);

        self::assertSame(1, LoadingTaskAdjustment::query()
            ->where('status', LoadingTaskAdjustment::STATUS_REJECTED)
            ->where('action_type', LoadingTaskAdjustment::ACTION_WAREHOUSE_REJECTED)
            ->count());
    }

    public function test_resolving_twice_is_refused(): void
    {
        $slot = $this->startedGroup(required: 3);
        $this->warehouseConfirm($slot, 3)->assertOk();
        $this->driverRequestAdjustment(2)->assertOk();

        $open = $this->productRow($this->manifest($slot))['open_adjustment'];
        $this->warehouseResolve($slot, $open['id'], 'accept')->assertOk();
        $this->warehouseResolve($slot, $open['id'], 'reject')->assertStatus(422);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // History, concurrency, authorization
    // ─────────────────────────────────────────────────────────────────────────

    public function test_multiple_adjustment_rounds_are_all_preserved(): void
    {
        $slot = $this->startedGroup(required: 10);
        $this->warehouseConfirm($slot, 10)->assertOk();

        // Round 1: driver says 8, warehouse accepts.
        $this->driverRequestAdjustment(8)->assertOk();
        $r1 = $this->productRow($this->manifest($slot))['open_adjustment'];
        $this->warehouseResolve($slot, $r1['id'], 'accept')->assertOk();

        // Round 2: driver recounts to 7, warehouse accepts again.
        $this->driverRequestAdjustment(7)->assertOk();
        $r2 = $this->productRow($this->manifest($slot))['open_adjustment'];
        $this->warehouseResolve($slot, $r2['id'], 'accept')->assertOk();

        // Round 1 must still be on record: 2 requests + 2 decisions.
        self::assertSame(4, LoadingTaskAdjustment::query()->count(), 'history must not be overwritten');
        self::assertSame(1, LoadingTaskAdjustment::query()->where('driver_reported_qty', 8.0)
            ->where('action_type', LoadingTaskAdjustment::ACTION_DRIVER_REQUESTED)->count());

        self::assertSame(7.0, $this->num($this->productRow($this->manifest($slot))['quantity_loaded']));
    }

    public function test_a_stale_driver_confirmation_is_refused(): void
    {
        $slot = $this->startedGroup(required: 5);
        $this->warehouseConfirm($slot, 5)->assertOk();

        // The driver's screen still shows 5; the warehouse has since revised to 4.
        $this->warehouseConfirm($slot, 4)->assertOk();

        $this->driverConfirm(5, expected: 5.0)->assertStatus(409);

        // Nothing was written on the refusal.
        self::assertNull($this->productRow($this->manifest($slot))['driver_confirmed_at']);
    }

    public function test_a_driver_cannot_resolve_an_adjustment(): void
    {
        $slot = $this->startedGroup(required: 3);
        $this->warehouseConfirm($slot, 3)->assertOk();
        $this->driverRequestAdjustment(2)->assertOk();
        $open = $this->productRow($this->manifest($slot))['open_adjustment'];

        // A driver-only subject: loading.driver.operate and nothing else.
        $driver = $this->userWithOnly('loading.driver.operate');

        $this->actingAsUnprivileged($driver)
            ->postJson(self::LOADING."/groups/{$slot}/adjustments/{$open['id']}/resolve", ['action' => 'accept'])
            ->assertStatus(403);

        // And cannot reach the warehouse quantity write at all.
        $this->actingAsUnprivileged($driver)
            ->postJson(self::LOADING."/groups/{$slot}/products/{$this->honey->id}/confirm", ['quantity_loaded' => 1])
            ->assertStatus(403);

        self::assertSame(3.0, $this->num($this->productRow($this->manifest($slot))['quantity_loaded']));
    }

    public function test_a_warehouse_operator_can_confirm_but_not_act_as_the_driver(): void
    {
        $slot = $this->startedGroup(required: 3);

        $warehouse = $this->userWithOnly('loading.session.operate');

        $this->actingAsUnprivileged($warehouse)
            ->postJson(self::LOADING."/groups/{$slot}/products/{$this->honey->id}/confirm", ['quantity_loaded' => 3])
            ->assertOk();

        // The driver runtime is a different capability the warehouse does not hold.
        $this->actingAsUnprivileged($warehouse)
            ->postJson("/api/driver/loading/products/{$this->honey->id}/confirm", ['received_qty' => 3])
            ->assertStatus(403);

        self::assertNull($this->productRow($this->manifest($slot))['driver_confirmed_at']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Loading Complete gate — TASK-LOADING-DRIVER-COMPLETE-GATE-001
    //
    // A shipment must not close while the warehouse has loaded something the driver
    // has not acknowledged. The gate reads the SAME derived custody state the driver's
    // manifest shows, so the refusal and the screen can never disagree.
    // ─────────────────────────────────────────────────────────────────────────

    /** CASE A — one confirmed, one awaiting. Completion must be refused. */
    public function test_completion_is_blocked_while_an_item_awaits_driver_confirmation(): void
    {
        $slot = $this->startedGroup(required: 1, second: 1);

        $this->warehouseConfirm($slot, 1)->assertOk();                       // product 1
        $this->warehouseConfirm($slot, 1, $this->coffee->id)->assertOk();    // product 2
        $this->driverConfirm(1)->assertOk();                                 // only product 1

        $response = $this->actingAs($this->driverUser())
            ->postJson('/api/driver/loading/complete')
            ->assertStatus(422);

        self::assertSame(1, $response->json('pending_confirmations'));
        self::assertStringContainsString('confirmed by the driver', (string) $response->json('message'));

        // The session/assignment must NOT have advanced.
        self::assertSame('loading', (string) DB::table('vehicle_assignments')->value('status'));
        self::assertNull(DB::table('vehicle_assignments')->value('loading_completed_at'));
    }

    /** CASE B — every loaded item confirmed. The existing completion flow runs. */
    public function test_completion_succeeds_once_every_loaded_item_is_confirmed(): void
    {
        $slot = $this->startedGroup(required: 1, second: 1);

        $this->warehouseConfirm($slot, 1)->assertOk();
        $this->warehouseConfirm($slot, 1, $this->coffee->id)->assertOk();
        $this->driverConfirm(1)->assertOk();
        $this->driverConfirm(1, productId: $this->coffee->id)->assertOk();

        $this->actingAs($this->driverUser())
            ->postJson('/api/driver/loading/complete')
            ->assertOk();

        self::assertSame('loading_complete', (string) DB::table('vehicle_assignments')->value('status'));
    }

    /** CASE C — nothing loaded must not block; there is no custody to acknowledge. */
    public function test_completion_is_not_blocked_when_nothing_was_loaded(): void
    {
        $slot = $this->startedGroup(required: 3);

        // A task exists but carries zero loaded quantity.
        $this->warehouseConfirm($slot, 0)->assertOk();

        $this->actingAs($this->driverUser())
            ->postJson('/api/driver/loading/complete')
            ->assertOk();

        self::assertSame('loading_complete', (string) DB::table('vehicle_assignments')->value('status'));
    }

    /** CASE D — a raised discrepancy is the driver ACTING, not a pending confirmation. */
    public function test_an_open_adjustment_does_not_block_completion(): void
    {
        $slot = $this->startedGroup(required: 3);

        $this->warehouseConfirm($slot, 3)->assertOk();
        $this->driverRequestAdjustment(2)->assertOk();

        // The driver disputed it; the approved adjustment workflow owns it now. Trapping
        // the shipment behind a dispute the driver already raised would be wrong.
        $this->actingAs($this->driverUser())
            ->postJson('/api/driver/loading/complete')
            ->assertOk();
    }

    /**
     * The gate governs items the WAREHOUSE handed over — not the legacy self-load path.
     *
     * Added after the regression suite caught an over-broad first version of this rule: a
     * task with a quantity but NO warehouse confirmation is the legacy flow where the
     * driver records the quantity themselves. There is no handover to acknowledge, and
     * blocking it made that flow uncompletable.
     */
    public function test_a_self_loaded_item_with_no_warehouse_confirmation_does_not_block(): void
    {
        $slot = $this->startedGroup(required: 4);

        // Quantity recorded WITHOUT a warehouse confirmation — `confirmed_at` stays null.
        $assignmentId = DB::table('vehicle_assignments')->value('id');
        DB::table('loading_tasks')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $this->company->id,
            'loading_session_id' => DB::table('loading_sessions')->value('id'),
            'vehicle_assignment_id' => $assignmentId,
            'pool_entry_id' => null,
            'preparation_wave_id' => null,
            'product_id' => $this->honey->id,
            'sku_snapshot' => 'SKU', 'name_snapshot' => 'honey',
            'quantity_planned' => 4, 'quantity_loaded' => 4, 'quantity_short' => 0,
            'status' => 'loaded',
            'created_by' => (string) Str::uuid(), 'updated_by' => (string) Str::uuid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        self::assertSame(
            'pending_loading',
            $this->productRow($this->manifest($slot))['workflow_state'],
        );

        $this->actingAs($this->driverUser())
            ->postJson('/api/driver/loading/complete')
            ->assertOk();
    }

    /** CASE E — a warehouse revision makes an earlier confirmation stale, so it blocks again. */
    public function test_a_stale_confirmation_blocks_completion_again(): void
    {
        $slot = $this->startedGroup(required: 5);

        $this->warehouseConfirm($slot, 5)->assertOk();
        $this->driverConfirm(5)->assertOk();

        // Confirmed and complete-able at this point.
        self::assertSame(
            'driver_confirmed',
            $this->productRow($this->manifest($slot))['workflow_state'],
        );

        // The warehouse then recounts to 4 — the driver never agreed to 4.
        $this->warehouseConfirm($slot, 4)->assertOk();

        $this->actingAs($this->driverUser())
            ->postJson('/api/driver/loading/complete')
            ->assertStatus(422);

        // Uses the EXISTING staleness mechanism — no second one was introduced.
        self::assertSame(
            'awaiting_driver_reconfirmation',
            $this->productRow($this->manifest($slot))['workflow_state'],
        );

        // And it clears once the driver accepts the new number.
        $this->driverConfirm(4)->assertOk();
        $this->actingAs($this->driverUser())
            ->postJson('/api/driver/loading/complete')
            ->assertOk();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function num(mixed $v): float
    {
        return (float) $v;
    }

    /** @return array<string, mixed> */
    private function manifest(string $slot): array
    {
        return $this->actingAs($this->userFor())
            ->getJson(self::LOADING.'/groups/'.$slot)
            ->assertOk()
            ->json('data');
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function productRow(array $data): array
    {
        $row = collect($data['products'])->firstWhere('product_id', $this->honey->id);
        self::assertNotNull($row, 'the product is missing from the manifest');

        return $row;
    }

    private function warehouseConfirm(
        string $slot,
        float $qty,
        ?string $productId = null,
    ): \Illuminate\Testing\TestResponse {
        $productId ??= $this->honey->id;

        return $this->actingAs($this->userFor())
            ->postJson(self::LOADING."/groups/{$slot}/products/{$productId}/confirm", [
                'quantity_loaded' => $qty,
            ]);
    }

    private function warehouseResolve(
        string $slot,
        string $adjustmentId,
        string $action,
        ?float $qty = null,
    ): \Illuminate\Testing\TestResponse {
        $payload = ['action' => $action];

        if ($qty !== null) {
            $payload['quantity_loaded'] = $qty;
        }

        return $this->actingAs($this->userFor())
            ->postJson(self::LOADING."/groups/{$slot}/adjustments/{$adjustmentId}/resolve", $payload);
    }

    private function driverConfirm(
        float $qty,
        ?float $expected = null,
        ?string $productId = null,
    ): \Illuminate\Testing\TestResponse {
        $payload = ['received_qty' => $qty];

        if ($expected !== null) {
            $payload['expected_loaded_qty'] = $expected;
        }

        $productId ??= $this->honey->id;

        return $this->actingAs($this->driverUser())
            ->postJson("/api/driver/loading/products/{$productId}/confirm", $payload);
    }

    private function driverRequestAdjustment(float $qty): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->driverUser())
            ->postJson("/api/driver/loading/products/{$this->honey->id}/adjustment", [
                'reported_qty' => $qty,
            ]);
    }

    /**
     * A Group with one order, a Trip, and an OPEN loading session + vehicle assignment —
     * i.e. Start Loading has already happened. No task and no quantity yet: those are
     * what the workflow under test creates.
     */
    private function startedGroup(float $required, ?float $second = null): string
    {
        $order = Order::query()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-CU-'.uniqid(),
            'order_date' => now()->toDateString(),
            'assigned_warehouse_id' => $this->warehouse->id,
            'city' => 'Maadi', 'governorate' => 'Cairo',
            'status' => 'in_progress',
            'subtotal' => 100, 'total' => 100, 'deposit_amount' => 0,
            'shipping_total' => 0, 'discount_total' => 0, 'tax_total' => 0,
        ]);

        OrderLine::query()->create([
            'order_id' => $order->id,
            'product_id' => $this->honey->id,
            'quantity' => $required,
            'unit_price' => 10,
            'line_total' => $required * 10,
        ]);

        if ($second !== null) {
            OrderLine::query()->create([
                'order_id' => $order->id,
                'product_id' => $this->coffee->id,
                'quantity' => $second,
                'unit_price' => 10,
                'line_total' => $second * 10,
            ]);
        }

        $this->actingAs($this->userFor())->postJson(self::DIST.'/windows/collect')->assertOk();

        $windowId = $this->windowId();
        $slot = $this->actingAs($this->userFor())
            ->postJson(self::DIST."/windows/{$windowId}/slots", [
                'warehouse_id' => $this->warehouse->id,
                'code' => 'DG-CU-'.substr(uniqid(), -5),
            ])->assertStatus(201)->json('data');

        $this->actingAs($this->userFor())
            ->postJson(self::DIST."/windows/{$windowId}/slots/{$slot['id']}/zones", ['zone_id' => $this->zone])
            ->assertOk();

        $this->openLoading($slot['id']);

        return (string) $slot['id'];
    }

    /**
     * Trip + loading session + vehicle assignment, written directly.
     *
     * The certified Start Loading path needs a real vehicle/driver pairing, which is a
     * different subsystem; this suite tests the CUSTODY conversation, so the execution
     * context is built as a fixture and Start Loading has its own coverage.
     */
    private function openLoading(string $slotId): void
    {
        $tripId = DB::table('distribution_trips')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->company->id,
            'virtual_slot_id' => $slotId,
            'trip_number' => 'TRP-'.substr(uniqid(), -6),
            'name' => 'custody trip',
            'status' => 'loading',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $sessionId = (string) Str::uuid();
        DB::table('loading_sessions')->insert([
            'id' => $sessionId,
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'session_number' => 'LS-'.substr(uniqid(), -6),
            'operational_date' => now()->toDateString(),
            'status' => 'loading',
            'created_by' => (string) Str::uuid(), 'updated_by' => (string) Str::uuid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('vehicle_assignments')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $this->company->id,
            'loading_session_id' => $sessionId,
            'trip_id' => $tripId,
            'vehicle_id' => (string) Str::uuid(),
            'vehicle_registration_snapshot' => '1336',
            'vehicle_type_snapshot' => 'van',
            'assignment_number' => 'VA-'.substr(uniqid(), -6),
            'status' => 'loading',
            'created_by' => (string) Str::uuid(), 'updated_by' => (string) Str::uuid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // The pairing the driver runtime resolves the caller's own trip through.
        DB::table('logistics_driver_vehicle_assignments')->insert([
            'driver_id' => $this->driverRecordId(),
            'vehicle_id' => $this->vehicleRecordId(),
            'assigned_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('distribution_trips')->where('id', $tripId)->update([
            'driver_vehicle_assignment_id' => DB::table('logistics_driver_vehicle_assignments')
                ->where('driver_id', $this->driverRecordId())->value('id'),
        ]);
    }

    private ?int $driverId = null;

    private ?int $vehicleId = null;

    private function driverRecordId(): int
    {
        return $this->driverId ??= (int) DB::table('logistics_drivers')->insertGetId([
            'company_id' => $this->company->id,
            'user_id' => $this->driverUser()->id,
            'driver_code' => 'DRV-'.substr(uniqid(), -5),
            'full_name' => 'Custody Driver',
            'mobile' => '0100'.random_int(1000000, 9999999),
            'national_id' => (string) random_int(10000000000000, 99999999999999),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function vehicleRecordId(): int
    {
        return $this->vehicleId ??= (int) DB::table('logistics_vehicles')->insertGetId([
            'company_id' => $this->company->id,
            'plate_number' => '1336-'.substr(uniqid(), -4),
            'capacity_orders' => 25,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private ?User $driverUserInstance = null;

    private function driverUser(): User
    {
        return $this->driverUserInstance ??= User::factory()->create(['company_id' => $this->company->id]);
    }

    private function setPrepared(string $slot, float $qty): void
    {
        $windowId = $this->windowId();

        $this->actingAs($this->userFor())
            ->putJson(self::DIST."/windows/{$windowId}/slots/{$slot}/preparation/{$this->honey->id}", [
                'prepared_qty' => $qty,
            ])->assertOk();
    }

    private function userWithOnly(string $permission): User
    {
        [$module, $resource, $action] = explode('.', $permission);

        $perm = Permission::firstOrCreate(
            ['name' => $permission],
            ['module' => $module, 'resource' => $resource, 'action' => $action],
        );

        $role = Role::create([
            'slug' => 'r-'.Str::random(8),
            'name' => 'Scoped actor',
            'is_system' => false,
        ]);
        $role->permissions()->attach($perm->id);

        $user = $this->userFor();
        $user->roles()->attach($role->id);

        return $user;
    }

    private function userFor(): User
    {
        return User::factory()->create(['company_id' => $this->company->id]);
    }

    private function windowId(): string
    {
        return (string) $this->actingAs($this->userFor())
            ->getJson(self::DIST.'/windows/current')
            ->assertOk()
            ->json('data.window.id');
    }

    /** Give a product real, reserved warehouse stock at the shipment's source warehouse. */
    private function seedStock(string $productId, float $onHand, float $reserved): void
    {
        DB::table('inventory_items')->insert([
            'id' => (string) Str::orderedUuid(),
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $productId,
            'company_id' => $this->company->id,
            'on_hand_qty' => $onHand,
            'reserved_qty' => $reserved,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
