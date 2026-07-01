<?php

declare(strict_types=1);

namespace Modules\POS\Application\Exceptions;

final class SessionAlreadyOpenException extends \RuntimeException
{
    public static function forTerminal(string $terminalId): self
    {
        return new self("Terminal '{$terminalId}' already has an open session.");
    }
}
