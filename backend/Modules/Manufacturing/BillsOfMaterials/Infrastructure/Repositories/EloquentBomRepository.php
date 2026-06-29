<?php

declare(strict_types=1);

namespace Modules\Manufacturing\BillsOfMaterials\Infrastructure\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Manufacturing\BillsOfMaterials\Domain\Contracts\BomRepositoryInterface;
use Modules\Manufacturing\BillsOfMaterials\Domain\Models\BillOfMaterial;

final class EloquentBomRepository implements BomRepositoryInterface
{
    private const PER_PAGE = 20;

    private const WITH = ['product'];

    private const WITH_DETAIL = ['product', 'lines.rawMaterial.unit'];

    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = BillOfMaterial::with(self::WITH);

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('bom_number', 'like', "%{$search}%")
                    ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$search}%"));
            });
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== 'all') {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        $sortBy = in_array($filters['sort_by'] ?? '', ['bom_number', 'version', 'created_at'], true)
            ? $filters['sort_by']
            : 'created_at';

        $sortDir = ($filters['sort_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sortBy, $sortDir);

        return $query->paginate((int) ($filters['per_page'] ?? self::PER_PAGE));
    }

    public function findById(string $id): ?BillOfMaterial
    {
        return BillOfMaterial::with(self::WITH_DETAIL)->find($id);
    }

    public function create(array $attributes, array $lines): BillOfMaterial
    {
        if ($attributes['is_active'] ?? false) {
            $this->deactivateOthers((string) $attributes['product_id'], null);
        }

        $bom = BillOfMaterial::create($attributes);
        $bom->lines()->createMany($lines);

        return $bom->load(self::WITH_DETAIL);
    }

    public function update(BillOfMaterial $bom, array $attributes, array $lines): BillOfMaterial
    {
        if ($attributes['is_active'] ?? false) {
            $this->deactivateOthers((string) $attributes['product_id'], $bom->id);
        }

        $bom->update($attributes);
        $bom->lines()->delete();
        $bom->lines()->createMany($lines);

        return $bom->load(self::WITH_DETAIL);
    }

    public function delete(BillOfMaterial $bom): void
    {
        $bom->delete();
    }

    public function nextVersionNumber(string $productId): int
    {
        $max = BillOfMaterial::withTrashed()
            ->where('product_id', $productId)
            ->max('bom_version_number');

        return ($max === null ? 0 : (int) $max) + 1;
    }

    public function nextBomNumber(): string
    {
        $last = BillOfMaterial::withTrashed()
            ->where('bom_number', 'like', 'BOM-%')
            ->orderByRaw("CAST(REPLACE(bom_number, 'BOM-', '') AS UNSIGNED) DESC")
            ->value('bom_number');

        if ($last === null) {
            return 'BOM-00001';
        }

        $current = (int) str_replace('BOM-', '', (string) $last);

        return 'BOM-'.str_pad((string) ($current + 1), 5, '0', STR_PAD_LEFT);
    }

    private function deactivateOthers(string $productId, ?string $excludeId): void
    {
        $query = BillOfMaterial::where('product_id', $productId)->where('is_active', true);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        $query->update(['is_active' => false]);
    }
}
