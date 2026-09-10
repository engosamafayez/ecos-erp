<?php

declare(strict_types=1);

namespace Modules\Purchasing\Suppliers\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Modules\Purchasing\Suppliers\Domain\Contracts\SupplierCategoryRepositoryInterface;
use Modules\Purchasing\Suppliers\Domain\Exceptions\SupplierCategoryInUseException;
use Modules\Purchasing\Suppliers\Domain\Exceptions\SupplierCategoryNotFoundException;
use Modules\Purchasing\Suppliers\Domain\Models\Supplier;

final class DeleteSupplierCategoryAction extends BaseAction
{
    public function __construct(private readonly SupplierCategoryRepositoryInterface $categories) {}

    public function execute(mixed ...$arguments): OperationResult
    {
        $id = (string) ($arguments[0] ?? '');

        $category = $this->categories->findById($id);

        if ($category === null) {
            throw new SupplierCategoryNotFoundException;
        }

        // §4 — "Archive/delete only where safe." Both `supplier_category_id` (the legacy
        // single/"primary" column) and `supplier_category_assignments` (the canonical
        // many-to-many set, §A.1) carry `restrictOnDelete()`/FK protection at the database
        // level, so this was never actually able to orphan a supplier — but without this
        // check the DB constraint violation surfaced as a raw, unfriendly 500 instead of a
        // clear, actionable message. Checked ahead of the delete rather than relying on
        // catching the DB exception, so the message can name the exact count without a
        // second query inside a catch block. DISTINCT because a Supplier can reach this
        // category via both the legacy column AND the pivot at once (they are kept in sync).
        $supplierCount = Supplier::query()
            ->where(function ($query) use ($id): void {
                $query->where('supplier_category_id', $id)
                    ->orWhereHas('categories', fn ($q) => $q->where('supplier_categories.id', $id));
            })
            ->distinct()
            ->count('suppliers.id');

        if ($supplierCount > 0) {
            throw new SupplierCategoryInUseException($supplierCount);
        }

        $this->categories->delete($category);

        return OperationResult::success(null, 'Supplier category deleted successfully.');
    }
}
