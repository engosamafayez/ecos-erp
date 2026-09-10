<?php

declare(strict_types=1);

namespace Modules\Finance\Receivables\Domain\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Ledger\Domain\Enums\JournalType;
use Modules\Finance\Ledger\Domain\Exceptions\FinanceException;
use Modules\Finance\Ledger\Domain\Models\Account;
use Modules\Finance\Ledger\Domain\ValueObjects\PostingLine;
use Modules\Finance\Ledger\Domain\ValueObjects\PostingRequest;
use Modules\Finance\Posting\Domain\Services\PostingCoordinator;
use Modules\Finance\Receivables\Domain\Enums\CustomerLedgerEntryType;
use Modules\Finance\Receivables\Domain\Models\CustomerLedgerEntry;
use Modules\Finance\Shared\Domain\Services\ControlAccountResolver;
use RuntimeException;

/**
 * TASK-...-026 §10 — Customer opening balances. No such authority existed before this task
 * (confirmed: only a read-only CustomerLedgerService existed). This is the direct structural
 * mirror of the existing, already-approved {@see \Modules\Finance\Payables\Domain\Services\SupplierOpeningBalanceService}
 * — same PostingCoordinator, same JournalType::Opening, same idempotency shape, same "Opening
 * Balance Equity" account — not a second Finance engine, the same one, applied to the symmetric
 * Receivables side using the ControlAccountResolver::receivable() this codebase already exposes.
 *
 * DR 1310 Trade Receivables / CR 3600 Opening Balance Equity — a customer opening balance is a
 * receivable ECOS did not create (pre-ECOS trading), so it debits Receivables directly (the
 * mirror image of Supplier's payable, which credits AP Control).
 */
final class CustomerOpeningBalanceService
{
    private const SOURCE_MODULE = 'customer_opening';

    private const OPENING_BALANCE_EQUITY_CODE = '3600';

    public function __construct(
        private readonly PostingCoordinator $coordinator,
        private readonly ControlAccountResolver $control,
    ) {}

    public function postOpeningReceivable(
        string $companyId,
        string $customerId,
        string $customerCode,
        float $amount,
        Carbon $entryDate,
        ?string $reference = null,
        ?string $notes = null,
        ?int $actorId = null,
    ): CustomerLedgerEntry {
        if ($amount <= 0.0) {
            throw new RuntimeException('Opening balance amount must be greater than zero.');
        }
        $amount = round($amount, 4);

        $sourceType = 'customer_opening_receivable';
        $existing = CustomerLedgerEntry::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $customerId)
            ->first();
        if ($existing !== null) {
            return $existing; // idempotent no-op — already posted
        }

        $receivable = $this->control->receivable($companyId);
        $equity = $this->accountByCode($companyId, self::OPENING_BALANCE_EQUITY_CODE);
        $eventId = $sourceType.':'.$customerId;

        return DB::transaction(function () use (
            $companyId, $customerId, $customerCode, $amount, $entryDate, $reference, $notes, $actorId, $receivable, $equity, $sourceType, $eventId
        ): CustomerLedgerEntry {
            $journal = $this->coordinator->post(
                self::SOURCE_MODULE,
                $eventId,
                new PostingRequest(
                    companyId: $companyId,
                    entryDate: $entryDate,
                    lines: [
                        PostingLine::debit((int) $receivable->id, $amount, $companyId),
                        PostingLine::credit((int) $equity->id, $amount, $companyId),
                    ],
                    reference: $reference ?? 'OB-'.$customerCode,
                    description: 'Customer opening receivable '.$customerCode.($notes !== null ? ' — '.$notes : ''),
                    source: 'posting',
                    sourceModule: self::SOURCE_MODULE,
                    sourceEventId: $eventId,
                    journalType: JournalType::Opening->value,
                ),
                null,
                $actorId,
            );

            return CustomerLedgerEntry::create([
                'company_id' => $companyId,
                'customer_id' => $customerId,
                'entry_date' => $entryDate->toDateString(),
                'entry_type' => CustomerLedgerEntryType::OpeningReceivable->value,
                'amount' => $amount,
                'source_type' => $sourceType,
                'source_id' => $customerId,
                'journal_entry_id' => $journal->id,
                'description' => 'Opening receivable '.($reference ?? 'OB-'.$customerCode),
            ]);
        });
    }

    private function accountByCode(string $companyId, string $code): Account
    {
        $account = Account::query()
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->where('is_active', true)
            ->first();

        if ($account === null) {
            throw FinanceException::accountNotFound($code);
        }

        return $account;
    }
}
