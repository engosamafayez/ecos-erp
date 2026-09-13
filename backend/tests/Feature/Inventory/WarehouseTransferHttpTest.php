<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\Inventory\InventoryItems\Domain\Models\InventoryItem;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Inventory\ReceiptLayers\Domain\Models\InventoryReceiptLayer;
use Modules\Inventory\Transfer\Domain\Models\WarehouseTransfer;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-V1.1-OPS-01-IMPLEMENTATION-044A-R1 — HTTP wiring for the
 * pre-existing, canonical WarehouseTransfer / TransferStockAction authority.
 *
 * These tests exercise the new route only. TransferStockAction's own business
 * logic (locking, FIFO-layer preservation, the cross-company guard) is
 * unmodified and reused as-is — not re-verified at the unit level here.
 *
 * NOT YET EXECUTED — written and reviewed statically; this session's shell
 * execution was blocked by an unrelated environment issue before these could
 * be run. See the engineering report.
 */
final class WarehouseTransferHttpTest extends TestCase
{
    use RefreshDatabase;

    protected bool $grantsBaselineAuthorization = false;

    private const PERMISSION = 'inventory.transfers.create';

    private function operatorFor(Company $company): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);

        $role = Role::firstOrCreate(
            ['slug' => 'test-warehouse-transfer-operator'],
            ['name' => 'Test Warehouse Transfer Operator', 'is_system' => false],
        );

        [$module, $resource, $action] = explode('.', self::PERMISSION);
        $permission = Permission::firstOrCreate(
            ['name' => self::PERMISSION],
            ['module' => $module, 'resource' => $resource, 'action' => $action],
        );

        if (! $role->permissions()->where('permissions.id', $permission->id)->exists()) {
            $role->permissions()->attach($permission->id);
        }

        $user->roles()->attach($role->id);
        $user->unsetRelation('roles');

        return $user;
    }

    /** Seeds a real InventoryItem + a matching FIFO receipt layer at the source warehouse. */
    private function stockedAt(Company $company, Warehouse $warehouse, Product $product, float $onHand, float $unitCost = 15.0): void
    {
        InventoryItem::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'company_id' => $company->id,
            'on_hand_qty' => $onHand,
            'reserved_qty' => 0,
        ]);

        InventoryReceiptLayer::query()->create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'received_qty' => $onHand,
            'remaining_qty' => $onHand,
            'landed_unit_cost' => $unitCost,
            'receipt_date' => now()->toDateString(),
        ]);
    }

    private function onHandAt(Warehouse $warehouse, Product $product): ?float
    {
        $item = InventoryItem::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->first();

        return $item === null ? null : (float) $item->on_hand_qty;
    }

    // ── 1. Successful same-company transfer ──────────────────────────────────

    public function test_authorized_same_company_transfer_moves_stock_and_fifo_layer(): void
    {
        $company = Company::factory()->create();
        $source = Warehouse::factory()->create(['company_id' => $company->id]);
        $destination = Warehouse::factory()->create(['company_id' => $company->id]);
        $product = Product::factory()->create();

        $this->stockedAt($company, $source, $product, 20.0, 15.0);

        $this->actingAsUnprivileged($this->operatorFor($company));

        $response = $this->postJson('/api/inventory/transfers', [
            'source_warehouse_id' => $source->id,
            'destination_warehouse_id' => $destination->id,
            'product_id' => $product->id,
            'quantity' => 8,
            'reference' => 'TEST-REF-1',
            'notes' => 'characterization test',
        ]);

        $response->assertCreated();
        self::assertSame('completed', $response->json('data.status'));

        // Source decreased, destination increased, by exactly the transferred quantity.
        self::assertEquals(12.0, $this->onHandAt($source, $product));
        self::assertEquals(8.0, $this->onHandAt($destination, $product));

        // FIFO lineage preserved: source layer reduced, destination layer created at
        // the SAME unit cost (not re-priced), summing back to the original 20.
        $sourceLayer = InventoryReceiptLayer::query()
            ->where('warehouse_id', $source->id)->where('product_id', $product->id)->sole();
        $destLayer = InventoryReceiptLayer::query()
            ->where('warehouse_id', $destination->id)->where('product_id', $product->id)->sole();

        self::assertEquals(12.0, (float) $sourceLayer->remaining_qty);
        self::assertEquals(8.0, (float) $destLayer->remaining_qty);
        self::assertEquals(15.0, (float) $destLayer->landed_unit_cost);

        // Audit record.
        $transfer = WarehouseTransfer::query()->sole();
        self::assertSame($company->id, $transfer->company_id);
        self::assertSame($source->id, $transfer->source_warehouse_id);
        self::assertSame($destination->id, $transfer->destination_warehouse_id);
        self::assertEquals(8.0, (float) $transfer->quantity);
    }

    // ── 2. Cross-company transfer rejected ───────────────────────────────────

    public function test_cross_company_transfer_is_rejected_and_leaves_no_partial_mutation(): void
    {
        $ownCompany = Company::factory()->create();
        $foreignCompany = Company::factory()->create();
        $source = Warehouse::factory()->create(['company_id' => $ownCompany->id]);
        $foreignDestination = Warehouse::factory()->create(['company_id' => $foreignCompany->id]);
        $product = Product::factory()->create();

        $this->stockedAt($ownCompany, $source, $product, 20.0);

        $this->actingAsUnprivileged($this->operatorFor($ownCompany));

        $response = $this->postJson('/api/inventory/transfers', [
            'source_warehouse_id' => $source->id,
            'destination_warehouse_id' => $foreignDestination->id,
            'product_id' => $product->id,
            'quantity' => 5,
        ]);

        // A foreign warehouse is invisible under Warehouse's own tenant scope,
        // so this is rejected as a validation error before the action ever runs.
        $response->assertStatus(422);

        self::assertEquals(20.0, $this->onHandAt($source, $product), 'Source stock must be untouched.');
        self::assertSame(0, WarehouseTransfer::query()->count());
    }

    public function test_unrestricted_actor_cross_company_transfer_is_rejected_by_the_action_guard(): void
    {
        $ownCompany = Company::factory()->create();
        $foreignCompany = Company::factory()->create();
        $source = Warehouse::factory()->create(['company_id' => $ownCompany->id]);
        $foreignDestination = Warehouse::factory()->create(['company_id' => $foreignCompany->id]);
        $product = Product::factory()->create();

        $this->stockedAt($ownCompany, $source, $product, 20.0);

        $user = User::factory()->create(['company_id' => null]);
        $this->actingAsUnprivileged($this->grantSystemRole($user));

        // An unrestricted actor CAN see both warehouses (no tenant scope applies to
        // them), so this reaches TransferStockAction's own CrossCompanyTransferException.
        $response = $this->postJson('/api/inventory/transfers', [
            'source_warehouse_id' => $source->id,
            'destination_warehouse_id' => $foreignDestination->id,
            'product_id' => $product->id,
            'quantity' => 5,
        ]);

        $response->assertStatus(422);
        self::assertEquals(20.0, $this->onHandAt($source, $product));
        self::assertSame(0, WarehouseTransfer::query()->count());
    }

    // ── 3. Insufficient stock rejected ───────────────────────────────────────

    public function test_insufficient_stock_transfer_is_rejected_and_leaves_no_partial_mutation(): void
    {
        $company = Company::factory()->create();
        $source = Warehouse::factory()->create(['company_id' => $company->id]);
        $destination = Warehouse::factory()->create(['company_id' => $company->id]);
        $product = Product::factory()->create();

        $this->stockedAt($company, $source, $product, 5.0);

        $this->actingAsUnprivileged($this->operatorFor($company));

        $response = $this->postJson('/api/inventory/transfers', [
            'source_warehouse_id' => $source->id,
            'destination_warehouse_id' => $destination->id,
            'product_id' => $product->id,
            'quantity' => 100,
        ]);

        $response->assertStatus(422);

        self::assertEquals(5.0, $this->onHandAt($source, $product), 'Source stock must be untouched on a rejected transfer.');
        self::assertNull($this->onHandAt($destination, $product), 'No destination row should be created on a rejected transfer.');
        self::assertSame(0, WarehouseTransfer::query()->count());
    }

    // ── 4. Invalid input rejected ─────────────────────────────────────────────

    public function test_same_warehouse_transfer_is_rejected(): void
    {
        $company = Company::factory()->create();
        $warehouse = Warehouse::factory()->create(['company_id' => $company->id]);
        $product = Product::factory()->create();

        $this->stockedAt($company, $warehouse, $product, 20.0);

        $this->actingAsUnprivileged($this->operatorFor($company));

        $this->postJson('/api/inventory/transfers', [
            'source_warehouse_id' => $warehouse->id,
            'destination_warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 5,
        ])->assertStatus(422)->assertJsonValidationErrors('destination_warehouse_id');
    }

    public function test_nonexistent_product_is_rejected(): void
    {
        $company = Company::factory()->create();
        $source = Warehouse::factory()->create(['company_id' => $company->id]);
        $destination = Warehouse::factory()->create(['company_id' => $company->id]);

        $this->actingAsUnprivileged($this->operatorFor($company));

        $this->postJson('/api/inventory/transfers', [
            'source_warehouse_id' => $source->id,
            'destination_warehouse_id' => $destination->id,
            'product_id' => (string) \Illuminate\Support\Str::uuid(),
            'quantity' => 5,
        ])->assertStatus(422)->assertJsonValidationErrors('product_id');
    }

    // ── 5. Permission enforcement ─────────────────────────────────────────────

    public function test_user_without_the_create_permission_is_refused(): void
    {
        $company = Company::factory()->create();
        $source = Warehouse::factory()->create(['company_id' => $company->id]);
        $destination = Warehouse::factory()->create(['company_id' => $company->id]);
        $product = Product::factory()->create();

        $this->stockedAt($company, $source, $product, 20.0);

        $user = User::factory()->create(['company_id' => $company->id]);
        $role = Role::firstOrCreate(
            ['slug' => 'test-no-transfer-permission'],
            ['name' => 'Test No Transfer Permission', 'is_system' => false],
        );
        $user->roles()->attach($role->id);

        $this->actingAsUnprivileged($user);

        $this->postJson('/api/inventory/transfers', [
            'source_warehouse_id' => $source->id,
            'destination_warehouse_id' => $destination->id,
            'product_id' => $product->id,
            'quantity' => 5,
        ])->assertForbidden();

        self::assertEquals(20.0, $this->onHandAt($source, $product));
    }
}
