<?php

declare(strict_types=1);

namespace Modules\Manufacturing\ManufacturingPolicy\Domain\Services;

use Modules\Manufacturing\ManufacturingPolicy\Domain\Enums\PolicyCode;
use Modules\Manufacturing\ManufacturingPolicy\Domain\ValueObjects\ManufacturingPolicyRequest;
use Modules\Manufacturing\ManufacturingPolicy\Domain\ValueObjects\ManufacturingPolicyResult;
use Modules\Manufacturing\ManufacturingPolicy\Domain\ValueObjects\OrderContext;
use Modules\Manufacturing\ManufacturingPolicy\Domain\ValueObjects\ProductContext;

/**
 * Manufacturing Policy — pure domain eligibility evaluator.
 *
 * Evaluates seven rules in priority order and short-circuits at the first
 * failure. Returns a typed ManufacturingPolicyResult for every outcome.
 *
 * CONTRACT — this service MUST NOT:
 *   - Call ManufacturingApplicationService
 *   - Call ManufacturingExecutor
 *   - Call ManufacturingPlanner
 *   - Consume inventory
 *   - Update any database record
 *   - Dispatch jobs or events
 *
 * The caller decides what to do with an eligible result. This service
 * never invokes ManufacturingApplicationService itself.
 *
 * Rule evaluation order (highest-priority first):
 *   1. Order not cancelled            — order-level hard stop
 *   2. Order status allows mfg        — in_progress | confirmed | ready_for_dispatch (ADR-042 V3)
 *   3. (REMOVED) Product can manufacture — the can_manufacture flag no longer gates
 *      order preparation; ADR-027 §16 v1.5 made recipe executability the sole
 *      fulfillability authority (TASK-ORDER-PREPARATION-FULFILLABILITY-CONTRACT-001).
 *   4. Recipe exists                  — product has an active recipe
 *   5. Product is inventory-managed   — physical trackable good
 *   6. Manufacturing required         — required_qty > 0
 *   7. Product not already mfd        — no existing transaction for this line
 */
final class ManufacturingPolicy
{
    /**
     * Order statuses that permit manufacturing to proceed.
     *
     * ADR-042 (Order FSM V3 Canonical) vocabulary. This is the SECOND status gate in
     * the trigger chain: ManufacturingLifecycleHandler::supports() admits the request,
     * then this Rule 2 re-checks the status. The prior set
     * ['pending','processing','preparing'] pre-dated V3 and matched NONE of the canonical
     * statuses, so even after the handler was aligned this rule rejected every real order
     * with OrderStatusNotAllowed — the second half of BREAK A
     * (TASK-MTO-MANUFACTURING-TRIGGER-GAP). Both gates must carry the same V3 set.
     *
     *   in_progress        → fulfilment-eligible entry state (ADR-042 §7)
     *   confirmed          → fulfilment-eligible after operator confirm (ADR-042 §7)
     *   ready_for_dispatch → the status the order HOLDS when the trigger runs, because
     *                        MoveToPreparationWorkflow flips to it before both the manual
     *                        and wave paths invoke manufacturing
     *
     * NOT allowed:
     *   awaiting_payment / awaiting_stock / scheduled / on_hold → not fulfilment-eligible
     *   delivered / returned                                    → already past fulfilment
     *   cancelled                                               → caught by Rule 1 first
     *
     * @var list<string>
     */
    private const MANUFACTURING_ALLOWED_STATUSES = [
        'in_progress',
        'confirmed',
        'ready_for_dispatch',
    ];

    /**
     * Evaluate all policy rules and return a typed eligibility result.
     *
     * Never throws for business outcomes. Only ever returns
     * ManufacturingPolicyResult — the caller inspects result->eligible.
     */
    public function evaluate(
        ManufacturingPolicyRequest $request,
        OrderContext $order,
        ProductContext $product,
    ): ManufacturingPolicyResult {
        $context = array_merge($request->metadata, [
            'product_id' => $request->product_id,
            'order_id' => $order->order_id,
            'order_line_id' => $order->order_line_id,
        ]);

        // ── Rule 1: Order not cancelled ───────────────────────────────────────
        // Checked first — a cancelled order supersedes all other rules.
        if ($order->is_cancelled) {
            return ManufacturingPolicyResult::ineligible(
                code: PolicyCode::OrderCancelled,
                reason: 'The order is cancelled. Manufacturing cannot proceed.',
                metadata: $context,
            );
        }

        // ── Rule 2: Order status allows manufacturing ─────────────────────────
        if (! in_array($order->order_status, self::MANUFACTURING_ALLOWED_STATUSES, strict: true)) {
            return ManufacturingPolicyResult::ineligible(
                code: PolicyCode::OrderStatusNotAllowed,
                reason: "Order status '{$order->order_status}' does not allow manufacturing. "
                    .'Allowed: '.implode(', ', self::MANUFACTURING_ALLOWED_STATUSES).'.',
                metadata: array_merge($context, ['order_status' => $order->order_status]),
            );
        }

        // ── Rule 3 REMOVED (TASK-ORDER-PREPARATION-FULFILLABILITY-CONTRACT-001, ADR-027 §16 v1.5) ──
        // The former `can_manufacture` precondition no longer gates order preparation.
        // ECOS is order-driven / made-to-order: the SAME recipe-executability contract
        // that let the order reserve (ReserveOrderInventoryAction, via
        // ManufacturingAvailabilityService) must let its preparation/assembly path run.
        // Gating preparation on the capability flag reintroduced the broken half-state
        // (reserved, but never prepared). Recipe presence is still enforced by Rule 4;
        // recipe executability is the reservation-time authority and is not recomputed
        // here. PolicyCode::ProductCannotManufacture is retained for backward compat but
        // is no longer emitted by this policy.

        // ── Rule 4: Recipe exists ─────────────────────────────────────────────
        if (! $product->has_active_recipe) {
            return ManufacturingPolicyResult::ineligible(
                code: PolicyCode::RecipeNotFound,
                reason: 'No active recipe (Bill of Materials) exists for this product.',
                metadata: $context,
            );
        }

        // ── Rule 5: Product is managed by inventory ───────────────────────────
        if (! $product->is_inventory_managed) {
            return ManufacturingPolicyResult::ineligible(
                code: PolicyCode::ProductNotInventoryManaged,
                reason: 'Product is not tracked by the inventory system. '
                    .'Manufacturing only applies to physical inventory-managed goods.',
                metadata: $context,
            );
        }

        // ── Rule 6: Manufacturing required ────────────────────────────────────
        if ($request->required_qty <= 0.0) {
            return ManufacturingPolicyResult::ineligible(
                code: PolicyCode::ManufacturingNotRequired,
                reason: 'Required quantity is zero or negative. No manufacturing needed.',
                metadata: array_merge($context, ['required_qty' => $request->required_qty]),
            );
        }

        // ── Rule 7: Product not already manufactured ──────────────────────────
        if ($order->already_manufactured) {
            return ManufacturingPolicyResult::ineligible(
                code: PolicyCode::AlreadyManufactured,
                reason: 'A manufacturing transaction already exists for this order line.',
                metadata: $context,
            );
        }

        // ── All rules passed ──────────────────────────────────────────────────
        return ManufacturingPolicyResult::eligible(
            metadata: array_merge($context, ['required_qty' => $request->required_qty]),
        );
    }
}
