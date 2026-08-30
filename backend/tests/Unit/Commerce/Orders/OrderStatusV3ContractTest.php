<?php

declare(strict_types=1);

namespace Tests\Unit\Commerce\Orders;

use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use PHPUnit\Framework\TestCase;

/**
 * ADR-042 Order FSM V3 — enum-level contract invariants (§2.2 lock model, §8 no legacy repair).
 *
 * TASK-ECOS-ADR-042-TARGETED-REMEDIATION-001 (D6). These two clauses are pure properties of
 * the `OrderStatus` enum and are proven here without a database or the HTTP surface, so the
 * assertions cannot drift with fixtures. This is a plain PHPUnit\TestCase (no Laravel boot):
 * the enum has no container dependency.
 *
 * TEST EXECUTION DEFERRED — added under the ADR-042 remediation but NOT run (project freeze).
 */
final class OrderStatusV3ContractTest extends TestCase
{
    /**
     * §2.2 — the ONLY unlocked (mutable-entry) statuses are the three entry states. Every
     * other case is locked. `isLocked()` is the exact negation of `entryStatuses()`; this
     * asserts both halves so a case added to one list without the other is caught.
     */
    public function test_is_locked_is_false_only_for_the_three_entry_statuses(): void
    {
        $unlocked = [
            OrderStatus::InProgress,
            OrderStatus::Scheduled,
            OrderStatus::AwaitingPayment,
        ];

        foreach (OrderStatus::cases() as $status) {
            $expectedLocked = ! in_array($status, $unlocked, true);

            self::assertSame(
                $expectedLocked,
                $status->isLocked(),
                "isLocked() disagrees with the §2.2 unlocked set for {$status->value}",
            );
        }
    }

    /** §2.2 — the unlocked set is exactly `entryStatuses()`; the two must never diverge. */
    public function test_unlocked_set_equals_entry_statuses(): void
    {
        $unlockedValues = array_values(array_map(
            static fn (OrderStatus $s): string => $s->value,
            array_filter(OrderStatus::cases(), static fn (OrderStatus $s): bool => ! $s->isLocked()),
        ));

        $entryValues = array_map(
            static fn (OrderStatus $s): string => $s->value,
            OrderStatus::entryStatuses(),
        );

        sort($unlockedValues);
        sort($entryValues);

        self::assertSame($entryValues, $unlockedValues, 'unlocked statuses must equal §3 entryStatuses()');
    }

    /**
     * §8 — no LEGACY_STATUS_MAP. Pre-V3 vocabulary is rejected at the edge, never silently
     * remapped: `tryFrom()` on any legacy value returns null rather than resurrecting a case.
     * `new` is the headline removal (ADR-042 §2.1); the rest are the pre-V3 synonyms V3 folded
     * away.
     *
     * @return array<string, array{0: string}>
     */
    public static function legacyStatusProvider(): array
    {
        return [
            'new (removed §2.1)' => ['new'],
            'pending' => ['pending'],
            'processing' => ['processing'],
            'preparing' => ['preparing'],
            'completed' => ['completed'],
            'review' => ['review'],
            'rescheduled' => ['rescheduled'],
        ];
    }

    /**
     * @dataProvider legacyStatusProvider
     */
    public function test_legacy_status_values_are_not_valid_enum_cases(string $legacy): void
    {
        self::assertNull(
            OrderStatus::tryFrom($legacy),
            "'{$legacy}' resolved to a case — §8 forbids read-time legacy repair",
        );
    }

    /** §2.1 — the canonical enum is exactly the 11 approved cases (no legacy leaked back in). */
    public function test_enum_holds_exactly_the_eleven_canonical_cases(): void
    {
        $values = array_map(static fn (OrderStatus $s): string => $s->value, OrderStatus::cases());

        self::assertSame([
            'in_progress',
            'confirmed',
            'ready_for_dispatch',
            'out_for_delivery',
            'delivered',
            'awaiting_payment',
            'awaiting_stock',
            'scheduled',
            'on_hold',
            'cancelled',
            'returned',
        ], $values);
    }
}
