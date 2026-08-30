<?php

declare(strict_types=1);

namespace Modules\Finance\Payables\Domain\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Ledger\Domain\Enums\JournalType;
use Modules\Finance\Ledger\Domain\Exceptions\FinanceException;
use Modules\Finance\Ledger\Domain\Models\Account;
use Modules\Finance\Ledger\Domain\ValueObjects\PostingLine;
use Modules\Finance\Ledger\Domain\ValueObjects\PostingRequest;
use Modules\Finance\Payables\Domain\Enums\SupplierLedgerEntryType;
use Modules\Finance\Payables\Domain\Models\SupplierBill;
use Modules\Finance\Payables\Domain\Models\SupplierLedgerEntry;
use Modules\Finance\Posting\Domain\Services\PostingCoordinator;
use Modules\Finance\Shared\Domain\Services\ControlAccountResolver;
use RuntimeException;

/**
 * TASK-PROC-SUPPLIER-OPENING-BALANCE-001 — supplier opening balances (approved contract).
 *
 * TWO independent onboarding types, both posted as first-class JournalType::Opening entries
 * through the canonical PostingCoordinator (source='posting' — the same path YearEndClosing
 * uses to move control accounts, so no manual-journal control guard fires). This is glue over
 * the existing Finance engine: NO second accounting engine, NO new posting rule, NO fake bill.
 *
 *   • Supplier Payable opening — pre-ECOS liability owed TO the supplier.
 *       DR 3600 Opening Balance Equity / CR 2110 AP Control.  Ledger: +opening_payable.
 *   • Supplier Advance opening — prepayment PAID TO the supplier for undelivered future supply.
 *       DR 1520 Supplier Advances / CR 3600 Opening Balance Equity.  Ledger: −advance (a credit
 *       position shown SEPARATELY as Available Advance, never as debt).
 *
 * Neither creates a Purchase, Purchase Bill, PO, Goods Receipt, Stock Movement or Inventory —
 * they touch only Finance accounts + the supplier subledger. Posting is idempotent (deterministic
 * PostingCoordinator source_event_id + a subledger-entry existence guard), so re-posting the same
 * supplier's opening balance is a no-op.
 */
final class SupplierOpeningBalanceService
{
    private const SOURCE_MODULE = 'supplier_opening';

    private const OPENING_BALANCE_EQUITY_CODE = '3600';

    private const SUPPLIER_ADVANCES_CODE = '1520';

    public function __construct(
        private readonly PostingCoordinator $coordinator,
        private readonly ControlAccountResolver $control,
    ) {}

    /**
     * Post a Supplier Payable opening balance: DR 3600 / CR 2110 (AP control). Idempotent.
     */
    public function postOpeningPayable(
        string $companyId,
        string $supplierId,
        string $supplierCode,
        float $amount,
        Carbon $entryDate,
        ?string $reference = null,
        ?string $notes = null,
        ?int $actorId = null,
    ): SupplierLedgerEntry {
        $this->assertPositive($amount);
        $amount = round($amount, 4);

        $sourceType = 'supplier_opening_payable';
        if ($existing = $this->existingEntry($sourceType, $supplierId)) {
            return $existing; // already posted — idempotent no-op
        }

        $equity = $this->accountByCode($companyId, self::OPENING_BALANCE_EQUITY_CODE);
        $apControl = $this->control->payable($companyId);
        $eventId = $sourceType.':'.$supplierId;

        return DB::transaction(function () use (
            $companyId, $supplierId, $supplierCode, $amount, $entryDate, $reference, $notes, $actorId, $equity, $apControl, $sourceType, $eventId
        ): SupplierLedgerEntry {
            $journal = $this->coordinator->post(
                self::SOURCE_MODULE,
                $eventId,
                new PostingRequest(
                    companyId: $companyId,
                    entryDate: $entryDate,
                    lines: [
                        PostingLine::debit((int) $equity->id, $amount, $companyId),
                        PostingLine::credit((int) $apControl->id, $amount, $companyId),
                    ],
                    reference: $reference ?? 'OB-'.$supplierCode,
                    description: 'Supplier opening payable '.$supplierCode.($notes !== null ? ' — '.$notes : ''),
                    source: 'posting',
                    sourceModule: self::SOURCE_MODULE,
                    sourceEventId: $eventId,
                    journalType: JournalType::Opening->value,
                ),
                null,
                $actorId,
            );

            return SupplierLedgerEntry::create([
                'company_id' => $companyId,
                'supplier_id' => $supplierId,
                'entry_date' => $entryDate->toDateString(),
                'entry_type' => SupplierLedgerEntryType::OpeningPayable->value,
                'amount' => $amount, // +ve — increases the payable
                'source_type' => $sourceType,
                'source_id' => $supplierId,
                'journal_entry_id' => $journal->id,
                'description' => 'Opening payable '.($reference ?? 'OB-'.$supplierCode),
            ]);
        });
    }

    /**
     * Post a Supplier Advance opening balance: DR 1520 / CR 3600. Idempotent.
     * Stored as a −ve ledger entry (a credit position) surfaced separately as Available Advance.
     */
    public function postOpeningAdvance(
        string $companyId,
        string $supplierId,
        string $supplierCode,
        float $amount,
        Carbon $entryDate,
        ?string $reference = null,
        ?string $notes = null,
        ?int $actorId = null,
    ): SupplierLedgerEntry {
        $this->assertPositive($amount);
        $amount = round($amount, 4);

        $sourceType = 'supplier_opening_advance';
        if ($existing = $this->existingEntry($sourceType, $supplierId)) {
            return $existing;
        }

        $advances = $this->accountByCode($companyId, self::SUPPLIER_ADVANCES_CODE);
        $equity = $this->accountByCode($companyId, self::OPENING_BALANCE_EQUITY_CODE);
        $eventId = $sourceType.':'.$supplierId;

        return DB::transaction(function () use (
            $companyId, $supplierId, $supplierCode, $amount, $entryDate, $reference, $notes, $actorId, $advances, $equity, $sourceType, $eventId
        ): SupplierLedgerEntry {
            $journal = $this->coordinator->post(
                self::SOURCE_MODULE,
                $eventId,
                new PostingRequest(
                    companyId: $companyId,
                    entryDate: $entryDate,
                    lines: [
                        PostingLine::debit((int) $advances->id, $amount, $companyId),
                        PostingLine::credit((int) $equity->id, $amount, $companyId),
                    ],
                    reference: $reference ?? 'OA-'.$supplierCode,
                    description: 'Supplier opening advance '.$supplierCode.($notes !== null ? ' — '.$notes : ''),
                    source: 'posting',
                    sourceModule: self::SOURCE_MODULE,
                    sourceEventId: $eventId,
                    journalType: JournalType::Opening->value,
                ),
                null,
                $actorId,
            );

            return SupplierLedgerEntry::create([
                'company_id' => $companyId,
                'supplier_id' => $supplierId,
                'entry_date' => $entryDate->toDateString(),
                'entry_type' => SupplierLedgerEntryType::Advance->value,
                'amount' => -$amount, // −ve — a prepaid credit, shown as Available Advance
                'source_type' => $sourceType,
                'source_id' => $supplierId,
                'journal_entry_id' => $journal->id,
                'description' => 'Opening advance '.($reference ?? 'OA-'.$supplierCode),
            ]);
        });
    }

    /**
     * Settle an available supplier advance against a posted supplier bill (the "settleable later"
     * contract). GL: DR 2110 AP control / CR 1520 Supplier Advances — clearing the prepaid asset
     * against the payable. Ledger: a −payment mirrors the AP reduction, a +advance consumes the
     * advance. The ledger (the SSOT) reflects both; no PaymentAllocation is used (an advance is
     * not a cash payment). Reuses the canonical PostingCoordinator — no new engine.
     */
    public function applyAdvanceToBill(SupplierBill $bill, float $amount, ?int $actorId = null): void
    {
        $this->assertPositive($amount);
        $amount = round($amount, 4);

        if (! $bill->isPosted()) {
            throw new RuntimeException('Cannot apply an advance to an unposted bill.');
        }

        $companyId = (string) $bill->company_id;
        $supplierId = (string) $bill->supplier_id;
        $ledger = app(SupplierLedgerService::class);

        $available = $ledger->availableAdvance($companyId, $supplierId);
        if ($amount > round($available, 4)) {
            throw new RuntimeException('Advance application ('.$amount.') exceeds the available advance ('.$available.').');
        }

        $payable = $ledger->outstandingPayable($companyId, $supplierId);
        if ($amount > round($payable, 4)) {
            throw new RuntimeException('Advance application ('.$amount.') exceeds the outstanding payable ('.$payable.').');
        }

        $advances = $this->accountByCode($companyId, self::SUPPLIER_ADVANCES_CODE);
        $apControl = $this->control->payable($companyId);
        $eventId = 'advance_apply:'.$bill->uuid.':'.$amount;

        DB::transaction(function () use ($companyId, $supplierId, $bill, $amount, $actorId, $advances, $apControl, $eventId): void {
            $journal = $this->coordinator->post(
                self::SOURCE_MODULE,
                $eventId,
                new PostingRequest(
                    companyId: $companyId,
                    entryDate: Carbon::now(),
                    lines: [
                        PostingLine::debit((int) $apControl->id, $amount, $companyId),
                        PostingLine::credit((int) $advances->id, $amount, $companyId),
                    ],
                    reference: 'ADV-APPLY-'.$bill->number,
                    description: 'Supplier advance applied to bill '.$bill->number,
                    source: 'posting',
                    sourceModule: self::SOURCE_MODULE,
                    sourceEventId: $eventId,
                    journalType: JournalType::Adjustment->value,
                ),
                null,
                $actorId,
            );

            // Mirror both GL legs on the supplier subledger (the SSOT).
            SupplierLedgerEntry::create([
                'company_id' => $companyId,
                'supplier_id' => $supplierId,
                'entry_date' => Carbon::now()->toDateString(),
                'entry_type' => SupplierLedgerEntryType::Payment->value,
                'amount' => -$amount, // reduces Outstanding Payable
                'source_type' => 'advance_settlement',
                'source_id' => (string) $bill->uuid,
                'journal_entry_id' => $journal->id,
                'description' => 'Advance applied to bill '.$bill->number,
            ]);

            SupplierLedgerEntry::create([
                'company_id' => $companyId,
                'supplier_id' => $supplierId,
                'entry_date' => Carbon::now()->toDateString(),
                'entry_type' => SupplierLedgerEntryType::Advance->value,
                'amount' => $amount, // consumes the advance — reduces Available Advance
                'source_type' => 'advance_settlement',
                'source_id' => (string) $bill->uuid,
                'journal_entry_id' => $journal->id,
                'description' => 'Advance consumed on bill '.$bill->number,
            ]);
        });
    }

    private function existingEntry(string $sourceType, string $supplierId): ?SupplierLedgerEntry
    {
        return SupplierLedgerEntry::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $supplierId)
            ->first();
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

    private function assertPositive(float $amount): void
    {
        if ($amount <= 0.0) {
            throw new RuntimeException('Opening balance amount must be greater than zero.');
        }
    }
}
