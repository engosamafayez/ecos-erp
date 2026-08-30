<?php

declare(strict_types=1);

namespace Modules\Finance\Shared\Domain\Services;

use Modules\Finance\Banking\Domain\Models\BankAccount;
use Modules\Finance\Cash\Domain\Models\CashAccount;
use Modules\Finance\Ledger\Domain\Exceptions\FinanceException;
use Modules\Finance\Ledger\Domain\Models\Account;

/**
 * Decides whether a GL account may serve as the SOURCE OF FUNDS for an outgoing
 * payment — a supplier payment today; any money-out flow that adopts it tomorrow.
 *
 * WHY A DEDICATED AUTHORITY. A payment credits its funding account: the money
 * leaves from THERE. Company ownership alone is not enough — a revenue, expense,
 * inventory, receivable or payable-control account all belong to the company yet
 * none is a place money is actually held. ECOS already designates the accounts
 * that ARE held cash through the Cash and Banking subledgers: a {@see CashAccount}
 * (a till / petty-cash box) and a {@see BankAccount} are each linked 1:1 to their
 * backing GL account (`gl_account_id`). This policy reads that existing taxonomy —
 * it invents no new account type, hardcodes no GL id, and adds no second
 * chart-of-accounts axis. An eligible funding source is a company GL account that
 * backs an ACTIVE cash or bank account; everything else is refused before any
 * journal is requested.
 *
 * This is the single canonical funding-eligibility authority. Keep it here (the
 * shared subledger↔GL bridge, alongside {@see ControlAccountResolver}) rather than
 * duplicating the rule in a controller, a service, a posting strategy, or the
 * frontend.
 */
final class FundingAccountPolicy
{
    /** Whether a GL account is a valid source of funds for the company. */
    public function isEligible(string $companyId, int $accountId): bool
    {
        return $this->ownedAccount($companyId, $accountId) !== null
            && $this->isCashOrBank($companyId, $accountId);
    }

    /**
     * Assert a GL account may fund an outgoing payment for the company, or refuse
     * with a precise domain error.
     *
     * A foreign or unknown account is reported as "not found" — tenant-safe, it
     * simply does not exist for this company. A same-company account that is not a
     * designated cash/bank source is reported as not eligible, naming its own code.
     */
    public function assertEligible(string $companyId, int $accountId): void
    {
        $account = $this->ownedAccount($companyId, $accountId);

        if ($account === null) {
            throw FinanceException::accountNotFound((string) $accountId);
        }

        if (! $this->isCashOrBank($companyId, $accountId)) {
            throw FinanceException::fundingAccountNotEligible($account->code);
        }
    }

    private function ownedAccount(string $companyId, int $accountId): ?Account
    {
        return Account::query()
            ->where('company_id', $companyId)
            ->whereKey($accountId)
            ->first();
    }

    /** A held-cash account: the GL node backs an active cash or bank account. */
    private function isCashOrBank(string $companyId, int $accountId): bool
    {
        $cash = CashAccount::query()
            ->where('company_id', $companyId)
            ->where('gl_account_id', $accountId)
            ->where('is_active', true)
            ->exists();

        if ($cash) {
            return true;
        }

        return BankAccount::query()
            ->where('company_id', $companyId)
            ->where('gl_account_id', $accountId)
            ->where('is_active', true)
            ->exists();
    }
}
