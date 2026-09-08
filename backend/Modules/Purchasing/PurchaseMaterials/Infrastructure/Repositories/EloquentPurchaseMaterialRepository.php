<?php

declare(strict_types=1);

namespace Modules\Purchasing\PurchaseMaterials\Infrastructure\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\Purchasing\PurchaseMaterials\Domain\Contracts\PurchaseMaterialRepositoryInterface;
use Modules\Purchasing\PurchaseMaterials\Domain\Models\PurchaseMaterial;

final class EloquentPurchaseMaterialRepository implements PurchaseMaterialRepositoryInterface
{
    private const SORTABLE = [
        'request_number', 'priority', 'required_date', 'status',
        'estimated_value', 'created_at', 'submitted_at',
    ];

    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = PurchaseMaterial::query()->with(['company', 'warehouse', 'channel', 'buyer', 'lines.product', 'lines.supplier']);

        // Inline aggregates for list columns
        $query->withCount('lines as items_count')
            ->withSum('lines as total_requested_qty', 'requested_qty');

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $b) use ($search): void {
                $b->where('request_number', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        // Material Requests and Purchases are the same table split by record_type;
        // honouring the filter is what keeps the two workspaces from showing an
        // identical list. TASK-PROCUREMENT-MANUAL-REMEDIATION-001.
        $recordType = trim((string) ($filters['record_type'] ?? ''));
        if ($recordType !== '' && $recordType !== 'all') {
            $query->where('record_type', $recordType);
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        $priority = trim((string) ($filters['priority'] ?? ''));
        if ($priority !== '' && $priority !== 'all') {
            $query->where('priority', $priority);
        }

        $warehouseId = trim((string) ($filters['warehouse_id'] ?? ''));
        if ($warehouseId !== '') {
            $query->where('warehouse_id', $warehouseId);
        }

        $companyId = trim((string) ($filters['company_id'] ?? ''));
        if ($companyId !== '') {
            $query->where('company_id', $companyId);
        }

        $channelId = trim((string) ($filters['channel_id'] ?? ''));
        if ($channelId !== '') {
            $query->where('channel_id', $channelId);
        }

        $buyer = trim((string) ($filters['assigned_buyer'] ?? ''));
        if ($buyer !== '') {
            $query->where('assigned_buyer', $buyer);
        }

        // Canonical-identity ownership filter (TASK-...-011 §5/§18) — distinct from the legacy
        // free-text `assigned_buyer` match above, which stays only for callers still on it.
        $buyerId = trim((string) ($filters['assigned_buyer_id'] ?? ''));
        if ($buyerId !== '') {
            $query->where('assigned_buyer_id', $buyerId);
        }

        if (! empty($filters['unowned'])) {
            $query->whereNull('assigned_buyer_id');
        }

        // "Required soon" mirrors the Hub card definition: not yet fulfilled, due within the next
        // 3 days (including already-overdue, so the card and this filter never disagree).
        if (! empty($filters['required_soon'])) {
            $query->whereNotIn('status', ['completed', 'cancelled', 'rejected'])
                ->whereNotNull('required_date')
                ->where('required_date', '<=', now()->addDays(3)->toDateString());
        }

        if (! empty($filters['overdue'])) {
            $query->whereNotIn('status', ['completed', 'cancelled', 'rejected'])
                ->whereNotNull('required_date')
                ->where('required_date', '<', now()->toDateString());
        }

        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        if ($dateFrom !== '') {
            $query->where('required_date', '>=', $dateFrom);
        }

        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        if ($dateTo !== '') {
            $query->where('required_date', '<=', $dateTo);
        }

        $sortBy = (string) ($filters['sort_by'] ?? 'created_at');
        if (! in_array($sortBy, self::SORTABLE, true)) {
            $sortBy = 'created_at';
        }

        $sortDir = strtolower((string) ($filters['sort_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $perPage = max(1, min((int) ($filters['per_page'] ?? 15), 100));

        return $query->orderBy($sortBy, $sortDir)->paginate($perPage);
    }

    public function findById(string $id): ?PurchaseMaterial
    {
        return PurchaseMaterial::query()
            ->with(['company', 'warehouse', 'channel', 'buyer', 'lines.product', 'lines.supplier'])
            ->find($id);
    }

    public function create(array $attributes, array $lines): PurchaseMaterial
    {
        $material = PurchaseMaterial::query()->create($attributes);
        if (! empty($lines)) {
            $material->lines()->createMany($lines);
        }

        return $this->findById((string) $material->id) ?? $material;
    }

    public function update(PurchaseMaterial $material, array $attributes, array $lines): PurchaseMaterial
    {
        $material->update($attributes);
        $material->lines()->delete();
        if (! empty($lines)) {
            $material->lines()->createMany($lines);
        }

        return $this->findById((string) $material->id) ?? $material->refresh();
    }

    public function delete(PurchaseMaterial $material): void
    {
        $material->delete();
    }

    public function nextRequestNumber(): string
    {
        // Numbering is GLOBAL across tenants — the DB enforces a unique index on
        // request_number alone. The tenant global scope must be lifted here or a
        // company-restricted actor would only scan their own rows and restart the
        // sequence, colliding across companies. See PurchaseMaterialNumberGenerationTest.
        $last = PurchaseMaterial::query()
            ->withoutGlobalScope('tenant')
            ->withTrashed()
            ->orderByRaw("CAST(REPLACE(request_number, 'PM-', '') AS UNSIGNED) DESC")
            ->value('request_number');

        if ($last === null) {
            return 'PM-00001';
        }

        $current = (int) str_replace('PM-', '', (string) $last);

        return 'PM-'.str_pad((string) ($current + 1), 5, '0', STR_PAD_LEFT);
    }
}
