<?php

declare(strict_types=1);

namespace Modules\Purchasing\Suppliers\Domain\Exceptions;

use App\Core\Exceptions\BusinessException;

/**
 * Thrown when a supplier category cannot be found. Maps to HTTP 404.
 */
final class SupplierCategoryNotFoundException extends BusinessException
{
    public function __construct()
    {
        parent::__construct('Supplier category not found.', [], 404);
    }
}
