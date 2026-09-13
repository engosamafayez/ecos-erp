<?php

declare(strict_types=1);

namespace Modules\Crm\Customers\Presentation\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Services\CustomerOrderMetricsService;
use Modules\Crm\Customers\Domain\Enums\CustomerStatus;
use Modules\Crm\Customers\Domain\Enums\CustomerType;
use Modules\Crm\Customers\Domain\Models\Customer;
use Modules\Crm\Customers\Domain\Services\Customer360Service;
use Modules\Crm\Customers\Domain\Services\CustomerSearchService;
use Modules\Crm\Customers\Domain\Services\CustomerService;
use Modules\Crm\Customers\Presentation\Http\Controllers\Concerns\ResolvesCustomerContext;
use Modules\Crm\Engagement\Domain\Enums\ActivityType;
use Modules\Crm\Engagement\Domain\Services\ActivityService;
use Modules\Crm\Engagement\Infrastructure\Timeline\ConversationTimelineSource;
use Modules\Crm\Portfolio\Domain\Services\PortfolioService;
use Modules\Crm\Service\Domain\Models\Ticket;
use Modules\Finance\Receivables\Domain\Services\CustomerLedgerService;
use Modules\Sales\Customers\Application\Actions\AssignSalesOwnerAction;
use Modules\Sales\Customers\Application\Actions\BlockCustomerOrPhoneAction;
use Modules\Sales\Customers\Application\Actions\UnblockCustomerAction;
use Modules\Sales\Customers\Domain\Models\CustomerBlock;
use Modules\Sales\Customers\Domain\Services\BlockedCustomerPolicy;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
                // Sales\Customers (see constructor note). `id` is exposed so the
                // canonical drawer's unblock action (CRM-01 Task 1) can target this
                // exact block episode without a second lookup.
                'blocked' => [
                    'id' => $block?->id,
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

    /**
     * CRM-01 TASK 1 — canonical-surface parity with the legacy Sales workspace being
     * retired from navigation. Reuses the same authorities the legacy controller called
     * (BlockCustomerOrPhoneAction/UnblockCustomerAction/AssignSalesOwnerAction all resolve
     * their own row by scalar id — no dependency on the legacy Customer class), so no
     * business logic is duplicated, only re-exposed under `/crm/customers`.
     */
    public function block(Request $request, string $id, BlockCustomerOrPhoneAction $action): JsonResponse
    {
        $companyId = $this->companyId($request);
        $customer = $this->customer($request, $id);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $result = $action->execute($companyId, (string) $customer->id, null, $validated['reason'], $this->actorIdString($request));

        return response()->json(['data' => $result->data(), 'message' => $result->message()]);
    }

    public function blockPhone(Request $request, BlockCustomerOrPhoneAction $action): JsonResponse
    {
        $companyId = $this->companyId($request);
        $validated = $request->validate(['phone' => ['required', 'string', 'max:32'], 'reason' => ['required', 'string', 'max:1000']]);

        $result = $action->execute($companyId, null, $validated['phone'], $validated['reason'], $this->actorIdString($request));

        return response()->json(['data' => $result->data(), 'message' => $result->message()]);
    }

    public function unblock(Request $request, string $id, UnblockCustomerAction $action): JsonResponse
    {
        $companyId = $this->companyId($request);
        $this->customer($request, $id);
        $validated = $request->validate(['block_id' => ['required', 'string'], 'reason' => ['required', 'string', 'max:1000']]);

        $result = $action->execute($companyId, $validated['block_id'], $validated['reason'], $this->actorIdString($request));

        return response()->json(['data' => $result->data(), 'message' => $result->message()]);
    }

    public function blockHistory(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyId($request);
        $customer = $this->customer($request, $id);

        $history = CustomerBlock::query()
            ->where('company_id', $companyId)
            ->where('customer_id', $customer->id)
            ->orderByDesc('blocked_at')
            ->get(['id', 'block_reason', 'blocked_by', 'blocked_at', 'unblock_reason', 'unblocked_by', 'unblocked_at', 'is_active']);

        return response()->json(['data' => $history]);
    }

    /**
     * The single write path for `customers.sales_owner_id`/`sales_owner_name` — same
     * authority the legacy Sales controller used, company-checked twice (customer lookup
     * here, candidate-owner lookup below) exactly as that controller did.
     */
    public function assignOwner(Request $request, string $id, AssignSalesOwnerAction $action, ActivityService $activities): JsonResponse
    {
        $companyId = $this->companyId($request);
        $customer = $this->customer($request, $id);

        $validated = $request->validate(['sales_owner_id' => ['nullable', Rule::exists('users', 'id')]]);
        $ownerId = $validated['sales_owner_id'] ?? null;
        $owner = null;

        if ($ownerId !== null) {
            $owner = User::query()->where('id', $ownerId)->where('company_id', $companyId)->first();

            if ($owner === null) {
                return response()->json([
                    'message' => 'The selected user is not valid for this company.',
                    'errors' => ['sales_owner_id' => ['invalid_company_owner']],
                ], 422);
            }
        }

        $action->execute((string) $customer->id, $owner?->id, $owner !== null ? ($owner->display_name ?? $owner->name) : null);

        $activities->log($companyId, (string) $customer->id, ActivityType::System, [
            'subject' => $owner !== null ? 'Sales owner assigned: '.($owner->display_name ?? $owner->name) : 'Sales owner unassigned',
            'related_type' => 'customer_ownership',
            'actor_id' => $this->actorId($request),
        ]);

        return response()->json(['data' => $this->profiles->identity($customer->refresh())]);
    }

    /** Distinct sales owners already referenced by this company's customers — same read as the legacy workspace offered. */
    public function salesOwnerOptions(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);

        $owners = Customer::query()
            ->where('company_id', $companyId)
            ->whereNotNull('sales_owner_id')
            ->select('sales_owner_id', 'sales_owner_name')
            ->distinct()
            ->orderBy('sales_owner_name')
            ->get()
            ->map(fn ($row) => ['id' => (string) $row->sales_owner_id, 'name' => $row->sales_owner_name])
            ->values();

        return response()->json(['data' => $owners]);
    }

    /**
     * Print/Export — shares the same filters as index() so the exported set always matches
     * what the user is currently looking at, per the canonical search's own filter shape
     * rather than the legacy action's (kept independent — see CRM-01 Task 1 report).
     */
    public function export(Request $request): StreamedResponse
    {
        $companyId = $this->companyId($request);
        $page = $this->search->search($companyId, [...$request->only(['q', 'status', 'type', 'group_id', 'tag_id']), 'per_page' => 10000]);
        $rows = collect($page->items())->map(fn (Customer $c) => $this->profiles->identity($c));

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, ['Code', 'Name', 'Type', 'Status', 'Phone', 'Email', 'Location']);
            foreach ($rows as $r) {
                fputcsv($out, [$r['code'], $r['display_name'], $r['type'], $r['status'], $r['primary_phone'], $r['primary_email'], $r['location']]);
            }
            fclose($out);
        }, 'customers.csv', ['Content-Type' => 'text/csv']);
    }

    private function actorIdString(Request $request): ?string
    {
        $id = $this->actorId($request);

        return $id !== null ? (string) $id : null;
    }

    /**
     * CRM-01 TASK 1 — Customer 360 Orders tab. Reads canonical Commerce Orders directly;
     * CRM never owns a copy of order state (see profile()'s order_metrics/purchased_products,
     * same principle).
     */
    public function orders(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyId($request);
        $customer = $this->customer($request, $id);

        $rows = Order::query()
            ->where('company_id', $companyId)
            ->where('customer_id', $customer->id)
            ->with('channel.brand')
            ->latest('order_date')
            ->limit(100)
            ->get()
            ->map(fn (Order $o) => [
                'id' => $o->id,
                'order_number' => $o->order_number,
                'order_date' => $o->order_date,
                'status' => $o->status?->value,
                'brand' => $o->channel?->brand?->name,
                'total' => (float) $o->total,
                'deposit_amount' => (float) $o->deposit_amount,
                'remaining_balance' => (float) $o->remaining_balance,
                'requested_delivery_date' => $o->requested_delivery_date,
                'shipping_address' => $o->shipping_address,
                'city' => $o->city,
                'governorate' => $o->governorate,
            ]);

        return response()->json(['data' => $rows]);
    }

    /**
     * CRM-01 TASK 1 — Customer 360 Support/Cases reference. Read-only: the ticket
     * management surface itself stays a later CRM part (see CRM-01 Task 1 report §15);
     * this only exposes the existing canonical `customer_id` linkage.
     */
    public function tickets(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyId($request);
        $customer = $this->customer($request, $id);

        $rows = Ticket::query()
            ->where('company_id', $companyId)
            ->where('customer_id', $customer->id)
            ->latest('created_at')
            ->limit(50)
            ->get(['id', 'ticket_number', 'subject', 'status', 'priority', 'created_at', 'resolved_at', 'closed_at'])
            ->map(fn (Ticket $t) => [
                'id' => $t->id,
                'ticket_number' => $t->ticket_number,
                'subject' => $t->subject,
                'status' => $t->status?->value,
                'priority' => $t->priority?->value,
                'created_at' => $t->created_at?->toIso8601String(),
                'resolved_at' => $t->resolved_at?->toIso8601String(),
                'closed_at' => $t->closed_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $rows]);
    }
}
