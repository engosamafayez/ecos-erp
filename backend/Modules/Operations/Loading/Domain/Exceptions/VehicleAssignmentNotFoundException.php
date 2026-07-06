<?php

declare(strict_types=1);

namespace Modules\Operations\Loading\Domain\Exceptions;

final class VehicleAssignmentNotFoundException extends \RuntimeException
{
    public static function forId(string $id): static
    {
        return new static("Vehicle assignment [{$id}] not found.");
    }
}
