<?php

declare(strict_types=1);

namespace Modules\POS\Application\Exceptions;

final class CartNotFoundException extends \RuntimeException
{
    public static function withId(string $id): self
    {
        return new self("Cart '{$id}' not found.");
    }
}
