<?php

declare(strict_types=1);

namespace Modules\AI\Domain\Enums;

/**
 * TASK-ECOS-V1.1-FINAL-AI-ASSISTANT-PERSONALIZED-COMPANION-046 §6A — the stable,
 * machine-readable avatar keys a user may choose for their assistant. Deliberately
 * separate from any translated/display name (§6A: "Do not bind persistence to
 * visible translated names") — the frontend owns the actual artwork and labels for
 * each key; this enum exists only so the backend can validate and persist a known,
 * closed set of values.
 *
 * These are original ECOS product concepts, not reproductions of any third-party
 * mascot (Codex/Dewey/Fireball/Hoots/Rocky/Seedy/Stacky/BSOD, etc.) — see the
 * frontend's `assistant-avatars.tsx` for the actual artwork.
 */
enum AssistantAvatar: string
{
    case EcosBlueBot = 'ecos_blue_bot';
    case EcosPurpleBot = 'ecos_purple_bot';
    case EcosEmberCompanion = 'ecos_ember_companion';
    case EcosOwlCompanion = 'ecos_owl_companion';
    case EcosRockCompanion = 'ecos_rock_companion';
    case EcosGrowthCompanion = 'ecos_growth_companion';
    case EcosStackCompanion = 'ecos_stack_companion';
    case EcosScreenCompanion = 'ecos_screen_companion';

    public static function default(): self
    {
        return self::EcosBlueBot;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c) => $c->value, self::cases());
    }
}
