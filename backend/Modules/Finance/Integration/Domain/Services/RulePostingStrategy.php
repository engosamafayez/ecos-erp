<?php

declare(strict_types=1);

namespace Modules\Finance\Integration\Domain\Services;

use Modules\Finance\Integration\Domain\Enums\BusinessEventType;
use Modules\Finance\Integration\Domain\ValueObjects\FinancialEvent;
use Modules\Finance\Ledger\Domain\Enums\JournalType;
use Modules\Finance\Ledger\Domain\Exceptions\FinanceException;
use Modules\Finance\Ledger\Domain\ValueObjects\PostingLine;
use Modules\Finance\Ledger\Domain\ValueObjects\PostingRequest;
use Modules\Finance\Posting\Domain\Contracts\PostingStrategyInterface;
use Modules\Finance\Posting\Domain\Models\PostingRule;

/**
 * The rule-driven posting strategy — the F3 sibling of DirectPostingStrategy.
 *
 * ┌─ CONFIGURATION → A BALANCED JOURNAL, NO HARDCODED ACCOUNTS ──────────────┐
 * │ It reads a rule's legs ({side, role, source}), resolves each role to the  │
 * │ company's account and each amount by name from the event, and returns a   │
 * │ balanced PostingRequest. It resolves accounts and amounts; it NEVER writes │
 * │ the ledger. A leg whose amount is zero (e.g. no tax) drops out, so one     │
 * │ rule serves both taxed and untaxed events without branching.              │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class RulePostingStrategy implements PostingStrategyInterface
{
    public function __construct(private readonly AccountRoleResolver $roles) {}

    public function supports(string $eventType): bool
    {
        return BusinessEventType::tryFromValue($eventType) !== null;
    }

    /**
     * @param  array<string, mixed>  $context  {event: FinancialEvent, rule: PostingRule}
     */
    public function build(string $eventType, array $context): PostingRequest
    {
        return $this->buildFromRule($context['rule'], $context['event']);
    }

    /** The typed entry point the processor uses. */
    public function buildFromRule(PostingRule $rule, FinancialEvent $event): PostingRequest
    {
        $legs = $rule->legs ?? [];
        if ($legs === []) {
            throw FinanceException::postingRuleHasNoLegs((string) $rule->code);
        }

        $dimensions = [
            'branchId' => $event->branchId(),
            'costCenterId' => $event->costCenterId(),
            'currency' => $event->currency,
        ];

        $lines = [];
        foreach ($legs as $leg) {
            $amount = $event->amount((string) $leg['source']);
            if ($amount <= 0.0) {
                continue; // a zero leg (e.g. no VAT on this event) simply drops out
            }

            $accountId = $this->roles->resolve($event->companyId, (string) $leg['role']);
            $lineDims = $dimensions + ['description' => $leg['description'] ?? null];

            $lines[] = ((string) $leg['side'] === 'debit')
                ? PostingLine::debit($accountId, $amount, $event->companyId, $lineDims)
                : PostingLine::credit($accountId, $amount, $event->companyId, $lineDims);
        }

        return new PostingRequest(
            companyId: $event->companyId,
            entryDate: $event->occurredAt,
            lines: $lines,
            reference: $event->reference ?? ($event->entityType.'#'.$event->entityId),
            description: $event->description ?? $rule->description ?? $event->eventCode(),
            source: 'posting',
            sourceModule: $event->sourceModule,
            sourceEventId: $event->idempotencyKey,
            journalType: $this->journalTypeFor($event->eventType)->value,
        );
    }

    private function journalTypeFor(BusinessEventType $type): JournalType
    {
        return match ($type->module()) {
            'pos' => JournalType::Sales,
            'procurement' => JournalType::Purchase,
            default => JournalType::General,
        };
    }
}
