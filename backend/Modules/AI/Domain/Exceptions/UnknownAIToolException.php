<?php

declare(strict_types=1);

namespace Modules\AI\Domain\Exceptions;

use RuntimeException;

/**
 * §10 — a model-proposed tool name not present in the explicit registry is
 * denied safely, never resolved by reflection/guessing.
 */
final class UnknownAIToolException extends RuntimeException
{
    public static function forName(string $name): self
    {
        return new self("Unknown AI tool: {$name}");
    }
}
