<?php

declare(strict_types=1);

namespace Modules\AI\Application\Tools;

use App\Models\User;
use Modules\AI\Domain\Contracts\AIToolInterface;
use Modules\AI\Domain\Enums\AIToolClassification;
use Modules\AI\Domain\Enums\AIToolScope;
use Modules\AI\Domain\ValueObjects\AIEntityReference;
use Modules\AI\Domain\ValueObjects\AIRequestContext;
use Modules\AI\Domain\ValueObjects\AIToolResult;
use Modules\Commerce\Orders\Application\Actions\GetOrderAction;
use Modules\Commerce\Orders\Domain\Exceptions\OrderNotFoundException;
use Modules\Commerce\Orders\Domain\Models\Order;

/**
 * §16 — wraps the canonical single-order read authority (GetOrderAction), which
 * already enforces company isolation itself (returns OrderNotFoundException,
 * never leaking a cross-company order's existence). This tool adds no second
 * lookup path and never infers a "blocker" the order record cannot prove.
 */
final class GetOrderSummaryTool implements AIToolInterface
{
    public function __construct(private readonly GetOrderAction $getOrder) {}

    public function name(): string
    {
        return 'get_order_summary';
    }

    public function description(): string
    {
        return 'Looks up one order by its id and returns its status, brand, customer, delivery date, and payment/fulfillment facts.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_id' => ['type' => 'string'],
            ],
            'required' => ['order_id'],
        ];
    }

    public function permission(): string
    {
        return 'sales.orders.view';
    }

    public function scope(): AIToolScope
    {
        // Brand is a read-only OUTPUT fact here (via the order's own channel.brand
        // relation), never a client-supplied input this tool must validate — the
        // order lookup itself is already fully company-scoped by GetOrderAction.
        return AIToolScope::Company;
    }

    public function classification(): AIToolClassification
    {
        return AIToolClassification::Read;
    }

    public function requiresConfirmation(): bool
    {
        return false;
    }

    public function execute(AIRequestContext $context, User $user, array $input): AIToolResult
    {
        $orderId = is_string($input['order_id'] ?? null) ? $input['order_id'] : null;

        if ($orderId === null || $orderId === '') {
            return AIToolResult::invalidInput('order_id is required.');
        }

        try {
            $result = $this->getOrder->execute($orderId);
        } catch (OrderNotFoundException) {
            return AIToolResult::notFound('No such order in your company.');
        }

        /** @var Order $order */
        $order = $result->data();
        $order->loadMissing('channel.brand');

        $isPaid = $order->payment_state === 'paid' || (bool) $order->date_paid;
        $hasDeposit = (float) $order->deposit_amount > 0;

        return AIToolResult::success(
            data: [
                'order_number' => $order->order_number,
                'brand' => $order->channel?->brand?->name,
                'customer_name' => $order->customer_name,
                'status' => $order->status?->value,
                'order_date' => $order->order_date,
                'requested_delivery_date' => $order->requested_delivery_date,
                'total' => (float) $order->total,
                'payment_status' => $isPaid ? 'paid' : ($hasDeposit ? 'partially_paid' : 'unpaid'),
                'deposit_amount' => (float) $order->deposit_amount,
                'remaining_balance' => (float) $order->remaining_balance,
                'payment_method' => $order->payment_method_title ?: $order->payment_method,
            ],
            references: [new AIEntityReference('order', (string) $order->id, "Order {$order->order_number}", "/orders?open={$order->id}")],
        );
    }
}
