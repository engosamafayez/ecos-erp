<?php

declare(strict_types=1);

namespace Tests\Feature\Manufacturing;

use Modules\Manufacturing\DecisionKernel\Domain\Enums\DecisionType;
use Modules\Manufacturing\DecisionKernel\Domain\Services\DecisionKernel;
use Modules\Manufacturing\DecisionKernel\Domain\ValueObjects\DecisionContext;
use Modules\Manufacturing\DecisionKernel\Domain\ValueObjects\DecisionTrigger;
use Modules\Manufacturing\DecisionOrchestrator\Domain\Builders\ManufacturingContextBuilder;
use Modules\Manufacturing\DecisionOrchestrator\Domain\Contracts\RuleProviderRegistryInterface;
use Modules\Manufacturing\ManufacturingWorkflow\Domain\Services\ManufacturingKernelGateProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * TASK-MANUFACTURING-THIN-GATE-IMPLEMENTATION-001 — Option A thin pass-through gate.
 *
 * Proves (without mocking the registry) that the REAL application registration resolves a
 * production `manufacturing` provider, that the provider is a pass-through (Approve for any
 * context), and that it is structurally thin — it owns no manufacturing business rule and
 * cannot inspect inventory/recipe, so it is NOT a second manufacturing authority.
 *
 * No DB, no inventory, no manufacturing execution — the gate is a pure domain admission.
 */
class ManufacturingKernelGateProviderTest extends TestCase
{
    // ── Part 7: the REAL application registry resolves the production provider ──

    public function test_application_registry_resolves_the_real_manufacturing_provider(): void
    {
        // No forgetInstance / no manual registration — this exercises the actual boot-time
        // registration in ManufacturingWorkflowServiceProvider::boot().
        $provider = app(RuleProviderRegistryInterface::class)->for('manufacturing');

        self::assertInstanceOf(ManufacturingKernelGateProvider::class, $provider);
    }

    public function test_registry_has_manufacturing_context_registered(): void
    {
        self::assertTrue(app(RuleProviderRegistryInterface::class)->has('manufacturing'));
    }

    // ── Part 8: the gate is a pass-through — Approve regardless of availability ──

    public function test_gate_returns_approve_regardless_of_availability_context(): void
    {
        $provider = app(RuleProviderRegistryInterface::class)->for('manufacturing');
        $kernel = app(DecisionKernel::class);
        $builder = new ManufacturingContextBuilder;
        $trigger = DecisionTrigger::now(type: 'MFG_REQUEST', id: 'trigger-1');

        // Two OPPOSITE availability contexts. A gate that "decided" on availability would
        // differ; a pass-through returns Approve for both — proving the real decision is
        // made downstream (InventoryAvailabilityEngine), not here.
        $bigShortage = $builder->build([
            'product_id' => 'prod-1', 'ordered_qty' => 100.0, 'available_qty' => 0.0, 'shortage_qty' => 100.0,
        ]);
        $noShortage = $builder->build([
            'product_id' => 'prod-1', 'ordered_qty' => 1.0, 'available_qty' => 100.0, 'shortage_qty' => 0.0,
        ]);

        self::assertSame(DecisionType::Approve, $kernel->evaluate($trigger, $bigShortage, $provider)->decision);
        self::assertSame(DecisionType::Approve, $kernel->evaluate($trigger, $noShortage, $provider)->decision);
    }

    // ── Part 9: no second authority — the provider is intentionally thin ──

    public function test_provider_exposes_exactly_one_pass_through_approve_rule(): void
    {
        $rules = (new ManufacturingKernelGateProvider)->rules();

        self::assertCount(1, $rules);
        $rule = $rules[0];
        self::assertSame(DecisionType::Approve, $rule->decisionType());
        self::assertSame(ManufacturingKernelGateProvider::RULE_ID, $rule->ruleId());
        self::assertSame(0, $rule->priority(), 'lowest priority so a future real rule overrides the pass-through');
        // Not an MFG-00x business-rule code — it is the kernel gate, not a business rule.
        self::assertStringNotContainsString('MFG-00', $rule->ruleId());
    }

    public function test_rule_condition_is_constant_and_ignores_all_context_values(): void
    {
        $rule = (new ManufacturingKernelGateProvider)->rules()[0];

        // Wildly different contexts — including an empty one and shortage/no-shortage —
        // all match, proving the predicate computes no shortage and inspects no stock.
        self::assertTrue($rule->matches(new DecisionContext('manufacturing')));
        self::assertTrue($rule->matches((new DecisionContext('manufacturing'))->with('shortage_qty', 999.0)->with('available_qty', 0.0)));
        self::assertTrue($rule->matches((new DecisionContext('manufacturing'))->with('shortage_qty', 0.0)->with('available_qty', 999.0)));
    }

    public function test_provider_is_structurally_thin_with_no_injected_authority(): void
    {
        // A dependency-free constructor means it CANNOT reach inventory/recipe/order
        // services — the "no second authority" guarantee is structural, not conventional.
        $ctor = (new ReflectionClass(ManufacturingKernelGateProvider::class))->getConstructor();

        self::assertTrue(
            $ctor === null || $ctor->getNumberOfParameters() === 0,
            'the pass-through gate must inject no manufacturing authority/service',
        );
    }
}
