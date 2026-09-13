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
use Modules\Admin\Configuration\Domain\Services\ConfigAuditService;
use Modules\Organization\Brands\Domain\Models\Brand;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * CRUD for Delivery Zones within a Governorate.
 *
 * GET    /configuration/brands/{brandId}/geographies/{geoId}/zones
 * POST   /configuration/brands/{brandId}/geographies/{geoId}/zones
 * PUT    /configuration/brands/{brandId}/geographies/{geoId}/zones/{id}
 * DELETE /configuration/brands/{brandId}/geographies/{geoId}/zones/{id}
 *
 * TENANT SAFETY (TASK-ECOS-V1.1-OPS-02-CLOSURE): every method resolves
 * $brandId against the authenticated user's own company, then resolves
 * $geoId scoped to THAT brand — a geography id or brand id alone is never
 * trusted as an ownership boundary. See DeliveryGeographyController's
 * class docblock for the fuller rationale (same pattern, same reason).
 */
final class DeliveryZoneController extends Controller
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

    /** Resolve $geoId, but ONLY if it belongs to the already-resolved (same-company) brand. */
    private function resolveGeography(Brand $brand, string $geoId): DeliveryGeography
    {
        $geography = DeliveryGeography::where('id', $geoId)
            ->where('brand_id', $brand->id)
            ->where('company_id', $brand->company_id)
            ->first();

        if ($geography === null) {
            throw new NotFoundHttpException('Governorate not found.');
        }

        return $geography;
    }

    public function index(string $brandId, string $geoId): JsonResponse
    {
        $brand = $this->resolveBrand($brandId);
        $geography = $this->resolveGeography($brand, $geoId);

        $zones = DeliveryZone::where('delivery_geography_id', $geography->id)
            ->where('brand_id', $brand->id)
            ->with('shippingRule')
            ->orderBy('sort_order')
            ->get();

        return $this->success($zones);
    }

    public function store(Request $request, string $brandId, string $geoId): JsonResponse
    {
        $brand = $this->resolveBrand($brandId);
        $geography = $this->resolveGeography($brand, $geoId);

        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'name_ar' => 'nullable|string|max:150',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        $actorId = Auth::id() ?? '';

        $zone = DeliveryZone::create([
            ...$validated,
            'delivery_geography_id' => $geography->id,
            'brand_id' => $brand->id,
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);

        $this->audit->record(
            companyId: $brand->company_id,
            module: 'delivery_geography',
            category: 'zone',
            action: 'create',
            oldValue: null,
            newValue: $zone->toArray(),
            brandId: $brand->id,
        );

        return $this->created($zone->load('shippingRule'), 'Zone created.');
    }

    public function update(Request $request, string $brandId, string $geoId, string $id): JsonResponse
    {
        $brand = $this->resolveBrand($brandId);
        $geography = $this->resolveGeography($brand, $geoId);

        $zone = DeliveryZone::where('delivery_geography_id', $geography->id)
            ->where('brand_id', $brand->id)
            ->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:150',
            'name_ar' => 'nullable|string|max:150',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            'custom_shipping_cost' => 'nullable|numeric|min:0',
        ]);

        $old = $zone->toArray();
        $zone->update([...$validated, 'updated_by' => Auth::id()]);

        $this->audit->record(
            companyId: $brand->company_id,
            module: 'delivery_geography',
            category: 'zone',
            action: 'update',
            oldValue: $old,
            newValue: $zone->fresh()?->toArray() ?? [],
            brandId: $brand->id,
        );

        return $this->updated($zone->load('shippingRule'), 'Zone updated.');
    }

    public function destroy(string $brandId, string $geoId, string $id): JsonResponse
    {
        $brand = $this->resolveBrand($brandId);
        $geography = $this->resolveGeography($brand, $geoId);

        $zone = DeliveryZone::where('delivery_geography_id', $geography->id)
            ->where('brand_id', $brand->id)
            ->findOrFail($id);

        $this->audit->record(
            companyId: $brand->company_id,
            module: 'delivery_geography',
            category: 'zone',
            action: 'delete',
            oldValue: $zone->toArray(),
            newValue: null,
            brandId: $brand->id,
        );

        $zone->delete();

        return $this->deleted('Zone deleted.');
    }
}
