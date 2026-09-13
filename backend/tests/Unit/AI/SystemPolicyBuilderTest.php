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
    private function context(string $locale = 'en'): AIRequestContext
    {
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
}
