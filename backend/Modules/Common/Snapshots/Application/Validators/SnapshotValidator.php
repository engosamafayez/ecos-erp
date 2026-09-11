<?php

declare(strict_types=1);

namespace Modules\Common\Snapshots\Application\Validators;

use Modules\Common\Snapshots\Domain\Contracts\FinancialSnapshotProvider;
use Modules\Common\Snapshots\Domain\Exceptions\SnapshotConsistencyException;

/**
 * Validates aggregate data before a financial snapshot is created.
 *
 * Throws SnapshotConsistencyException for any constraint violation.
 * Orders no longer validate directly — they pass a provider here.
 */
final class SnapshotValidator
{
    /**
     * TASK-...-035D-R1 §5 — one cent, matching the 2-decimal-place rounding this module's
     * money fields already use throughout Commerce/Orders (e.g. CreateManualOrderAction's own
     * `round($subtotal ..., 2)`). subtotal and each line's lineTotal can be independently
     * rounded to the cent by their own upstream computation, so summing several
     * independently-rounded lines can legitimately differ from a directly-rounded subtotal by
     * a cent at the edge — this is the same tolerance-not-exact-equality pattern already
     * established elsewhere in this codebase for comparing two independently-computed money
     * floats (e.g. Logistics\Distribution\CashHandoverService::EPSILON,
     * Operations\Loading\ReceiveVehicleReturnAction's quantity-comparison EPSILON).
     */
    private const SUBTOTAL_TOLERANCE = 0.01;

    /**
     * @throws SnapshotConsistencyException
     */
    public function validateConsistency(FinancialSnapshotProvider $provider): void
    {
        $this->assertGrandTotalPositive($provider);
        $this->assertCurrencyPresent($provider);
        $this->assertLinesNotEmpty($provider);
        $this->assertAggregateIdentityComplete($provider);
        $this->assertSubtotalMatchesLineTotals($provider);
    }

    private function assertGrandTotalPositive(FinancialSnapshotProvider $provider): void
    {
        if ($provider->getGrandTotal() <= 0.0) {
            throw new SnapshotConsistencyException(
                'Snapshot grand_total must be positive. '
                ."Aggregate: {$provider->getSnapshotAggregateType()} {$provider->getSnapshotAggregateId()}",
            );
        }
    }

    private function assertCurrencyPresent(FinancialSnapshotProvider $provider): void
    {
        if (trim((string) $provider->getCurrency()) === '') {
            throw new SnapshotConsistencyException(
                'Snapshot currency must not be empty. '
                ."Aggregate: {$provider->getSnapshotAggregateType()} {$provider->getSnapshotAggregateId()}",
            );
        }
    }

    private function assertLinesNotEmpty(FinancialSnapshotProvider $provider): void
    {
        if (count($provider->getLineItems()) === 0) {
            throw new SnapshotConsistencyException(
                'Snapshot must contain at least one line item. '
                ."Aggregate: {$provider->getSnapshotAggregateType()} {$provider->getSnapshotAggregateId()}",
            );
        }
    }

    private function assertAggregateIdentityComplete(FinancialSnapshotProvider $provider): void
    {
        if (trim($provider->getSnapshotAggregateId()) === '') {
            throw new SnapshotConsistencyException('Snapshot aggregate_id must not be empty.');
        }

        if (trim($provider->getSnapshotAggregateType()) === '') {
            throw new SnapshotConsistencyException('Snapshot aggregate_type must not be empty.');
        }
    }

    private function assertSubtotalMatchesLineTotals(FinancialSnapshotProvider $provider): void
    {
        $sumOfLines = array_sum(array_map(
            static fn ($line): float => $line->lineTotal,
            $provider->getLineItems(),
        ));

        $difference = abs($provider->getSubtotal() - $sumOfLines);

        if ($difference > self::SUBTOTAL_TOLERANCE) {
            throw new SnapshotConsistencyException(
                "Snapshot subtotal ({$provider->getSubtotal()}) does not reconcile with the sum of its "
                ."line values ({$sumOfLines}), a difference of {$difference}. "
                ."Aggregate: {$provider->getSnapshotAggregateType()} {$provider->getSnapshotAggregateId()}",
            );
        }
    }
}
