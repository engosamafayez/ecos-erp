<?php

declare(strict_types=1);

namespace Modules\Inventory\Products\Domain\Enums;

/**
 * The BUSINESS availability of a product — T-1, the approved three-state contract.
 *
 *   Available >  0                              → In Stock
 *   Available <= 0  AND  Allow Negative = true  → Negative Allowed
 *   Available <= 0  AND  Allow Negative = false → Out of Stock
 *
 * There is NO fourth business-facing state. A product with no inventory row is not a
 * separate kind of thing to a salesperson: it has nothing available, so the same rule
 * decides it, and `null` is read as a quantity of nothing.
 *
 * WHY THIS IS SEPARATE FROM {@see \Modules\Inventory\InventoryItems\Domain\Enums\AvailabilityState}
 *
 * The two answer different questions, and collapsing them is what produced the
 * contradiction T-1 was raised for:
 *
 *   AvailabilityState    DATA PLATFORM. Untracked | InStock | OutOfStock. `Untracked`
 *                        means "no inventory record exists", which is a real and useful
 *                        distinction for InventorySummaryService and the inventory-layer
 *                        endpoint. It knows nothing about `allow_negative_stock`.
 *
 *   ProductAvailability  BUSINESS. The three states above, policy folded in. This is what
 *                        any product-facing surface — API, table, drawer, filter — must
 *                        speak.
 *
 * `AvailabilityState::Untracked` is deliberately RETAINED for the data platform and is
 * simply never projected here: this enum maps it to the business answer instead of
 * leaking it. Deleting that case would break the inventory-layer contract, whose consumer
 * was proven before this enum was written.
 *
 * The three cases are MUTUALLY EXCLUSIVE and COLLECTIVELY EXHAUSTIVE over the input
 * domain: `project()` branches on `> 0` and then on a boolean, so exactly one case is
 * reachable for every (available, allowNegative) pair, `null` availability included.
 */
enum ProductAvailability: string
{
    case InStock = 'in_stock';
    case NegativeAllowed = 'negative_allowed';
    case OutOfStock = 'out_of_stock';

    /**
     * Project a signed availability and the product's negative-stock policy onto the
     * single business answer.
     *
     * @param  float|null  $available  SIGNED canonical availability (on_hand − reserved);
     *                                 may be negative. `null` = no inventory row, read as 0.
     */
    public static function project(?float $available, bool $allowNegative): self
    {
        // A missing inventory row must NOT create a fourth business state.
        $qty = $available ?? 0.0;

        if ($qty > 0.0) {
            return self::InStock;
        }

        return $allowNegative ? self::NegativeAllowed : self::OutOfStock;
    }

    /**
     * The SQL predicate selecting exactly this state.
     *
     * Filter and badge derive from THIS enum and nothing else, so the population a filter
     * returns is by construction the population whose badge matches. Previously the rule
     * existed twice — once as a `match` in the repository and once as a projection in the
     * resource — and the two disagreed about products with no inventory row.
     *
     * @param  string  $available  SQL expression for signed availability, already COALESCEd
     * @param  string  $allowNegative  SQL expression for the boolean policy column
     */
    public function sqlPredicate(string $available, string $allowNegative): string
    {
        return match ($this) {
            self::InStock => "{$available} > 0",
            self::NegativeAllowed => "{$available} <= 0 AND {$allowNegative} = TRUE",
            self::OutOfStock => "{$available} <= 0 AND {$allowNegative} = FALSE",
        };
    }

    /** Backing values, for validation and for typed frontend contracts. */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
