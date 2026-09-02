<?php

declare(strict_types=1);

namespace Modules\Sales\Customers\Infrastructure\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Customers\Domain\Contracts\CustomerRepositoryInterface;
use Modules\Sales\Customers\Domain\Models\Customer;

final class EloquentCustomerRepository implements CustomerRepositoryInterface
{
    private const SORTABLE = ['code', 'name', 'country', 'city', 'is_active', 'created_at'];

    public function paginate(array $filters): LengthAwarePaginator
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

        $sortBy = (string) ($filters['sort_by'] ?? 'created_at');
        if (! in_array($sortBy, self::SORTABLE, true)) {
            $sortBy = 'created_at';
        }

        $sortDir = strtolower((string) ($filters['sort_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $perPage = max(1, min((int) ($filters['per_page'] ?? 10), 100));

        // Default address only — ONE extra query for the whole page, never one per row.
        return $query
            ->with(['customerBrands.brand', 'addresses' => fn ($a) => $a->where('is_default', true)])
            ->orderBy($sortBy, $sortDir)
            ->paginate($perPage);
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
