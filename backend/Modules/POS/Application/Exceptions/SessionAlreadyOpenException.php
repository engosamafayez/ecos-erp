<?php

declare(strict_types=1);

namespace Modules\POS\Application\Exceptions;

use App\Core\Exceptions\BusinessException;

final class SessionAlreadyOpenException extends BusinessException
{
    public static function forTerminal(string $terminalId): self
    {
        return new self("Terminal '{$terminalId}' already has an open session.", [], 409);
    }
}
