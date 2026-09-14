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

        $this->assertFalse($defaults->voiceInputEnabled);
        $this->assertFalse($defaults->spokenResponsesEnabled);
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
            'voice_input_enabled' => true,
            'spoken_responses_enabled' => true,
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

    public function test_voice_input_and_wake_by_name_are_read_as_real_booleans_from_stored_payload(): void
    {
        $prefs = AssistantPreferences::fromPayload(['voice_input_enabled' => true, 'wake_by_name_enabled' => false], 'en');

        $this->assertTrue($prefs->voiceInputEnabled);
        $this->assertFalse($prefs->wakeByNameEnabled);
    }

    // ── FINAL CLOSURE §2 — voice input, spoken output, and Wake by Name must be
    // independently controllable; Wake by Name depends ONLY on voice input ──

    public function test_spoken_responses_can_be_enabled_independently_of_voice_input(): void
    {
        $prefs = AssistantPreferences::fromPayload([
            'voice_input_enabled' => false,
            'spoken_responses_enabled' => true,
        ], 'en');

        $this->assertFalse($prefs->voiceInputEnabled);
        $this->assertTrue($prefs->spokenResponsesEnabled);
    }

    public function test_wake_by_name_can_stay_enabled_while_spoken_responses_are_disabled(): void
    {
        $prefs = AssistantPreferences::fromPayload([
            'voice_input_enabled' => true,
            'spoken_responses_enabled' => false,
            'wake_by_name_enabled' => true,
        ], 'en');

        $this->assertTrue($prefs->wakeByNameEnabled);
        $this->assertFalse($prefs->spokenResponsesEnabled);
    }

    public function test_wake_by_name_fails_closed_to_false_when_voice_input_is_disabled(): void
    {
        $prefs = AssistantPreferences::fromPayload([
            'voice_input_enabled' => false,
            'wake_by_name_enabled' => true,
        ], 'en');

        $this->assertFalse($prefs->wakeByNameEnabled, 'Wake by Name must never be true when voice input is off, even from a stale/tampered payload.');
    }

    public function test_legacy_voice_enabled_payload_enables_both_new_fields_for_backward_compatibility(): void
    {
        $prefs = AssistantPreferences::fromPayload(['voice_enabled' => true], 'en');

        $this->assertTrue($prefs->voiceInputEnabled);
        $this->assertTrue($prefs->spokenResponsesEnabled);
    }

    public function test_legacy_voice_enabled_false_payload_leaves_both_new_fields_disabled(): void
    {
        $prefs = AssistantPreferences::fromPayload(['voice_enabled' => false], 'en');

        $this->assertFalse($prefs->voiceInputEnabled);
        $this->assertFalse($prefs->spokenResponsesEnabled);
    }

    public function test_new_keys_take_precedence_over_the_legacy_voice_enabled_key_when_both_are_present(): void
    {
        $prefs = AssistantPreferences::fromPayload([
            'voice_enabled' => true,
            'voice_input_enabled' => true,
            'spoken_responses_enabled' => false,
        ], 'en');

        $this->assertTrue($prefs->voiceInputEnabled);
        $this->assertFalse($prefs->spokenResponsesEnabled, 'An explicit spoken_responses_enabled: false must win over the legacy combined flag.');
    }
}
