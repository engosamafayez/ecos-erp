<?php

declare(strict_types=1);

namespace Tests\Unit\Admin\GoLive;

use Modules\Admin\GoLive\Domain\Enums\ResetDomain;
use Modules\Admin\GoLive\Domain\Services\UnsafeCombinationRule;
use PHPUnit\Framework\TestCase;

/**
 * TASK-...-026 §6/§21.6 — proves the unsafe-combination rule without a database (plain
 * PHPUnit\TestCase — the class under test takes its counts as plain arguments, same discipline as
 * TASK-...-025's EcosOrderStatusToWooTranslatorTest / this project's own OrderStatusV3ContractTest).
 */
final class UnsafeCombinationRuleTest extends TestCase
{
    public function test_commerce_alone_is_always_safe(): void
    {
        self::assertSame([], UnsafeCombinationRule::evaluate([ResetDomain::Commerce], remainingOrders: 500));
    }

    public function test_inventory_alone_is_safe_when_no_orders_remain(): void
    {
        self::assertSame([], UnsafeCombinationRule::evaluate([ResetDomain::Inventory], remainingOrders: 0));
    }

    public function test_inventory_alone_is_blocked_when_orders_remain(): void
    {
        $blockers = UnsafeCombinationRule::evaluate([ResetDomain::Inventory], remainingOrders: 7);

        self::assertCount(1, $blockers);
        self::assertStringContainsString('Inventory', $blockers[0]);
        self::assertStringContainsString('7', $blockers[0]);
    }

    public function test_finance_alone_is_blocked_when_orders_remain(): void
    {
        $blockers = UnsafeCombinationRule::evaluate([ResetDomain::Finance], remainingOrders: 3);

        self::assertCount(1, $blockers);
        self::assertStringContainsString('Finance', $blockers[0]);
    }

    public function test_inventory_and_finance_together_without_commerce_produce_two_blockers(): void
    {
        $blockers = UnsafeCombinationRule::evaluate([ResetDomain::Inventory, ResetDomain::Finance], remainingOrders: 1);

        self::assertCount(2, $blockers);
    }

    public function test_inventory_and_finance_are_safe_when_commerce_is_also_selected(): void
    {
        self::assertSame(
            [],
            UnsafeCombinationRule::evaluate(
                [ResetDomain::Commerce, ResetDomain::Inventory, ResetDomain::Finance],
                remainingOrders: 42,
            ),
        );
    }

    public function test_operations_alone_is_never_blocked_by_this_rule(): void
    {
        // Operations has no polymorphic Order-reference concern this rule protects against —
        // its own FK chain to Orders is real (cascade/restrict), enforced by the DB itself.
        self::assertSame([], UnsafeCombinationRule::evaluate([ResetDomain::Operations], remainingOrders: 999));
    }
}
