<?php

declare(strict_types=1);

namespace Modules\Crm\Portfolio\Domain\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Commerce\Orders\Domain\Services\CustomerOrderMetricsService;
use Modules\Crm\Customers\Domain\Models\Customer;
use Modules\Crm\Engagement\Domain\Enums\FollowUpQueue;
use Modules\Crm\Engagement\Domain\Enums\TaskStatus;
use Modules\Crm\Engagement\Domain\Models\CustomerTask;
use Modules\Finance\Receivables\Domain\Services\CustomerLedgerService;
use Modules\Sales\Customers\Domain\Services\BlockedCustomerPolicy;
use Modules\Sales\Customers\Domain\Services\PhoneNormalizer;
use Throwable;

/**
 * The CRM Portfolio read model — NOT a new aggregate/table (TASK-ECOS-CRM-
 * CUSTOMER-PORTFOLIO-AND-FOLLOWUP-003 §2/§12). Every canonically visible
 * Customer of the current company is a candidate row; this composes each
 * one with CRM-owned follow-up context plus read-only cross-domain facts,
 * entirely via existing authorities:
 *
 * - identity              -> Crm\Customers\Domain\Models\Customer (canonical)
 * - CRM/Commercial owner  -> customers.sales_owner_id (§3 ratified authority)
 * - follow-up context     -> Crm\Engagement\CustomerTask/CustomerActivity
 * - blocked state         -> Sales\Customers\BlockedCustomerPolicy
 * - Finance balance       -> Finance\Receivables\CustomerLedgerService
 * - Commerce summary      -> Commerce\Orders\CustomerOrderMetricsService
 * - engagement recency    -> cep_conversations, read directly and guarded,
 *                            exactly like Crm\Engagement's own
 *                            ConversationTimelineSource — no dependency on
 *                            CustomerEngagement's classes.
 *
 * §13 bounded-query rule: every one of the facts above is fetched ONE time
 * for the whole page (never once per customer). A page of N customers costs
 * a small, fixed number of queries regardless of N.
 */
final class PortfolioService
{
    public function __construct(
        private readonly CustomerOrderMetricsService $orderMetrics,
        private readonly CustomerLedgerService $ledger,
        private readonly BlockedCustomerPolicy $blockedCustomers,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  search, sales_owner_id, unassigned
     *                                         (bool), blocked (bool), queue
     *                                         (FollowUpQueue value), priority,
     *                                         per_page, page
     */
    public function list(string $companyId, array $filters): LengthAwarePaginator
    {
        $query = Customer::query()->where('company_id', $companyId);

        $this->applySearch($query, (string) ($filters['search'] ?? ''));
        $this->applyOwnerFilter($query, $filters);
        $this->applyBlockedFilter($query, $filters, $companyId);
        $this->applyFollowUpFilters($query, $filters, $companyId);

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->orderBy('name')->paginate(
            (int) ($filters['per_page'] ?? 20),
            ['*'],
            'page',
            (int) ($filters['page'] ?? 1),
        );

        $customers = collect($paginator->items());

        // Six queries total for the WHOLE page, never per row (§13) — computed
        // once here, then each row below is pure in-memory array lookup.
        $ids = $customers->pluck('id')->map(fn ($id) => (string) $id)->all();
        $context = [
            'balances' => $this->ledger->balances($ids, $companyId),
            'orderMetrics' => $this->orderMetrics->forCustomers($ids, $companyId),
            'blocks' => $this->blockedCustomers->activeBlocksForIdentities(
                $customers->map(fn (Customer $c) => ['id' => (string) $c->id, 'phone' => $c->phone, 'mobile' => $c->mobile])->all(),
                $companyId,
            ),
            'followUps' => $this->openFollowUpsByCustomer($ids, $companyId),
            'recentActivity' => $this->recentActivityByCustomer($ids, $companyId),
            'engagement' => $this->engagementRecencyByCustomer($ids, $companyId),
        ];

        $paginator->setCollection($customers->map(fn (Customer $c) => $this->compose($c, $context)));

        return $paginator;
    }

    /** One customer's full Customer 360 CRM section — same facts, same rules, single-row path. */
    public function crmSectionFor(Customer $customer, string $companyId): array
    {
        $ids = [(string) $customer->id];
        $context = [
            'balances' => [],
            'orderMetrics' => [],
            'blocks' => $this->blockedCustomers->activeBlocksForIdentities(
                [['id' => (string) $customer->id, 'phone' => $customer->phone, 'mobile' => $customer->mobile]],
                $companyId,
            ),
            'followUps' => $this->openFollowUpsByCustomer($ids, $companyId),
            'recentActivity' => $this->recentActivityByCustomer($ids, $companyId),
            'engagement' => [],
        ];

        return $this->compose($customer, $context)['crm'];
    }

    /** @param array{balances: array, orderMetrics: array, blocks: array, followUps: array, recentActivity: array, engagement: array} $context */
    private function compose(Customer $customer, array $context): array
    {
        $id = (string) $customer->id;
        $nextFollowUp = $context['followUps'][$id][0] ?? null;
        $block = $context['blocks'][$id] ?? null;

        return [
            'id' => $id,
            'code' => $customer->code,
            'name' => $customer->displayName(),
            'primary_phone' => $customer->phone,
            'sales_owner_id' => $customer->sales_owner_id !== null ? (string) $customer->sales_owner_id : null,
            'sales_owner_name' => $customer->sales_owner_name,
            'is_unassigned' => $customer->sales_owner_id === null,
            'blocked' => [
                'is_blocked' => $block !== null,
                'reason' => $block?->block_reason,
                'blocked_at' => $block?->blocked_at?->toIso8601String(),
                'blocked_by' => $block?->blocked_by,
            ],
            'finance' => ['balance' => $context['balances'][$id] ?? 0.0],
            'commerce' => $context['orderMetrics'][$id] ?? CustomerOrderMetricsService::emptyMetrics(),
            'crm' => [
                'owner' => [
                    'id' => $customer->sales_owner_id !== null ? (string) $customer->sales_owner_id : null,
                    'name' => $customer->sales_owner_name,
                ],
                'open_follow_ups_count' => count($context['followUps'][$id] ?? []),
                'next_follow_up' => $nextFollowUp,
                'recent_activity' => $context['recentActivity'][$id] ?? null,
            ],
            'engagement' => $context['engagement'][$id] ?? ['conversations_count' => 0, 'last_conversation_at' => null],
        ];
    }

    private function applySearch(&$query, string $search): void
    {
        $search = trim($search);
        if ($search === '') {
            return;
        }

        $query->where(function ($q) use ($search): void {
            $q->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%");
        });
    }

    /** @param array<string, mixed> $filters */
    private function applyOwnerFilter(&$query, array $filters): void
    {
        $ownerId = $filters['sales_owner_id'] ?? null;

        if ($ownerId !== null && $ownerId !== '') {
            $query->where('sales_owner_id', $ownerId);

            return;
        }

        if (filter_var($filters['unassigned'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->whereNull('sales_owner_id');
        }
    }

    /** @param array<string, mixed> $filters */
    private function applyBlockedFilter(&$query, array $filters, string $companyId): void
    {
        if (! array_key_exists('blocked', $filters) || $filters['blocked'] === null || $filters['blocked'] === '') {
            return;
        }

        $wantBlocked = filter_var($filters['blocked'], FILTER_VALIDATE_BOOLEAN);
        $phoneExpr = PhoneNormalizer::sqlExpression('customers.phone');
        $mobileExpr = PhoneNormalizer::sqlExpression('customers.mobile');

        $exists = function ($q) use ($companyId, $phoneExpr, $mobileExpr): void {
            $q->from('customer_blocks')
                ->where('customer_blocks.company_id', $companyId)
                ->where('customer_blocks.is_active', true)
                ->where(function ($qq) use ($phoneExpr, $mobileExpr): void {
                    $qq->whereColumn('customer_blocks.customer_id', 'customers.id')
                        ->orWhereRaw("customer_blocks.normalized_phone = {$phoneExpr}")
                        ->orWhereRaw("customer_blocks.normalized_phone = {$mobileExpr}");
                });
        };

        $wantBlocked ? $query->whereExists($exists) : $query->whereNotExists($exists);
    }

    /** @param array<string, mixed> $filters */
    private function applyFollowUpFilters(&$query, array $filters, string $companyId): void
    {
        $queue = $filters['queue'] ?? null;
        $priority = $filters['priority'] ?? null;

        if ($queue === null && $priority === null) {
            return;
        }

        $now = Carbon::now(config('app.timezone'));
        $endOfDay = $now->copy()->endOfDay();

        $query->whereExists(function ($q) use ($companyId, $queue, $priority, $now, $endOfDay): void {
            $q->from('crm_customer_tasks')
                ->whereColumn('crm_customer_tasks.customer_id', 'customers.id')
                ->where('crm_customer_tasks.company_id', $companyId)
                ->where('crm_customer_tasks.status', TaskStatus::Open->value);

            if ($priority !== null) {
                $q->where('crm_customer_tasks.priority', $priority);
            }

            match ($queue) {
                FollowUpQueue::Overdue->value => $q->whereNotNull('crm_customer_tasks.due_at')->where('crm_customer_tasks.due_at', '<', $now),
                FollowUpQueue::DueToday->value => $q->whereNotNull('crm_customer_tasks.due_at')->where('crm_customer_tasks.due_at', '>=', $now)->where('crm_customer_tasks.due_at', '<=', $endOfDay),
                FollowUpQueue::Upcoming->value => $q->whereNotNull('crm_customer_tasks.due_at')->where('crm_customer_tasks.due_at', '>', $endOfDay),
                FollowUpQueue::Unscheduled->value => $q->whereNull('crm_customer_tasks.due_at'),
                default => null,
            };
        });
    }

    /**
     * Every OPEN follow-up for the page, grouped by customer and sorted
     * soonest-due-first (nulls last) — ONE query for the whole page.
     *
     * @param  list<string>  $customerIds
     * @return array<string, list<array<string, mixed>>>
     */
    private function openFollowUpsByCustomer(array $customerIds, string $companyId): array
    {
        if ($customerIds === []) {
            return [];
        }

        return CustomerTask::query()
            ->where('company_id', $companyId)
            ->whereIn('customer_id', $customerIds)
            ->where('status', TaskStatus::Open->value)
            ->orderByRaw('due_at IS NULL')
            ->orderBy('due_at')
            ->get()
            ->groupBy(fn (CustomerTask $t) => (string) $t->customer_id)
            ->map(fn ($tasks) => $tasks->map(fn (CustomerTask $t) => [
                'id' => $t->id,
                'title' => $t->title,
                'priority' => $t->priority,
                'due_at' => $t->due_at?->toIso8601String(),
                'queue' => $t->queue()?->value,
            ])->values()->all())
            ->all();
    }

    /**
     * The single most recent CustomerActivity per customer — ONE query for the
     * whole page, via a per-customer window function rather than a single
     * global LIMIT (a flat `ORDER BY customer_id, occurred_at DESC LIMIT N`
     * can starve customers later in the id ordering on a busy page — the
     * window function partitions per customer, so every customer with any
     * activity gets its own latest row regardless of how much others have).
     *
     * @param  list<string>  $customerIds
     * @return array<string, array<string, mixed>>
     */
    private function recentActivityByCustomer(array $customerIds, string $companyId): array
    {
        if ($customerIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($customerIds), '?'));

        $rows = DB::select(
            "select customer_id, subject, occurred_at from (
                select customer_id, subject, occurred_at,
                       row_number() over (partition by customer_id order by occurred_at desc) as rn
                from crm_customer_activities
                where company_id = ? and customer_id in ({$placeholders})
            ) ranked where rn = 1",
            [$companyId, ...$customerIds],
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row->customer_id] = [
                'subject' => $row->subject,
                'occurred_at' => Carbon::parse($row->occurred_at)->toIso8601String(),
            ];
        }

        return $result;
    }

    /**
     * Conversation count + last conversation timestamp per customer, read
     * directly from `cep_conversations` and guarded exactly like
     * Crm\Engagement\Infrastructure\Timeline\ConversationTimelineSource — no
     * dependency on the CustomerEngagement module's own classes, and no
     * per-customer query.
     *
     * @param  list<string>  $customerIds
     * @return array<string, array{conversations_count: int, last_conversation_at: ?string}>
     */
    private function engagementRecencyByCustomer(array $customerIds, string $companyId): array
    {
        if ($customerIds === [] || ! Schema::hasTable('cep_conversations')) {
            return [];
        }

        try {
            return DB::table('cep_conversations')
                ->whereIn('customer_id', $customerIds)
                ->when(Schema::hasColumn('cep_conversations', 'company_id'), fn ($q) => $q->where('company_id', $companyId))
                ->selectRaw('customer_id, COUNT(*) as conversations_count, MAX(started_at) as last_conversation_at')
                ->groupBy('customer_id')
                ->get()
                ->mapWithKeys(fn ($row) => [(string) $row->customer_id => [
                    'conversations_count' => (int) $row->conversations_count,
                    'last_conversation_at' => $row->last_conversation_at !== null ? Carbon::parse($row->last_conversation_at)->toIso8601String() : null,
                ]])
                ->all();
        } catch (Throwable) {
            return [];
        }
    }
}
