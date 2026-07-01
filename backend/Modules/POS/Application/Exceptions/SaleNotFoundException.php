<?php

declare(strict_types=1);

namespace Modules\POS\Application\Exceptions;

final class SaleNotFoundException extends \RuntimeException
{
    public static function withId(string $id): self
    {
        return new self("Sale '{$id}' not found.");
    }
}
