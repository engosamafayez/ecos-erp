<?php

declare(strict_types=1);

namespace Modules\Purchasing\SupplierInvoices\Domain\Services;

/**
 * TASK-ECOS-PROCUREMENT-SUPPLIERS-FINAL-USER-REVIEW-REMEDIATION-002 §12/§13.
 *
 * Deterministic, remainder-exact proportional allocation of a single monetary total across N
 * weighted buckets (here: a Supplier Invoice's line quantities), used to spread freight/
 * additional costs across lines per the approved landed-cost rule:
 *
 *     allocated_extra_per_unit = (freight + additional_costs) / total_invoice_quantity
 *     final_landed_unit_cost   = base_unit_price + allocated_extra_per_unit
 *
 * WHY THIS EXISTS: no Finance-scoped Money/minor-unit value object exists anywhere in this
 * codebase to reuse (the only Money VO lives under `Modules\POS\Shared`, fixed at 2dp, never
 * imported outside POS). Rather than allocating in plain floats — which cannot guarantee
 * `sum(allocated) === total` once each line's share is independently rounded for storage — this
 * class does the ALLOCATION DECISION in integer minor units at Finance's existing 4-decimal-
 * place convention (the same precision `round($x, 4)` already used throughout Purchasing/
 * Finance), so only the input/output boundary ever touches floating point.
 *
 * ALGORITHM — largest-remainder / Hamilton's method, a standard, documented, deterministic
 * apportionment rule (not invented here): each bucket's exact proportional share is computed at
 * full precision, floored to whole minor units, and the handful of minor units lost to flooring
 * (always fewer than the number of buckets) are handed out one at a time to the buckets with
 * the largest fractional remainder, largest first — ties broken by original bucket order, so
 * the result is 100% reproducible for identical input. This guarantees
 * `array_sum(allocate($total, $weights)) === round($total, 4)` exactly, for any input,
 * including a negative $total (a credit/reversal), which is handled symmetrically.
 *
 * A zero-or-negative-weight bucket (an invalid/zero-quantity line) always receives exactly 0.0
 * and never participates in the remainder distribution — "total_invoice_quantity" in the
 * approved rule is explicitly the sum of VALID quantities only.
 */
final class LandedCostAllocator
{
    private const SCALE = 4;

    /**
     * @param  array<int, float>  $weights  non-negative weights (e.g. line quantities), keyed
     *                                      identically to the lines being allocated to
     * @return array<int, float> allocated amount per bucket, same keys as $weights, summing to
     *                           exactly round($total, 4)
     */
    public static function allocate(float $total, array $weights): array
    {
        $result = array_fill_keys(array_keys($weights), 0.0);

        if ($weights === []) {
            return $result;
        }

        $positiveKeys = array_keys(array_filter($weights, static fn (float $w): bool => $w > 0.0));

        if ($positiveKeys === [] || $total === 0.0) {
            return $result;
        }

        $totalWeight = array_sum(array_intersect_key($weights, array_flip($positiveKeys)));
        $unit = 10 ** self::SCALE;
        $targetMinor = (int) round($total * $unit);

        $rawShares = [];
        foreach ($positiveKeys as $key) {
            $rawShares[$key] = ($total * $unit) * ($weights[$key] / $totalWeight);
        }

        $flooredMinor = [];
        foreach ($rawShares as $key => $value) {
            $flooredMinor[$key] = (int) floor($value);
        }

        $remainder = $targetMinor - array_sum($flooredMinor);

        $fractions = [];
        foreach ($rawShares as $key => $value) {
            $fractions[$key] = $value - floor($value);
        }

        if ($remainder > 0) {
            arsort($fractions);
            $orderedKeys = array_keys($fractions);
            $count = count($orderedKeys);
            for ($i = 0; $i < $remainder; $i++) {
                $flooredMinor[$orderedKeys[$i % $count]] += 1;
            }
        } elseif ($remainder < 0) {
            asort($fractions);
            $orderedKeys = array_keys($fractions);
            $count = count($orderedKeys);
            for ($i = 0; $i < abs($remainder); $i++) {
                $flooredMinor[$orderedKeys[$i % $count]] -= 1;
            }
        }

        foreach ($flooredMinor as $key => $minor) {
            $result[$key] = round($minor / $unit, self::SCALE);
        }

        return $result;
    }
}
