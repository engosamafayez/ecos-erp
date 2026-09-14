<?php

declare(strict_types=1);

namespace Modules\AI\Application\ValueObjects;

use Modules\AI\Domain\Enums\AssistantAvatar;
use Modules\AI\Domain\Enums\AssistantLanguage;
use Modules\AI\Domain\Enums\AssistantPersona;
use Modules\AI\Domain\Enums\AssistantSpeakingStyle;

/**
 * TASK-ECOS-V1.1-FINAL-AI-ASSISTANT-PERSONALIZED-COMPANION-046 §7/§9 — the one
 * bounded, pure representation of a user's assistant personalization. Framework-
 * agnostic (no Eloquent, no HTTP) so defaults/merging are unit-testable without a
 * database — {@see \Modules\AI\Presentation\Http\Controllers\AssistantPreferenceController}
 * is the only place this is combined with actual persisted data
 * ({@see \Modules\Core\UserPreferences\Domain\Models\UserPreference}, category
 * 'ai_assistant' — no second preferences table, no second storage engine).
 */
final class AssistantPreferences
{
    public function __construct(
        public readonly string $avatarKey,
        public readonly string $name,
        public readonly string $persona,
        public readonly string $speakingStyle,
        public readonly string $language,
        // CTO scope override (same task, ticket 046) — voice is a client-side I/O
        // preference only (which browser Web Speech APIs to use and how), never a
        // server-side AI-behaviour toggle: it never reaches AIRequestContext/
        // SystemPolicyBuilder. `voiceChoice` is a BEST-EFFORT device-local voice
        // name/URI (Web Speech voices differ per browser/OS) — the frontend falls
        // back to a sensible default when the stored value isn't available on the
        // current device; this is a disclosed, honest limitation, not a bug.
        public readonly bool $voiceEnabled = false,
        public readonly bool $wakeByNameEnabled = false,
        public readonly ?string $voiceChoice = null,
    ) {}

    /**
     * §7 — sensible defaults at the application level, no seed/migration hack.
     * Arabic-first exactly when the resolving locale is Arabic; otherwise a
     * bilingual-leaning default (§6E) with a neutral, friendly presentation.
     */
    public static function defaults(string $locale): self
    {
        $arabicFirst = str_starts_with(strtolower($locale), 'ar');

        return new self(
            avatarKey: AssistantAvatar::default()->value,
            name: $arabicFirst ? 'مساعد ECOS' : 'ECOS Assistant',
            persona: AssistantPersona::default()->value,
            speakingStyle: AssistantSpeakingStyle::default()->value,
            language: $arabicFirst ? AssistantLanguage::Arabic->value : AssistantLanguage::Bilingual->value,
        );
    }

    /**
     * Merge a (possibly partial, possibly empty) stored payload over the
     * locale-appropriate defaults — never requires every key to be present, so a
     * user who has only ever set an avatar still gets a valid, complete object
     * back for every other field.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload, string $locale): self
    {
        $defaults = self::defaults($locale);

        $avatarKey = (string) ($payload['avatar_key'] ?? $defaults->avatarKey);
        $persona = (string) ($payload['persona'] ?? $defaults->persona);
        $speakingStyle = (string) ($payload['speaking_style'] ?? $defaults->speakingStyle);
        $language = (string) ($payload['language'] ?? $defaults->language);
        $name = trim((string) ($payload['name'] ?? $defaults->name));
        $voiceChoice = $payload['voice_choice'] ?? null;

        return new self(
            avatarKey: AssistantAvatar::tryFrom($avatarKey) !== null ? $avatarKey : $defaults->avatarKey,
            name: $name !== '' ? $name : $defaults->name,
            persona: AssistantPersona::tryFrom($persona) !== null ? $persona : $defaults->persona,
            speakingStyle: AssistantSpeakingStyle::tryFrom($speakingStyle) !== null ? $speakingStyle : $defaults->speakingStyle,
            language: AssistantLanguage::tryFrom($language) !== null ? $language : $defaults->language,
            voiceEnabled: (bool) ($payload['voice_enabled'] ?? $defaults->voiceEnabled),
            wakeByNameEnabled: (bool) ($payload['wake_by_name_enabled'] ?? $defaults->wakeByNameEnabled),
            voiceChoice: is_string($voiceChoice) && $voiceChoice !== '' ? $voiceChoice : null,
        );
    }

    /**
     * @return array{avatar_key: string, name: string, persona: string, speaking_style: string,
     *     language: string, voice_enabled: bool, wake_by_name_enabled: bool, voice_choice: ?string}
     */
    public function toArray(): array
    {
        return [
            'avatar_key' => $this->avatarKey,
            'name' => $this->name,
            'persona' => $this->persona,
            'speaking_style' => $this->speakingStyle,
            'language' => $this->language,
            'voice_enabled' => $this->voiceEnabled,
            'wake_by_name_enabled' => $this->wakeByNameEnabled,
            'voice_choice' => $this->voiceChoice,
        ];
    }
}
