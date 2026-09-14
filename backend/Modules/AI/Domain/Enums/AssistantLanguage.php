<?php

declare(strict_types=1);

namespace Modules\AI\Domain\Enums;

/**
 * §6E — the user's preferred assistant language. This is a HINT passed into the
 * system prompt (see SystemPolicyBuilder), never a hard switch: the model already
 * mirrors whatever language the user actually types in, and bilingual behaviour is
 * only ever as real as the underlying AIProviderInterface (§6E: "Only expose
 * bilingual AI behavior if the actual existing AI authority can support it" — the
 * existing OpenAIProvider/DisabledAIProvider pair is unchanged by this enum).
 */
enum AssistantLanguage: string
{
    case Arabic = 'ar';
    case English = 'en';
    case Bilingual = 'bilingual';

    public static function default(): self
    {
        return self::Bilingual;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c) => $c->value, self::cases());
    }
}
