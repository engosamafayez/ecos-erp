<?php

declare(strict_types=1);

namespace Modules\Sales\Customers\Presentation\Http\Controllers;

use App\Core\Company\CurrentCompanyService;
use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Sales\Customers\Domain\Models\Customer;
use Modules\Sales\Customers\Domain\Models\CustomerAddress;

final class CustomerAddressController extends Controller
{
    use HasApiResponse;

    public function __construct(private readonly CurrentCompanyService $currentCompany) {}

    /**
     * Resolve a customer inside the caller's tenant.
     *
     * Every method here previously used a bare `Customer::findOrFail()`, which let one
     * company read AND WRITE another company's addresses. 404 (not 403) so the response
     * does not confirm that the record exists.
     */
    private function customer(string $id): Customer
    {
        $companyId = $this->currentCompany->id();

        return Customer::query()
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->findOrFail($id);
    }

    /**
     * Resolve an address inside the caller's tenant, scoped through its own
     * customer relation. update()/destroy() are registered as shallow routes
     * (PUT|DELETE /addresses/{address}, no {customer} segment — see
     * routes/api.php), so there is no customer id to resolve from the URL.
     */
    private function address(string $id): CustomerAddress
    {
        $companyId = $this->currentCompany->id();

        return CustomerAddress::query()
            ->when($companyId !== null, fn ($q) => $q->whereHas('customer', fn ($q2) => $q2->where('company_id', $companyId)))
            ->findOrFail($id);
    }

    public function index(string $customer): JsonResponse
    {
        $model = $this->customer($customer);
        $addresses = $model->addresses()->orderByDesc('is_default')->orderBy('created_at')->get();

        return $this->success($addresses);
    }

    public function show(string $address): JsonResponse
    {
        $addressModel = $this->address($address);

        return $this->success($addressModel);
    }

    public function store(Request $request, string $customer): JsonResponse
    {
        $model = $this->customer($customer);

        $validated = $request->validate([
            'label' => 'sometimes|string|max:100',
            'governorate' => 'required|string|max:100',
            'city' => 'nullable|string|max:100',
            'area' => 'nullable|string|max:100',
            'address_line' => 'nullable|string|max:500',
            'google_maps_lat' => 'nullable|numeric|between:-90,90',
            'google_maps_lng' => 'nullable|numeric|between:-180,180',
            'is_default' => 'sometimes|boolean',
        ]);

        if (! empty($validated['is_default'])) {
            $model->addresses()->update(['is_default' => false]);
        }

        $address = $model->addresses()->create($validated);

        return $this->created($address, 'Address added successfully.');
    }

    public function update(Request $request, string $address): JsonResponse
    {
        $addressModel = $this->address($address);

        $validated = $request->validate([
            'label' => 'sometimes|string|max:100',
            'governorate' => 'sometimes|required|string|max:100',
            'city' => 'nullable|string|max:100',
            'area' => 'nullable|string|max:100',
            'address_line' => 'nullable|string|max:500',
            'google_maps_lat' => 'nullable|numeric|between:-90,90',
            'google_maps_lng' => 'nullable|numeric|between:-180,180',
            'is_default' => 'sometimes|boolean',
        ]);

        if (! empty($validated['is_default'])) {
            CustomerAddress::where('customer_id', $addressModel->customer_id)
                ->where('id', '!=', $address)
                ->update(['is_default' => false]);
        }

        $addressModel->update($validated);

        return $this->updated($addressModel, 'Address updated successfully.');
    }

    public function destroy(string $address): JsonResponse
    {
        $addressModel = $this->address($address);
        $addressModel->delete();

        return $this->deleted('Address deleted successfully.');
    }
}
