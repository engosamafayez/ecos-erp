<?php

declare(strict_types=1);

namespace Modules\Operations\Loading\Domain\Exceptions;

final class VehiclePlanNotFoundException extends \RuntimeException
{
    public static function forId(string $id): static
    {
        return new static("Vehicle plan [{$id}] not found.");
    }
}
