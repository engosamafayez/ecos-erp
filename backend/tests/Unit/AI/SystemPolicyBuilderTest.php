<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use Modules\AI\Application\Services\SystemPolicyBuilder;
use Modules\AI\Domain\ValueObjects\AIRequestContext;
use Tests\TestCase;

/**
 * CORE-03 Task 1 §23/§31 — the system prompt is where language behaviour is
 * specified (no separate NLU/translation service exists or is needed). This is
 * a pure unit test: no DB, no HTTP.
 */
class SystemPolicyBuilderTest extends TestCase
{
    private function context(
        string $locale = 'en',
        ?string $assistantName = null,
        ?string $assistantPersona = null,
        ?string $assistantSpeakingStyle = null,
        ?string $assistantLanguage = null,
    ): AIRequestContext {
        return new AIRequestContext(
            userId: 1,
            companyId: 'company-1',
            brandId: null,
            locale: $locale,
            route: null,
            module: null,
            page: null,
            entityType: null,
            entityId: null,
            assistantName: $assistantName,
            assistantPersona: $assistantPersona,
            assistantSpeakingStyle: $assistantSpeakingStyle,
            assistantLanguage: $assistantLanguage,
        );
    }

    public function test_policy_instructs_the_model_to_support_arabic_egyptian_arabic_and_english(): void
    {
        $prompt = (new SystemPolicyBuilder)->build($this->context('ar'));

        $this->assertStringContainsString('Egyptian Arabic', $prompt);
        $this->assertStringContainsString('English', $prompt);
        $this->assertStringContainsString('mirror', strtolower($prompt));
    }

    public function test_locale_is_included_only_as_a_hint_never_a_forced_language(): void
    {
        $prompt = (new SystemPolicyBuilder)->build($this->context('en'));

        $this->assertStringContainsString('hint', $prompt);
    }

    public function test_policy_forbids_claiming_an_unperformed_action(): void
    {
        $prompt = (new SystemPolicyBuilder)->build($this->context());

        $this->assertStringContainsString('read-only', $prompt);
    }

    // ── TASK-...-046 §12 — persona lines are additive and never authoritative ──

    public function test_an_unpersonalized_request_produces_the_exact_same_prompt_as_before(): void
    {
        $withNulls = (new SystemPolicyBuilder)->build($this->context('en'));
        $withoutPersonaArgsAtAll = (new SystemPolicyBuilder)->build(new AIRequestContext(
            userId: 1,
            companyId: 'company-1',
            brandId: null,
            locale: 'en',
            route: null,
            module: null,
            page: null,
            entityType: null,
            entityId: null,
        ));

        $this->assertSame($withoutPersonaArgsAtAll, $withNulls);
    }

    public function test_chosen_name_is_included_as_cosmetic_only(): void
    {
        $prompt = (new SystemPolicyBuilder)->build($this->context(assistantName: 'Nour'));

        $this->assertStringContainsString('Nour', $prompt);
        $this->assertStringContainsString('cosmetic only', $prompt);
    }

    public function test_neutral_persona_adds_no_extra_presentation_line(): void
    {
        $neutral = (new SystemPolicyBuilder)->build($this->context(assistantPersona: 'neutral'));
        $none = (new SystemPolicyBuilder)->build($this->context());

        $this->assertSame($none, $neutral);
    }

    public function test_non_neutral_persona_is_explicitly_scoped_to_presentation_only(): void
    {
        $prompt = (new SystemPolicyBuilder)->build($this->context(assistantPersona: 'female'));

        $this->assertStringContainsString('female', $prompt);
        $this->assertStringContainsString('never changes what data or actions you may access', $prompt);
    }

    public function test_speaking_style_adds_its_own_guidance_line(): void
    {
        $prompt = (new SystemPolicyBuilder)->build($this->context(assistantSpeakingStyle: 'concise'));

        $this->assertStringContainsString('Be brief', $prompt);
    }

    public function test_an_unrecognized_speaking_style_adds_no_line_rather_than_crashing(): void
    {
        $prompt = (new SystemPolicyBuilder)->build($this->context(assistantSpeakingStyle: 'not_a_real_style'));
        $none = (new SystemPolicyBuilder)->build($this->context());

        $this->assertSame($none, $prompt);
    }

    public function test_language_preference_is_phrased_as_a_leaning_never_a_forced_switch(): void
    {
        $prompt = (new SystemPolicyBuilder)->build($this->context(assistantLanguage: 'ar'));

        $this->assertStringContainsString('lean toward Arabic', $prompt);
        $this->assertStringContainsString('still mirror', $prompt);
    }
}
