<?php

declare(strict_types=1);

namespace Modules\Operations\OrderLifecycle\Application\Services;

use Modules\Manufacturing\ManufacturingPolicy\Domain\Services\ManufacturingPolicy;
use Modules\Manufacturing\ManufacturingPolicy\Domain\ValueObjects\ManufacturingPolicyRequest;
use Modules\Manufacturing\ManufacturingPolicy\Domain\ValueObjects\OrderContext;
use Modules\Manufacturing\ManufacturingPolicy\Domain\ValueObjects\ProductContext;
use Modules\Manufacturing\ManufacturingService\Application\DTOs\Requests\ManufactureProductRequest;
use Modules\Manufacturing\ManufacturingService\Application\Services\ManufacturingApplicationService;
use Modules\Operations\OrderLifecycle\Application\DTOs\OrderLifecycleRequest;
use Modules\Operations\OrderLifecycle\Application\DTOs\OrderLifecycleResult;

/**
 * Order Lifecycle Coordinator — the single integration point between
 * the Orders domain and all operational domains.
 *
 * ARCHITECTURE RULE:
 *   Orders NEVER invoke ManufacturingApplicationService directly.
 *   Orders notify this coordinator via OrderLifecycleRequest.
 *   This coordinator evaluates policies and invokes application services.
 *
 * CURRENT SCOPE (PKG-07A):
 *   Manufacturing only. Shipping, CRM, Accounting, and Notifications
 *   will be added as private handler methods in future packages
 *   WITHOUT changing this class's public contract.
 *
 * COORDINATION FLOW:
 *   1. Preliminary: Is this status worth evaluating? → StatusIgnored if not
 *   2. Manufacturing Policy: Is this order/product eligible? → PolicyRejected if not
 *   3. ManufacturingApplicationService: Execute manufacturing
 *      → ManufacturingTriggered (success / idempotent replay)
 *      → ManufacturingBlocked  (workflow blocked by a domain engine)
 *
 * CONTRACT — this coordinator MUST NOT:
 *   - Execute manufacturing logic directly
 *   - Read recipes, inventory, or plans
 *   - Build manufacturing plans
 *   - Dispatch shipping
 *   - Send notifications
 *   - Update accounting records
 *
 * EXTENSION PATTERN (future packages):
 *   Add a private `handleShipping()` method called after `handleManufacturing()`.
 *   The public `handle()` method signature never changes.
 */
final class OrderLifecycleCoordinator
{
    /**
     * Order statuses that trigger manufacturing evaluation.
     *
     * Derived from OrderStatus enum (Commerce module):
     *   pending    → order placed; manufacturing may be needed
     *   processing → order accepted; manufacturing may proceed
     *
     * NOT evaluated:
     *   completed → manufacturing is unnecessary (order done)
     *   cancelled → manufacturing is impossible (order cancelled)
     *
     * @var list<string>
     */
    private const MANUFACTURING_TRIGGER_STATUSES = [
        'pending',
        'processing',
        'preparing', // PKG-07: orders enter this status when PrepareOrderAction runs
    ];

    public function __construct(
        private readonly ManufacturingPolicy $policy,
        private readonly ManufacturingApplicationService $manufacturing,
    ) {}

    /**
     * Handle an order lifecycle event for a single order line.
     *
     * Always returns an OrderLifecycleResult — never throws for business outcomes.
     * Exceptions from infrastructure (DB errors, etc.) propagate unchanged.
     */
    public function handle(OrderLifecycleRequest $request): OrderLifecycleResult
    {
        return $this->handleManufacturing($request);

        // EXTENSION POINT: future packages add here
        // $result = $this->handleShipping($result, $request);
        // $result = $this->handleCRM($result, $request);
        // $result = $this->handleNotifications($result, $request);
        // return $result;
    }

    // ── Manufacturing handler ─────────────────────────────────────────────────

    private function handleManufacturing(OrderLifecycleRequest $request): OrderLifecycleResult
    {
        // ── Step 1: Should we even evaluate manufacturing for this status? ────
        if (! $this->shouldEvaluateManufacturing($request->order_status)) {
            return OrderLifecycleResult::statusIgnored(
                orderId:     $request->order_id,
                orderLineId: $request->order_line_id,
                reason:      "Order status '{$request->order_status}' does not trigger manufacturing evaluation. "
                    . 'Expected: ' . implode(', ', self::MANUFACTURING_TRIGGER_STATUSES) . '.',
                metadata:    $request->metadata,
            );
        }

        // ── Step 2: Manufacturing Policy evaluation ───────────────────────────
        $policyResult = $this->policy->evaluate(
            new ManufacturingPolicyRequest(
                product_id:   $request->product_id,
                required_qty: $request->required_qty,
                actor_id:     $request->actor_id,
                metadata:     $request->metadata,
            ),
            new OrderContext(
                order_id:             $request->order_id,
                order_line_id:        $request->order_line_id,
                order_status:         $request->order_status,
                is_cancelled:         $request->is_order_cancelled,
                already_manufactured: $request->already_manufactured,
            ),
            new ProductContext(
                product_id:           $request->product_id,
                can_manufacture:      $request->product_can_manufacture,
                has_active_recipe:    $request->product_has_active_recipe,
                is_inventory_managed: $request->product_is_inventory_managed,
            ),
        );

        if (! $policyResult->eligible) {
            return OrderLifecycleResult::policyRejected(
                orderId:      $request->order_id,
                orderLineId:  $request->order_line_id,
                policyResult: $policyResult,
            );
        }

        // ── Step 3: Invoke Manufacturing Application Service ──────────────────
        $mfgResponse = $this->manufacturing->manufactureProduct(
            new ManufactureProductRequest(
                product_id:   $request->product_id,
                warehouse_id: $request->warehouse_id,
                company_id:   $request->company_id,
                required_qty: $request->required_qty,
                actor_id:     $request->actor_id,
                trigger_type: 'order_lifecycle',
                trigger_id:   $request->order_line_id,
                metadata:     array_merge($request->metadata, [
                    'order_id'      => $request->order_id,
                    'order_line_id' => $request->order_line_id,
                    'order_status'  => $request->order_status,
                ]),
            ),
        );

        if ($mfgResponse->is_blocked) {
            return OrderLifecycleResult::manufacturingBlocked(
                orderId:      $request->order_id,
                orderLineId:  $request->order_line_id,
                policyResult: $policyResult,
                mfgResult:    $mfgResponse,
            );
        }

        return OrderLifecycleResult::manufacturingTriggered(
            orderId:      $request->order_id,
            orderLineId:  $request->order_line_id,
            policyResult: $policyResult,
            mfgResult:    $mfgResponse,
        );
    }

    private function shouldEvaluateManufacturing(string $status): bool
    {
        return in_array($status, self::MANUFACTURING_TRIGGER_STATUSES, strict: true);
    }
}
