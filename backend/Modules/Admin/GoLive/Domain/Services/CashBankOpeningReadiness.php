<?php

declare(strict_types=1);

namespace Modules\Admin\GoLive\Domain\Services;

use Illuminate\Support\Facades\DB;
use Modules\Admin\GoLive\Domain\Enums\ResetDomain;
use Modules\Admin\GoLive\Domain\Enums\ResetOperationStatus;

/**
 * TASK-...-026-R1 Gate 4 — truthful Cash/Bank opening readiness.
 *
 * NO CASH/BANK OPENING-BALANCE POSTING AUTHORITY EXISTS in ECOS today. Supplier and Customer each
 * have one (`SupplierOpeningBalanceService`, `CustomerOpeningBalanceService`, both posting through
 * `PostingCoordinator` + `JournalType::Opening`). Cash and Bank do not: `CashAccount`/`BankAccount`
 * store no balance of their own — by design, their balance IS their linked GL account's balance,
 * built up only from real postings — and neither `ControlAccountResolver` nor any other service in
 * `Modules\Finance` exposes a `cash()`/`bank()` opening-balance equivalent. This class does NOT
 * invent one. It only tells the truth about when that gap actually matters.
 *
 * WHEN IT MATTERS. A company that has never configured a Cash or Bank account in ECOS was never
 * going to need one — no blocker. A company whose Finance transactions were never reset still has
 * whatever Cash/Bank GL postings it already accumulated — no blocker. The gap becomes a REAL,
 * user-facing problem only in the one combination where both are true: the company actually uses
 * Cash/Bank tracking (at least one active `finance_cash_accounts`/`finance_bank_accounts` row) AND
 * a completed Go-Live reset has wiped Finance's transactions (`golive_reset_operations` with
 * `status=completed` and `finance` in `selected_domains`), leaving those accounts with no ledger
 * history and no canonical way to (re-)establish where they actually stand.
 *
 * Used by both `GoLiveActivationController::status()` (so the operator sees this BEFORE clicking
 * Activate, not only as a rejected request) and `ActivateGoLiveAction` (the actual enforcement —
 * the status readout is advisory, this is the gate).
 */
final class CashBankOpeningReadiness
{
    public function isBlocked(string $companyId): bool
    {
        return $this->cashOrBankAccountCount($companyId) > 0 && $this->financeWasReset($companyId);
    }

    /** Null when not blocked — callers must not fabricate a message in that case. */
    public function missingPrerequisiteMessage(string $companyId): ?string
    {
        if (! $this->isBlocked($companyId)) {
            return null;
        }

        $count = $this->cashOrBankAccountCount($companyId);

        return "Finance transactions were reset and this company has {$count} active Cash/Bank account(s), but ECOS has "
            .'no canonical Cash/Bank opening-balance authority yet to re-establish their starting position. '
            .'Resolve this with an administrator before activating Go-Live.';
    }

    private function financeWasReset(string $companyId): bool
    {
        return DB::table('golive_reset_operations')
            ->where('company_id', $companyId)
            ->where('status', ResetOperationStatus::Completed->value)
            ->whereJsonContains('selected_domains', ResetDomain::Finance->value)
            ->exists();
    }

    private function cashOrBankAccountCount(string $companyId): int
    {
        return DB::table('finance_cash_accounts')->where('company_id', $companyId)->where('is_active', true)->count()
            + DB::table('finance_bank_accounts')->where('company_id', $companyId)->where('is_active', true)->count();
    }
}
