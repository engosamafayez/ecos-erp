<?php

declare(strict_types=1);

namespace Modules\Manufacturing\BillsOfMaterials\Domain\Exceptions;

use RuntimeException;

/**
 * Raised when a recipe cannot be frozen into an approved cost snapshot, or when a
 * consumer asks for a snapshot that does not exist.
 *
 * Every case here is deliberately fatal rather than a warning. A recipe that cannot
 * be priced must not become approved, and a disassembly that cannot be valued must
 * not execute — an unpriced component silently valued at zero would corrupt
 * inventory valuation in a way nobody would notice until the accounts disagreed.
 */
final class RecipeCostSnapshotException extends RuntimeException
{
    public static function noComponents(string $bomNumber): self
    {
        return new self("Recipe [{$bomNumber}] has no components and cannot be costed.");
    }

    /**
     * @param  list<string>  $skus
     */
    public static function unpricedComponents(string $bomNumber, array $skus): self
    {
        $list = implode(', ', $skus);

        return new self(
            "Recipe [{$bomNumber}] cannot be approved: no material cost is set for [{$list}]. "
            .'Set a material cost for every component, then approve again.',
        );
    }

    public static function missingForDisassembly(string $bomNumber): self
    {
        return new self(
            "Recipe [{$bomNumber}] has no approved cost snapshot, so its components cannot be valued. "
            .'Re-approve the recipe to record one, then retry the disassembly.',
        );
    }
}
