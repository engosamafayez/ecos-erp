<?php

declare(strict_types=1);

namespace Modules\Purchasing\Suppliers\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Purchasing\Suppliers\Application\DTO\SupplierDTO;
use Modules\Purchasing\Suppliers\Domain\Contracts\SupplierRepositoryInterface;
use Modules\Purchasing\Suppliers\Domain\Exceptions\SupplierNotFoundException;
use Modules\Purchasing\Suppliers\Domain\Services\SupplierCapabilitySyncService;
use Modules\Purchasing\Suppliers\Domain\Services\SupplierCategoryAssignmentSyncService;

/**
 * Updates an existing supplier, and syncs its declared Supplier Categories and
 * Supply Capabilities (Raw Materials / Product Categories) in the same transaction.
 */
final class UpdateSupplierAction extends BaseAction
{
    public function __construct(
        private readonly SupplierRepositoryInterface $suppliers,
        private readonly SupplierCapabilitySyncService $capabilities,
        private readonly SupplierCategoryAssignmentSyncService $categoryAssignments,
    ) {}

    /**
     * @param  mixed  ...$arguments  Expects (string $id, SupplierDTO $dto).
     *
     * @throws SupplierNotFoundException
     */
    public function execute(mixed ...$arguments): OperationResult
    {
        $id = (string) ($arguments[0] ?? '');
        $dto = $arguments[1] ?? null;

        if (! $dto instanceof SupplierDTO) {
            throw new InvalidArgumentException('UpdateSupplierAction::execute expects a SupplierDTO.');
        }

        $supplier = DB::transaction(function () use ($id, $dto) {
            $supplier = $this->suppliers->findById($id);

            if ($supplier === null) {
                throw new SupplierNotFoundException;
            }

            // Code is backend-owned and assigned once at creation — an ordinary edit must never
            // regenerate or overwrite it, regardless of what the client sends.
            $attributes = $dto->toArray();
            unset($attributes['code'], $attributes['raw_material_ids'], $attributes['product_category_ids'], $attributes['supplier_category_ids']);

            // Multiple Categories (§A.1) — the array is authoritative; the legacy singular
            // column is a derived "primary category" mirror, falling back to whatever the
            // caller sent directly only when no array was supplied at all (back-compat for
            // any caller not yet updated to the array field).
            $categoryIds = $dto->supplier_category_ids !== [] || $dto->supplier_category_id === null
                ? $dto->supplier_category_ids
                : [$dto->supplier_category_id];
            $attributes['supplier_category_id'] = $categoryIds[0] ?? null;

            $supplier = $this->suppliers->update($supplier, $attributes);

            $this->capabilities->sync($supplier, $dto->raw_material_ids, $dto->product_category_ids, Auth::id());
            $this->categoryAssignments->sync($supplier, $categoryIds, Auth::id());

            return $supplier;
        });

        return OperationResult::success($supplier, 'Supplier updated successfully.');
    }
}
