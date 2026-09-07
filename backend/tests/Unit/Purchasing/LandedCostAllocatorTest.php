<?php

declare(strict_types=1);

namespace Tests\Unit\Purchasing;

use Modules\Purchasing\SupplierInvoices\Domain\Services\LandedCostAllocator;
use PHPUnit\Framework\TestCase;

/**
 * TASK-ECOS-PROCUREMENT-SUPPLIERS-FINAL-USER-REVIEW-REMEDIATION-002 §12/§13/§20.
 * Pure-function tests — no database required.
 */
class LandedCostAllocatorTest extends TestCase
{
    public function test_splits_evenly_across_equal_weights(): void
    {
        $result = LandedCostAllocator::allocate(100.0, [10.0, 10.0, 10.0, 10.0]);

        $this->assertSame([25.0, 25.0, 25.0, 25.0], $result);
        $this->assertEqualsWithDelta(100.0, array_sum($result), 0.0000001);
    }

    public function test_reconciles_exactly_when_division_leaves_a_remainder(): void
    {
        // 100 / 3 lines of qty 1 each = 33.3333... repeating — a textbook rounding trap.
        $result = LandedCostAllocator::allocate(100.0, [1.0, 1.0, 1.0]);

        $this->assertEqualsWithDelta(100.0, array_sum($result), 0.0000001);
        // Deterministic remainder rule: the extra 0.0001 units go to the earliest bucket(s) in
        // descending-fraction order; for three equal fractions the tie breaks by original order.
        $this->assertSame(33.3334, $result[0]);
        $this->assertSame(33.3333, $result[1]);
        $this->assertSame(33.3333, $result[2]);
    }

    public function test_reconciles_exactly_for_uneven_quantity_weighted_split(): void
    {
        $result = LandedCostAllocator::allocate(250.0, [1.0, 2.0, 7.0]);

        $this->assertEqualsWithDelta(250.0, array_sum($result), 0.0000001);
        $this->assertEqualsWithDelta(25.0, $result[0], 0.01);
        $this->assertEqualsWithDelta(50.0, $result[1], 0.01);
        $this->assertEqualsWithDelta(175.0, $result[2], 0.01);
    }

    public function test_zero_or_negative_weight_line_gets_nothing(): void
    {
        $result = LandedCostAllocator::allocate(90.0, [3.0, 0.0, -1.0, 6.0]);

        $this->assertSame(0.0, $result[1]);
        $this->assertSame(0.0, $result[2]);
        $this->assertEqualsWithDelta(90.0, array_sum($result), 0.0000001);
        $this->assertEqualsWithDelta(30.0, $result[0], 0.01);
        $this->assertEqualsWithDelta(60.0, $result[3], 0.01);
    }

    public function test_zero_total_allocates_nothing(): void
    {
        $result = LandedCostAllocator::allocate(0.0, [1.0, 2.0, 3.0]);

        $this->assertSame([0.0, 0.0, 0.0], $result);
    }

    public function test_no_lines_returns_empty(): void
    {
        $this->assertSame([], LandedCostAllocator::allocate(100.0, []));
    }

    public function test_all_zero_weights_allocates_nothing(): void
    {
        $result = LandedCostAllocator::allocate(100.0, [0.0, 0.0]);

        $this->assertSame([0.0, 0.0], $result);
    }

    public function test_negative_total_credit_reconciles_symmetrically(): void
    {
        $result = LandedCostAllocator::allocate(-100.0, [1.0, 1.0, 1.0]);

        $this->assertEqualsWithDelta(-100.0, array_sum($result), 0.0000001);
        foreach ($result as $value) {
            $this->assertLessThanOrEqual(0.0, $value);
        }
    }

    public function test_preserves_non_sequential_keys(): void
    {
        // SupplierInvoiceLine collections can be keyed by their own model IDs rather than a
        // clean 0..n-1 sequence — the allocator must not assume sequential integer keys.
        $result = LandedCostAllocator::allocate(60.0, [7 => 1.0, 12 => 2.0, 99 => 3.0]);

        $this->assertSame([7, 12, 99], array_keys($result));
        $this->assertEqualsWithDelta(60.0, array_sum($result), 0.0000001);
    }
}
