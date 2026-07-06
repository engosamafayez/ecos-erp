<?php

declare(strict_types=1);

namespace Modules\Operations\Loading\Domain\Exceptions;

final class LoadingTaskNotFoundException extends \RuntimeException
{
    public static function forId(string $id): static
    {
        return new static("Loading task [{$id}] not found.");
    }
}
