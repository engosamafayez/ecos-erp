<?php

declare(strict_types=1);

namespace Modules\AI\Domain\Enums;

/**
 * §6C — presentation/self-reference only. Never consulted for authorization, tool
 * availability, or any business decision; {@see \Modules\AI\Application\Services\
 * SystemPolicyBuilder} is the ONLY place this value is read, and even there it only
 * shapes natural-language self-reference (e.g. Arabic grammatical gender), never
 * what the assistant is permitted to do.
 */
enum AssistantPersona: string
{
    case Male = 'male';
    case Female = 'female';
    case Neutral = 'neutral';

    public static function default(): self
    {
        return self::Neutral;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c) => $c->value, self::cases());
    }
}
