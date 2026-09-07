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
use Modules\Crm\Engagement\Infrastructure\Timeline\ConversationTimelineSource;
use Modules\Crm\Portfolio\Domain\Services\PortfolioService;
use Modules\Finance\Receivables\Domain\Services\CustomerLedgerService;
use Modules\Sales\Customers\Domain\Models\CustomerBlock;
use Modules\Sales\Customers\Domain\Services\BlockedCustomerPolicy;

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
        private readonly CustomerLedgerService $ledger,
        // Blocked-customer state's real, tested authority still lives on the legacy
        // Sales\Customers module (TASK-ECOS-COMMERCE-CUSTOMERS-BATCH-02-BLOCKED-
        // CUSTOMERS-009) — Gate A's identity consolidation never claimed that
        // capability. Read from it directly rather than re-implementing or
        // relocating it; see TASK-ECOS-CRM-CONTINUATION-AND-CUSTOMER360-GATE-B-002.
        private readonly BlockedCustomerPolicy $blockedCustomers,
        private readonly ConversationTimelineSource $conversations,
        // CRM Portfolio's own composer, reused here so the "current/open
        // follow-ups, next follow-up, priority, overdue" facts can never
        // disagree between the Portfolio list and this profile (TASK-ECOS-
        // CRM-CUSTOMER-PORTFOLIO-AND-FOLLOWUP-003 §15).
        private readonly PortfolioService $portfolio,
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
        $customerId = (string) $customer->id;

        $block = $this->activeBlockFor($customer, $companyId);
        $conversations = $this->conversations->entries($companyId, $customerId);

        return response()->json([
            'data' => [
                ...$this->profiles->profile($customer),
                // Order-derived KPIs and purchased products come from canonical
                // `orders`, never from the customer-intelligence profile.
                'order_metrics' => $this->orderMetrics->forCustomer($customerId, $companyId),
                'purchased_products' => $this->orderMetrics->purchasedProducts($customerId, $companyId),
                // Accounting truth stays in Finance — never recomputed here.
                'finance' => [
                    'balance' => $this->ledger->balance($companyId, $customerId),
                ],
                // Read-only: the blocked-customer authority itself stays on
                // Sales\Customers (see constructor note).
                'blocked' => [
                    'is_blocked' => $block !== null,
                    'reason' => $block?->block_reason,
                    'blocked_at' => $block?->blocked_at?->toIso8601String(),
                    'blocked_by' => $block?->blocked_by,
                ],
                // Reads cep_conversations directly, same as the CRM timeline —
                // no dependency on the CustomerEngagement module's own classes.
                'engagement' => [
                    'conversations_count' => count($conversations),
                    'last_conversation_at' => ($conversations[0] ?? null)?->occurredAt?->toIso8601String(),
                ],
                // Additive CRM section (TASK-ECOS-CRM-CUSTOMER-PORTFOLIO-AND-
                // FOLLOWUP-003 §15) — owner, open follow-ups, next follow-up,
                // recent activity. Bounded on purpose: this stays a summary
                // with an entry point into the Portfolio, not a duplicate of it.
                'crm' => $this->portfolio->crmSectionFor($customer, $companyId),
            ],
        ]);
    }

    /**
     * The active block for this customer, checked by phone then mobile — matching
     * BlockedCustomerPolicy's own "customer_id OR either saved phone/mobile" match
     * semantics (see its activeBlocksForCustomers docblock), just as a single-row
     * lookup instead of a batch one.
     */
    private function activeBlockFor(Customer $customer, string $companyId): ?CustomerBlock
    {
        $phone = $this->blockedCustomers->normalize($customer->phone);
        $block = $phone !== '' ? $this->blockedCustomers->activeBlockForPhone($companyId, $phone) : null;

        if ($block !== null) {
            return $block;
        }

        $mobile = $this->blockedCustomers->normalize($customer->mobile);

        return $mobile !== '' && $mobile !== $phone ? $this->blockedCustomers->activeBlockForPhone($companyId, $mobile) : null;
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
