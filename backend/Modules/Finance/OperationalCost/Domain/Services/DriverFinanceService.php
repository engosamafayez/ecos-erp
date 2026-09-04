<?php

declare(strict_types=1);

namespace Modules\Finance\OperationalCost\Domain\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Integration\Domain\Services\AccountRoleResolver;
use Modules\Finance\Ledger\Domain\Enums\JournalType;
use Modules\Finance\Ledger\Domain\Exceptions\FinanceException;
use Modules\Finance\Ledger\Domain\Models\JournalEntry;
use Modules\Finance\Ledger\Domain\Services\JournalEngine;
use Modules\Finance\Ledger\Domain\ValueObjects\PostingLine;
use Modules\Finance\Ledger\Domain\ValueObjects\PostingRequest;
use Modules\Finance\OperationalCost\Domain\Enums\DriverLedgerEntryType;
use Modules\Finance\OperationalCost\Domain\Models\DriverLedgerEntry;
use Modules\Finance\Posting\Domain\Services\PostingCoordinator;
use Modules\Finance\Shared\Domain\Services\FundingAccountPolicy;

/**
 * Driver Financial Accounting (TASK-ECOS-FINANCE-OPERATIONAL-COST-
 * ACCOUNTING-007, FIN-EXEC-07) — the Finance-owned RECEIVING CONTRACT for
 * approved driver advances, approved driver expenses, and approved driver
 * shortages. driver_id is an opaque party reference (the CustomerInvoice/
 * SupplierBill convention) — Finance never models a driver itself.
 *
 * ┌─ THREE MOVEMENTS, ONE SWING ACCOUNT ────────────────────────────────────┐
 * │ All three post against the SAME driver_receivable role (1320 Employee     │
 * │ Receivables) — a due-to/from account, not a pure asset. This is what        │
 * │ unifies TASK §11's three possible expense outcomes ("consume a previous    │
 * │ advance", "create reimbursement payable") into ONE posting shape: Dr        │
 * │ expense account / Cr driver_receivable. Whether the driver's resulting     │
 * │ balance is still positive (they still owe an advance) or has gone          │
 * │ negative (the company now owes them a reimbursement) falls out of the      │
 * │ account's own running balance — no branching code needed. The third         │
 * │ outcome ("create direct cash/bank expense", no driver involved at all)      │
 * │ needs no new code either: it is an ordinary ExpenseService posting.        │
 * │                                                                            │
 * │ TASK §9/§12 hard rule, enforced by NOT existing rather than by a runtime    │
 * │ check: there is no method here that turns a raw waste/damage report into   │
 * │ a liability. recognizeDriverShortage() exists ONLY for an ALREADY-         │
 * │ approved shortage — its caller (a future listener, once Logistics exposes  │
 * │ a real approval event; none exists today, see the Task 7 report) is what   │
 * │ must gate on approval, not this service.                                  │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class DriverFinanceService
{
    private const SOURCE_MODULE = 'finance.driver';

    public function __construct(
        private readonly PostingCoordinator $coordinator,
        private readonly JournalEngine $journalEngine,
        private readonly AccountRoleResolver $roles,
        private readonly FundingAccountPolicy $fundingAccounts,
    ) {}

    /** An approved cash advance issued to a driver: Dr driver_receivable, Cr funding. */
    public function recognizeDriverAdvance(
        string $companyId,
        string $driverId,
        float $amount,
        int $fundingAccountId,
        Carbon $date,
        ?int $actorId = null,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?string $description = null,
    ): ?DriverLedgerEntry {
        if ($amount <= 0.0) {
            return null;
        }

        $existing = $this->existing($companyId, $sourceType, $sourceId);
        if ($existing !== null) {
            return $existing;
        }

        $this->fundingAccounts->assertEligible($companyId, $fundingAccountId);
        $receivable = $this->roles->resolve($companyId, 'driver_receivable');

        return $this->post(
            $companyId, $driverId, DriverLedgerEntryType::Advance, round($amount, 4),
            debitAccountId: $receivable, creditAccountId: $fundingAccountId,
            date: $date, actorId: $actorId, sourceType: $sourceType, sourceId: $sourceId,
            description: $description ?? 'Driver advance',
        );
    }

    /**
     * An approved driver expense — consumes a prior advance/float or creates
     * a reimbursement payable, depending purely on the driver's resulting
     * balance (see class docblock). Dr the given expense account, Cr
     * driver_receivable.
     */
    public function recognizeDriverExpense(
        string $companyId,
        string $driverId,
        float $amount,
        int $expenseAccountId,
        Carbon $date,
        ?int $actorId = null,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?string $description = null,
    ): ?DriverLedgerEntry {
        if ($amount <= 0.0) {
            return null;
        }

        $existing = $this->existing($companyId, $sourceType, $sourceId);
        if ($existing !== null) {
            return $existing;
        }

        $receivable = $this->roles->resolve($companyId, 'driver_receivable');

        return $this->post(
            $companyId, $driverId, DriverLedgerEntryType::Expense, round($amount, 4),
            debitAccountId: $expenseAccountId, creditAccountId: $receivable,
            date: $date, actorId: $actorId, sourceType: $sourceType, sourceId: $sourceId,
            description: $description ?? 'Approved driver expense',
        );
    }

    /**
     * An APPROVED driver shortage/liability only — never a raw waste/damage
     * report (TASK §9/§12; enforced by the caller, see class docblock). Dr
     * driver_receivable (they now owe this), Cr driver_shortage_recovery.
     */
    public function recognizeDriverShortage(
        string $companyId,
        string $driverId,
        float $amount,
        Carbon $date,
        ?int $actorId = null,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?string $description = null,
    ): ?DriverLedgerEntry {
        if ($amount <= 0.0) {
            return null;
        }

        $existing = $this->existing($companyId, $sourceType, $sourceId);
        if ($existing !== null) {
            return $existing;
        }

        $receivable = $this->roles->resolve($companyId, 'driver_receivable');
        $recovery = $this->roles->resolve($companyId, 'driver_shortage_recovery');

        return $this->post(
            $companyId, $driverId, DriverLedgerEntryType::Shortage, round($amount, 4),
            debitAccountId: $receivable, creditAccountId: $recovery,
            date: $date, actorId: $actorId, sourceType: $sourceType, sourceId: $sourceId,
            description: $description ?? 'Approved driver shortage',
        );
    }

    /**
     * Reverse a driver ledger entry's journal — the same reversal pattern as
     * every other Task 5/6/7 posting: JournalEngine::reverse() plus one new,
     * append-only, sign-flipped DriverLedgerEntry.
     */
    public function reverseDriverLedgerPosting(DriverLedgerEntry $entry, string $reason, ?int $actorId = null): ?JournalEntry
    {
        if ($entry->journal_entry_id === null) {
            return null;
        }

        return DB::transaction(function () use ($entry, $reason, $actorId): JournalEntry {
            $journal = JournalEntry::query()->whereKey($entry->journal_entry_id)->firstOrFail();
            $reversalJournal = $this->journalEngine->reverse($journal, $reason, $actorId);

            DriverLedgerEntry::create([
                'company_id' => $entry->company_id,
                'driver_id' => $entry->driver_id,
                'entry_date' => Carbon::today(),
                'entry_type' => $entry->entry_type->value,
                'amount' => round((float) $entry->amount * -1, 4),
                'source_type' => 'ledger_entry_reversal',
                'source_id' => $entry->uuid,
                'journal_entry_id' => $reversalJournal->id,
                'description' => 'Reversal of '.$entry->description,
            ]);

            return $reversalJournal;
        });
    }

    private function existing(string $companyId, ?string $sourceType, ?string $sourceId): ?DriverLedgerEntry
    {
        if ($sourceType === null || $sourceId === null) {
            return null;
        }

        return DriverLedgerEntry::query()
            ->where('company_id', $companyId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->first();
    }

    private function post(
        string $companyId,
        string $driverId,
        DriverLedgerEntryType $type,
        float $amount,
        int $debitAccountId,
        int $creditAccountId,
        Carbon $date,
        ?int $actorId,
        ?string $sourceType,
        ?string $sourceId,
        string $description,
    ): DriverLedgerEntry {
        if ($amount <= 0.0) {
            throw FinanceException::allocationMustBePositive();
        }

        $eventId = $sourceType !== null && $sourceId !== null
            ? $type->value.':'.$sourceType.':'.$sourceId
            : $type->value.':'.$companyId.':'.$driverId.':'.uniqid('', true);

        $request = new PostingRequest(
            companyId: $companyId,
            entryDate: $date,
            lines: [
                PostingLine::debit($debitAccountId, $amount, $companyId),
                PostingLine::credit($creditAccountId, $amount, $companyId),
            ],
            reference: $driverId,
            description: $description,
            source: 'posting',
            sourceModule: self::SOURCE_MODULE,
            sourceEventId: $eventId,
            journalType: JournalType::General->value,
        );

        return DB::transaction(function () use ($companyId, $driverId, $type, $amount, $date, $actorId, $sourceType, $sourceId, $description, $eventId, $request): DriverLedgerEntry {
            $journal = $this->coordinator->post(self::SOURCE_MODULE, $eventId, $request, null, $actorId);

            return DriverLedgerEntry::create([
                'company_id' => $companyId,
                'driver_id' => $driverId,
                'entry_date' => $date->toDateString(),
                'entry_type' => $type->value,
                'amount' => round($amount * $type->sign(), 4),
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'journal_entry_id' => $journal->id,
                'description' => $description,
            ]);
        });
    }
}
