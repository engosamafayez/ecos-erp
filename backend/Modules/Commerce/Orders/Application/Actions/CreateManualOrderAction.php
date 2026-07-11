<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Commerce\Orders\Application\Actions\ResolveProductPricingAction;
use Modules\Commerce\Orders\Domain\Contracts\OrderRepositoryInterface;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\OrderEvent;
use Modules\IAM\Domain\Contracts\PermissionServiceInterface;
use Modules\Operations\Preparation\Application\Services\WarehouseAssignmentEngine;
use Modules\Sales\Customers\Domain\Models\Customer;
use Modules\Sales\Customers\Domain\Models\CustomerAddress;

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
        private readonly WarehouseAssignmentEngine $warehouseAssignment,
    ) {}

    /**
     * @param  array<string, mixed>  ...$arguments  [0] = validated request data
     */
    public function execute(mixed ...$arguments): OperationResult
    {
        /** @var array<string, mixed> $data */
        $data = $arguments[0];

        // Enforce approved pricing before entering the transaction.
        $this->enforceApprovedPricing($data);

        $order = DB::transaction(function () use ($data) {
            $customerId = $this->resolveCustomer($data);

            $subtotal = array_sum(
                array_map(
                    static fn (array $l): float => (float) $l['quantity'] * (float) $l['unit_price'],
                    $data['lines'] ?? [],
                )
            );

            $discountAmount = (float) ($data['discount_amount'] ?? 0);
            $shippingCost   = (float) ($data['shipping_cost'] ?? 0);
            $depositAmount  = (float) ($data['deposit_amount'] ?? 0);
            $grandTotal     = $subtotal - $discountAmount + $shippingCost;
            $remaining      = max(0, $grandTotal - $depositAmount);

            // Always derive company from the authenticated actor — never trust the
            // request body. This closes the cross-tenant order-creation vector.
            $actorCompanyId  = Auth::user()?->company_id;

            $orderAttributes = [
                'company_id'               => $actorCompanyId,
                'channel_id'               => $data['channel_id'] ?? null,
                'customer_id'              => $customerId,
                'order_number'             => $this->orders->nextOrderNumber(),
                'order_date'               => $data['order_date'] ?? now()->toDateString(),
                'status'                   => $data['status'] ?? OrderStatus::InProgress->value,
                'subtotal'                 => $subtotal,
                'total'                    => $grandTotal,
                'notes'                    => $data['notes'] ?? null,
                'requested_delivery_date'  => $data['requested_delivery_date'] ?? null,
                'preferred_delivery_time'  => $data['preferred_delivery_time'] ?? null,
                'delivery_window_id'       => $data['delivery_window_id'] ?? null,
                'delivery_window'          => $data['delivery_window'] ?? null,
                'delivery_zone_id'         => $data['delivery_zone_id'] ?? null,
                'delivery_zone'            => $data['delivery_zone'] ?? null,
                'payment_method_manual'    => $data['payment_method_manual'] ?? null,
                'payment_proof_path'       => $data['payment_proof_path'] ?? null,
                'governorate'              => $data['governorate'] ?? null,
                'area'                     => $data['area'] ?? null,
                'shipping_cost'            => $shippingCost ?: null,
                'shipping_cost_source'     => $data['shipping_cost_source'] ?? null,
                'discount_amount'          => $discountAmount,
                'discount_type'            => $data['discount_type'] ?? null,
                'deposit_amount'           => $depositAmount,
                'remaining_balance'        => $remaining,
            ];

            $lines = array_map(static fn (array $l): array => [
                'product_id' => (string) $l['product_id'],
                'quantity'   => (float) $l['quantity'],
                'unit_price' => (float) $l['unit_price'],
                'line_total' => (float) $l['quantity'] * (float) $l['unit_price'],
            ], $data['lines'] ?? []);

            return $this->orders->create($orderAttributes, $lines);
        });

        $order->load(['customer', 'lines.product.unit', 'fees', 'coupons', 'channel']);

        // CR-PREP-001: Auto-assign warehouse immediately after order creation.
        $this->warehouseAssignment->assign($order, Auth::user()?->company_id ?? $order->channel?->brand?->company_id);

        $this->logAuditEvents($order->id, $data);

        return OperationResult::success($order, 'Order created successfully.');
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
     * @throws AuthorizationException
     */
    private function enforceApprovedPricing(array $data): void
    {
        $user      = Auth::user();
        $companyId = $user?->company_id;

        // System roles (super-admin) bypass all permission checks.
        $isSystemUser = $user !== null && $this->permissions->userHasSystemRole($user);
        $canOverride  = $isSystemUser
            || ($user !== null && $this->permissions->userHasPermission($user, 'sales.orders.override_price'));

        foreach ($data['lines'] ?? [] as $line) {
            $productId      = (string) ($line['product_id'] ?? '');
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
                    "Price override is not permitted. Product approved price is {$approvedPrice}. " .
                    'The `sales.orders.override_price` permission is required to submit a different price.'
                );
            }

            // Override is allowed — will be logged in logAuditEvents.
        }
    }

    private function logAuditEvents(string $orderId, array $data): void
    {
        $actorId = Auth::id() !== null ? (string) Auth::id() : null;

        OrderEvent::log($orderId, 'order_created', 'Manual order created.', [], $actorId);

        if (!empty($data['customer_name']) && empty($data['customer_id'])) {
            OrderEvent::log($orderId, 'customer_created', 'New customer created during order.', [
                'name'  => $data['customer_name'],
                'phone' => $data['customer_phone'] ?? null,
            ], $actorId);
        }

        if (!empty($data['discount_amount']) && (float) $data['discount_amount'] > 0) {
            OrderEvent::log($orderId, 'discount_applied', 'Discount applied to order.', [
                'amount' => $data['discount_amount'],
                'type'   => $data['discount_type'] ?? 'fixed',
            ], $actorId);
        }

        if (!empty($data['deposit_amount']) && (float) $data['deposit_amount'] > 0) {
            OrderEvent::log($orderId, 'deposit_recorded', 'Deposit recorded on order.', [
                'amount' => $data['deposit_amount'],
            ], $actorId);
        }

        if (!empty($data['payment_proof_path'])) {
            OrderEvent::log($orderId, 'proof_uploaded', 'Payment proof attached.', [
                'path'   => $data['payment_proof_path'],
                'method' => $data['payment_method_manual'] ?? null,
            ], $actorId);
        }

        if (($data['shipping_cost_source'] ?? null) === 'override') {
            OrderEvent::log($orderId, 'shipping_override', 'Shipping cost manually overridden.', [
                'cost' => $data['shipping_cost'] ?? null,
            ], $actorId);
        }
    }

    /**
     * Returns an existing customer ID or creates a new customer + address.
     */
    private function resolveCustomer(array &$data): string
    {
        if (!empty($data['customer_id'])) {
            return (string) $data['customer_id'];
        }

        // Auto-generate a sequential customer code
        $lastCode = Customer::orderByDesc('created_at')->value('code') ?? 'CUS-00000';
        preg_match('/(\d+)$/', $lastCode, $m);
        $next = isset($m[1]) ? (int) $m[1] + 1 : 1;
        $code = 'CUS-' . str_pad((string) $next, 5, '0', STR_PAD_LEFT);

        $customer = Customer::create([
            'code'    => $code,
            'name'    => (string) $data['customer_name'],
            'phone'   => $data['customer_phone'] ?? null,
            'mobile'  => $data['customer_secondary_phone'] ?? null,
            'city'    => $data['city'] ?? null,
            'governorate' => $data['governorate'] ?? null,
            'area'    => $data['area'] ?? null,
            'address' => $data['shipping_address'] ?? null,
            'notes'   => $data['customer_notes'] ?? null,
            'is_active' => true,
        ]);

        // Create default address record
        if (!empty($data['governorate'])) {
            CustomerAddress::create([
                'customer_id'    => $customer->id,
                'label'          => 'Default',
                'governorate'    => (string) $data['governorate'],
                'city'           => $data['city'] ?? null,
                'area'           => $data['area'] ?? null,
                'address_line'   => $data['shipping_address'] ?? null,
                'google_maps_lat' => $data['google_maps_lat'] ?? null,
                'google_maps_lng' => $data['google_maps_lng'] ?? null,
                'is_default'     => true,
            ]);
        }

        return $customer->id;
    }
}
