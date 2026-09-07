<?php

declare(strict_types=1);

namespace Modules\Purchasing\Suppliers\Domain\Exceptions;

use App\Core\Exceptions\BusinessException;

/**
 * TASK-ECOS-PROCUREMENT-SUPPLIERS-FINAL-USER-REVIEW-REMEDIATION-002 §4.
 *
 * Thrown when deleting a Supplier Category is refused because one or more suppliers are still
 * assigned to it. The `supplier_category_id` FK already carries `restrictOnDelete()` at the
 * database level, so an unsafe delete was never actually possible — but without this guard it
 * surfaced as a raw, unfriendly SQL constraint-violation error instead of a clear message.
 * Maps to HTTP 422 (a well-formed request refused for a business reason, not a validation
 * failure or a missing resource).
 */
final class SupplierCategoryInUseException extends BusinessException
{
    public function __construct(int $supplierCount)
    {
        parent::__construct(
            $supplierCount === 1
                ? 'Cannot delete this category — 1 supplier is still assigned to it. Reassign or archive it instead.'
                : "Cannot delete this category — {$supplierCount} suppliers are still assigned to it. Reassign or archive it instead.",
            ['supplier_count' => $supplierCount],
            422,
        );
    }
}
