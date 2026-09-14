<?php

declare(strict_types=1);

namespace Modules\Crm\SelfService\Presentation\Http\Controllers;

use App\Core\Audit\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Commerce\Orders\Application\Actions\ChangeOrderPaymentMethodAction;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Exceptions\PaymentMethodChangeRejectedException;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Services\PaymentFulfillmentGate;
use Modules\Crm\SelfService\Domain\Models\CustomerTrackingToken;

/**
 * §19/§20 — reuses ChangeOrderPaymentMethodAction and PaymentFulfillmentGate completely
 * unchanged; this is a customer-facing eligibility WRAPPER, not a second implementation. The
 * gate's own resulting proof/payment-state behaviour remains fully authoritative — this
 * controller adds only the boundary the domain action itself does not need to know about:
 * ownership, company/Brand scope, a narrower customer-eligible status window, and a
 * customer-safe method allow-list.
 *
 * Approved decision #4: allowed only while AwaitingPayment or InProgress AND not structurally
 * locked (OrderStatus::isLocked()) — Confirmed and every later state must go through support.
 */
final class CustomerPaymentMethodController extends Controller
{
    public function __construct(
        private readonly ChangeOrderPaymentMethodAction $changeMethod,
        private readonly PaymentFulfillmentGate $gate,
        private readonly AuditService $audit,
    ) {}

    /** §20 — the customer-selectable method list, derived from real configured policy only. */
    public function options(Request $request): JsonResponse
    {
        $order = $this->resolveOwnedOrder($request);

        if ($order === null) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $methods = array_keys($this->gate->proofPolicyFor($order->channel_id, $order->company_id));

        return response()->json(['data' => ['methods' => array_values($methods)]]);
    }

    public function update(Request $request): JsonResponse
    {
        /** @var CustomerTrackingToken $token */
        $token = $request->attributes->get('customer_tracking_token');
        $order = $this->resolveOwnedOrder($request);

        if ($order === null) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        if (! in_array($order->status, [OrderStatus::AwaitingPayment, OrderStatus::InProgress], true) || $order->status->isLocked()) {
            return response()->json(['message' => 'This order can no longer have its payment method changed here — please contact support.'], 422);
        }

        $allowList = array_keys($this->gate->proofPolicyFor($order->channel_id, $order->company_id));

        $data = $request->validate([
            'payment_method' => ['required', 'string', Rule::in($allowList ?: [''])],
        ]);

        try {
            $result = $this->changeMethod->execute($order, $data['payment_method']);
        } catch (PaymentMethodChangeRejectedException) {
            return response()->json(['message' => 'This payment method could not be applied to your order.'], 422);
        }

        $this->audit->record(
            action: 'customer_self_service.payment_method_changed',
            entityType: 'order',
            entityId: $order->id,
            companyId: $order->company_id,
            metadata: ['customer_id' => $token->customer_id, 'payment_method' => $data['payment_method']],
        );

        return response()->json(['data' => ['message' => $result->message()]]);
    }

    private function resolveOwnedOrder(Request $request): ?Order
    {
        /** @var CustomerTrackingToken $token */
        $token = $request->attributes->get('customer_tracking_token');

        if ($token->order_id === null) {
            return null;
        }

        $order = Order::query()->with('channel')->find($token->order_id);

        if ($order === null || $order->customer_id !== $token->customer_id || $order->company_id !== $token->company_id) {
            return null;
        }

        if ($token->brand_id !== null && $order->channel?->brand_id !== $token->brand_id) {
            return null;
        }

        return $order;
    }
}
