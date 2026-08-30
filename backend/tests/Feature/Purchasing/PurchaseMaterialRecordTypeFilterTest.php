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
use Tests\TestCase;

/**
 * TASK-PROCUREMENT-MANUAL-REMEDIATION-001 — record_type is honoured end to end.
 *
 * The Material Requests and Purchases workspaces are the same table split by
 * `record_type`, but the create path dropped the client's value (persisting the
 * column default 'material_request') and the list path never filtered on it — so
 * the two screens showed an identical list and a purchase never appeared as a
 * purchase. This pins both halves of the repair.
 */
final class PurchaseMaterialRecordTypeFilterTest extends TestCase
{
    use RefreshDatabase;

    protected bool $grantsBaselineAuthorization = false;

    private Company $company;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
    }

    private function buyer(): User
    {
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $role = Role::firstOrCreate(['slug' => 'test-pm-buyer'], ['name' => 'test-pm-buyer', 'is_system' => false]);

        foreach (['purchasing.materials.view', 'purchasing.materials.create'] as $name) {
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

    public function test_create_persists_the_requested_record_type(): void
    {
        $product = Product::factory()->create();
        $this->actingAsUnprivileged($this->buyer());

        $id = $this->postJson('/api/purchase-materials', [
            'warehouse_id' => $this->warehouse->id,
            'record_type' => 'purchase',
            'lines' => [['product_id' => $product->id, 'requested_qty' => 3]],
        ])->assertCreated()->json('data.id');

        $stored = PurchaseMaterial::query()->withoutGlobalScope('tenant')->findOrFail($id);
        self::assertSame('purchase', $stored->record_type);
    }

    public function test_list_is_filtered_by_record_type(): void
    {
        $product = Product::factory()->create();
        $this->actingAsUnprivileged($this->buyer());

        foreach (['purchase', 'material_request'] as $type) {
            $this->postJson('/api/purchase-materials', [
                'warehouse_id' => $this->warehouse->id,
                'record_type' => $type,
                'lines' => [['product_id' => $product->id, 'requested_qty' => 2]],
            ])->assertCreated();
        }

        $purchases = $this->getJson('/api/purchase-materials?record_type=purchase')
            ->assertOk()->json('data.items');
        self::assertCount(1, $purchases);
        self::assertSame('purchase', $purchases[0]['record_type']);

        $requests = $this->getJson('/api/purchase-materials?record_type=material_request')
            ->assertOk()->json('data.items');
        self::assertCount(1, $requests);
        self::assertSame('material_request', $requests[0]['record_type']);
    }

    /**
     * TASK-PROCUREMENT-SUPPLIER-ACCOUNTING-CLOSURE-001 §2 — the KPI/stats leak.
     * The list was already filtered, but the stats aggregation ignored record_type, so the
     * Purchases screen's KPI cards summed Material Requests too. Purchase stats must count only
     * purchases; Material-Request stats only material requests.
     */
    public function test_stats_are_filtered_by_record_type(): void
    {
        $product = Product::factory()->create();
        $this->actingAsUnprivileged($this->buyer());

        // 1 purchase + 2 material requests.
        foreach (['purchase', 'material_request', 'material_request'] as $type) {
            $this->postJson('/api/purchase-materials', [
                'warehouse_id' => $this->warehouse->id,
                'record_type' => $type,
                'lines' => [['product_id' => $product->id, 'requested_qty' => 2]],
            ])->assertCreated();
        }

        $purchaseStats = $this->getJson('/api/purchase-materials/stats?record_type=purchase')
            ->assertOk()->json('data');
        self::assertSame(1, array_sum($purchaseStats['operational']), 'Purchase KPIs must exclude material_request rows.');

        $requestStats = $this->getJson('/api/purchase-materials/stats?record_type=material_request')
            ->assertOk()->json('data');
        self::assertSame(2, array_sum($requestStats['operational']), 'Material-Request KPIs must exclude purchase rows.');
    }
}
