<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Illuminate\Support\Facades\Auth;
use Modules\Commerce\Orders\Application\DTO\OrderDTO;
use Modules\Commerce\Orders\Domain\Contracts\OrderRepositoryInterface;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Services\PaymentFulfillmentGate;

final class CreateOrderAction extends BaseAction
{
    public function __construct(
        private readonly OrderRepositoryInterface $orders,
        private readonly PaymentFulfillmentGate $paymentGate,
    ) {}

    public function execute(mixed ...$arguments): OperationResult
    {
        /** @var OrderDTO $dto */
        $dto = $arguments[0];

        $attributes = $dto->orderAttributes();
        $attributes['order_number'] = $this->orders->nextOrderNumber();

        // ADR-042 §3.1 (as amended) — the payment control applies to EVERY creation path.
        //
        // `StoreOrderRequest` admits all eleven statuses (including `confirmed`), and the
        // Order model's P9 status guard is registered on `updating` only, so creation is
        // exempt from it by construction. That combination is what makes this endpoint worth
        // guarding even though it is currently harmless.
        //
        // STATE OF THIS GUARD TODAY: inert. `OrderDTO` carries no payment method, so
        // `$method` is always null here and `permitsAtCreation()` always permits — there is
        // no payment contract to bypass on this path today, and none is invented. It is wired
        // now so that the day a payment method is added to this DTO, the control applies
        // automatically instead of this endpoint silently becoming the next bypass.
        $method = isset($attributes['payment_method_manual']) ? (string) $attributes['payment_method_manual'] : null;
        $method ??= isset($attributes['payment_method']) ? (string) $attributes['payment_method'] : null;

        $companyId = Auth::user()?->company_id;

        if (! $this->paymentGate->permitsAtCreation(
            $method,
            isset($attributes['channel_id']) ? (string) $attributes['channel_id'] : null,
            $companyId !== null ? (string) $companyId : null,
        )) {
            $attributes['status'] = OrderStatus::AwaitingPayment->value;
        }

        $subtotal = array_sum(array_column($dto->lineAttributes(), 'line_total'));
        $attributes['subtotal'] = $subtotal;
        $attributes['total'] = $subtotal;

        $order = $this->orders->create($attributes, $dto->lineAttributes());

        return OperationResult::success($order, 'Order created successfully.');
    }
}
