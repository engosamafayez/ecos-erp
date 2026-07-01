<?php

declare(strict_types=1);

namespace Modules\POS\Application\Exceptions;

final class ShiftAlreadyOpenException extends \RuntimeException
{
    public static function forSession(string $sessionId): self
    {
        return new self("Session '{$sessionId}' already has an open shift.");
    }
}
