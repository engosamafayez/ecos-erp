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
 * TASK-PROC-PURCHASING-SUPPLIER-SELECTION-FIX-001.
 *
 * `SelectLineSupplierAction` imported `Modules\Shared\Application\OperationResult`, a class
 * that does not exist anywhere in the codebase (the canonical one is
 * `App\Core\Responses\OperationResult`, used by the 10 sibling actions). The line UPDATE
 * committed first and the fatal fired only when the result object was constructed, so the
 * supplier was silently persisted while the caller received HTTP 500 and the UI reported
 * "Failed to select supplier" — and, because the mutation never resolved, React Query never
 * invalidated the cache.
 *
 * These tests pin the HTTP contract end to end so the flow cannot regress to a 500 again.
 */
final class PurchaseMaterialSupplierSelectionTest extends TestCase
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

    private function buyer(?Company $company = null): User
    {
        $company ??= $this->company;
        $user = User::factory()->create(['company_id' => $company->id]);
        $role = Role::firstOrCreate(['slug' => 'test-pm-supplier'], ['name' => 'test-pm-supplier', 'is_system' => false]);

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

    private function approvedPurchaseLine(?Company $company = null, ?Warehouse $warehouse = null): PurchaseMaterialLine
    {
        $company ??= $this->company;
        $warehouse ??= $this->warehouse;

        $pm = PurchaseMaterial::query()->create([
            'request_number' => 'PM-'.substr(md5(uniqid('', true)), 0, 8),
            'company_id' => $company->id,
            'warehouse_id' => $warehouse->id,
            'record_type' => 'purchase',
            'status' => 'approved',
            'priority' => 'normal',
        ]);

        return PurchaseMaterialLine::query()->create([
            'purchase_material_id' => $pm->id,
            'product_id' => Product::factory()->create()->id,
            'requested_qty' => 100,
        ]);
    }

    private function url(PurchaseMaterialLine $line): string
    {
        return "/api/purchase-materials/{$line->purchase_material_id}/lines/{$line->id}/select-supplier";
    }

    // ── The reported failure ─────────────────────────────────────────────────

    public function test_confirm_supplier_succeeds_and_persists(): void
    {
        $line = $this->approvedPurchaseLine();
        $supplier = Supplier::factory()->create(['company_id' => $this->company->id]);
        $this->actingAsUnprivileged($this->buyer());

        // Before the fix this returned 500 (Error: class not found) even though the row
        // had already been written.
        $this->postJson($this->url($line), ['supplier_id' => $supplier->id])->assertOk();

        $line->refresh();
        self::assertSame((string) $supplier->id, (string) $line->supplier_id);
        self::assertNotNull($line->supplier_selected_at);
    }

    public function test_the_response_carries_the_selected_supplier_back(): void
    {
        $line = $this->approvedPurchaseLine();
        $supplier = Supplier::factory()->create(['company_id' => $this->company->id]);
        $this->actingAsUnprivileged($this->buyer());

        // The 500 meant the client never received the updated line, so the drawer could not
        // refresh from the response either.
        $this->postJson($this->url($line), ['supplier_id' => $supplier->id])
            ->assertOk()
            ->assertJsonPath('data.supplier_id', (string) $supplier->id);
    }

    public function test_agreed_price_and_qty_are_persisted_when_supplied(): void
    {
        $line = $this->approvedPurchaseLine();
        $supplier = Supplier::factory()->create(['company_id' => $this->company->id]);
        $this->actingAsUnprivileged($this->buyer());

        $this->postJson($this->url($line), [
            'supplier_id' => $supplier->id,
            'agreed_price' => 25.5,
            'agreed_qty' => 80,
            'lead_time_days' => 7,
        ])->assertOk();

        $line->refresh();
        self::assertSame(25.5, (float) $line->agreed_price);
        self::assertSame(80.0, (float) $line->agreed_qty);
        self::assertSame(7, (int) $line->lead_time_days);
    }

    // ── Validation is NOT weakened ───────────────────────────────────────────

    public function test_a_non_uuid_supplier_is_rejected(): void
    {
        $line = $this->approvedPurchaseLine();
        $this->actingAsUnprivileged($this->buyer());

        // e.g. a supplier CODE such as '398830' must not be accepted in place of the id.
        $this->postJson($this->url($line), ['supplier_id' => '398830'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('supplier_id');

        self::assertNull($line->refresh()->supplier_id);
    }

    public function test_a_supplier_that_does_not_exist_is_rejected(): void
    {
        $line = $this->approvedPurchaseLine();
        $this->actingAsUnprivileged($this->buyer());

        $this->postJson($this->url($line), ['supplier_id' => (string) \Illuminate\Support\Str::uuid()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('supplier_id');

        self::assertNull($line->refresh()->supplier_id);
    }

    public function test_supplier_selection_requires_the_select_supplier_permission(): void
    {
        $line = $this->approvedPurchaseLine();
        $supplier = Supplier::factory()->create(['company_id' => $this->company->id]);

        $user = User::factory()->create(['company_id' => $this->company->id]);
        $this->actingAsUnprivileged($user); // holds no purchasing permission at all

        $this->postJson($this->url($line), ['supplier_id' => $supplier->id])->assertForbidden();

        self::assertNull($line->refresh()->supplier_id);
    }

    // ── Tenant isolation ─────────────────────────────────────────────────────

    public function test_a_purchase_of_another_company_cannot_be_touched(): void
    {
        $otherCompany = Company::factory()->create();
        $otherWarehouse = Warehouse::factory()->create(['company_id' => $otherCompany->id]);
        $foreignLine = $this->approvedPurchaseLine($otherCompany, $otherWarehouse);

        $supplier = Supplier::factory()->create(['company_id' => $this->company->id]);
        $this->actingAsUnprivileged($this->buyer());

        // The Purchase belongs to another company: the tenant scope must hide it entirely.
        $response = $this->postJson($this->url($foreignLine), ['supplier_id' => $supplier->id]);
        self::assertContains($response->status(), [403, 404], $response->getContent());

        self::assertNull($foreignLine->refresh()->supplier_id);
    }

    // ── The status contract is unchanged ─────────────────────────────────────

    public function test_a_draft_purchase_still_refuses_supplier_selection(): void
    {
        $line = $this->approvedPurchaseLine();
        $line->purchaseMaterial()->update(['status' => 'draft']);

        $supplier = Supplier::factory()->create(['company_id' => $this->company->id]);
        $this->actingAsUnprivileged($this->buyer());

        $response = $this->postJson($this->url($line), ['supplier_id' => $supplier->id]);
        self::assertGreaterThanOrEqual(400, $response->status());

        self::assertNull($line->refresh()->supplier_id);
    }
}
