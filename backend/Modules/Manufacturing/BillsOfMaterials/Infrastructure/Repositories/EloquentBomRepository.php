<?php

declare(strict_types=1);

namespace Modules\Manufacturing\BillsOfMaterials\Infrastructure\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\CostManagement\Domain\Services\ProductCostCalculator;
use Modules\Manufacturing\BillsOfMaterials\Domain\Contracts\BomRepositoryInterface;
use Modules\Manufacturing\BillsOfMaterials\Domain\Models\BillOfMaterial;

final class EloquentBomRepository implements BomRepositoryInterface
{
    private const PER_PAGE = 20;

    public function __construct(private readonly ProductCostCalculator $productCostCalculator) {}

    /**
     * Relations for list endpoints — no lines, but includes withTrashed on category
     * so that soft-deleted categories still display instead of appearing null.
     *
     * @return array<int|string, mixed>
     */
    private function getWith(): array
    {
        return [
            'product.channelMappings.channel.brand.company',
            'product.category' => fn ($q) => $q->withTrashed(),
        ];
    }

    /**
     * Relations for detail/create/update endpoints — includes lines with raw material data.
     *
     * @return array<int|string, mixed>
     */
    private function getWithDetail(): array
    {
        return [
            'product.channelMappings.channel.brand.company',
            'product.category' => fn ($q) => $q->withTrashed(),
            'lines.rawMaterial.unit',
        ];
    }

    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = BillOfMaterial::with($this->getWith())
            ->withCount('lines')
            ->addSelect([
                'bills_of_materials.*',
                DB::raw(
                    '(SELECT COALESCE(SUM(l.quantity * l.waste_percentage) / NULLIF(SUM(l.quantity), 0), 0)'
                    . ' FROM bill_of_material_lines l'
                    . ' WHERE l.bom_id = bills_of_materials.id) AS total_waste_pct'
                ),
            ]);

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('bom_number', 'like', "%{$search}%")
                    ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%"));
            });
        }

        if (isset($filters['product_id']) && $filters['product_id'] !== '') {
            $query->where('product_id', (string) $filters['product_id']);
        }

        $companyId = trim((string) ($filters['company_id'] ?? ''));
        if ($companyId !== '') {
            $query->whereHas(
                'product.channelMappings.channel',
                fn ($q) => $q->where('company_id', $companyId),
            );
        }

        $channelId = trim((string) ($filters['channel_id'] ?? ''));
        if ($channelId !== '') {
            $query->whereHas(
                'product.channelMappings',
                fn ($q) => $q->where('channel_id', $channelId),
            );
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== 'all') {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        if (!empty($filters['has_manufacturing_cost'])) {
            $query->where('manufacturing_cost', '>', 0);
        }

        if (!empty($filters['has_packaging_materials'])) {
            $query->whereHas('lines.rawMaterial', fn ($q) => $q->where('product_type', 'packaging_material'));
        }

        if (!empty($filters['updated_from'])) {
            $query->where('updated_at', '>=', $filters['updated_from']);
        }

        if (!empty($filters['updated_to'])) {
            $query->where('updated_at', '<=', $filters['updated_to'].' 23:59:59');
        }

        $sortDir = ($filters['sort_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        match ($filters['sort_by'] ?? '') {
            'bom_number'      => $query->orderBy('bills_of_materials.bom_number', $sortDir),
            'version'         => $query->orderBy('bills_of_materials.version', $sortDir),
            'recipe_cost'     => $query->orderBy('bills_of_materials.recipe_cost', $sortDir),
            'total_waste_pct' => $query->orderBy('total_waste_pct', $sortDir),
            'lines_count'     => $query->orderBy('lines_count', $sortDir),
            'updated_at'      => $query->orderBy('bills_of_materials.updated_at', $sortDir),
            'product_name'    => $query->orderByRaw(
                "(SELECT name FROM products WHERE id = bills_of_materials.product_id) {$sortDir}"
            ),
            'category'        => $query->orderByRaw(
                "(SELECT c.name FROM categories c"
                . " JOIN products p ON p.category_id = c.id"
                . " WHERE p.id = bills_of_materials.product_id) {$sortDir}"
            ),
            default           => $query->orderBy('bills_of_materials.created_at', $sortDir),
        };

        return $query->paginate((int) ($filters['per_page'] ?? self::PER_PAGE));
    }

    public function findById(string $id): ?BillOfMaterial
    {
        return BillOfMaterial::with($this->getWithDetail())->find($id);
    }

    public function create(array $attributes, array $lines): BillOfMaterial
    {
        if ($attributes['is_active'] ?? false) {
            $this->deactivateOthers((string) $attributes['product_id'], null);
        }

        $bom = BillOfMaterial::create($attributes);
        $bom->lines()->createMany($lines);
        $this->recomputeRecipeCost($bom);

        return $bom->load($this->getWithDetail());
    }

    public function update(BillOfMaterial $bom, array $attributes, array $lines): BillOfMaterial
    {
        if ($attributes['is_active'] ?? false) {
            $this->deactivateOthers((string) $attributes['product_id'], $bom->id);
        }

        $bom->update($attributes);
        $bom->lines()->delete();
        $bom->lines()->createMany($lines);
        $this->recomputeRecipeCost($bom);

        return $bom->load($this->getWithDetail());
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

    /**
     * Recompute and persist recipe_cost for a BOM from its current lines,
     * then cascade to product_cost so the product grid never shows a stale cost.
     *
     * recipe_cost = SUM(quantity × (1 + waste_pct/100) × material_cost) across all lines.
     */
    private function recomputeRecipeCost(BillOfMaterial $bom): void
    {
        $cost = DB::table('bill_of_material_lines as l')
            ->join('products as p', 'p.id', '=', 'l.raw_material_id')
            ->where('l.bom_id', $bom->id)
            ->selectRaw(
                'COALESCE(SUM(l.quantity * (1 + l.waste_percentage / 100) * COALESCE(p.material_cost, 0)), 0) AS recipe_cost'
            )
            ->value('recipe_cost') ?? 0;

        $bom->recipe_cost = (float) $cost;
        $bom->saveQuietly();

        // Cascade to product_cost only when this recipe is the active one.
        // Inactive/draft BOMs should not affect the product's published cost.
        if ($bom->is_active) {
            $bom->loadMissing('product');
            if ($bom->product !== null) {
                // Pass the just-saved BOM as activeRecipe so ProductCostCalculator
                // uses the updated recipe_cost instead of a stale eager-loaded copy.
                $bom->product->setRelation('activeRecipe', $bom);
                $this->productCostCalculator->recalculate($bom->product);
            }
        }
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
