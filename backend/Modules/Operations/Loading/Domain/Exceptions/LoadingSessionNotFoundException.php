<?php

declare(strict_types=1);

namespace Modules\Operations\Loading\Domain\Exceptions;

final class LoadingSessionNotFoundException extends \RuntimeException
{
    public static function forId(string $id): static
    {
        return new static("Loading session [{$id}] not found.");
    }
}
