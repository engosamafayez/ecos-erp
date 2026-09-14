<?php

declare(strict_types=1);

namespace Modules\AI\Domain\Enums;

/**
 * §6D — a stable, closed vocabulary for how the assistant phrases itself. Guidance
 * only (see SystemPolicyBuilder) — never a business rule, never a permission.
 */
enum AssistantSpeakingStyle: string
{
    case EgyptianCasual = 'egyptian_casual';
    case Formal = 'formal';
    case Concise = 'concise';
    case Friendly = 'friendly';
    case Technical = 'technical';
    case Detailed = 'detailed';

    public static function default(): self
    {
        return self::Friendly;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c) => $c->value, self::cases());
    }
}
