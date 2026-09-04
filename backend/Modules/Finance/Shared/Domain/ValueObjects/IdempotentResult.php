<?php

declare(strict_types=1);

namespace Modules\Finance\Shared\Domain\ValueObjects;

use Illuminate\Database\Eloquent\Model;

/**
 * The outcome of a {@see \Modules\Finance\Shared\Domain\Services\CommandIdempotencyGuard::execute()}
 * call: either the command ran just now, or an identical earlier request's
 * result is being replayed verbatim. $wasReplayed carries no transport
 * meaning by itself — a future controller (Task 3) is expected to map it to
 * an HTTP status/header (e.g. 201 vs 200 + `Idempotent-Replay: true`); this
 * value object only states the domain fact.
 */
final class IdempotentResult
{
    private function __construct(
        public readonly Model $result,
        public readonly bool $wasReplayed,
    ) {}

    public static function firstExecution(Model $result): self
    {
        return new self($result, false);
    }

    public static function replay(Model $result): self
    {
        return new self($result, true);
    }
}
