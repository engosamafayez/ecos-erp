<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Purchasing\PurchaseMaterials\Domain\Models\PurchaseMaterial;
use Modules\Purchasing\PurchaseMaterials\Domain\Models\PurchaseMaterialLine;
use Modules\Purchasing\Suppliers\Domain\Models\Supplier;
use Tests\TestCase;

/**
 * TASK-ECOS-PROCUREMENT-PURCHASE-REQUESTS-AND-HUB-FINAL-REMEDIATION-011 §7/§9.
 *
 * Partial/incremental ordering: agreed_qty is a CUMULATIVE commitment quantity. It may grow
 * across multiple select-supplier calls, may never exceed requested_qty, and may never
 * decrease. execution_percent / ordered_items_count / not_yet_ordered_items_count are derived
 * from this per-line state, not stored separately.
 */
final class PurchaseMaterialIncrementalOrderingTest extends TestCase
{
    use RefreshDatabase;

    protected bool $grantsBaselineAuthorization = false;

    private Company $company;

    private Warehouse $warehouse;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $this->supplier = Supplier::factory()->create(['company_id' => $this->company->id]);
    }

    private function buyer(): User
    {
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $role = Role::create(['slug' => 'test-pm-ordering-'.uniqid(), 'name' => 'test-pm-ordering', 'is_system' => false]);

        foreach (['purchasing.materials.view', 'purchasing.materials.select_supplier'] as $name) {
            [$module, $resource, $action] = explode('.', $name);
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                ['module' => $module, 'resource' => $resource, 'action' => $action],
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user->roles()->attach($role->id);
        $user->unsetRelation('roles');

        return $user;
    }

    private function approvedLine(float $requestedQty = 100.0): PurchaseMaterialLine
    {
        $pm = PurchaseMaterial::query()->create([
            'request_number' => 'PM-'.substr(md5(uniqid('', true)), 0, 8),
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'record_type' => 'purchase',
            'status' => 'approved',
            'priority' => 'normal',
        ]);

        return PurchaseMaterialLine::query()->create([
            'purchase_material_id' => $pm->id,
            'product_id' => Product::factory()->create()->id,
            'requested_qty' => $requestedQty,
        ]);
    }

    private function url(PurchaseMaterialLine $line): string
    {
        return "/api/purchase-materials/{$line->purchase_material_id}/lines/{$line->id}/select-supplier";
    }

    public function test_a_partial_commitment_is_accepted_and_the_material_moves_to_purchasing(): void
    {
        $line = $this->approvedLine(100.0);
        $this->actingAsUnprivileged($this->buyer());

        $this->postJson($this->url($line), ['supplier_id' => $this->supplier->id, 'agreed_qty' => 40])
            ->assertOk();

        self::assertSame(40.0, (float) $line->refresh()->agreed_qty);
        self::assertSame('purchasing', $line->purchaseMaterial->fresh()->status->value);
    }

    public function test_a_second_commitment_may_top_up_the_agreed_qty(): void
    {
        $line = $this->approvedLine(100.0);
        $this->actingAsUnprivileged($this->buyer());

        $this->postJson($this->url($line), ['supplier_id' => $this->supplier->id, 'agreed_qty' => 40])->assertOk();
        $this->postJson($this->url($line), ['supplier_id' => $this->supplier->id, 'agreed_qty' => 100])->assertOk();

        self::assertSame(100.0, (float) $line->refresh()->agreed_qty);
    }

    public function test_agreed_qty_cannot_exceed_requested_qty(): void
    {
        $line = $this->approvedLine(100.0);
        $this->actingAsUnprivileged($this->buyer());

        $this->postJson($this->url($line), ['supplier_id' => $this->supplier->id, 'agreed_qty' => 150])
            ->assertStatus(422)
            ->assertJsonValidationErrors('agreed_qty');

        self::assertNull($line->refresh()->agreed_qty);
    }

    public function test_agreed_qty_cannot_decrease_once_committed(): void
    {
        $line = $this->approvedLine(100.0);
        $this->actingAsUnprivileged($this->buyer());

        $this->postJson($this->url($line), ['supplier_id' => $this->supplier->id, 'agreed_qty' => 60])->assertOk();

        $this->postJson($this->url($line), ['supplier_id' => $this->supplier->id, 'agreed_qty' => 30])
            ->assertStatus(422)
            ->assertJsonValidationErrors('agreed_qty');

        self::assertSame(60.0, (float) $line->refresh()->agreed_qty);
    }

    public function test_selecting_supplier_at_an_invalid_status_returns_a_clean_422_not_a_500(): void
    {
        // Regression guard: InvalidPurchaseMaterialStatusException previously took 3 required
        // constructor args but was invoked here with 1 — an ArgumentCountError (fatal 500)
        // instead of the intended validation error.
        $line = $this->approvedLine(100.0);
        $line->purchaseMaterial()->update(['status' => 'draft']);
        $this->actingAsUnprivileged($this->buyer());

        $response = $this->postJson($this->url($line), ['supplier_id' => $this->supplier->id, 'agreed_qty' => 10]);

        self::assertSame(422, $response->status(), $response->getContent());
    }

    public function test_execution_percent_and_ordered_counts_reflect_partial_ordering(): void
    {
        $pm = PurchaseMaterial::query()->create([
            'request_number' => 'PM-'.substr(md5(uniqid('', true)), 0, 8),
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'record_type' => 'purchase',
            'status' => 'approved',
            'priority' => 'normal',
        ]);
        $lineA = PurchaseMaterialLine::query()->create([
            'purchase_material_id' => $pm->id, 'product_id' => Product::factory()->create()->id, 'requested_qty' => 100,
        ]);
        $lineB = PurchaseMaterialLine::query()->create([
            'purchase_material_id' => $pm->id, 'product_id' => Product::factory()->create()->id, 'requested_qty' => 50,
        ]);

        $this->actingAsUnprivileged($this->buyer());

        // Fully order line A (100/100), leave line B untouched (0/50).
        $this->postJson($this->url($lineA), ['supplier_id' => $this->supplier->id, 'agreed_qty' => 100])->assertOk();

        $response = $this->getJson("/api/purchase-materials/{$pm->id}")->assertOk();
        $response->assertJsonPath('data.execution_percent', 50.0);
        $response->assertJsonPath('data.ordered_items_count', 1);
        $response->assertJsonPath('data.not_yet_ordered_items_count', 1);

        $notYetOrdered = $response->json('data.not_yet_ordered_items');
        self::assertCount(1, $notYetOrdered);
        self::assertSame((string) $lineB->id, (string) $notYetOrdered[0]['id']);
        self::assertSame(50.0, (float) $notYetOrdered[0]['remaining_to_order']);
    }
}
