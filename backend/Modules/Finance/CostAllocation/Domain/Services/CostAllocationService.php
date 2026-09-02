<?php

declare(strict_types=1);

namespace Modules\Finance\CostAllocation\Domain\Services;

use Illuminate\Support\Facades\DB;
use Modules\Finance\CostAllocation\Domain\Enums\CostAllocationMethod;
use Modules\Finance\CostAllocation\Domain\Models\CostAllocation;
use Modules\Finance\Expenses\Domain\Models\Expense;
use Modules\Finance\Ledger\Domain\Exceptions\FinanceException;

/**
 * Cost Allocation (TASK-ECOS-FINANCE-OPERATIONAL-COST-ACCOUNTING-007,
 * FIN-EXEC-06) — redistributes an already-posted expense's cost across
 * management dimensions (Brand) for profitability analysis.
 *
 * ┌─ MANAGEMENT DIMENSION ONLY — NO SECOND GL JOURNAL ──────────────────────┐
 * │ TASK §24 asks whether this needs to reclassify the GL. It does not: the    │
 * │ source expense already posted once (Dr its expense account / Cr funding).  │
 * │ A second journal (Dr Brand-tagged-expense / Cr a "shared cost pool")        │
 * │ would recognise the SAME cost a second time unless that pool exactly        │
 * │ relieves the original expense account — which would require either a new    │
 * │ clearing account with no chart-of-accounts precedent, or rewriting the      │
 * │ original posting, both explicitly forbidden (TASK §17: "a cost must appear  │
 * │ once economically"; §23: never destructively edit). A pure management-      │
 * │ dimension table carries zero double-count risk and is the conservative,      │
 * │ textbook-correct choice: statutory GL and internal cost attribution are      │
 * │ two different views of the same one posted fact.                           │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * Concurrency: locks the source Expense row for the duration of the
 * transaction (the exact AllocationEngine pattern — lock the one row every
 * concurrent allocation attempt against this source must contend for), so
 * the sum-cannot-exceed-source invariant holds even for the first-ever
 * allocation against a source with no prior rows to lock.
 */
final class CostAllocationService
{
    private const SOURCE_TYPE_EXPENSE = 'expense';

    /**
     * @param  list<array{profit_center_id: string, amount?: float, percentage?: float}>  $destinations
     * @return list<CostAllocation>
     */
    public function allocate(
        Expense $source,
        CostAllocationMethod $method,
        array $destinations,
        ?int $actorId = null,
    ): array {
        if ($destinations === []) {
            throw FinanceException::allocationMustBePositive();
        }
        if (! $source->isPosted()) {
            throw FinanceException::documentNotPosted('Expense', $source->number);
        }

        return DB::transaction(function () use ($source, $method, $destinations, $actorId): array {
            // Lock the source row for the duration of this transaction — the
            // one row every concurrent allocation attempt against it must
            // contend for.
            $locked = Expense::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            $sourceAmount = round((float) $locked->amount, 4);

            $alreadyAllocated = $this->effectiveAllocatedAmount($locked->company_id, $locked->uuid);
            $rows = [];
            $newTotal = 0.0;

            foreach ($destinations as $destination) {
                $amount = $method === CostAllocationMethod::Percentage
                    ? round($sourceAmount * ((float) ($destination['percentage'] ?? 0) / 100), 4)
                    : round((float) ($destination['amount'] ?? 0), 4);

                if ($amount <= 0.0) {
                    throw FinanceException::allocationMustBePositive();
                }

                $newTotal = round($newTotal + $amount, 4);

                if (round($alreadyAllocated + $newTotal, 4) > $sourceAmount) {
                    throw FinanceException::allocationExceedsDocument('Expense '.$locked->number, (string) $sourceAmount);
                }

                $rows[] = CostAllocation::create([
                    'company_id' => $locked->company_id,
                    'source_type' => self::SOURCE_TYPE_EXPENSE,
                    'source_id' => $locked->uuid,
                    'source_amount' => $sourceAmount,
                    'method' => $method->value,
                    'destination_profit_center_id' => $destination['profit_center_id'],
                    'allocated_amount' => $amount,
                    'percentage' => $method === CostAllocationMethod::Percentage ? round((float) ($destination['percentage'] ?? 0), 4) : null,
                    'created_by' => $actorId,
                ]);
            }

            return $rows;
        });
    }

    /**
     * Reverse one allocation with a new, append-only, negative contra-row —
     * the original is never edited (the model's own updating()/deleting()
     * guards refuse it unconditionally).
     */
    public function reverseAllocation(CostAllocation $allocation, string $reason, ?int $actorId = null): CostAllocation
    {
        if ($allocation->isReversal()) {
            throw FinanceException::cannotReverseAReversal();
        }
        if ($reason === '') {
            throw FinanceException::reversalReasonRequired();
        }

        return DB::transaction(function () use ($allocation, $reason, $actorId): CostAllocation {
            return CostAllocation::create([
                'company_id' => $allocation->company_id,
                'source_type' => $allocation->source_type,
                'source_id' => $allocation->source_id,
                'source_amount' => $allocation->source_amount,
                'method' => $allocation->method->value,
                'destination_profit_center_id' => $allocation->destination_profit_center_id,
                'allocated_amount' => round((float) $allocation->allocated_amount * -1, 4),
                'percentage' => null,
                'reverses_allocation_id' => $allocation->id,
                'reversal_reason' => $reason,
                'created_by' => $actorId,
            ]);
        });
    }

    /** Σ of non-reversal allocations for a source, net of their own reversals. */
    public function effectiveAllocatedAmount(string $companyId, string $sourceId): float
    {
        return round((float) CostAllocation::query()
            ->where('company_id', $companyId)
            ->where('source_type', self::SOURCE_TYPE_EXPENSE)
            ->where('source_id', $sourceId)
            ->sum('allocated_amount'), 4);
    }
}
