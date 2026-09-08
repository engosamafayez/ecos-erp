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
use Modules\Purchasing\GoodsReceipts\Application\Actions\CreateGoodsReceiptAction;
use Modules\Purchasing\GoodsReceipts\Application\Actions\PostGoodsReceiptAction;
use Modules\Purchasing\GoodsReceipts\Application\DTO\GoodsReceiptDTO;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceipt;
use Modules\Purchasing\PurchaseMaterials\Domain\Models\PurchaseMaterial;
use Modules\Purchasing\PurchaseMaterials\Domain\Models\PurchaseMaterialLine;
use Modules\Purchasing\Suppliers\Domain\Models\Supplier;
use Tests\TestCase;

/**
 * TASK-ECOS-PROCUREMENT-PURCHASE-REQUESTS-AND-HUB-FINAL-REMEDIATION-011 §7-§10.
 *
 * Approved -> Purchasing -> Receiving -> Completed now advances as a DERIVED consequence of
 * ordering and receiving progress (AdvancePurchaseMaterialWorkflowAction), hooked into both
 * SelectLineSupplierAction (ordering) and PostGoodsReceiptAction Step 3b (physical receipt) —
 * the exact extension point PostGoodsReceiptAction's own "Part 2 is deliberately not done here"
 * comment flagged. Completion requires the FULL REQUESTED quantity to be physically received,
 * not merely whatever a partial order committed to.
 */
final class PurchaseMaterialFulfillmentWorkflowTest extends TestCase
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
        $role = Role::create(['slug' => 'test-pm-fulfillment-'.uniqid(), 'name' => 'test-pm-fulfillment', 'is_system' => false]);

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

    private function approvedMaterialWithLine(float $requestedQty = 100.0): PurchaseMaterialLine
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

    private function commitSupplier(PurchaseMaterialLine $line, float $agreedQty): void
    {
        $this->postJson(
            "/api/purchase-materials/{$line->purchase_material_id}/lines/{$line->id}/select-supplier",
            ['supplier_id' => $this->supplier->id, 'agreed_qty' => $agreedQty],
        )->assertOk();
    }

    private function receiveAndPost(PurchaseMaterialLine $line, float $qty): GoodsReceipt
    {
        $dto = GoodsReceiptDTO::fromArray([
            'purchase_order_id' => null,
            'warehouse_id' => $this->warehouse->id,
            'receipt_date' => now()->toDateString(),
            'lines' => [[
                'purchase_material_line_id' => $line->id,
                'product_id' => $line->product_id,
                'ordered_quantity' => (float) $line->requested_qty,
                'gross_received_quantity' => $qty,
                'net_received_quantity' => $qty,
            ]],
        ]);

        /** @var GoodsReceipt $receipt */
        $receipt = app(CreateGoodsReceiptAction::class)->execute($dto)->data();
        app(PostGoodsReceiptAction::class)->execute($receipt->id);

        return $receipt;
    }

    public function test_committing_a_supplier_advances_approved_to_purchasing(): void
    {
        $line = $this->approvedMaterialWithLine(100.0);
        $this->actingAsUnprivileged($this->buyer());

        $this->commitSupplier($line, 40.0);

        self::assertSame('purchasing', $line->purchaseMaterial->fresh()->status->value);
    }

    public function test_a_partial_physical_receipt_advances_to_receiving_not_completed(): void
    {
        $line = $this->approvedMaterialWithLine(100.0);
        $this->actingAsUnprivileged($this->buyer());
        $this->commitSupplier($line, 100.0);

        $this->receiveAndPost($line, 60.0);

        self::assertSame('receiving', $line->purchaseMaterial->fresh()->status->value);
        self::assertNull($line->purchaseMaterial->fresh()->completed_at);
    }

    public function test_full_physical_receipt_of_the_full_requested_qty_completes_the_request(): void
    {
        $line = $this->approvedMaterialWithLine(100.0);
        $this->actingAsUnprivileged($this->buyer());
        $this->commitSupplier($line, 100.0);

        $this->receiveAndPost($line, 60.0);
        self::assertSame('receiving', $line->purchaseMaterial->fresh()->status->value);

        $this->receiveAndPost($line, 40.0);

        $material = $line->purchaseMaterial->fresh();
        self::assertSame('completed', $material->status->value);
        self::assertNotNull($material->completed_at);
    }

    public function test_receiving_everything_that_was_partially_ordered_does_not_complete_the_request(): void
    {
        // Requested 100, but purchasing only committed to 60 (partial order) — receiving the
        // full 60 that was ordered must NOT mark the original 100-unit request Completed: the
        // other 40 units of DEMAND are still outstanding.
        $line = $this->approvedMaterialWithLine(100.0);
        $this->actingAsUnprivileged($this->buyer());
        $this->commitSupplier($line, 60.0);

        $this->receiveAndPost($line, 60.0);

        self::assertSame('receiving', $line->purchaseMaterial->fresh()->status->value);
        self::assertNull($line->purchaseMaterial->fresh()->completed_at);
    }

    public function test_multi_line_request_completes_only_once_every_line_is_fully_received(): void
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
            'purchase_material_id' => $pm->id, 'product_id' => Product::factory()->create()->id, 'requested_qty' => 50,
        ]);
        $lineB = PurchaseMaterialLine::query()->create([
            'purchase_material_id' => $pm->id, 'product_id' => Product::factory()->create()->id, 'requested_qty' => 20,
        ]);

        $this->actingAsUnprivileged($this->buyer());
        $this->commitSupplier($lineA, 50.0);
        $this->commitSupplier($lineB, 20.0);

        $this->receiveAndPost($lineA, 50.0);
        self::assertSame('receiving', $pm->fresh()->status->value, 'One line fully received, the other not yet — must stay Receiving.');

        $this->receiveAndPost($lineB, 20.0);
        self::assertSame('completed', $pm->fresh()->status->value);
    }

    public function test_a_request_on_hold_is_never_auto_advanced(): void
    {
        $line = $this->approvedMaterialWithLine(100.0);
        $this->actingAsUnprivileged($this->buyer());
        $this->commitSupplier($line, 100.0);

        $line->purchaseMaterial->update(['status' => 'on_hold', 'held_from_status' => 'purchasing']);

        $this->receiveAndPost($line, 100.0);

        self::assertSame('on_hold', $line->purchaseMaterial->fresh()->status->value);
    }
}
