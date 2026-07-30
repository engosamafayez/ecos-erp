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
}
