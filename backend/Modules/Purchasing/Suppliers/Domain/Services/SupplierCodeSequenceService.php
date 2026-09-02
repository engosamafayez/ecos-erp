<?php

declare(strict_types=1);

namespace Modules\Purchasing\Suppliers\Domain\Services;

use Illuminate\Support\Facades\DB;
use Modules\Purchasing\Suppliers\Domain\Models\Supplier;

/**
 * Generates the next canonical Supplier Code from a dedicated, per-company
 * sequence row — NOT a count()+lockForUpdate() scan.
 *
 * TASK-ECOS-PROCUREMENT-SUPPLIERS-BATCH-01-MASTER-DATA-002-R1: the prior
 * `SupplierCodeGeneratorService` mirrored WarehouseCodeGeneratorService's
 * `count()+1` under `lockForUpdate()`, which is unsafe for a company's FIRST
 * Supplier — a `SELECT ... WHERE company_id = ? FOR UPDATE` that matches zero
 * rows takes no row lock at all, so two concurrent "first Supplier for this
 * company" creates can both compute count=0 and both attempt the same code.
 *
 * This service's real atomicity comes from a single `INSERT ... ON DUPLICATE
 * KEY UPDATE` (Laravel's `upsert()`) against `supplier_code_sequences`,
 * primary-keyed on `company_id` — mirroring the proven
 * `pos_receipt_counters` / SequentialReceiptNumberingStrategy pattern. MySQL
 * serializes concurrent upserts targeting the SAME primary key regardless of
 * whether the row exists yet: whichever transaction's upsert executes first
 * takes the row lock (held until it commits); a second concurrent upsert on
 * the same company blocks until the first commits, then correctly increments
 * off the real committed value — not off a value it read before the race.
 * Different companies never share a row, so they never block each other.
 *
 * The `bootstrap+1` value in the upsert's INSERT branch only matters the
 * first time a company's row is created; every subsequent call — including a
 * concurrent one racing the very first call — takes the UPDATE branch and
 * increments the real row, so a stale/duplicate bootstrap read can never
 * cause a collision (see class-level proof above).
 */
final class SupplierCodeSequenceService
{
    private const TABLE = 'supplier_code_sequences';

    /** Matches ONLY the canonical format — legacy/manual codes never match. */
    private const CANONICAL_CODE_PATTERN = '/^SUP-(\d{6})$/';

    public function next(string $companyId): string
    {
        $number = DB::transaction(function () use ($companyId): int {
            $rowExists = DB::table(self::TABLE)->where('company_id', $companyId)->exists();

            // Only worth computing when the row doesn't exist yet — once a
            // company has a sequence row, the upsert's UPDATE branch fires
            // and this value is never used, so there's no need to re-scan
            // every Supplier's code on every single creation.
            $bootstrap = $rowExists ? 0 : $this->highestCanonicalSuffix($companyId);

            DB::table(self::TABLE)->upsert(
                ['company_id' => $companyId, 'current_number' => $bootstrap + 1],
                ['company_id'],
                ['current_number' => DB::raw(self::TABLE.'.current_number + 1')],
            );

            return (int) DB::table(self::TABLE)->where('company_id', $companyId)->value('current_number');
        });

        return sprintf('SUP-%06d', $number);
    }

    /**
     * The highest canonical numeric suffix already in use by this company's
     * Suppliers — scanning ALL of them, including soft-deleted (a number
     * must never be reissued even if its Supplier was later deleted), but
     * safely ignoring anything that isn't exactly `SUP-######` (legacy or
     * manually-entered codes never distort this). Returns 0 if none match —
     * a genuinely fresh company starts its sequence at 1.
     */
    private function highestCanonicalSuffix(string $companyId): int
    {
        $codes = Supplier::query()
            ->withoutGlobalScopes()
            ->withTrashed()
            ->where('company_id', $companyId)
            ->where('code', 'like', 'SUP-%')
            ->pluck('code');

        $max = 0;

        foreach ($codes as $code) {
            if (preg_match(self::CANONICAL_CODE_PATTERN, (string) $code, $matches) === 1) {
                $max = max($max, (int) $matches[1]);
            }
        }

        return $max;
    }
}
