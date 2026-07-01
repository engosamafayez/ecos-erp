<?php

declare(strict_types=1);

namespace Modules\POS\Receipt\Domain\Exceptions;

final class ReceiptAlreadyVoidedException extends \RuntimeException
{
    public static function forReceipt(string $receiptNumber): self
    {
        return new self("Receipt {$receiptNumber} has already been voided.");
    }
}
