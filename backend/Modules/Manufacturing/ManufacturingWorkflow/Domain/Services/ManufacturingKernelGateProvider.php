<?php

declare(strict_types=1);

namespace Modules\Manufacturing\ManufacturingWorkflow\Domain\Services;

use Modules\Manufacturing\DecisionKernel\Domain\Contracts\DecisionRuleInterface;
use Modules\Manufacturing\DecisionKernel\Domain\Contracts\RuleProviderInterface;
use Modules\Manufacturing\DecisionKernel\Domain\Enums\DecisionType;
use Modules\Manufacturing\DecisionKernel\Domain\ValueObjects\DecisionContext;
use Modules\Manufacturing\DecisionKernel\Domain\ValueObjects\DecisionReason;
use Modules\Manufacturing\DecisionKernel\Domain\ValueObjects\DecisionRule;

/**
 * Manufacturing KERNEL PASS-THROUGH GATE — the rule provider for the `manufacturing`
 * Decision-Kernel context (TASK-MANUFACTURING-THIN-GATE-IMPLEMENTATION-001; Option A of
 * TASK-MANUFACTURING-DECISION-ARCHITECTURE-DECISION-001).
 *
 * ⚠️ THIS IS NOT A MANUFACTURING AUTHORITY. It owns NO manufacturing business rule.
 *
 * Its single rule returns APPROVE unconditionally, and APPROVE here means exactly one
 * thing: "let the canonical manufacturing pipeline continue to its EXISTING authorities."
 * It does **not** mean "manufacture regardless of conditions." The authoritative
 * manufacturing decisions are made — before and after this gate — by:
 *
 *   • ManufacturingPolicy               — eligibility (order not cancelled, status allowed,
 *                                          active recipe, inventory-managed, required>0,
 *                                          not-already-manufactured). Runs in the handler
 *                                          BEFORE the workflow reaches this gate.
 *   • InventoryAvailabilityEngine       — availability + EXACT production quantity +
 *                                          raw-material sufficiency + allow_negative
 *                                          (the frozen MFG-005..008 outcomes: Sufficient /
 *                                          CanManufacture / Partial / CannotManufacture).
 *                                          Runs AFTER this gate (workflow stage 2) and can
 *                                          still block a request this gate "approved".
 *   • ManufacturingPlanner / Executor   — plan + execute.
 *
 * DO NOT, in this provider, add any of: stock/availability checks, shortage calculation,
 * recipe checks, raw-material checks, negative-stock logic, production-quantity logic,
 * `can_manufacture` gating (MFG-001 was superseded by ADR-027 v1.5 — do NOT resurrect it),
 * thresholds, profitability/demand/procurement rules. Adding any of those turns this into
 * a forbidden SECOND manufacturing authority and duplicates the services above.
 *
 * The provider is intentionally dependency-free: with no inventory/recipe/order services
 * injected, it structurally CANNOT inspect on_hand/reserved/recipe/etc. — that is the
 * guarantee, not merely a convention.
 *
 * The single rule is priority 0 (the lowest) so that if a genuine, owner-approved
 * manufacturing rule is ever added, its higher priority wins over this pass-through
 * (the kernel selects the highest-priority matching rule).
 */
final class ManufacturingKernelGateProvider implements RuleProviderInterface
{
    /** Rule id for the pass-through gate — deliberately NOT an MFG-00x business-rule code. */
    public const RULE_ID = 'MFG-KERNEL-GATE';

    /** @return list<DecisionRuleInterface> */
    public function rules(): array
    {
        return [
            new DecisionRule(
                rule_id: self::RULE_ID,
                name: 'Manufacturing kernel pass-through gate',
                priority: 0, // lowest — any future owner-approved real rule (higher priority) wins
                decision_type: DecisionType::Approve,
                reason: new DecisionReason(
                    code: 'mfg_kernel_gate_pass_through',
                    message: 'Kernel pass-through: the canonical manufacturing authorities '
                        .'(ManufacturingPolicy + InventoryAvailabilityEngine) decide downstream.',
                    context: ['authority' => 'downstream', 'pass_through' => true],
                ),
                // Constant predicate — reads no context, evaluates no business rule.
                condition: static fn (DecisionContext $context): bool => true,
                metadata: ['pass_through' => true],
            ),
        ];
    }
}
