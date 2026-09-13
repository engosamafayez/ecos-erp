<?php

declare(strict_types=1);

namespace Modules\AI\Domain\Contracts;

use App\Models\User;
use Modules\AI\Domain\Enums\AIToolClassification;
use Modules\AI\Domain\Enums\AIToolScope;
use Modules\AI\Domain\ValueObjects\AIRequestContext;
use Modules\AI\Domain\ValueObjects\AIToolResult;

/**
 * One narrow, explicitly-registered capability (§6/§9). A tool owns none of its
 * own authorization logic beyond declaring `permission()`/`scope()` — the actual
 * gate is {@see \Modules\AI\Application\Services\AIToolInvoker}, applied
 * identically to every tool so no tool can quietly grant itself more access.
 */
interface AIToolInterface
{
    /** The exact name the model calls this tool by. Must be unique in the registry. */
    public function name(): string;

    /** Sent to the provider verbatim — tells the model when/why to call this tool. */
    public function description(): string;

    /** @return array<string, mixed> JSON Schema (type: object, properties, required). */
    public function inputSchema(): array;

    /** The existing ECOS domain permission this tool additionally requires. */
    public function permission(): string;

    public function scope(): AIToolScope;

    public function classification(): AIToolClassification;

    /** V1 has no confirmable tools (§9/§33) — every current tool returns false. */
    public function requiresConfirmation(): bool;

    /**
     * @param  array<string, mixed>  $input  Raw, already-schema-validated model input.
     */
    public function execute(AIRequestContext $context, User $user, array $input): AIToolResult;
}
