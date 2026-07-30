<?php

declare(strict_types=1);

namespace Modules\Finance\Ledger\Domain\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A refusal an accountant can act on.
 *
 * Financial refusals are never vague: an unbalanced journal says by how much,
 * a closed-period posting names the period, an immutability violation says what
 * was protected. "Posting failed" teaches nobody anything.
 */
class FinanceException extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }

    // ── Double entry ───────────────────────────────────────────────────────────

    public static function unbalanced(string $debits, string $credits): self
    {
        return new self(
            "The journal does not balance: debits {$debits} ≠ credits {$credits}. Every entry must have equal debits and credits."
        );
    }

    public static function noLines(): self
    {
        return new self('A journal must have at least two lines.');
    }

    public static function lineNotSingleSided(): self
    {
        return new self('Each journal line must be exactly one of debit or credit, and greater than zero.');
    }

    public static function zeroValue(): self
    {
        return new self('A journal must move a non-zero amount.');
    }

    // ── Accounts ────────────────────────────────────────────────────────────────

    public static function accountNotPostable(string $code): self
    {
        return new self("Account {$code} is a rollup account and cannot be posted to directly.");
    }

    public static function accountInactive(string $code): self
    {
        return new self("Account {$code} is inactive.");
    }

    public static function accountNotFound(string $ref): self
    {
        return new self("Account {$ref} does not exist.");
    }

    public static function controlAccountManual(string $code): self
    {
        return new self("Account {$code} is a control account and cannot be posted to manually; it is moved only by its subledger.");
    }

    // ── Immutability ─────────────────────────────────────────────────────────────

    public static function journalImmutable(): self
    {
        return new self('A posted journal is immutable. Correct it with a reversing entry, never an edit.');
    }

    public static function alreadyReversed(): self
    {
        return new self('That journal has already been reversed.');
    }

    public static function cannotReverseDraft(): self
    {
        return new self('Only a posted journal can be reversed.');
    }

    // ── Period control ───────────────────────────────────────────────────────────

    public static function periodNotOpen(string $period, string $status): self
    {
        return new self("Fiscal period {$period} is {$status}; postings are only accepted while it is open.");
    }

    public static function noPeriodForDate(string $date): self
    {
        return new self("No fiscal period covers {$date}. Create and open the period first.");
    }

    public static function invalidPeriodTransition(string $from, string $to): self
    {
        return new self("A fiscal period cannot move from {$from} to {$to}.");
    }

    public static function periodHasNoOpenYear(): self
    {
        return new self('The period\'s fiscal year is not open.');
    }

    // ── Segregation of duties ──────────────────────────────────────────────────

    public static function makerCannotApproveOwn(): self
    {
        return new self('The person who created a journal may not approve it. Segregation of duties requires a second person.');
    }

    public static function journalNotApproved(): self
    {
        return new self('A journal must be approved before it can be posted.');
    }

    // ── Posting engine ───────────────────────────────────────────────────────────

    public static function postingRuleNotFound(string $event): self
    {
        return new self("No active posting rule maps the event '{$event}'.");
    }

    // ── Subledgers (AR / AP / Cash / Banking) — EPIC F2 ─────────────────────────

    public static function controlAccountNotConfigured(string $subledger): self
    {
        return new self(
            "No {$subledger} control account is configured. Mark a chart-of-accounts node as the {$subledger} control before posting subledger documents."
        );
    }

    public static function documentAlreadyPosted(string $kind, string $number): self
    {
        return new self("{$kind} {$number} is already posted. A posted document is immutable; correct it with a credit/debit note or a reversal.");
    }

    public static function documentVoided(string $kind, string $number): self
    {
        return new self("{$kind} {$number} is voided and cannot be posted, allocated, or amended.");
    }

    public static function documentNotPosted(string $kind, string $number): self
    {
        return new self("{$kind} {$number} must be posted before it can be allocated or settled.");
    }

    public static function documentHasNoLines(string $kind): self
    {
        return new self("A {$kind} must have at least one line before it can be posted.");
    }

    public static function documentUnbalancedTotal(string $kind): self
    {
        return new self("The {$kind} total does not equal the sum of its lines plus tax. Recompute before posting.");
    }

    public static function allocationExceedsSource(string $source, string $available): self
    {
        return new self("This allocation exceeds the unallocated balance of the {$source} ({$available} remaining).");
    }

    public static function allocationExceedsDocument(string $kind, string $outstanding): self
    {
        return new self("This allocation exceeds the outstanding balance of {$kind} ({$outstanding} remaining).");
    }

    public static function allocationPartyMismatch(): self
    {
        return new self('A receipt or payment can only be allocated to documents of the same party.');
    }

    public static function allocationMustBePositive(): self
    {
        return new self('An allocation amount must be greater than zero.');
    }

    public static function paymentNotApproved(string $number): self
    {
        return new self("Payment {$number} must be approved before it can be posted. Money leaving the business needs a second person.");
    }

    public static function paymentAlreadyApproved(string $number): self
    {
        return new self("Payment {$number} is already approved.");
    }

    public static function approverCannotBeMaker(): self
    {
        return new self('The person who created a payment may not approve it. Segregation of duties requires a second person.');
    }

    public static function cashSessionAlreadyOpen(string $account): self
    {
        return new self("Cash account {$account} already has an open session. Close it before opening another.");
    }

    public static function cashSessionNotOpen(): self
    {
        return new self('That cash session is not open.');
    }

    public static function reconciliationAlreadyCompleted(): self
    {
        return new self('That bank reconciliation is already completed and is immutable.');
    }

    public static function reconciliationNotBalanced(string $difference): self
    {
        return new self("The reconciliation cannot be completed: an unexplained difference of {$difference} remains. Match or explain every item first.");
    }

    public static function statementLineAlreadyMatched(): self
    {
        return new self('That statement line is already matched.');
    }
}
