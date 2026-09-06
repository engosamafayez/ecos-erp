<?php

declare(strict_types=1);

namespace Modules\Sales\Customers\Presentation\Http\Controllers;

use App\Core\Company\CurrentCompanyService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Modules\Commerce\Orders\Domain\Services\CustomerOrderMetricsService;
use Modules\Crm\Engagement\Domain\Enums\ActivityType;
use Modules\Crm\Engagement\Domain\Services\ActivityService;
use Modules\Sales\Customers\Application\Actions\AssignSalesOwnerAction;
use Modules\Sales\Customers\Application\Actions\BlockCustomerOrPhoneAction;
use Modules\Sales\Customers\Application\Actions\CreateCustomerAction;
use Modules\Sales\Customers\Application\Actions\DeleteCustomerAction;
use Modules\Sales\Customers\Application\Actions\ExportCustomersAction;
use Modules\Sales\Customers\Application\Actions\GetCustomerAction;
use Modules\Sales\Customers\Application\Actions\ListCustomersAction;
use Modules\Sales\Customers\Application\Actions\SearchCustomerByPhoneAction;
use Modules\Sales\Customers\Application\Actions\UnblockCustomerAction;
use Modules\Sales\Customers\Application\Actions\UpdateCustomerAction;
use Modules\Sales\Customers\Application\DTO\CustomerDTO;
use Modules\Sales\Customers\Domain\Models\Customer;
use Modules\Sales\Customers\Domain\Models\CustomerBlock;
use Modules\Sales\Customers\Domain\Services\BlockedCustomerPolicy;
use Modules\Sales\Customers\Presentation\Http\Requests\StoreCustomerRequest;
use Modules\Sales\Customers\Presentation\Http\Requests\UpdateCustomerRequest;
use Modules\Sales\Customers\Presentation\Http\Resources\CustomerResource;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        // CRM's own append-only activity log — reused, not duplicated, for the
        // ownership-change audit trail TASK-...-003 §24 requires (assignOwner()).
        private readonly ActivityService $activities,
    ) {}

    public function index(Request $request, ListCustomersAction $action): JsonResponse
    {
        $filters = $this->buildFilters($request);
        $paginator = $action->execute($filters)->data();
        $customers = collect($paginator->items());

        return $this->success([
            'items' => $this->enrichCustomers($customers, $request),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * TASK-ECOS-COMMERCE-CUSTOMERS-BATCH-02-FINAL-UI-CLOSURE-014 (§10/§11).
     * GET /customers/export?format=csv|html — Print/Export, sharing every filter index()
     * accepts (minus pagination) so the exported/printed set always matches what the user
     * is currently looking at. Uses ExportCustomersAction/allMatching() to fetch the FULL
     * filtered+sorted population once, server-side — never re-derived from paginated
     * browser fetches, never the browser's currently-rendered rows only.
     */
    public function export(Request $request, ExportCustomersAction $action): StreamedResponse|Response
    {
        $filters = $this->buildFilters($request);
        $customers = $action->execute($filters)->data();
        $rows = $this->exportRows($customers);
        $format = (string) $request->query('format', 'csv');

        return $format === 'html' ? $this->printableHtml($rows) : $this->streamCsv($rows);
    }

    /**
     * TASK-...-FINAL-UI-CLOSURE-014 (§15) — distinct Sales Owners currently referenced by
     * this company's Customers, read straight off the already-denormalised
     * `sales_owner_name` column (Customers Batch 02) — never a new employee/user
     * directory lookup. Naturally empty until a future task adds the assignment action.
     */
    public function salesOwnerOptions(Request $request): JsonResponse
    {
        $companyId = $this->currentCompany->id();

        $owners = Customer::query()
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->whereNotNull('sales_owner_id')
            ->select('sales_owner_id', 'sales_owner_name')
            ->distinct()
            ->orderBy('sales_owner_name')
            ->get()
            ->map(fn ($row) => ['id' => (string) $row->sales_owner_id, 'name' => $row->sales_owner_name])
            ->values();

        return $this->success($owners);
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
            // TASK-...-FINAL-UI-CLOSURE-014 (§5) — canonical human-readable actor identity.
            'blocked_by_name' => $activeBlock !== null ? $this->actorName($activeBlock->blocked_by, $activeBlock->blockedByUser) : null,
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

    /**
     * The single write path for the CRM/Commercial owner authority
     * (`sales_owner_id`/`sales_owner_name` — TASK-ECOS-CRM-CUSTOMER-PORTFOLIO-
     * AND-FOLLOWUP-003 §3/§4). Company scope is enforced twice: the customer
     * lookup (via AssignSalesOwnerAction -> CurrentCompanyService, same as
     * update()) and the candidate owner lookup below — a cross-company user id
     * is rejected exactly like a cross-company customer id, never silently
     * accepted.
     */
    public function assignOwner(Request $request, string $customer, AssignSalesOwnerAction $action): JsonResponse
    {
        $companyId = $this->currentCompany->id();

        if ($companyId === null) {
            return $this->error('A company context is required to assign an owner.', 422);
        }

        $validated = $request->validate([
            'sales_owner_id' => ['nullable', Rule::exists('users', 'id')],
        ]);

        $owner = null;
        $ownerId = $validated['sales_owner_id'] ?? null;

        if ($ownerId !== null) {
            $owner = User::query()->where('id', $ownerId)->where('company_id', $companyId)->first();

            if ($owner === null) {
                return $this->error(
                    'The selected user is not valid for this company.',
                    422,
                    ['sales_owner_id' => ['invalid_company_owner']],
                );
            }
        }

        $result = $action->execute($customer, $owner?->id, $owner !== null ? ($owner->display_name ?? $owner->name) : null);

        $this->activities->log($companyId, $customer, ActivityType::System, [
            'subject' => $owner !== null
                ? 'Sales owner assigned: '.($owner->display_name ?? $owner->name)
                : 'Sales owner unassigned',
            'related_type' => 'customer_ownership',
            'actor_id' => $request->user()?->id,
        ]);

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

        // TASK-...-FINAL-UI-CLOSURE-014 (§5) — resolve both actors for every episode
        // (blockedByUser/unblockedByUser are eager-loaded by historyForCustomer() itself,
        // ONE extra query for the whole history, never one per row).
        return $this->success($history->map(fn (CustomerBlock $block) => [
            'id' => $block->id,
            'company_id' => $block->company_id,
            'customer_id' => $block->customer_id,
            'normalized_phone' => $block->normalized_phone,
            'is_active' => $block->is_active,
            'block_reason' => $block->block_reason,
            'blocked_by' => $block->blocked_by,
            'blocked_by_name' => $this->actorName($block->blocked_by, $block->blockedByUser),
            'blocked_at' => $block->blocked_at?->toIso8601String(),
            'unblock_reason' => $block->unblock_reason,
            'unblocked_by' => $block->unblocked_by,
            'unblocked_by_name' => $block->unblocked_by !== null ? $this->actorName($block->unblocked_by, $block->unblockedByUser) : null,
            'unblocked_at' => $block->unblocked_at?->toIso8601String(),
        ])->values());
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
     * TASK-...-FINAL-UI-CLOSURE-014 — every filter index()/export() accept, in one place
     * so the two can never drift apart. `per_page` is harmless noise for export() (its
     * repository path never reads it — Print/Export always covers the full filtered
     * population, capped defensively by MAX_EXPORT_ROWS, never one page).
     *
     * @return array<string, mixed>
     */
    private function buildFilters(Request $request): array
    {
        return [
            'search' => $request->query('search'),
            'status' => $request->query('status', 'all'),
            'country' => $request->query('country'),
            'city' => $request->query('city'),
            'brand_id' => $request->query('brand_id'),
            'sales_owner_id' => $request->query('sales_owner_id'),
            'unassigned_sales_owner' => $request->query('unassigned_sales_owner'),
            'channel_id' => $request->query('channel_id'),
            // TASK-...-FINAL-UI-CLOSURE-014-R1 (§2) — Top Spenders population segment,
            // deliberately separate from the sort_by=total_order_value "Highest Spend" sort.
            'top_spenders' => $request->query('top_spenders'),
            // Customer Intelligence (TASK-...-CUSTOMER-INTELLIGENCE-008): Repeat Customers
            // and product-specific repeat-buyer filters, both backend-authoritative —
            // never a client-side filter of the current page.
            'repeat_only' => $request->query('repeat_only'),
            'product_id' => $request->query('product_id'),
            'min_purchase_count' => $request->query('min_purchase_count'),
            'order_activity' => $request->query('order_activity'),
            // TASK-...-BLOCKED-CUSTOMERS-009 (§40) / FINAL-UI-CLOSURE-014 (§17).
            'blocked_only' => $request->query('blocked_only'),
            'not_blocked_only' => $request->query('not_blocked_only'),
            'sort_by' => $request->query('sort_by', 'created_at'),
            'sort_dir' => $request->query('sort_dir', 'desc'),
            'per_page' => $request->query('per_page', 10),
            'company_id' => $this->currentCompany->id(),
        ];
    }

    /**
     * SIX aggregate queries per company on the page — never one per row. Grouping by the
     * customer's OWN company_id matters for the documented super-admin context, where
     * CurrentCompanyService::id() is null: filtering by a single company would zero every
     * metric. A normal user's page is one company, so this stays at six queries; a
     * super-admin's page costs six per distinct company — bounded, never proportional to
     * the number of customers. Shared by enrichCustomers() (JSON) and exportRows() (CSV/
     * HTML) so both presentations of the SAME request agree on every figure.
     *
     * @return array{0: array, 1: array, 2: array, 3: array, 4: array, 5: array}
     */
    private function batchEnrichmentData(Collection $customers): array
    {
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

        return [$metrics, $topProds, $locations, $governorates, $channels, $blocks];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function enrichCustomers(Collection $customers, Request $request): array
    {
        [$metrics, $topProds, $locations, $governorates, $channels, $blocks] = $this->batchEnrichmentData($customers);

        return $customers->map(function (Customer $c) use ($request, $metrics, $topProds, $locations, $governorates, $channels, $blocks) {
            $block = $blocks[(string) $c->id] ?? null;

            return [
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
                'is_blocked' => $block !== null,
                'block_reason' => $block?->block_reason,
                'blocked_at' => $block?->blocked_at?->toIso8601String(),
                'blocked_by' => $block?->blocked_by,
                // TASK-...-FINAL-UI-CLOSURE-014 (§5) — canonical human-readable identity.
                'blocked_by_name' => $block !== null ? $this->actorName($block->blocked_by, $block->blockedByUser) : null,
                'customer_block_id' => $block?->id,
            ];
        })->all();
    }

    /**
     * TASK-...-FINAL-UI-CLOSURE-014 (§10/§11/§12) — flat scalar rows for CSV/print, built
     * directly off the Customer model rather than reusing enrichCustomers()'s array: its
     * 'brands' key holds a CustomerResource collection (a Resource object, not a plain
     * array — fine for JSON encoding, not for a synchronous CSV join here), whereas
     * `customerBrands.brand` is the SAME eager load paginate()/allMatching() already
     * apply, so reading it directly needs no new query and no new engine.
     *
     * @return list<array<string, string>>
     */
    private function exportRows(Collection $customers): array
    {
        [$metrics, , , , $channels, $blocks] = $this->batchEnrichmentData($customers);

        return $customers->map(function (Customer $c) use ($metrics, $channels, $blocks) {
            $id = (string) $c->id;
            $m = $metrics[$id] ?? CustomerOrderMetricsService::emptyMetrics();
            $block = $blocks[$id] ?? null;

            $brandNames = $c->customerBrands
                ->map(fn ($cb) => $cb->brand?->name)
                ->filter(fn (?string $n) => $n !== null && $n !== '')
                ->implode(', ');

            $channelNames = collect($channels[$id] ?? [])
                ->pluck('channel_name')
                ->filter(fn (?string $n) => $n !== null && $n !== '')
                ->implode(', ');

            return [
                'code' => (string) $c->code,
                'name' => (string) $c->name,
                'phone' => (string) ($c->phone ?? ''),
                'mobile' => (string) ($c->mobile ?? ''),
                'full_address' => (string) ($this->fullAddress($c) ?? ''),
                'brands' => $brandNames,
                'sales_owner_name' => (string) ($c->sales_owner_name ?? ''),
                'channels' => $channelNames,
                'orders_count' => (string) ($m['orders_count'] ?? 0),
                'total_order_value' => (string) ($m['total_order_value'] ?? 0),
                'repeat_status' => ($m['is_repeat_customer'] ?? false) ? 'Repeat' : 'Not Repeat',
                'first_order_at' => (string) ($m['first_order_at'] ?? ''),
                'last_order_at' => (string) ($m['last_order_at'] ?? ''),
                'is_blocked' => $block !== null ? 'Blocked' : 'Not Blocked',
                'block_reason' => (string) ($block?->block_reason ?? ''),
                'blocked_by_name' => $block !== null ? $this->actorName($block->blocked_by, $block->blockedByUser) : '',
            ];
        })->all();
    }

    /** @var array<string, string> column_key => CSV column header, in Section 12's order. */
    private const EXPORT_CSV_HEADERS = [
        'code' => 'Customer Code',
        'name' => 'Customer Name',
        'phone' => 'Phone',
        'mobile' => 'Secondary Phone',
        'full_address' => 'Full Address',
        'brands' => 'Brand(s)',
        'sales_owner_name' => 'Sales Owner',
        'channels' => 'Channel(s)',
        'orders_count' => 'Orders Count',
        'total_order_value' => 'Total Order Value',
        'repeat_status' => 'Repeat Status',
        'first_order_at' => 'First Order',
        'last_order_at' => 'Last Order',
        'is_blocked' => 'Blocked Status',
        'block_reason' => 'Block Reason',
        'blocked_by_name' => 'Blocked By',
    ];

    /** @var array<string, string> column_key => print column header, Section 10's smaller set. */
    private const PRINT_HEADERS = [
        'code' => 'Customer Code',
        'name' => 'Name',
        'phone' => 'Phone',
        'full_address' => 'Full Address',
        'brands' => 'Brand(s)',
        'sales_owner_name' => 'Sales Owner',
        'channels' => 'Channel(s)',
        'orders_count' => 'Orders',
        'total_order_value' => 'Total Order Value',
        'is_blocked' => 'Blocked',
    ];

    /**
     * Same fputcsv()/StreamedResponse/UTF-8-BOM idiom already proven by
     * Marketing\Intelligence\GenerateReportAction::streamCsv() — that method is private
     * and Marketing-specific (not a shared trait/service), so the idiom is replicated
     * here rather than reused by reference; no new export engine, no new architecture.
     *
     * @param  list<array<string, string>>  $rows
     */
    private function streamCsv(array $rows): StreamedResponse
    {
        $filename = 'customers_'.now()->format('Y-m-d').'.csv';

        return response()->stream(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            // UTF-8 BOM — Excel needs this to open Arabic/non-ASCII text correctly.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_values(self::EXPORT_CSV_HEADERS));

            foreach ($rows as $row) {
                $line = [];
                foreach (array_keys(self::EXPORT_CSV_HEADERS) as $key) {
                    $line[] = $row[$key] ?? '';
                }
                fputcsv($out, $line);
            }

            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    private function printableHtml(array $rows): Response
    {
        $html = '<!doctype html><html><head><meta charset="utf-8"><title>Customers</title>'
            .'<style>body{font-family:sans-serif;font-size:12px}table{border-collapse:collapse;width:100%}'
            .'th,td{border:1px solid #ddd;padding:4px 8px;text-align:start}th{background:#f5f5f5}</style>'
            .'</head><body>'
            .'<h1>Customers</h1>'
            .'<p>Generated: '.now()->format('Y-m-d H:i').'</p>'
            .'<table><thead><tr>';

        foreach (self::PRINT_HEADERS as $label) {
            $html .= '<th>'.htmlspecialchars($label, ENT_QUOTES, 'UTF-8').'</th>';
        }

        $html .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach (array_keys(self::PRINT_HEADERS) as $key) {
                $html .= '<td>'.htmlspecialchars((string) ($row[$key] ?? ''), ENT_QUOTES, 'UTF-8').'</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</tbody></table></body></html>';

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * TASK-...-FINAL-UI-CLOSURE-014 (§5) — the canonical human-readable identity for a
     * block/unblock actor. `display_name` wins when set (it exists specifically to curate
     * how a user is shown in the UI), falling back to `name`. A null actor id means the
     * action was system-originated — 'System' is the SAME fallback text
     * DistributionZoneController/DistributionPlanningController already use elsewhere,
     * not a new convention. Never returns a raw id: an actor id that fails to resolve to
     * any User row falls back to a neutral label instead (this app only soft-deletes
     * Users — blockedByUser()/unblockedByUser() use withTrashed() — so this is a
     * defensive edge, not an expected case).
     */
    private function actorName(?string $actorId, ?User $user): string
    {
        if ($actorId === null) {
            return 'System';
        }

        $name = $user?->display_name ?: $user?->name;

        return ($name !== null && $name !== '') ? $name : 'Unknown User';
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
