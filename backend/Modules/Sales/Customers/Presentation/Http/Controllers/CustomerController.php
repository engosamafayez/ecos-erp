<?php

declare(strict_types=1);

namespace Modules\Sales\Customers\Presentation\Http\Controllers;

use App\Core\Company\CurrentCompanyService;
use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Commerce\Orders\Domain\Services\CustomerOrderMetricsService;
use Modules\Sales\Customers\Application\Actions\BlockCustomerOrPhoneAction;
use Modules\Sales\Customers\Application\Actions\CreateCustomerAction;
use Modules\Sales\Customers\Application\Actions\DeleteCustomerAction;
use Modules\Sales\Customers\Application\Actions\GetCustomerAction;
use Modules\Sales\Customers\Application\Actions\ListCustomersAction;
use Modules\Sales\Customers\Application\Actions\SearchCustomerByPhoneAction;
use Modules\Sales\Customers\Application\Actions\UnblockCustomerAction;
use Modules\Sales\Customers\Application\Actions\UpdateCustomerAction;
use Modules\Sales\Customers\Application\DTO\CustomerDTO;
use Modules\Sales\Customers\Domain\Models\Customer;
use Modules\Sales\Customers\Domain\Services\BlockedCustomerPolicy;
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
        // TASK-ECOS-COMMERCE-CUSTOMERS-BATCH-02-BLOCKED-CUSTOMERS-009 (§33) — the
        // single read authority for index()/show() block-state enrichment.
        private readonly BlockedCustomerPolicy $blockedPolicy,
    ) {}

    public function index(Request $request, ListCustomersAction $action): JsonResponse
    {
        $filters = [
            'search' => $request->query('search'),
            'status' => $request->query('status', 'all'),
            'country' => $request->query('country'),
            'city' => $request->query('city'),
            'brand_id' => $request->query('brand_id'),
            // Customer Intelligence (TASK-...-CUSTOMER-INTELLIGENCE-008): Repeat Customers
            // and product-specific repeat-buyer filters, both backend-authoritative —
            // never a client-side filter of the current page.
            'repeat_only' => $request->query('repeat_only'),
            'product_id' => $request->query('product_id'),
            'min_purchase_count' => $request->query('min_purchase_count'),
            // TASK-...-BLOCKED-CUSTOMERS-009 (§40) — Blocked Customers filter/segment.
            'blocked_only' => $request->query('blocked_only'),
            'sort_by' => $request->query('sort_by', 'created_at'),
            'sort_dir' => $request->query('sort_dir', 'desc'),
            'per_page' => $request->query('per_page', 10),
            'company_id' => $this->currentCompany->id(),
        ];

        $paginator = $action->execute($filters)->data();

        // FIVE aggregate queries per company on the page — never one per row.
        //
        // Grouping by the customer's OWN company_id matters for the documented super-admin
        // context, where CurrentCompanyService::id() is null: filtering by a single company
        // would zero every metric. A normal user's page is one company, so this stays at
        // five queries; a super-admin's page costs five per distinct company — bounded,
        // and never proportional to the number of customers.
        $customers = collect($paginator->items());
        $metrics = [];
        $topProds = [];
        $locations = [];
        $governorates = [];
        $channels = [];
        $blocks = [];

        foreach ($customers->groupBy(fn (Customer $c) => (string) $c->company_id) as $companyId => $group) {
            if ((string) $companyId === '') {
                continue;
            }

            $ids = $group->pluck('id')->map(fn ($id) => (string) $id)->all();
            $metrics += $this->orderMetrics->forCustomers($ids, (string) $companyId);
            $topProds += $this->orderMetrics->topProductsForCustomers($ids, (string) $companyId);
            $locations += $this->orderMetrics->locationUrlForCustomers($ids, (string) $companyId);
            $governorates += $this->orderMetrics->preferredGovernorateForCustomers($ids, (string) $companyId);
            $channels += $this->orderMetrics->channelsForCustomers($ids, (string) $companyId);
            // TASK-...-BLOCKED-CUSTOMERS-009 (§33/§54) — ONE query per company on the
            // page, never one per row, same shape as the metrics above. Takes the
            // Customer models (not bare ids): matching is by customer_id OR either
            // saved phone/mobile (§10), which bare ids cannot express.
            $blocks += $this->blockedPolicy->activeBlocksForCustomers($group, (string) $companyId);
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
                // Distinct Channels this customer has ordered through, most-used first.
                // Derived read — see CustomerOrderMetricsService::channelsForCustomers().
                'channels' => $channels[(string) $c->id] ?? [],
                // TASK-...-BLOCKED-CUSTOMERS-009 (§33) — current block state only;
                // full history is fetched on demand via GET .../block-history.
                'is_blocked' => isset($blocks[(string) $c->id]),
                'block_reason' => $blocks[(string) $c->id]?->block_reason,
                'blocked_at' => $blocks[(string) $c->id]?->blocked_at?->toIso8601String(),
                'blocked_by' => $blocks[(string) $c->id]?->blocked_by,
                'customer_block_id' => $blocks[(string) $c->id]?->id,
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

        $activeBlock = $this->blockedPolicy->activeBlocksForCustomers(collect([$model]), $companyId)[$id] ?? null;

        return $this->success([
            ...(new CustomerResource($model))->toArray($request),
            ...$this->orderMetrics->forCustomer($id, $companyId),
            // Same grouped-by-product query the CRM 360 uses — one query, never per order.
            'purchased_products' => $this->orderMetrics->purchasedProducts($id, $companyId),
            'location_url' => $this->orderMetrics->locationUrlForCustomers([$id], $companyId)[$id] ?? null,
            'preferred_governorate' => $this->orderMetrics->preferredGovernorateForCustomers([$id], $companyId)[$id] ?? null,
            'channels' => $this->orderMetrics->channelsForCustomers([$id], $companyId)[$id] ?? [],
            'full_address' => $this->fullAddress($model),
            // TASK-...-BLOCKED-CUSTOMERS-009 (§33/§41).
            'is_blocked' => $activeBlock !== null,
            'block_reason' => $activeBlock?->block_reason,
            'blocked_at' => $activeBlock?->blocked_at?->toIso8601String(),
            'blocked_by' => $activeBlock?->blocked_by,
            'customer_block_id' => $activeBlock?->id,
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
     * TASK-ECOS-COMMERCE-CUSTOMERS-BATCH-02-BLOCKED-CUSTOMERS-009 (§11).
     * POST /customers/{customer}/block — blocks an existing Customer's saved phone.
     */
    public function block(Request $request, string $customer, BlockCustomerOrPhoneAction $action): JsonResponse
    {
        $companyId = $this->currentCompany->id();

        if ($companyId === null) {
            return $this->error('A company context is required to block a customer.', 422);
        }

        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $actorId = $request->user()?->id !== null ? (string) $request->user()->id : null;

        $result = $action->execute($companyId, $customer, null, $validated['reason'], $actorId);

        return $this->success($result->data(), $result->message());
    }

    /**
     * TASK-ECOS-COMMERCE-CUSTOMERS-BATCH-02-BLOCKED-CUSTOMERS-009 (§12).
     * POST /customers/block-phone — blocks a phone that may not yet belong to a
     * Customer. Never fabricates a Customer record.
     */
    public function blockPhone(Request $request, BlockCustomerOrPhoneAction $action): JsonResponse
    {
        $companyId = $this->currentCompany->id();

        if ($companyId === null) {
            return $this->error('A company context is required to block a phone.', 422);
        }

        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $actorId = $request->user()?->id !== null ? (string) $request->user()->id : null;

        $result = $action->execute($companyId, null, $validated['phone'], $validated['reason'], $actorId);

        return $this->success($result->data(), $result->message());
    }

    /**
     * TASK-ECOS-COMMERCE-CUSTOMERS-BATCH-02-BLOCKED-CUSTOMERS-009 (§28).
     * POST /customers/{customer}/unblock — expects the ACTIVE customer_block id
     * (surfaced as `customer_block_id` on the Customer read model) as `block_id`.
     */
    public function unblock(Request $request, string $customer, UnblockCustomerAction $action): JsonResponse
    {
        $companyId = $this->currentCompany->id();

        if ($companyId === null) {
            return $this->error('A company context is required to unblock a customer.', 422);
        }

        $validated = $request->validate([
            'block_id' => ['required', 'string'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $actorId = $request->user()?->id !== null ? (string) $request->user()->id : null;

        $result = $action->execute($companyId, $validated['block_id'], $validated['reason'], $actorId);

        return $this->success($result->data(), $result->message());
    }

    /**
     * TASK-ECOS-COMMERCE-CUSTOMERS-BATCH-02-BLOCKED-CUSTOMERS-009 (§6/§41).
     * GET /customers/{customer}/block-history
     */
    public function blockHistory(string $customer): JsonResponse
    {
        $companyId = $this->currentCompany->id();
        $model = Customer::query()
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->findOrFail($customer);

        $history = $this->blockedPolicy->historyForCustomer(
            (string) $model->id,
            (string) ($companyId ?? $model->company_id),
            [$model->phone, $model->mobile],
        );

        return $this->success($history->values());
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
     *
     * TASK-...-OPERATIONAL-READ-MODEL-007: extended to include building/floor/apartment/
     * landmark — `customer_addresses` has carried these columns since
     * 2026_07_13_200001_add_address_details_to_customer_addresses_table.php, but this
     * formatter never read them, so "full address" was missing exactly the operational
     * detail (which building, which floor) a driver actually needs. The legacy
     * `customers.*` fallback branch is unchanged — those flat columns never had
     * building/floor/apartment/landmark equivalents to begin with.
     */
    private function fullAddress(Customer $customer): ?string
    {
        $default = $customer->relationLoaded('addresses')
            ? $customer->addresses->firstWhere('is_default', true)
            : null;

        $parts = $default !== null
            ? [
                $default->address_line,
                $default->building,
                $default->floor,
                $default->apartment,
                $default->area,
                $default->city,
                $default->governorate,
                $default->landmark,
            ]
            : [$customer->address, $customer->area, $customer->city, $customer->governorate];

        $parts = array_values(array_filter(
            array_map(static fn ($p) => is_string($p) ? trim($p) : null, $parts),
            static fn (?string $p) => $p !== null && $p !== '',
        ));

        return $parts === [] ? null : implode('، ', $parts);
    }
}
