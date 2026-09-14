<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use Modules\AI\Application\ValueObjects\AssistantPreferences;
use Tests\TestCase;

/**
 * TASK-ECOS-V1.1-FINAL-AI-ASSISTANT-PERSONALIZED-COMPANION-046 §7/§9 — pure unit
 * tests: no DB, no HTTP. Proves the ONE default/merge authority every read path
 * (AssistantPreferenceController::show(), AssistantController::message()) shares.
 */
class AssistantPreferencesTest extends TestCase
{
    // ── §7 defaults ──────────────────────────────────────────────────────────

    public function test_arabic_locale_yields_an_arabic_first_default(): void
    {
        $defaults = AssistantPreferences::defaults('ar');

        $this->assertSame('مساعد ECOS', $defaults->name);
        $this->assertSame('ar', $defaults->language);
    }

    public function test_non_arabic_locale_yields_a_bilingual_leaning_default(): void
    {
        $defaults = AssistantPreferences::defaults('en');

        $this->assertSame('ECOS Assistant', $defaults->name);
        $this->assertSame('bilingual', $defaults->language);
    }

    public function test_default_avatar_persona_and_style_are_stable_across_locales(): void
    {
        $ar = AssistantPreferences::defaults('ar');
        $en = AssistantPreferences::defaults('en');

        $this->assertSame('ecos_blue_bot', $ar->avatarKey);
        $this->assertSame('ecos_blue_bot', $en->avatarKey);
        $this->assertSame('neutral', $ar->persona);
        $this->assertSame('friendly', $ar->speakingStyle);
    }

    // ── CTO scope override (same task 046) — voice defaults are OFF, opt-in only ──

    public function test_voice_and_wake_by_name_default_to_disabled_with_no_stored_voice_choice(): void
    {
        $defaults = AssistantPreferences::defaults('en');

        $this->assertFalse($defaults->voiceEnabled);
        $this->assertFalse($defaults->wakeByNameEnabled);
        $this->assertNull($defaults->voiceChoice);
    }

    // ── §9 merge behaviour — a new user needs no seed hack ──────────────────

    public function test_an_empty_stored_payload_resolves_to_full_locale_defaults(): void
    {
        $prefs = AssistantPreferences::fromPayload([], 'ar');

        $this->assertSame(AssistantPreferences::defaults('ar')->toArray(), $prefs->toArray());
    }

    public function test_a_partial_stored_payload_keeps_only_the_set_fields(): void
    {
        $prefs = AssistantPreferences::fromPayload(['avatar_key' => 'ecos_ember_companion'], 'en');

        $this->assertSame('ecos_ember_companion', $prefs->avatarKey);
        // Everything else still falls back to the locale default, not an empty/null value.
        $this->assertSame('ECOS Assistant', $prefs->name);
        $this->assertSame('bilingual', $prefs->language);
    }

    public function test_an_invalid_stored_avatar_key_fails_closed_to_the_default_rather_than_persisting_garbage(): void
    {
        $prefs = AssistantPreferences::fromPayload(['avatar_key' => 'not_a_real_avatar'], 'en');

        $this->assertSame('ecos_blue_bot', $prefs->avatarKey);
    }

    public function test_a_whitespace_only_stored_name_falls_back_to_the_default_name(): void
    {
        $prefs = AssistantPreferences::fromPayload(['name' => '   '], 'en');

        $this->assertSame('ECOS Assistant', $prefs->name);
    }

    public function test_to_array_round_trips_through_from_payload(): void
    {
        $original = AssistantPreferences::fromPayload([
            'avatar_key' => 'ecos_owl_companion',
            'name' => 'Nour',
            'persona' => 'female',
            'speaking_style' => 'concise',
            'language' => 'ar',
            'voice_enabled' => true,
            'wake_by_name_enabled' => true,
            'voice_choice' => 'Microsoft Hoda - Arabic (Egypt)',
        ], 'en');

        $roundTripped = AssistantPreferences::fromPayload($original->toArray(), 'en');

        $this->assertSame($original->toArray(), $roundTripped->toArray());
    }

    public function test_voice_choice_is_stored_only_when_a_non_empty_string(): void
    {
        $blank = AssistantPreferences::fromPayload(['voice_choice' => ''], 'en');
        $set = AssistantPreferences::fromPayload(['voice_choice' => 'Google US English'], 'en');

        $this->assertNull($blank->voiceChoice);
        $this->assertSame('Google US English', $set->voiceChoice);
    }

    public function test_voice_and_wake_by_name_are_read_as_real_booleans_from_stored_payload(): void
    {
        $prefs = AssistantPreferences::fromPayload(['voice_enabled' => true, 'wake_by_name_enabled' => false], 'en');

        $this->assertTrue($prefs->voiceEnabled);
        $this->assertFalse($prefs->wakeByNameEnabled);
    }
}
