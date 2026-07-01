<?php

declare(strict_types=1);

namespace Modules\POS\Application\Exceptions;

final class SessionNotFoundException extends \RuntimeException
{
    public static function withId(string $id): self
    {
        return new self("Session '{$id}' not found.");
    }
}
