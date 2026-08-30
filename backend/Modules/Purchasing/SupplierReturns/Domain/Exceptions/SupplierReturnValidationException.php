<?php

declare(strict_types=1);

namespace Modules\Purchasing\SupplierReturns\Domain\Exceptions;

use RuntimeException;

/**
 * A Supplier Return was refused before any inventory, ledger or FIFO mutation occurred.
 *
 * Deliberately distinct from InsufficientStockException: an over-return is an invalid
 * business request, not a stock shortage, and reporting it as the latter would send an
 * operator to look for stock when the real problem is that the goods were never received.
 */
final class SupplierReturnValidationException extends RuntimeException {}
