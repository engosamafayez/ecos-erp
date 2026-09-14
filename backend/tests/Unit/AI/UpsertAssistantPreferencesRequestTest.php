<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use Illuminate\Validation\Rule;
use Modules\AI\Domain\Enums\AssistantAvatar;
use Modules\AI\Domain\Enums\AssistantLanguage;
use Modules\AI\Domain\Enums\AssistantPersona;
use Modules\AI\Domain\Enums\AssistantSpeakingStyle;
use Modules\AI\Presentation\Http\Requests\UpsertAssistantPreferencesRequest;
use Tests\TestCase;

/**
 * TASK-ECOS-V1.1-FINAL-AI-ASSISTANT-PERSONALIZED-COMPANION-046 §6/§9/§20 — proves
 * the request's own rules() array is bound to the real enums (never a hand-typed
 * copy that could silently drift), mirroring the existing convention in
 * tests/Feature/Commerce/ManualOrderStatusValidationTest.php ("the rule is
 * derived, not copied"). Pure unit test: instantiates the FormRequest directly,
 * no DB, no HTTP.
 */
class UpsertAssistantPreferencesRequestTest extends TestCase
{
    private function rules(): array
    {
        return (new UpsertAssistantPreferencesRequest)->rules();
    }

    public function test_avatar_key_is_required_and_bound_to_the_real_avatar_enum(): void
    {
        $rules = $this->rules();

        $this->assertContains('required', $rules['avatar_key']);
        $this->assertEquals(Rule::in(AssistantAvatar::values()), $this->findInRule($rules['avatar_key']));
    }

    public function test_persona_is_required_and_bound_to_the_real_persona_enum(): void
    {
        $rules = $this->rules();

        $this->assertContains('required', $rules['persona']);
        $this->assertEquals(Rule::in(AssistantPersona::values()), $this->findInRule($rules['persona']));
    }

    public function test_speaking_style_is_required_and_bound_to_the_real_style_enum(): void
    {
        $rules = $this->rules();

        $this->assertContains('required', $rules['speaking_style']);
        $this->assertEquals(Rule::in(AssistantSpeakingStyle::values()), $this->findInRule($rules['speaking_style']));
    }

    public function test_language_is_required_and_bound_to_the_real_language_enum(): void
    {
        $rules = $this->rules();

        $this->assertContains('required', $rules['language']);
        $this->assertEquals(Rule::in(AssistantLanguage::values()), $this->findInRule($rules['language']));
    }

    public function test_name_is_nullable_with_a_bounded_max_length(): void
    {
        $rules = $this->rules();

        $this->assertContains('nullable', $rules['name']);
        $this->assertContains('max:40', $rules['name']);
    }

    // ── CTO scope override (same task 046) / FINAL CLOSURE §2 — voice fields ────

    public function test_voice_input_spoken_responses_and_wake_by_name_are_nullable_booleans(): void
    {
        $rules = $this->rules();

        $this->assertContains('nullable', $rules['voice_input_enabled']);
        $this->assertContains('boolean', $rules['voice_input_enabled']);
        $this->assertContains('nullable', $rules['spoken_responses_enabled']);
        $this->assertContains('boolean', $rules['spoken_responses_enabled']);
        $this->assertContains('nullable', $rules['wake_by_name_enabled']);
        $this->assertContains('boolean', $rules['wake_by_name_enabled']);
    }

    public function test_voice_choice_is_a_bounded_nullable_string_never_a_closed_enum(): void
    {
        $rules = $this->rules();

        $this->assertContains('nullable', $rules['voice_choice']);
        $this->assertContains('string', $rules['voice_choice']);
        $this->assertContains('max:100', $rules['voice_choice']);
        // Deliberately NOT Rule::in(...) — Web Speech voice names are assigned by
        // the browser/OS, not ECOS, so there is no fixed set to validate against.
        foreach ($rules['voice_choice'] as $rule) {
            $this->assertIsString($rule, 'voice_choice must never be bound to a closed server-side enum.');
        }
    }

    private function findInRule(array $fieldRules): mixed
    {
        foreach ($fieldRules as $rule) {
            if (! is_string($rule)) {
                return $rule;
            }
        }

        $this->fail('Expected a Rule::in(...) object among the field rules.');
    }
}
