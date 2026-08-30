<?php

declare(strict_types=1);

namespace Modules\Sales\Customers\Presentation\Http\Controllers;

use App\Core\Company\CurrentCompanyService;
use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Commerce\Orders\Domain\Services\CustomerOrderMetricsService;
use Modules\Sales\Customers\Application\Actions\CreateCustomerAction;
use Modules\Sales\Customers\Application\Actions\DeleteCustomerAction;
use Modules\Sales\Customers\Application\Actions\GetCustomerAction;
use Modules\Sales\Customers\Application\Actions\ListCustomersAction;
use Modules\Sales\Customers\Application\Actions\SearchCustomerByPhoneAction;
use Modules\Sales\Customers\Application\Actions\UpdateCustomerAction;
use Modules\Sales\Customers\Application\DTO\CustomerDTO;
use Modules\Sales\Customers\Domain\Models\Customer;
use Modules\Sales\Customers\Presentation\Http\Requests\StoreCustomerRequest;
use Modules\Sales\Customers\Presentation\Http\Requests\UpdateCustomerRequest;
use Modules\Sales\Customers\Presentation\Http\Resources\CustomerResource;

final class CustomerController extends Controller
{
    use HasApiResponse;

    public function __construct(
        private readonly CurrentCompanyService $currentCompany,
        // The SAME canonical service the CRM workspace uses. Sales does not define its own
        // order semantics — Orders Count, Total Value and Receiving Rate mean one thing
        // platform-wide, and that meaning lives in Commerce\Orders where `orders` lives.
        private readonly CustomerOrderMetricsService $orderMetrics,
    ) {}

    public function index(Request $request, ListCustomersAction $action): JsonResponse
    {
        $filters = [
            'search' => $request->query('search'),
            'status' => $request->query('status', 'all'),
            'country' => $request->query('country'),
            'city' => $request->query('city'),
            'brand_id' => $request->query('brand_id'),
            'sort_by' => $request->query('sort_by', 'created_at'),
            'sort_dir' => $request->query('sort_dir', 'desc'),
            'per_page' => $request->query('per_page', 10),
            'company_id' => $this->currentCompany->id(),
        ];

        $paginator = $action->execute($filters)->data();

        // FOUR aggregate queries per company on the page — never one per row.
        //
        // Grouping by the customer's OWN company_id matters for the documented super-admin
        // context, where CurrentCompanyService::id() is null: filtering by a single company
        // would zero every metric. A normal user's page is one company, so this stays at
        // four queries; a super-admin's page costs four per distinct company — bounded,
        // and never proportional to the number of customers.
        $customers = collect($paginator->items());
        $metrics = [];
        $topProds = [];
        $locations = [];
        $governorates = [];

        foreach ($customers->groupBy(fn (Customer $c) => (string) $c->company_id) as $companyId => $group) {
            if ((string) $companyId === '') {
                continue;
            }

            $ids = $group->pluck('id')->map(fn ($id) => (string) $id)->all();
            $metrics += $this->orderMetrics->forCustomers($ids, (string) $companyId);
            $topProds += $this->orderMetrics->topProductsForCustomers($ids, (string) $companyId);
            $locations += $this->orderMetrics->locationUrlForCustomers($ids, (string) $companyId);
            $governorates += $this->orderMetrics->preferredGovernorateForCustomers($ids, (string) $companyId);
        }

        return $this->success([
            'items' => $customers->map(fn (Customer $c) => [
                ...(new CustomerResource($c))->toArray($request),
                // Order-derived facts, composed here in the presentation layer exactly as the
                // CRM workspace composes them. Identical definitions, one implementation.
                ...($metrics[(string) $c->id] ?? CustomerOrderMetricsService::emptyMetrics()),
                'top_products_count' => $topProds[(string) $c->id]['distinct_count'] ?? 0,
                'top_products' => $topProds[(string) $c->id]['top'] ?? [],
                'location_url' => $locations[(string) $c->id] ?? null,
                'full_address' => $this->fullAddress($c),
                // Most frequent orders.governorate. NULL when the customer has no order
                // carrying one — never substituted with city or a guess.
                'preferred_governorate' => $governorates[(string) $c->id] ?? null,
            ])->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, string $customer, GetCustomerAction $action): JsonResponse
    {
        $model = $action->execute($customer)->data();

        // The tenant boundary now lives in the repository, where it belongs: GetCustomerAction
        // resolves the company itself and a foreign customer never comes back at all (404 via
        // CustomerNotFoundException). No controller-level guard is needed, and — unlike the
        // guard it replaces — this does not break the documented super-admin context.
        $id = (string) $model->id;
        // Metrics are keyed by the customer's OWN company, so a super-admin (no company
        // context) still gets real figures instead of zeros.
        $companyId = (string) ($this->currentCompany->id() ?? $model->company_id ?? '');

        if ($companyId === '') {
            return $this->success(new CustomerResource($model));
        }

        return $this->success([
            ...(new CustomerResource($model))->toArray($request),
            ...$this->orderMetrics->forCustomer($id, $companyId),
            // Same grouped-by-product query the CRM 360 uses — one query, never per order.
            'purchased_products' => $this->orderMetrics->purchasedProducts($id, $companyId),
            'location_url' => $this->orderMetrics->locationUrlForCustomers([$id], $companyId)[$id] ?? null,
            'preferred_governorate' => $this->orderMetrics->preferredGovernorateForCustomers([$id], $companyId)[$id] ?? null,
            'full_address' => $this->fullAddress($model),
        ]);
    }

    public function store(StoreCustomerRequest $request, CreateCustomerAction $action): JsonResponse
    {
        $companyId = $this->currentCompany->id();

        if ($companyId === null) {
            return $this->error('A company context is required to create a customer.', 422);
        }

        $validated = $request->validated();

        $duplicateResponse = $this->checkDuplicatePhone(
            companyId: $companyId,
            phone: $validated['phone'] ?? null,
            excludeId: null,
        );
        if ($duplicateResponse !== null) {
            return $duplicateResponse;
        }

        $payload = array_merge($validated, ['company_id' => $companyId]);
        $result = $action->execute(CustomerDTO::fromArray($payload));

        return $this->created(new CustomerResource($result->data()), $result->message());
    }

    public function update(
        UpdateCustomerRequest $request,
        string $customer,
        UpdateCustomerAction $action,
    ): JsonResponse {
        $companyId = $this->currentCompany->id();
        $validated = $request->validated();

        if ($companyId !== null) {
            $duplicateResponse = $this->checkDuplicatePhone(
                companyId: $companyId,
                phone: $validated['phone'] ?? null,
                excludeId: $customer,
            );
            if ($duplicateResponse !== null) {
                return $duplicateResponse;
            }
        }

        $result = $action->execute($customer, CustomerDTO::fromArray($validated));

        return $this->updated(new CustomerResource($result->data()), $result->message());
    }

    public function destroy(string $customer, DeleteCustomerAction $action): JsonResponse
    {
        $result = $action->execute($customer);

        return $this->deleted($result->message() ?? 'Customer deleted successfully.');
    }

    public function searchByPhone(Request $request, SearchCustomerByPhoneAction $action): JsonResponse
    {
        $phone = trim((string) $request->query('phone', ''));

        if ($phone === '') {
            return $this->success(null, 'Phone number is required.');
        }

        $result = $action->execute($phone);

        return $this->success($result->data(), $result->message());
    }

    /**
     * Return a 422 response if a customer in the same company already owns this phone number.
     * Pass $excludeId on update so the current record is not flagged against itself.
     */
    private function checkDuplicatePhone(string $companyId, ?string $phone, ?string $excludeId): ?JsonResponse
    {
        if ($phone === null || $phone === '') {
            return null;
        }

        $query = Customer::query()
            ->where('company_id', $companyId)
            ->where('phone', $phone)
            ->whereNull('deleted_at')
            ->select(['id', 'name', 'code']);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        $existing = $query->first();

        if ($existing === null) {
            return null;
        }

        return $this->error(
            'A customer with this phone number already exists.',
            422,
            [
                'phone' => ['duplicate_customer_phone'],
                'existing_customer' => [
                    'id' => $existing->id,
                    'name' => $existing->name,
                    'code' => $existing->code,
                ],
            ],
        );
    }

    /**
     * The customer's address as one display string.
     *
     * Same precedence as the CRM workspace: the structured `customer_addresses` default row
     * wins over the denormalised `customers.*` columns, so both screens answer the same
     * address for the same customer. The two sources exist and can disagree — see the
     * TASK-CUSTOMER-360 report; this does not re-decide that, it follows it.
     */
    private function fullAddress(Customer $customer): ?string
    {
        $default = $customer->relationLoaded('addresses')
            ? $customer->addresses->firstWhere('is_default', true)
            : null;

        $parts = $default !== null
            ? [$default->address_line, $default->area, $default->city, $default->governorate]
            : [$customer->address, $customer->area, $customer->city, $customer->governorate];

        $parts = array_values(array_filter(
            array_map(static fn ($p) => is_string($p) ? trim($p) : null, $parts),
            static fn (?string $p) => $p !== null && $p !== '',
        ));

        return $parts === [] ? null : implode('، ', $parts);
    }
}
