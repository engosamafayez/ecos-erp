<?php

declare(strict_types=1);

namespace Modules\Inventory\InventoryItems\Infrastructure\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\Inventory\InventoryItems\Domain\Contracts\InventoryItemRepositoryInterface;
use Modules\Inventory\InventoryItems\Domain\Models\InventoryItem;
use Modules\Inventory\InventoryItems\Domain\Models\StockLedgerEntry;

final class EloquentInventoryItemRepository implements InventoryItemRepositoryInterface
{
    private const SORTABLE = ['on_hand_qty', 'reserved_qty', 'created_at'];

    public function findOrCreate(string $warehouseId, string $productId, string $companyId): InventoryItem
    {
        return InventoryItem::query()->firstOrCreate(
            ['warehouse_id' => $warehouseId, 'product_id' => $productId],
            ['company_id' => $companyId, 'on_hand_qty' => 0, 'reserved_qty' => 0],
        );
    }

    public function lockForUpdate(string $id): ?InventoryItem
    {
        return InventoryItem::query()->lockForUpdate()->find($id);
    }

    public function save(InventoryItem $item): void
    {
        $item->save();
    }

    public function recordEntry(array $attributes): StockLedgerEntry
    {
        return StockLedgerEntry::query()->create($attributes);
    }

    public function findByWarehouseAndProduct(string $warehouseId, string $productId): ?InventoryItem
    {
        return InventoryItem::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->first();
    }

    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = InventoryItem::query()->with(['warehouse', 'product', 'company']);

        $companyId = trim((string) ($filters['company_id'] ?? ''));
        if ($companyId !== '') {
            $query->where('company_id', $companyId);
        }

        $warehouseId = trim((string) ($filters['warehouse_id'] ?? ''));
        if ($warehouseId !== '') {
            $query->where('warehouse_id', $warehouseId);
        }

        $productId = trim((string) ($filters['product_id'] ?? ''));
        if ($productId !== '') {
            $query->where('product_id', $productId);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $b) use ($search): void {
                $b->whereHas('product', function (Builder $q) use ($search): void {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%");
                })->orWhereHas('warehouse', function (Builder $q) use ($search): void {
                    $q->where('name', 'like', "%{$search}%");
                });
            });
        }

        $sortBy = (string) ($filters['sort_by'] ?? 'created_at');
        if (! in_array($sortBy, self::SORTABLE, true)) {
            $sortBy = 'created_at';
        }

        $sortDir = strtolower((string) ($filters['sort_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $perPage = max(1, min((int) ($filters['per_page'] ?? 25), 100));

        return $query->orderBy($sortBy, $sortDir)->paginate($perPage);
    }
}
