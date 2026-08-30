<?php

declare(strict_types=1);

namespace Modules\Organization\Brands\Presentation\Http\Controllers;

use App\Core\Company\CurrentCompanyService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\MasterData\Warehouses\Domain\Models\WarehouseBrandCoverage;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Brands\Presentation\Http\Requests\UpdateBrandWarehouseCoverageRequest;

/**
 * Brand → Warehouses coverage configuration.
 * TASK-WAREHOUSE-BRAND-PAYMENT-IMPLEMENTATION-001 §A.
 *
 * A thin write surface over the EXISTING, certified `warehouse_brand_coverage`
 * relation (TASK-WAREHOUSE-COVERAGE-BRAND-ASSIGNMENT-001). It only lets a brand's
 * operator pick which of THEIR OWN company's warehouses serve the brand. It does
 * NOT change the certified semantics read by BranchAssignmentEngine:
 *   - tenant scoped; a warehouse may serve many brands; a brand many warehouses;
 *   - absence of a row = the warehouse does NOT serve the brand (fail-closed);
 *   - no auto-seed — a new brand serves no warehouse until configured here.
 */
class BrandWarehouseCoverageController extends Controller
{
    public function __construct(private readonly CurrentCompanyService $currentCompany) {}

    /** GET /brands/{brand}/warehouse-coverage */
    public function index(string $brandId): JsonResponse
    {
        $brand = $this->resolveBrand($brandId);

        $warehouses = Warehouse::query()
            ->where('company_id', $brand->company_id)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'is_active']);

        $served = WarehouseBrandCoverage::query()
            ->where('company_id', $brand->company_id)
            ->where('brand_id', $brand->id)
            ->where('is_active', true)
            ->pluck('warehouse_id')
            ->flip();

        $data = $warehouses->map(static fn (Warehouse $w): array => [
            'id' => $w->id,
            'name' => $w->name,
            'code' => $w->code,
            'is_active' => (bool) $w->is_active,
            'serves_brand' => $served->has($w->id),
        ])->all();

        return response()->json(['data' => $data]);
    }

    /** PUT /brands/{brand}/warehouse-coverage  { warehouse_ids: string[] } */
    public function update(UpdateBrandWarehouseCoverageRequest $request, string $brandId): JsonResponse
    {
        $brand = $this->resolveBrand($brandId);

        $requested = collect($request->validated()['warehouse_ids'] ?? [])
            ->filter()->unique()->values();

        // Tenant guard — every requested warehouse MUST belong to the brand's
        // company. Foreign-company IDs are rejected: cross-company selection is
        // impossible, matching the certified cross-tenant deny.
        $companyWarehouseIds = Warehouse::query()
            ->where('company_id', $brand->company_id)
            ->whereIn('id', $requested)
            ->pluck('id');

        if ($companyWarehouseIds->count() !== $requested->count()) {
            abort(422, "One or more warehouses do not belong to this brand's company.");
        }

        DB::transaction(function () use ($brand, $companyWarehouseIds): void {
            $existing = WarehouseBrandCoverage::query()
                ->where('company_id', $brand->company_id)
                ->where('brand_id', $brand->id)
                ->get()
                ->keyBy('warehouse_id');

            // Enable requested (idempotent: create missing, re-activate inactive).
            foreach ($companyWarehouseIds as $warehouseId) {
                $row = $existing->get($warehouseId);
                if ($row === null) {
                    WarehouseBrandCoverage::query()->create([
                        'company_id' => $brand->company_id,
                        'warehouse_id' => $warehouseId,
                        'brand_id' => $brand->id,
                        'is_active' => true,
                    ]);
                } elseif (! $row->is_active) {
                    $row->update(['is_active' => true]);
                }
            }

            // Remove coverage no longer requested (absence = does not serve).
            $keep = $companyWarehouseIds->flip();
            foreach ($existing as $warehouseId => $row) {
                if (! $keep->has($warehouseId)) {
                    $row->delete();
                }
            }
        });

        return $this->index($brand->id);
    }

    /** Tenant-scoped brand resolution: a foreign-company brand is a 404. */
    private function resolveBrand(string $brandId): Brand
    {
        return Brand::query()
            ->where('id', $brandId)
            ->where('company_id', $this->currentCompany->id())
            ->firstOrFail();
    }
}
