<?php

declare(strict_types=1);

namespace Modules\Operations\Preparation\Domain\Exceptions;

use RuntimeException;

final class WaveNotFoundException extends RuntimeException
{
    public static function forId(string $id): self
    {
        return new self("Preparation wave [{$id}] not found.");
    }
}
