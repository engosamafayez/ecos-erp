<?php

declare(strict_types=1);

namespace Modules\Crm\Customers\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Commerce\Orders\Domain\Services\CustomerOrderMetricsService;
use Modules\Crm\Customers\Domain\Enums\CustomerStatus;
use Modules\Crm\Customers\Domain\Enums\CustomerType;
use Modules\Crm\Customers\Domain\Models\Customer;
use Modules\Crm\Customers\Domain\Services\Customer360Service;
use Modules\Crm\Customers\Domain\Services\CustomerSearchService;
use Modules\Crm\Customers\Domain\Services\CustomerService;
use Modules\Crm\Customers\Presentation\Http\Controllers\Concerns\ResolvesCustomerContext;

/**
 * The customer master — the single source of truth for identity. Create, edit,
 * search, profile (360°), status and archive.
 */
class CustomerController extends Controller
{
    use ResolvesCustomerContext;

    public function __construct(
        private readonly CustomerService $customers,
        private readonly Customer360Service $profiles,
        private readonly CustomerSearchService $search,
        // Composed here, not inside Customer360Service: that service documents that
        // it imports no operational module and that the dependency never inverts.
        private readonly CustomerOrderMetricsService $orderMetrics,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $page = $this->search->search($companyId, $request->only(['q', 'status', 'type', 'group_id', 'tag_id', 'per_page']));

        // ONE aggregate query for the whole page — not one per row.
        $customers = collect($page->items());
        $metrics = $this->orderMetrics->forCustomers(
            $customers->pluck('id')->map(fn ($id) => (string) $id)->all(),
            $companyId,
        );

        return response()->json([
            'data' => $customers->map(fn (Customer $c) => [
                ...$this->profiles->identity($c),
                ...($metrics[(string) $c->id] ?? CustomerOrderMetricsService::emptyMetrics()),
            ]),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->profiles->identity($this->customer($request, $id))]);
    }

    public function profile(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyId($request);
        $customer = $this->customer($request, $id);

        return response()->json([
            'data' => [
                ...$this->profiles->profile($customer),
                // Order-derived KPIs and purchased products come from canonical
                // `orders`, never from the customer-intelligence profile.
                'order_metrics' => $this->orderMetrics->forCustomer((string) $customer->id, $companyId),
                'purchased_products' => $this->orderMetrics->purchasedProducts((string) $customer->id, $companyId),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(['individual', 'business'])],
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'business_name' => ['nullable', 'string', 'max:200'],
            'tax_registration_number' => ['nullable', 'string', 'max:60'],
            'contact_person' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', Rule::in(array_map(fn ($s) => $s->value, CustomerStatus::cases()))],
            'customer_group_id' => ['nullable', 'string'],
            'preferred_language' => ['nullable', 'string', 'max:10'],
            'preferred_contact_method' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:200'],
            'country' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
        ]);

        $customer = $this->customers->create(
            $this->companyId($request), CustomerType::from($validated['type']), $validated, $this->actorId($request),
        );

        return response()->json(['data' => $this->profiles->identity($customer)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'business_name' => ['nullable', 'string', 'max:200'],
            'tax_registration_number' => ['nullable', 'string', 'max:60'],
            'contact_person' => ['nullable', 'string', 'max:200'],
            'customer_group_id' => ['nullable', 'string'],
            'preferred_language' => ['nullable', 'string', 'max:10'],
            'preferred_contact_method' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
        ]);

        $customer = $this->customers->update($this->customer($request, $id), $validated);

        return response()->json(['data' => $this->profiles->identity($customer)]);
    }

    public function setStatus(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['status' => ['required', Rule::in(array_map(fn ($s) => $s->value, CustomerStatus::cases()))]]);
        $customer = $this->customers->setStatus($this->customer($request, $id), CustomerStatus::from($validated['status']));

        return response()->json(['data' => $this->profiles->identity($customer)]);
    }

    public function archive(Request $request, string $id): JsonResponse
    {
        $customer = $this->customers->archive($this->customer($request, $id), $this->actorId($request));

        return response()->json(['data' => $this->profiles->identity($customer)]);
    }
}
