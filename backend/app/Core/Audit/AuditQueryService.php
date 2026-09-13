<?php

declare(strict_types=1);

namespace App\Core\Audit;

use App\Core\Company\TenantOwnershipResolver;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * CORE-02 Task 2 §2 — the central Audit read/search surface. Read-only counterpart to
 * {@see AuditService::record()}: queries the same `audit_logs` table that every existing
 * `*AuditService` adapter (IAM, HR, Finance posting excluded — see below, Marketing,
 * Logistics, Engineering, ...) already writes to. No second audit table, no new capture
 * path — this class never inserts a row.
 *
 * Company scoping mirrors every other resource in this codebase: an unrestricted (system)
 * actor may narrow to one company explicitly via `company_id`, but a normal actor's own
 * company — resolved server-side through {@see TenantOwnershipResolver}, never from client
 * input — is the only scope they can ever see (§3/§10).
 */
final class AuditQueryService
{
    private const MAX_PER_PAGE = 100;

    public function __construct(private readonly TenantOwnershipResolver $tenant) {}

    /**
     * @param  array{user_id?: int|null, action?: string|null, entity_type?: string|null, entity_id?: string|null, company_id?: string|null, date_from?: string|null, date_to?: string|null}  $filters
     */
    public function search(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $page = max(1, $page);

        $query = AuditLog::query()->with('actor');

        if ($this->tenant->isUnrestricted()) {
            if (! empty($filters['company_id'])) {
                $query->where('company_id', $filters['company_id']);
            }
        } else {
            $companyId = $this->tenant->companyId();

            if ($companyId === null) {
                return new LengthAwarePaginator([], 0, $perPage, $page);
            }

            $query->where('company_id', $companyId);
        }

        if (! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (! empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        if (! empty($filters['entity_type'])) {
            $query->where('entity_type', $filters['entity_type']);
        }

        if (! empty($filters['entity_id'])) {
            $query->where('entity_id', $filters['entity_id']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('occurred_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('occurred_at', '<=', $filters['date_to']);
        }

        return $query->orderByDesc('occurred_at')->paginate($perPage, ['*'], 'page', $page);
    }
}
