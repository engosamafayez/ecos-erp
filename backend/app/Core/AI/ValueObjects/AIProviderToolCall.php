<?php

declare(strict_types=1);

namespace App\Core\AI\ValueObjects;

/**
 * A tool invocation PROPOSED by the model. This is a proposal only — it carries
 * no authority. {@see \Modules\AI\Application\Services\AIToolInvoker} is the only
 * code path allowed to turn a proposal into an actual execution.
 */
final class AIProviderToolCall
{
    /**
     * @param  array<string, mixed>  $arguments  Raw, untrusted, model-supplied input.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $arguments,
    ) {}
}
