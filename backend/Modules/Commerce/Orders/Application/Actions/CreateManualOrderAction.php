<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Admin\Configuration\Domain\Services\ConfigurationManager;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\Orders\Application\Services\GoogleMapsUrlResolver;
use Modules\Commerce\Orders\Domain\Contracts\OrderRepositoryInterface;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\OrderEvent;
use Modules\Commerce\Orders\Domain\Services\PaymentFulfillmentGate;
use Modules\Commerce\Shipping\Domain\Services\ShippingValidationService;
use Modules\Commerce\Shipping\Domain\ValueObjects\ShippingValidationResult;
use Modules\IAM\Domain\Contracts\PermissionServiceInterface;
use Modules\Operations\Fulfillment\Application\FulfillmentEngine;
use Modules\Operations\Fulfillment\Application\Workflows\ProcessOrderWorkflow;
use Modules\Operations\Preparation\Application\Services\BranchAssignmentEngine;
use Modules\Sales\Customers\Domain\Models\Customer;
use Modules\Sales\Customers\Domain\Models\CustomerAddress;
use Throwable;

/**
 * Creates a manual order with optional inline customer creation.
 *
 * If customer_id is supplied the existing customer is used.
 * If customer data is supplied instead, a new customer + default address
 * are created atomically within the same transaction.
 */
final class CreateManualOrderAction extends BaseAction
{
    public function __construct(
        private readonly OrderRepositoryInterface $orders,
        private readonly ResolveProductPricingAction $pricingAction,
        private readonly PermissionServiceInterface $permissions,
        private readonly BranchAssignmentEngine $branchAssignment,
        private readonly ConfigurationManager $config,
        private readonly ReserveOrderInventoryAction $reserveInventory,
        private readonly ShippingValidationService $shippingEngine,
        private readonly FulfillmentEngine $fulfillmentEngine,
        private readonly ProcessOrderWorkflow $initiateWorkflow,
        private readonly GoogleMapsUrlResolver $mapsResolver,
        private readonly PaymentFulfillmentGate $paymentGate,
    ) {}

    /**
     * @param  array<string, mixed>  ...$arguments  [0] = validated request data
     */
    public function execute(mixed ...$arguments): OperationResult
    {
        /** @var array<string, mixed> $data */
        $data = $arguments[0];

        // Resolve a short Google Maps link to coordinates server-side (Finding 05),
        // so an order created from a pasted short link persists lat/lng rather than
        // a URL that the grid reports as "No GPS". No-op when coords are already set.
        $data = $this->mapsResolver->backfillCoordinates($data);

        // Resolve brand and order policy once — reused throughout this action.
        $brandId = $this->resolveBrandId($data['channel_id'] ?? null);
        $orderPolicy = $brandId !== null ? $this->config->getBrandPolicy($brandId, 'order') : [];

        // Enforce pricing constraints and discount limits.
        $this->enforceApprovedPricing($data);
        $this->enforceDiscountPolicy($data);

        // Validate shipping area through the Shipping Engine.
        // Walk-in POS (no governorate_id) returns walkIn() — no validation.
        $shippingResult = $this->validateAndResolveShipping($data, $brandId);
        /** @var ShippingValidationResult $shippingVO */
        $shippingVO = $shippingResult['result'];

        if ($shippingVO->isRejected()) {
            return OperationResult::failure(
                'Shipping area rejected: '.$shippingVO->reason.
                ' The destination is not supported by the brand shipping policy.',
            );
        }

        $customerWasReused = false;
        $subtotal = 0.0;
        $monetaryDiscount = 0.0;
        $grandTotal = 0.0;
        $remaining = 0.0;

        // ADR-042 §3 — Entry Status is PICK-AND-STAY. Resolved BEFORE the
        // transaction so its audit trail (§3.1) is still in scope after commit,
        // where the OrderEvent is written.
        //
        // The company is derived from the authenticated actor — the same value the
        // order row is stamped with below — because it is the second scope in the
        // payment-policy chain (D2-B) and must never be taken from the request body.
        $actorCompanyId = Auth::user()?->company_id;
        $statusResolution = $this->resolveManualOrderStatus(
            $data,
            $orderPolicy,
            isset($data['channel_id']) ? (string) $data['channel_id'] : null,
            $actorCompanyId !== null ? (string) $actorCompanyId : null,
        );

        $order = DB::transaction(function () use ($data, $orderPolicy, $shippingResult, $statusResolution, &$customerWasReused, &$subtotal, &$monetaryDiscount, &$grandTotal, &$remaining) {
            [$customerId, $customerWasReused] = $this->resolveCustomer($data, $orderPolicy);

            // Keep the customer's default delivery address in sync with the order data.
            // This runs for both new and existing customers so that the profile is always
            // up-to-date when searching the same customer in a subsequent order.
            $this->syncCustomerDefaultAddress($customerId, $data);

            // Load the resolved customer to supply fallback values for snapshot fields.
            // When an existing customer is matched by phone the form may not pre-fill
            // secondary_phone or notes, so we fall back to the customer record's current
            // values to ensure the snapshot is complete at creation time.
            $customerRecord = Customer::find($customerId);

            $subtotal = array_sum(
                array_map(
                    static fn (array $l): float => (float) $l['quantity'] * (float) $l['unit_price'],
                    $data['lines'] ?? [],
                ),
            );

            // discount_amount in the request is the raw input (10 for "10%", or 150 for "EGP 150 fixed").
            // Convert to monetary amount before computing grand_total — same logic as OrderResource.
            $rawDiscount = (float) ($data['discount_amount'] ?? 0);
            $discountType = (string) ($data['discount_type'] ?? '');
            $monetaryDiscount = $discountType === 'percentage'
                ? round($subtotal * $rawDiscount / 100, 2)
                : $rawDiscount;
            $shippingCost = (float) ($data['shipping_cost'] ?? 0);
            $depositAmount = (float) ($data['deposit_amount'] ?? 0);
            $grandTotal = round($subtotal - $monetaryDiscount + $shippingCost, 2);
            $remaining = max(0.0, round($grandTotal - $depositAmount, 2));

            // Always derive company from the authenticated actor — never trust the
            // request body. This closes the cross-tenant order-creation vector.
            $actorCompanyId = Auth::user()?->company_id;

            $orderAttributes = [
                'company_id' => $actorCompanyId,
                'channel_id' => $data['channel_id'] ?? null,
                'customer_id' => $customerId,
                'order_number' => $this->orders->nextOrderNumber(),
                'order_date' => $data['order_date'] ?? now()->toDateString(),
                // ADR-042 §3.1 (as amended) — PRECEDENCE. The payment control outranks the
                // shipping-review override. Before this, a proof-required order that also
                // needed shipping review was stored `on_hold` — a status the payment gate
                // never evaluates (it fires only from `awaiting_payment`), so the order could
                // be confirmed straight out of `on_hold` with no payment and no proof. Worse,
                // the §3.1 audit event was still written and still reported
                // `stored_status: awaiting_payment`, which the row contradicted.
                'status' => $statusResolution['payment_blocked']
                    ? $statusResolution['status']
                    : ($shippingResult['status_override'] ?? $statusResolution['status']),
                'subtotal' => $subtotal,
                'total' => $grandTotal,
                'notes' => $data['notes'] ?? null,
                'requested_delivery_date' => $data['requested_delivery_date'] ?? null,
                'preferred_delivery_time' => $data['preferred_delivery_time'] ?? null,
                'delivery_window_id' => $data['delivery_window_id'] ?? null,
                'delivery_window' => $data['delivery_window'] ?? null,
                'delivery_zone_id' => $data['delivery_zone_id'] ?? null,
                'delivery_zone' => $data['delivery_zone'] ?? null,
                'payment_method_manual' => $data['payment_method_manual'] ?? null,
                'payment_proof_path' => $data['payment_proof_path'] ?? null,
                'governorate' => $data['governorate'] ?? null,
                'city' => $data['city'] ?? null,
                'shipping_address' => $data['shipping_address'] ?? null,
                'building' => $data['building'] ?? null,
                'floor' => $data['floor'] ?? null,
                'apartment' => $data['apartment'] ?? null,
                'landmark' => $data['landmark'] ?? null,
                'address_notes' => $data['address_notes'] ?? null,
                'area' => $data['area'] ?? null,
                'google_maps_lat' => $data['google_maps_lat'] ?? null,
                'google_maps_lng' => $data['google_maps_lng'] ?? null,
                'google_maps_url' => $data['google_maps_url'] ?? null,
                'location_source' => $data['location_source'] ?? null,
                // Customer snapshot — historically immutable once written.
                // Form data takes precedence; customer record provides fallback for
                // fields not pre-filled when an existing customer is matched by phone.
                'created_by_id' => Auth::id() !== null ? (string) Auth::id() : null,
                'created_by_name' => Auth::user()?->name ?? null,
                'status_entered_at' => now(),
                'customer_name' => $data['customer_name'] ?? $customerRecord?->name,
                'customer_secondary_phone' => ($data['customer_secondary_phone'] ?? null) ?: $customerRecord?->mobile,
                'customer_notes' => ($data['customer_notes'] ?? null) ?: $customerRecord?->notes,
                'billing_phone' => $data['customer_phone'] ?? null,
                'shipping_cost' => $shippingCost ?: null,
                'shipping_cost_source' => $data['shipping_cost_source'] ?? null,
                'discount_amount' => $rawDiscount,
                'discount_type' => $data['discount_type'] ?? null,
                'deposit_amount' => $depositAmount,
                'remaining_balance' => $remaining,
            ];

            $lines = array_map(static fn (array $l): array => [
                'product_id' => (string) $l['product_id'],
                'quantity' => (float) $l['quantity'],
                'unit_price' => (float) $l['unit_price'],
                'line_total' => (float) $l['quantity'] * (float) $l['unit_price'],
            ], $data['lines'] ?? []);

            return $this->orders->create($orderAttributes, $lines);
        });

        $order->load(['customer', 'lines.product.unit', 'fees', 'coupons', 'channel']);

        // TASK-BRANCH-ASSIGNMENT-ENGINE-001: Resolve branch → warehouse via coverage rules.
        $this->branchAssignment->assign($order, Auth::user()?->company_id ?? $order->channel?->brand?->company_id);

        // ADR-042 §3.1 — record the one sanctioned entry-status override. Written
        // before the fulfilment trigger so the audit trail reflects creation order.
        if ($statusResolution['override_reason'] !== null) {
            OrderEvent::log(
                orderId: $order->id,
                type: 'entry_status_overridden_by_payment_proof_policy',
                description: "Entry status [{$statusResolution['submitted']}] was overridden to [{$order->status->value}]: the payment method requires verified payment proof, which cannot exist before the order does.",
                payload: [
                    'submitted_status' => $statusResolution['submitted'],
                    // The status ACTUALLY stored, read back from the row — never the intended
                    // one. The previous version reported the resolution's own value, which the
                    // shipping override could silently contradict.
                    'stored_status' => $order->status->value,
                    'reason' => $statusResolution['override_reason'],
                    'payment_method' => $data['payment_method_manual'] ?? null,
                ],
                module: 'orders',
            );
        }

        // ADR-042 §6 — the reservation trigger is UNCHANGED in timing: reservation
        // still happens at creation, for orders that enter the operational queue.
        // Only the name of the triggering state changed (`new` → `in_progress`),
        // because normal orders are now created directly at In Progress.
        //
        // This is not a status rewrite: ProcessOrderWorkflow writes InProgress, so
        // for an order already at InProgress the write is a no-op and PICK-AND-STAY
        // is preserved.
        //
        // CLOSURE-001 PART 1/23-B — the gate is now the canonical
        // OrderStatus::decidesAvailabilityAtCreation(), which also admits
        // `awaiting_payment`. Gating on InProgress alone meant an unpaid order took NO
        // availability decision whatsoever and rested at `reservation_status = NULL` —
        // rendered by the Orders UI as "Pending", a state PART 5 removes from the
        // business vocabulary. Deciding availability does not touch the payment block:
        // ProcessOrderWorkflow preserves the lifecycle status for AwaitingPayment in
        // both directions (yieldsToStockBlock and advancesToInProgressOnReservation are
        // both false for it), so the order stays Awaiting Payment and merely learns
        // whether the goods exist.
        //
        // `scheduled` remains excluded — PART 12 owns its activation (D-1), and
        // ActivateScheduledOrdersCommand is what decides its availability later.
        if (in_array($order->status, OrderStatus::decidesAvailabilityAtCreation(), true)) {
            try {
                $actorId = Auth::id() !== null ? (string) Auth::id() : null;
                $this->fulfillmentEngine->run(
                    $this->initiateWorkflow,
                    $order->fresh(),
                    // Declares WHICH invocation this is, so the workflow's payment advance
                    // (ADR-042 §7.1) does not fire inside the creation request and undo the
                    // entry status the operator just chose. The invariant this preserves is
                    // the one the comment above states.
                    ['creation_availability_decision' => true],
                    $actorId,
                );
            } catch (Throwable $e) {
                Log::channel('daily')->warning('[Order] Auto-initiate fulfillment failed after creation', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->logAuditEvents($order->id, $data, $order->status->value, $customerWasReused, $order, [
            'subtotal' => $subtotal,
            'monetary_discount' => $monetaryDiscount,
            'grand_total' => $grandTotal,
            'remaining' => $remaining,
        ]);

        return OperationResult::success($order, 'Order created successfully.');
    }

    /** Resolves the brand_id from the sales channel, or null if no channel is set. */
    private function resolveBrandId(?string $channelId): ?string
    {
        if (! $channelId) {
            return null;
        }

        return Channel::query()->where('id', $channelId)->value('brand_id');
    }

    /**
     * Determines the entry status for a manually created order — ADR-042 §3.
     *
     * PICK-AND-STAY: an explicitly submitted canonical entry status is stored
     * verbatim. Nothing below that check may displace it — not the payment method,
     * not the delivery date, not the brand policy's ordering.
     *
     * Two mechanisms were REMOVED here and are prohibited from returning
     * (ADR-042 §4, §8):
     *
     *   - `PAYMENT_CLEAR_STATUS_PREFERENCE` — preferred `in_progress`/`new` whenever
     *     a payment method was present, silently displacing the operator's choice.
     *     Payment method is not an input to the lifecycle state machine.
     *
     *   - `LEGACY_STATUS_MAP` — repaired pre-V3 configuration values on every read,
     *     which made stale configuration look canonical and hid the drift for weeks.
     *     Configuration is normalised once, by migration
     *     2026_08_13_100000_supersede_order_lifecycle_v3_canonical; anything still
     *     non-canonical afterwards is ignored rather than guessed at.
     *
     * The brand policy now governs which options are OFFERED (via GET /orders/statuses
     * and the Config OS matrix) and which fallback applies when nothing was submitted.
     * It no longer silently substitutes a status the operator did not choose.
     *
     * @return array{status: string, submitted: string|null, override_reason: string|null, payment_blocked: bool}
     */
    private function resolveManualOrderStatus(
        array $data,
        array $orderPolicy,
        ?string $channelId,
        ?string $companyId,
    ): array {
        $method = (string) ($data['payment_method_manual'] ?? '');
        $submittedStatus = (string) ($data['status'] ?? '');

        // ── ADR-042 §3.1 (as amended) — the single sanctioned override ────────
        // Owner decision D1-A: `payment_proof_policy: required` is a MANDATORY FINANCIAL
        // CONTROL. Fulfilment eligibility needs sufficient payment AND an active VERIFIED
        // `payment_proofs` record. A `payment_proofs` row can only be written by
        // POST /orders/{order}/payment-proofs, which needs an order that already exists, so
        // the second condition is UNSATISFIABLE here — a proof-required method is therefore
        // always created `awaiting_payment`.
        //
        // Two things this deliberately no longer does:
        //
        //   - it no longer reads `$data['payment_proof_path']`. That column is unvalidated
        //     free text (`nullable|string|max:500`) and was declared superseded by
        //     TASK-PAYMENT-PROOF-LIFECYCLE-001; any non-empty value used to skip
        //     `awaiting_payment` entirely, which meant the hardened confirmation gate — the
        //     only place the payment control is evaluated — never ran for that order at all.
        //     The path is still persisted and still audited; it simply has no lifecycle
        //     authority.
        //
        //   - it no longer resolves the policy from `$orderPolicy` (brand-only). Resolution
        //     goes through PaymentFulfillmentGate, the single implementation shared with
        //     ConfirmOrderWorkflow, which continues down the documented chain when the order
        //     has no channel instead of hardcoding `'none'` (D2-B).
        //
        // `$orderPolicy` is still used below for `source_entry_policies`, which is a genuinely
        // brand-scoped concern and is unchanged.
        if (! $this->paymentGate->permitsAtCreation($method, $channelId, $companyId)) {
            return [
                'status' => OrderStatus::AwaitingPayment->value,
                'submitted' => $submittedStatus !== '' ? $submittedStatus : null,
                'override_reason' => $submittedStatus !== '' && $submittedStatus !== OrderStatus::AwaitingPayment->value
                    ? 'payment_proof_required_and_unverified_at_creation'
                    : null,
                'payment_blocked' => true,
            ];
        }

        // ── PICK-AND-STAY ─────────────────────────────────────────────────────
        if ($submittedStatus !== '' && self::isEntryStatus($submittedStatus)) {
            return ['status' => $submittedStatus, 'submitted' => $submittedStatus, 'override_reason' => null, 'payment_blocked' => false];
        }

        // ── Fallbacks — reached only when no usable status was submitted ───────

        // Future delivery date → Scheduled so the order waits until its date arrives.
        $deliveryDate = (string) ($data['requested_delivery_date'] ?? '');
        if ($deliveryDate !== '' && $deliveryDate > now()->toDateString()) {
            return ['status' => OrderStatus::Scheduled->value, 'submitted' => null, 'override_reason' => null, 'payment_blocked' => false];
        }

        $configured = $orderPolicy['source_entry_policies']['manual'] ?? null;

        if (is_array($configured)) {
            foreach ($configured as $status) {
                if (self::isEntryStatus((string) $status)) {
                    return ['status' => (string) $status, 'submitted' => null, 'override_reason' => null, 'payment_blocked' => false];
                }
            }
        }

        if (is_string($configured) && self::isEntryStatus($configured)) {
            return ['status' => $configured, 'submitted' => null, 'override_reason' => null, 'payment_blocked' => false];
        }

        return ['status' => OrderStatus::InProgress->value, 'submitted' => null, 'override_reason' => null, 'payment_blocked' => false];
    }

    /** True when the value is one of the three canonical creation entry states (ADR-042 §3). */
    private static function isEntryStatus(string $value): bool
    {
        $candidate = OrderStatus::tryFrom($value);

        return $candidate !== null && in_array($candidate, OrderStatus::entryStatuses(), true);
    }

    /**
     * Validates that each line's submitted unit_price matches the product's
     * current approved selling price. Allows deviation only when the actor
     * holds the `sales.orders.override_price` permission.
     *
     * Products with no approved price set (both regular_price and sale_price
     * are null) are skipped — those products haven't been priced yet and
     * blocking the order would be more disruptive than helpful.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws AuthorizationException
     */
    private function enforceApprovedPricing(array $data): void
    {
        $user = Auth::user();
        $companyId = $user?->company_id;

        // System roles (super-admin) bypass all permission checks.
        $isSystemUser = $user !== null && $this->permissions->userHasSystemRole($user);
        $canOverride = $isSystemUser
            || ($user !== null && $this->permissions->userHasPermission($user, 'sales.orders.override_price'));

        foreach ($data['lines'] ?? [] as $line) {
            $productId = (string) ($line['product_id'] ?? '');
            $submittedPrice = (float) ($line['unit_price'] ?? 0);

            $pricing = $this->pricingAction->execute($productId, $companyId);

            // No approved price set — skip enforcement.
            if ($pricing['resolved_price'] === null) {
                continue;
            }

            $approvedPrice = (float) $pricing['resolved_price'];

            // Allow a rounding tolerance of ±0.005 (half-cent).
            if (abs($submittedPrice - $approvedPrice) <= 0.005) {
                continue;
            }

            if (! $canOverride) {
                throw new AuthorizationException(
                    "Price override is not permitted. Product approved price is {$approvedPrice}. ".
                    'The `sales.orders.override_price` permission is required to submit a different price.',
                );
            }

            // Override is allowed — will be logged in logAuditEvents.
        }
    }

    /**
     * Validates that the submitted discount_amount does not exceed the brand's
     * configured discount limit. Requires `sales.orders.override_discount` permission
     * to bypass, or system role.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws AuthorizationException
     */
    private function enforceDiscountPolicy(array $data): void
    {
        $discountAmount = (float) ($data['discount_amount'] ?? 0);
        if ($discountAmount <= 0.0) {
            return;
        }

        $channelId = $data['channel_id'] ?? null;
        if (! $channelId) {
            return;
        }

        $channel = Channel::find($channelId);
        if (! $channel) {
            return;
        }

        $policy = $this->config->getBrandPolicy((string) $channel->brand_id, 'pricing');
        $discountType = (string) ($policy['discount_type'] ?? 'percentage');
        $discountValue = (float) ($policy['discount_value'] ?? 0);

        if ($discountValue <= 0) {
            return; // No limit configured — skip enforcement
        }

        $subtotal = array_sum(
            array_map(
                static fn (array $l): float => (float) $l['quantity'] * (float) $l['unit_price'],
                $data['lines'] ?? [],
            ),
        );

        $maxDiscount = $discountType === 'percentage'
            ? $subtotal * ($discountValue / 100)
            : $discountValue;

        if ($discountAmount <= $maxDiscount + 0.005) {
            return; // Within limit
        }

        $user = Auth::user();
        $isSystemUser = $user !== null && $this->permissions->userHasSystemRole($user);
        $canOverride = $isSystemUser
            || ($user !== null && $this->permissions->userHasPermission($user, 'sales.orders.override_discount'));

        if (! $canOverride) {
            $limit = $discountType === 'percentage'
                ? "{$discountValue}% of subtotal (max ".number_format($maxDiscount, 2).' EGP)'
                : number_format($discountValue, 2).' EGP';

            throw new AuthorizationException(
                "Discount of {$discountAmount} EGP exceeds the configured limit of {$limit}. ".
                'The `sales.orders.override_discount` permission is required to proceed.',
            );
        }
    }

    private function logAuditEvents(
        string $orderId,
        array $data,
        string $orderStatus = '',
        bool $customerWasReused = false,
        ?\Modules\Commerce\Orders\Domain\Models\Order $order = null,
        array $financials = [],
    ): void {
        $actorId = Auth::id() !== null ? (string) Auth::id() : null;
        $actorName = Auth::user()?->name;
        $actorRole = Auth::user()?->roles()->value('name');

        OrderEvent::log(
            $orderId,
            'order_created',
            'Manual order created.',
            [],
            $actorId,
            $actorName,
            null,
            null,
            'orders',
            'user',
            'dashboard',
            'created',
            null,
            null,
            null,
            null,
            [
                'channel' => $order?->channel?->name,
                'customer_name' => $data['customer_name'] ?? null,
                'order_total' => $order?->total,
            ],
            $actorRole,
        );

        if ($orderStatus === OrderStatus::AwaitingPayment->value) {
            OrderEvent::log($orderId, 'awaiting_payment', 'Order created with payment proof pending.', [
                'payment_method' => $data['payment_method_manual'] ?? null,
            ], $actorId);
        }

        if (empty($data['customer_id'])) {
            if ($customerWasReused) {
                OrderEvent::log($orderId, 'customer_reused', 'Existing customer matched by phone.', [
                    'phone' => $data['customer_phone'] ?? null,
                ], $actorId);
            } elseif (! empty($data['customer_name'])) {
                OrderEvent::log($orderId, 'customer_created', 'New customer created during order.', [
                    'name' => $data['customer_name'],
                    'phone' => $data['customer_phone'] ?? null,
                ], $actorId);
            }
        }

        if (! empty($data['discount_amount']) && (float) $data['discount_amount'] > 0) {
            OrderEvent::log(
                $orderId,
                'discount_applied',
                'Discount applied to order.',
                [],
                $actorId,
                $actorName,
                null,
                null,
                'orders',
                'user',
                'dashboard',
                'payment',
                null,
                null,
                null,
                null,
                [
                    'amount' => $data['discount_amount'],
                    'type' => $data['discount_type'] ?? 'fixed',
                    'calculated_value' => $financials['monetary_discount'] ?? null,
                    'subtotal' => $financials['subtotal'] ?? null,
                ],
                $actorRole,
                Auth::user()?->email,
            );
        }

        if (! empty($data['deposit_amount']) && (float) $data['deposit_amount'] > 0) {
            $depositAmt = (float) $data['deposit_amount'];
            $grandTotal = (float) ($financials['grand_total'] ?? 0);
            $remaining = (float) ($financials['remaining'] ?? max(0, $grandTotal - $depositAmt));

            OrderEvent::log(
                $orderId,
                'deposit_recorded',
                'Deposit recorded on order.',
                [],
                $actorId,
                $actorName,
                ['deposit_amount' => 0, 'remaining_balance' => $grandTotal],
                ['deposit_amount' => $depositAmt, 'remaining_balance' => $remaining],
                'orders',
                'user',
                'dashboard',
                'payment',
                null,
                null,
                null,
                null,
                [
                    'grand_total' => $grandTotal,
                ],
                $actorRole,
                Auth::user()?->email,
            );
        }

        if (! empty($data['payment_proof_path'])) {
            OrderEvent::log($orderId, 'proof_uploaded', 'Payment proof attached.', [
                'path' => $data['payment_proof_path'],
                'method' => $data['payment_method_manual'] ?? null,
            ], $actorId);
        }

        if (($data['shipping_cost_source'] ?? null) === 'override') {
            OrderEvent::log($orderId, 'shipping_override', 'Shipping cost manually overridden.', [
                'cost' => $data['shipping_cost'] ?? null,
            ], $actorId);
        }

        if (! empty($data['requested_delivery_date'])) {
            OrderEvent::log($orderId, 'delivery_date_set', 'Requested delivery date recorded.', [
                'date' => $data['requested_delivery_date'],
            ], $actorId);
        }

        if (! empty($data['google_maps_lat']) && ! empty($data['google_maps_lng'])) {
            OrderEvent::log($orderId, 'location_set', 'Customer location coordinates recorded.', [
                'lat' => $data['google_maps_lat'],
                'lng' => $data['google_maps_lng'],
                'source' => $data['location_source'] ?? null,
            ], $actorId);
        }
    }

    /**
     * Returns [customerId, wasReused] — the customer ID to attach to the order and whether
     * an existing customer was matched by phone (vs. a new one being created).
     *
     * Customer Matching Policy values (Phase 4):
     *   reuse_existing    — phone match → silently reuse (recommended default)
     *   warn_only         — phone match → reuse; frontend may show a warning
     *   block_new_customer — phone match → must reuse (same backend behaviour as reuse_existing)
     *   always_create_new — skip phone lookup; always create a new customer record
     *
     * Orders must NEVER be rejected because a customer already exists.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $orderPolicy
     * @return array{string, bool}
     */
    private function resolveCustomer(array $data, array $orderPolicy = []): array
    {
        if (! empty($data['customer_id'])) {
            return [(string) $data['customer_id'], false];
        }

        $phone = (string) ($data['customer_phone'] ?? '');
        $policy = (string) ($orderPolicy['customer_matching_policy'] ?? 'reuse_existing');

        // Same tenant source the order itself uses (see the `company_id` assignment on the
        // Order below) — resolved here because resolveCustomer() runs before that point.
        $companyId = Auth::user()?->company_id;

        // Phone-based matching applies for all policies except always_create_new.
        if ($policy !== 'always_create_new' && $phone !== '') {
            // Scoped to the acting company, and the phone/mobile alternation is GROUPED.
            // Without the closure the `orWhere` would escape the company predicate and a
            // phone belonging to another tenant would be attached to this order.
            $existing = Customer::query()
                ->where('company_id', $companyId)
                ->where(fn ($q) => $q->where('phone', $phone)->orWhere('mobile', $phone))
                ->first();

            if ($existing !== null) {
                return [$existing->id, true];
            }
        }

        // Create a new customer record.
        // Use MAX of the numeric suffix to avoid collision when records are inserted out of sequence.
        $maxNum = (int) \DB::table('customers')
            ->selectRaw("COALESCE(MAX(CAST(SUBSTRING_INDEX(code, '-', -1) AS UNSIGNED)), 0) as n")
            ->value('n');
        $code = 'CUS-'.str_pad((string) ($maxNum + 1), 5, '0', STR_PAD_LEFT);

        $customer = Customer::create([
            // Without this the customer is created with a NULL company and is therefore
            // invisible to every company-scoped read — the Customers workspace showed one
            // record while five orders each carried a correctly-linked customer, because
            // only that one record had a company_id.
            'company_id' => $companyId,
            'code' => $code,
            'name' => (string) $data['customer_name'],
            'phone' => $data['customer_phone'] ?? null,
            'mobile' => $data['customer_secondary_phone'] ?? null,
            'city' => $data['city'] ?? null,
            'governorate' => $data['governorate'] ?? null,
            'area' => $data['area'] ?? null,
            'address' => $data['shipping_address'] ?? null,
            'notes' => $data['customer_notes'] ?? null,
            'is_active' => true,
        ]);

        // CustomerAddress is created by syncCustomerDefaultAddress after resolveCustomer returns.
        return [$customer->id, false];
    }

    /**
     * Upserts the customer's default delivery address with the non-null fields
     * supplied by the order form. Runs for both new and existing customers so the
     * customer profile always reflects the most recent known delivery details.
     *
     * Null values are intentionally skipped — they must not overwrite existing data
     * when the current order form didn't include every address field.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncCustomerDefaultAddress(string $customerId, array $data): void
    {
        $governorate = $data['governorate'] ?? null;
        $city = $data['city'] ?? null;

        if ($governorate === null && $city === null) {
            return;
        }

        $fields = [
            'governorate' => $governorate,
            'city' => $city,
            'area' => $data['area'] ?? null,
            'address_line' => $data['shipping_address'] ?? null,
            'building' => $data['building'] ?? null,
            'floor' => $data['floor'] ?? null,
            'apartment' => $data['apartment'] ?? null,
            'landmark' => $data['landmark'] ?? null,
            'address_notes' => $data['address_notes'] ?? null,
            'google_maps_lat' => $data['google_maps_lat'] ?? null,
            'google_maps_lng' => $data['google_maps_lng'] ?? null,
            'google_maps_url' => $data['google_maps_url'] ?? null,
            'location_source' => $data['location_source'] ?? null,
        ];

        // Only send non-null values so we never blank out fields not present in this request.
        $updates = array_filter($fields, static fn ($v) => $v !== null);

        if (empty($updates)) {
            return;
        }

        $existing = CustomerAddress::where('customer_id', $customerId)
            ->where('is_default', true)
            ->first();

        if ($existing !== null) {
            $existing->update($updates);
        } else {
            CustomerAddress::create(array_merge($updates, [
                'customer_id' => $customerId,
                'label' => 'Default',
                'is_default' => true,
            ]));
        }

        // Persist customer-level notes when the order form provided them.
        if (! empty($data['customer_notes'])) {
            Customer::where('id', $customerId)->update(['notes' => $data['customer_notes']]);
        }
    }

    /**
     * Runs the Shipping Engine and converts the result into order-creation overrides.
     *
     * No exceptions. Walk-in (no governorate_id) returns an empty override array.
     * Rejected orders return a structured failure so the caller can surface a
     * human-readable API error rather than a 500.
     *
     * @param  array<string, mixed>  $data
     * @return array{result: ShippingValidationResult, status_override?: string}
     */
    private function validateAndResolveShipping(array $data, ?string $brandId): array
    {
        $governorateId = isset($data['governorate_id']) ? (int) $data['governorate_id'] : null;
        $cityId = isset($data['city_id']) ? (int) $data['city_id'] : null;
        $isDelivery = $governorateId !== null;

        if ($brandId === null || ! $isDelivery) {
            return ['result' => ShippingValidationResult::walkIn()];
        }

        $result = $this->shippingEngine->evaluate(
            brandId: $brandId,
            governorateId: $governorateId,
            cityId: $cityId,
            isDeliveryOrder: true,
        );

        $override = ['result' => $result];

        if ($result->requiresReview()) {
            $override['status_override'] = OrderStatus::OnHold->value;
        }

        return $override;
    }
}
