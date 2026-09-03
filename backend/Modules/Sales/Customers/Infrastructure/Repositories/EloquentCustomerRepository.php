<?php

declare(strict_types=1);

namespace Modules\Sales\Customers\Infrastructure\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Commerce\Orders\Domain\Services\CustomerOrderMetricsService;
use Modules\Sales\Customers\Domain\Contracts\CustomerRepositoryInterface;
use Modules\Sales\Customers\Domain\Models\Customer;
use Modules\Sales\Customers\Domain\Services\PhoneNormalizer;

final class EloquentCustomerRepository implements CustomerRepositoryInterface
{
    private const SORTABLE = ['code', 'name', 'country', 'city', 'is_active', 'created_at'];

    /**
     * TASK-...-FINAL-UI-CLOSURE-014 (§11) — a defensive cap on allMatching(), never a
     * real pagination mechanism: Print/Export must cover the full filtered population,
     * but an unbounded query is still not safe to promise for an arbitrarily large
     * company. Chosen well above any realistic current tenant size, not a business rule.
     */
    private const MAX_EXPORT_ROWS = 10000;

    /**
     * Customer Intelligence sort fields (TASK-ECOS-COMMERCE-CUSTOMERS-BATCH-02-CUSTOMER-
     * INTELLIGENCE-008) — not real columns on `customers`, so they sort by a correlated
     * subquery over `orders` instead of ->orderBy($column). Same qualifying-order scope
     * as CustomerOrderMetricsService::forCustomers() (company_id + soft-delete only, no
     * status filter) so the sort order always agrees with the displayed totals.
     */
    private const AGGREGATE_SORTABLE = ['total_order_value', 'orders_count', 'last_order_at'];

    public function paginate(array $filters): LengthAwarePaginator
    {
        $perPage = max(1, min((int) ($filters['per_page'] ?? 10), 100));

        // Default address only — ONE extra query for the whole page, never one per row.
        return $this->buildQuery($filters)
            ->with(['customerBrands.brand', 'addresses' => fn ($a) => $a->where('is_default', true)])
            ->paginate($perPage);
    }

    /**
     * TASK-...-FINAL-UI-CLOSURE-014 (§10/§11) — the full filtered+sorted population for
     * Print/Export, never just the current page and never the browser re-deriving it from
     * paginated fetches. Same filter/sort logic as paginate() (buildQuery() is shared), so
     * the two can never disagree about which Customers match. Capped by MAX_EXPORT_ROWS —
     * a safety bound, not a second pagination mechanism.
     */
    public function allMatching(array $filters): Collection
    {
        return $this->buildQuery($filters)
            ->with(['customerBrands.brand', 'addresses' => fn ($a) => $a->where('is_default', true)])
            ->limit(self::MAX_EXPORT_ROWS)
            ->get();
    }

    /**
     * All filter + sort logic shared by paginate() and allMatching() — the list view and
     * Print/Export must never be able to disagree about which Customers match.
     */
    private function buildQuery(array $filters): Builder
    {
        $query = Customer::query();

        $companyId = trim((string) ($filters['company_id'] ?? ''));
        if ($companyId !== '') {
            $query->where('company_id', $companyId);
        }

        $brandId = trim((string) ($filters['brand_id'] ?? ''));
        if ($brandId !== '') {
            $query->whereHas('customerBrands', function (Builder $builder) use ($brandId): void {
                $builder->where('brand_id', $brandId);
            });
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            // Phone/mobile matching is load-bearing for the Phone First workspace (DD-055):
            // the smart search box and CustomerFormDrawer's pre-submit duplicate check both
            // go through this same generic search, not the dedicated /search-by-phone
            // exact-match action. Without it, searching an existing customer's exact phone
            // number always fell through to zero-results/create-new.
            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('contact_person', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('mobile', 'like', "%{$search}%");
            });
        }

        $status = (string) ($filters['status'] ?? 'all');
        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        $country = trim((string) ($filters['country'] ?? ''));
        if ($country !== '') {
            $query->where('country', $country);
        }

        $city = trim((string) ($filters['city'] ?? ''));
        if ($city !== '') {
            $query->where('city', $city);
        }

        // TASK-...-FINAL-UI-CLOSURE-014 (§15) — Sales Owner filter, over the existing
        // denormalised sales_owner_id column (Customers Batch 02). "Unassigned" is an
        // honest NULL check, never a sentinel id — mutually exclusive with a specific
        // owner id, matching how the frontend control presents them.
        $salesOwnerId = trim((string) ($filters['sales_owner_id'] ?? ''));
        if ($salesOwnerId !== '') {
            $query->where('sales_owner_id', $salesOwnerId);
        } elseif (filter_var($filters['unassigned_sales_owner'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->whereNull('sales_owner_id');
        }

        // TASK-...-FINAL-UI-CLOSURE-014 (§16) — Channel filter. There is no `channel`
        // column on `customers` — Channel is order-level — so "this Customer's channel"
        // is always a derived read over its own qualifying orders, same ordersSubquery()
        // shape product_id already uses below.
        $channelId = trim((string) ($filters['channel_id'] ?? ''));
        if ($channelId !== '') {
            $query->where(
                $this->ordersSubquery()->where('orders.channel_id', $channelId)->selectRaw('COUNT(*)'),
                '>=',
                1,
            );
        }

        // TASK-...-FINAL-UI-CLOSURE-014-R1 (§2) — Top Spenders: a real backend-
        // authoritative population SEGMENT (top 20% of eligible Customers by
        // total_order_value), deliberately separate from the "Highest Spend" SORT
        // (sort_by=total_order_value) below/elsewhere — this never touches sort_by/
        // sort_dir, and the sort is untouched by this filter.
        if (filter_var($filters['top_spenders'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $this->applyTopSpendersFilter($query, $companyId);
        }

        // Repeat Customers — orders_count >= REPEAT_ORDER_THRESHOLD, same threshold and
        // same qualifying-order scope as CustomerOrderMetricsService.
        if (filter_var($filters['repeat_only'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->where(
                $this->ordersSubquery()->selectRaw('COUNT(*)'),
                '>=',
                CustomerOrderMetricsService::REPEAT_ORDER_THRESHOLD,
            );
        }

        // TASK-...-FINAL-UI-CLOSURE-014 (§18) — Order Activity classification. Reuses the
        // SAME ordersSubquery()/REPEAT_ORDER_THRESHOLD 'repeat_only' above already uses —
        // never a second repeat-customer definition. Independent of repeat_only (the two
        // compose harmlessly — both express the identical condition when both are sent).
        $orderActivity = (string) ($filters['order_activity'] ?? 'all');
        if ($orderActivity === 'no_orders') {
            $query->where($this->ordersSubquery()->selectRaw('COUNT(*)'), '=', 0);
        } elseif ($orderActivity === 'one_time') {
            $query->where($this->ordersSubquery()->selectRaw('COUNT(*)'), '=', 1);
        } elseif ($orderActivity === 'repeat') {
            $query->where(
                $this->ordersSubquery()->selectRaw('COUNT(*)'),
                '>=',
                CustomerOrderMetricsService::REPEAT_ORDER_THRESHOLD,
            );
        }

        // TASK-...-BLOCKED-CUSTOMERS-009-R1 (§4) — Blocked Customers filter/segment.
        // The original whereColumn version compared customer_blocks.normalized_phone
        // (digits, country-code-prefixed) directly against customers.phone/mobile
        // (raw, unnormalized), so a differently-formatted but equivalent phone never
        // matched. Fixed below via applyBlockedOnlyFilter() — see its own docblock.
        if (filter_var($filters['blocked_only'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $this->applyBlockedOnlyFilter($query, $companyId);
        } elseif (filter_var($filters['not_blocked_only'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            // TASK-...-FINAL-UI-CLOSURE-014 (§17) — the honest inverse of the same
            // identity match applyBlockedOnlyFilter() uses, never a separate/weaker
            // definition of "blocked".
            $this->applyNotBlockedFilter($query, $companyId);
        }

        // Product-specific repeat buyers ("customers who bought Product X repeatedly") —
        // backend-authoritative, never a client-side filter of the current page. Defaults
        // to the same repeat threshold as Repeat Customers unless the caller overrides it.
        $productId = trim((string) ($filters['product_id'] ?? ''));
        if ($productId !== '') {
            $minPurchases = max(1, (int) ($filters['min_purchase_count'] ?? CustomerOrderMetricsService::REPEAT_ORDER_THRESHOLD));

            $query->where(
                $this->ordersSubquery()
                    ->join('order_lines as ol', 'ol.order_id', '=', 'orders.id')
                    ->where('ol.product_id', $productId)
                    ->selectRaw('COUNT(DISTINCT orders.id)'),
                '>=',
                $minPurchases,
            );
        }

        $sortBy = (string) ($filters['sort_by'] ?? 'created_at');
        $sortDir = strtolower((string) ($filters['sort_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        if (in_array($sortBy, self::AGGREGATE_SORTABLE, true)) {
            $query->orderBy($this->aggregateSortSubquery($sortBy), $sortDir);
        } else {
            $query->orderBy(in_array($sortBy, self::SORTABLE, true) ? $sortBy : 'created_at', $sortDir);
        }

        return $query;
    }

    /**
     * TASK-ECOS-COMMERCE-CUSTOMERS-BATCH-02-BLOCKED-CUSTOMERS-009-R1 (§4).
     *
     * Dispatches to whichever of the two strategies below fits the caller's scope.
     * The common case (a real tenant request, $companyId set) gets the cheap ONE-
     * QUERY-FOR-THE-WHOLE-PAGE path; the documented super-admin cross-company case
     * (index()'s own comment: CurrentCompanyService::id() can be null) falls back
     * to a correlated per-row check, since there is no single company's block list
     * to pre-fetch. Both paths compare against PhoneNormalizer::sqlExpression() —
     * the SAME normalization rules normalize() uses in PHP, restated in SQL rather
     * than duplicated ad hoc — so a customer whose saved phone is a differently
     * formatted equivalent of a blocked number (e.g. "0100 123 4567" vs the block's
     * normalized "201001234567") is no longer missed.
     */
    private function applyBlockedOnlyFilter(Builder $query, string $companyId): void
    {
        if ($companyId === '') {
            $query->whereExists(function (QueryBuilder $q): void {
                $q->select(DB::raw(1))
                    ->from('customer_blocks')
                    ->whereColumn('customer_blocks.company_id', 'customers.company_id')
                    ->where('customer_blocks.is_active', true)
                    ->where(function (QueryBuilder $q2): void {
                        $q2->whereColumn('customer_blocks.customer_id', 'customers.id')
                            ->orWhereRaw('customer_blocks.normalized_phone = '.PhoneNormalizer::sqlExpression('customers.phone'))
                            ->orWhereRaw('customer_blocks.normalized_phone = '.PhoneNormalizer::sqlExpression('customers.mobile'));
                    });
            });

            return;
        }

        // ONE query for the whole page, not one per customer: every active block for
        // THIS company is read once — bounded by how many blocks exist, never by how
        // many customers do — then the main query matches against that fixed, small
        // list. A phone-first block (customer_id still null) still surfaces a Customer
        // whose saved phone/mobile normalizes to the same digits.
        $blocks = DB::table('customer_blocks')
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->select('customer_id', 'normalized_phone')
            ->get();

        $customerIds = $blocks->pluck('customer_id')->filter()->map(fn ($id) => (string) $id)->unique()->values()->all();
        $normalizedPhones = $blocks->pluck('normalized_phone')->filter()->unique()->values()->all();

        if ($customerIds === [] && $normalizedPhones === []) {
            // No active block in this company — the filter must yield zero rows,
            // not "unfiltered".
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $q) use ($customerIds, $normalizedPhones): void {
            if ($customerIds !== []) {
                $q->orWhereIn('id', $customerIds);
            }
            if ($normalizedPhones !== []) {
                $q->orWhereIn(DB::raw(PhoneNormalizer::sqlExpression('phone')), $normalizedPhones);
                $q->orWhereIn(DB::raw(PhoneNormalizer::sqlExpression('mobile')), $normalizedPhones);
            }
        });
    }

    /**
     * TASK-...-FINAL-UI-CLOSURE-014 (§17) — the honest inverse of
     * applyBlockedOnlyFilter(): a Customer matches only when NEITHER its id NOR its
     * saved phone/mobile (normalized) appears in this company's current active-block
     * list. Mirrors that method's two strategies (scoped vs. the documented unscoped/
     * super-admin case) so "Blocked" and "Not Blocked" can never both — or neither —
     * match the same Customer. PhoneNormalizer::sqlExpression() already COALESCEs a
     * NULL phone/mobile to '', which never equals a real (non-empty) blocked number —
     * so a Customer with no saved mobile is correctly never excluded by the mobile leg.
     */
    private function applyNotBlockedFilter(Builder $query, string $companyId): void
    {
        if ($companyId === '') {
            $query->whereNotExists(function (QueryBuilder $q): void {
                $q->select(DB::raw(1))
                    ->from('customer_blocks')
                    ->whereColumn('customer_blocks.company_id', 'customers.company_id')
                    ->where('customer_blocks.is_active', true)
                    ->where(function (QueryBuilder $q2): void {
                        $q2->whereColumn('customer_blocks.customer_id', 'customers.id')
                            ->orWhereRaw('customer_blocks.normalized_phone = '.PhoneNormalizer::sqlExpression('customers.phone'))
                            ->orWhereRaw('customer_blocks.normalized_phone = '.PhoneNormalizer::sqlExpression('customers.mobile'));
                    });
            });

            return;
        }

        $blocks = DB::table('customer_blocks')
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->select('customer_id', 'normalized_phone')
            ->get();

        $customerIds = $blocks->pluck('customer_id')->filter()->map(fn ($id) => (string) $id)->unique()->values()->all();
        $normalizedPhones = $blocks->pluck('normalized_phone')->filter()->unique()->values()->all();

        if ($customerIds === [] && $normalizedPhones === []) {
            // No active block in this company at all — every Customer is "not blocked".
            return;
        }

        if ($customerIds !== []) {
            $query->whereNotIn('id', $customerIds);
        }
        if ($normalizedPhones !== []) {
            $query->whereNotIn(DB::raw(PhoneNormalizer::sqlExpression('phone')), $normalizedPhones);
            $query->whereNotIn(DB::raw(PhoneNormalizer::sqlExpression('mobile')), $normalizedPhones);
        }
    }

    /**
     * TASK-ECOS-COMMERCE-CUSTOMERS-BATCH-02-FINAL-UI-CLOSURE-014-R1 (§2/§6/§7) —
     * CTO-approved Top Spenders population segment.
     *
     * Eligible population: this company's Customers with at least one qualifying
     * order (orders_count > 0) — the SAME qualifying-order scope every other filter
     * in this class uses (tenant + soft-delete only, no status filter) and the SAME
     * total_order_value definition CustomerOrderMetricsService::forCustomers()
     * computes (COALESCE(SUM(total), 0)) / aggregateSortSubquery('total_order_value')
     * already uses below — no new spend metric is created here.
     *
     * Segment = every eligible Customer whose total_order_value is >= the value at
     * the top-20%-of-N cutoff rank (§7 — never a hard top-N cut that would
     * arbitrarily exclude Customers tied with the last included one). Computed
     * against the WHOLE eligible tenant population; other active filters intersect
     * with this fixed set via the normal AND-composed WHERE this adds — they never
     * narrow the population §6's percentile is computed over (§3).
     */
    private function applyTopSpendersFilter(Builder $query, string $companyId): void
    {
        // The segment is defined per-tenant (§2: "same tenant/company only"). There
        // is no single population to rank against in the documented unscoped/
        // super-admin case, so this correctly yields zero matches there rather than
        // silently ignoring the filter — which would desync the button's active
        // state from the real query, exactly the bug class this task exists to fix.
        if ($companyId === '') {
            $query->whereRaw('1 = 0');

            return;
        }

        $cutoff = $this->topSpenderCutoffValue($companyId);

        if ($cutoff === null) {
            // No eligible (orders_count > 0) Customer in this company at all.
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(
            $this->ordersSubquery()->selectRaw('COALESCE(SUM(orders.total), 0)'),
            '>=',
            $cutoff,
        );
    }

    /**
     * The total_order_value AT the 20% cutoff rank for this company, or null when no
     * Customer here has ever placed a qualifying order. TWO bounded queries scoped
     * to this one company — never per-row, never per-customer, never fetching every
     * eligible Customer's full row into PHP just to rank them.
     */
    private function topSpenderCutoffValue(string $companyId): ?float
    {
        $eligibleCount = DB::table('orders')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->distinct()
            ->count('customer_id');

        if ($eligibleCount === 0) {
            return null;
        }

        // ceil(N * 0.20), minimum 1 when N > 0 (§7) — ceil() of any N >= 1 already
        // guarantees >= 1; max() here documents the requirement, not a real branch.
        $targetCount = max(1, (int) ceil($eligibleCount * 0.20));

        $cutoff = DB::table('orders')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->groupBy('customer_id')
            ->selectRaw('COALESCE(SUM(total), 0) AS total_order_value')
            // Stable tiebreak on a natural key, matching this codebase's established
            // convention (see CustomerOrderMetricsService::preferredGovernorateForCustomers'
            // own docblock) — irrelevant to the VALUE returned (tied rows share the
            // same value); kept only so the query plan is deterministic.
            ->orderByDesc('total_order_value')
            ->orderBy('customer_id')
            ->offset($targetCount - 1)
            ->limit(1)
            ->value('total_order_value');

        return $cutoff !== null ? (float) $cutoff : null;
    }

    /**
     * Correlated subquery scoped to THIS row's own customer_id/company_id — the same
     * qualifying-order scope CustomerOrderMetricsService::forCustomers() uses (tenant +
     * soft-delete only, no status filter). Callers add their own select()/aggregate.
     */
    private function ordersSubquery(): QueryBuilder
    {
        return DB::table('orders')
            ->whereColumn('orders.customer_id', 'customers.id')
            ->whereColumn('orders.company_id', 'customers.company_id')
            ->whereNull('orders.deleted_at');
    }

    private function aggregateSortSubquery(string $sortBy): QueryBuilder
    {
        return match ($sortBy) {
            'total_order_value' => $this->ordersSubquery()->selectRaw('COALESCE(SUM(orders.total), 0)'),
            'orders_count' => $this->ordersSubquery()->selectRaw('COUNT(*)'),
            'last_order_at' => $this->ordersSubquery()->selectRaw('MAX(orders.order_date)'),
        };
    }

    public function findById(string $id, ?string $companyId): ?Customer
    {
        return Customer::query()
            ->with(['customerBrands.brand', 'addresses' => fn ($a) => $a->where('is_default', true)])
            // Scoped whenever there IS a company context. Null is the documented
            // unrestricted case and matches how paginate() already behaves.
            ->when($companyId !== null && $companyId !== '', fn ($q) => $q->where('company_id', $companyId))
            ->find($id);
    }

    public function create(array $attributes): Customer
    {
        return Customer::query()->create($attributes);
    }

    public function update(Customer $customer, array $attributes): Customer
    {
        $customer->update($attributes);

        return $customer->refresh();
    }

    public function delete(Customer $customer): void
    {
        $customer->delete();
    }

    /**
     * TASK-ECOS-COMMERCE-CUSTOMERS-BATCH-02-TASK-2-REMEDIATION-007-R1.
     *
     * Replaces the original count()+1+lockForUpdate() design (the pattern still used,
     * unfixed, by Brand/BusinessAccount/Team — see the remediation report). That design
     * was both logically incorrect (row COUNT is not the same quantity as "highest
     * CUST-NNNNNN suffix in use" the moment any gap, soft-delete, or coexisting
     * legacy/manual code exists — it eventually regenerates an already-taken code and
     * the create fails with a raw duplicate-key error) and concurrency-unsafe (InnoDB gap
     * locks taken by a SELECT ... FOR UPDATE over ZERO matching rows are mutually
     * COMPATIBLE across transactions — they only block a later INSERT, not another
     * transaction's identical locking SELECT — so two concurrent "first customer for this
     * company" requests both observe count()=0 and race to insert the same code).
     *
     * This design uses a dedicated per-company sequence row (customer_code_sequences),
     * mirroring the proven counter-table pattern already live in production for POS
     * Return numbering (SequentialReturnNumberingStrategy / pos_return_counters), adapted
     * because — unlike a brand-new POS sequence — this table is being retrofitted onto a
     * column that may already hold codes from the old algorithm or from manual entry: see
     * highestExistingCodeSuffix() below.
     *
     * MUST be called inside the same DB transaction the caller uses to insert the new
     * Customer: the row-level exclusive lock taken by the increment() below is only held
     * for that transaction's duration, and the read-back immediately after it relies on
     * that lock still being held (read-your-own-write) so no concurrent increment can be
     * interleaved between "increment" and "read the value back."
     */
    public function nextCodeNumber(string $companyId): int
    {
        $this->ensureCodeSequenceRow($companyId);

        DB::table('customer_code_sequences')
            ->where('company_id', $companyId)
            ->increment('next_number');

        return (int) DB::table('customer_code_sequences')
            ->where('company_id', $companyId)
            ->value('next_number');
    }

    /**
     * Creates this company's sequence row on first use only. Concurrency-safe for the
     * "two requests race to create the very first row for this company" case:
     * insertOrIgnore() compiles to MySQL's INSERT IGNORE, and InnoDB makes a second
     * transaction's INSERT of the same (already-primary-key) company_id BLOCK behind a
     * first transaction's still-uncommitted insert of that same key, then re-check once
     * it resolves — it does not race past it the way a lockForUpdate() SELECT over zero
     * rows does. Whichever transaction's insert actually lands wins the bootstrap value;
     * the loser's insert is silently ignored and it proceeds straight to increment().
     */
    private function ensureCodeSequenceRow(string $companyId): void
    {
        $exists = DB::table('customer_code_sequences')
            ->where('company_id', $companyId)
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('customer_code_sequences')->insertOrIgnore([
            'company_id' => $companyId,
            'next_number' => $this->highestExistingCodeSuffix($companyId),
        ]);
    }

    /**
     * The highest numeric suffix among this company's EXISTING canonical CUST-NNNNNN
     * codes (0 if none) — across trashed rows too, since a soft-deleted row's code still
     * occupies the (company_id, code) unique index and must never be reissued. The strict
     * REGEXP means legacy/manual codes outside the canonical namespace (e.g. "SUPPLIER-A")
     * can never inflate or corrupt the bootstrap value — only real CUST-NNNNNN rows count.
     * Runs once per company, the first time nextCodeNumber() is ever called for it; every
     * call after that hits the cheap exists() fast path above instead.
     */
    private function highestExistingCodeSuffix(string $companyId): int
    {
        $max = Customer::query()
            ->withTrashed()
            ->where('company_id', $companyId)
            ->whereRaw("code REGEXP '^CUST-[0-9]{6}$'")
            ->selectRaw('MAX(CAST(SUBSTRING(code, 6) AS UNSIGNED)) as max_suffix')
            ->value('max_suffix');

        return (int) $max;
    }
}
