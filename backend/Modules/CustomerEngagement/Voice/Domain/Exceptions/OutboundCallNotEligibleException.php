<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Voice\Domain\Exceptions;

use RuntimeException;

final class OutboundCallNotEligibleException extends RuntimeException
{
    public static function optedOut(string $customerId): self
    {
        return new self("Customer {$customerId} has opted out of outbound voice calls.");
    }
}
