<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Domain\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * A payment-method change was rejected because it would leave the order in a state the
 * PaymentFulfillmentGate forbids — a proof-required method on an order still in fulfilment
 * that the demotion workflow could not pull back (e.g. already out for delivery).
 *
 * Thrown from ChangeOrderPaymentMethodAction INSIDE its transaction, so the rejection rolls
 * back the payment_method_manual write — the order never commits a new method alongside a
 * stale/unsatisfied fulfilment state. Maps to HTTP 422.
 */
final class PaymentMethodChangeRejectedException extends UnprocessableEntityHttpException
{
    public function __construct(?string $message = null)
    {
        parent::__construct(
            $message
            ?? 'This order is already in fulfilment and the selected payment method requires verified payment proof, so it cannot be changed to it now.'
        );
    }
}
