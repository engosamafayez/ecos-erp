<?php

declare(strict_types=1);

namespace Modules\Admin\Configuration\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Admin\Configuration\Domain\Models\DeliveryGeography;
use Modules\Admin\Configuration\Domain\Models\DeliveryZone;
use Modules\Admin\Configuration\Domain\Models\MasterZone;
use Modules\Admin\Configuration\Domain\Services\ConfigAuditService;
use Modules\Organization\Brands\Domain\Models\Brand;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * CRUD for Delivery Geographies (Governorates) scoped per brand.
 *
 * GET    /configuration/brands/{brandId}/geographies
 * POST   /configuration/brands/{brandId}/geographies
 * PUT    /configuration/brands/{brandId}/geographies/{id}
 * DELETE /configuration/brands/{brandId}/geographies/{id}
 *
 * TENANT SAFETY (TASK-ECOS-V1.1-OPS-02-CLOSURE): every method resolves
 * $brandId against the AUTHENTICATED USER'S OWN company_id before touching
 * anything — a brand UUID is not itself an ownership boundary, and this
 * module's sibling controllers (BrandConfigurationController,
 * BrandCoverageController) do not enforce this today, so it is deliberately
 * NOT inherited from a shared base; it is asserted here, explicitly, because
 * this is the controller being newly exposed. Geography rows are additionally
 * re-checked against the resolved brand's own company_id (belt-and-braces —
 * brand_id matching alone is not trusted either).
 */
final class DeliveryGeographyController extends Controller
{
    use HasApiResponse;

    public function __construct(private readonly ConfigAuditService $audit) {}

    /** Resolve $brandId, but ONLY if it belongs to the authenticated user's own company. */
    private function resolveBrand(string $brandId): Brand
    {
        $companyId = Auth::user()?->company_id;

        $brand = $companyId !== null
            ? Brand::where('id', $brandId)->where('company_id', $companyId)->first()
            : null;

        if ($brand === null) {
            throw new NotFoundHttpException('Brand not found.');
        }

        return $brand;
    }

    public function index(string $brandId): JsonResponse
    {
        $brand = $this->resolveBrand($brandId);

        $geographies = DeliveryGeography::where('brand_id', $brand->id)
            ->where('company_id', $brand->company_id)
            ->with(['zones' => fn ($q) => $q->with('shippingRule')->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get();

        return $this->success($geographies);
    }

    public function store(Request $request, string $brandId): JsonResponse
    {
        $brand = $this->resolveBrand($brandId);

        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'name_ar' => 'nullable|string|max:150',
            'code' => 'nullable|string|max:50',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            'default_shipping_cost' => 'nullable|numeric|min:0',
            // Master Governorate is deliberately global, company-less reference
            // data (see MasterGovernorate's own docblock) — any company may
            // reference any existing row; there is no cross-company boundary here.
            'master_governorate_id' => 'nullable|uuid|exists:master_governorates,id',
        ]);

        $companyId = $brand->company_id;
        $actorId = Auth::id() ?? '';

        $geography = DeliveryGeography::updateOrCreate(
            ['brand_id' => $brand->id, 'name' => $validated['name']],
            [...$validated, 'company_id' => $companyId, 'created_by' => $actorId, 'updated_by' => $actorId],
        );

        // Auto-create all master zones when a master-linked governorate is activated
        $isActive = $validated['is_active'] ?? true;
        if (! empty($validated['master_governorate_id']) && $isActive) {
            $masterZones = MasterZone::where('master_governorate_id', $validated['master_governorate_id'])
                ->orderBy('sort_order')
                ->get();

            foreach ($masterZones as $masterZone) {
                DeliveryZone::updateOrCreate(
                    [
                        'delivery_geography_id' => $geography->id,
                        'master_zone_id' => $masterZone->id,
                    ],
                    [
                        'brand_id' => $brand->id,
                        'name' => $masterZone->name,
                        'sort_order' => $masterZone->sort_order,
                        'created_by' => $actorId,
                        'updated_by' => $actorId,
                    ],
                );
            }
        }

        $this->audit->record(
            companyId: $companyId,
            module: 'delivery_geography',
            category: 'geography',
            action: $geography->wasRecentlyCreated ? 'create' : 'update',
            oldValue: null,
            newValue: $geography->toArray(),
            brandId: $brand->id,
        );

        return $this->created($geography->load('zones'), 'Governorate enabled.');
    }

    public function update(Request $request, string $brandId, string $id): JsonResponse
    {
        $brand = $this->resolveBrand($brandId);

        $geography = DeliveryGeography::where('brand_id', $brand->id)
            ->where('company_id', $brand->company_id)
            ->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:150',
            'name_ar' => 'nullable|string|max:150',
            'code' => 'nullable|string|max:50',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            'default_shipping_cost' => 'nullable|numeric|min:0',
        ]);

        $old = $geography->toArray();
        $geography->update([...$validated, 'updated_by' => Auth::id()]);

        $this->audit->record(
            companyId: $brand->company_id,
            module: 'delivery_geography',
            category: 'geography',
            action: 'update',
            oldValue: $old,
            newValue: $geography->fresh()?->toArray() ?? [],
            brandId: $brand->id,
        );

        return $this->updated($geography->load('zones'), 'Governorate updated.');
    }

    public function destroy(string $brandId, string $id): JsonResponse
    {
        $brand = $this->resolveBrand($brandId);

        $geography = DeliveryGeography::where('brand_id', $brand->id)
            ->where('company_id', $brand->company_id)
            ->findOrFail($id);

        $this->audit->record(
            companyId: $brand->company_id,
            module: 'delivery_geography',
            category: 'geography',
            action: 'delete',
            oldValue: $geography->toArray(),
            newValue: null,
            brandId: $brand->id,
        );

        $geography->delete();

        return $this->deleted('Governorate deleted.');
    }
}
