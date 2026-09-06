<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Configuration\Domain\Services\ConfigurationManager;
use Modules\Core\UserPreferences\Application\Services\UserPreferenceService;
use Modules\Notifications\Application\Services\NotificationDeliveryPolicy;
use Modules\Notifications\Domain\Enums\NotificationPriority;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-NOTIFICATIONS-ATTENTION-EXPERIENCE-003 (ADR-047 §14/§26.4-§26.8).
 *
 * Proves the three-tier precedence — MANDATORY SYSTEM POLICY > COMPANY DEFAULT > USER
 * PREFERENCE — governing the popup/sound attention layer, on top of Task 2's foundation.
 * Uses the real `ConfigurationManager`/`UserPreferenceService` write paths (not direct DB
 * pokes) so a future change to either module's internal storage shape is caught here too.
 */
class NotificationAttentionPolicyTest extends TestCase
{
    use RefreshDatabase;

    private NotificationDeliveryPolicy $policy;

    private ConfigurationManager $companySettings;

    private UserPreferenceService $userPreferences;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = app(NotificationDeliveryPolicy::class);
        $this->companySettings = app(ConfigurationManager::class);
        $this->userPreferences = app(UserPreferenceService::class);
    }

    private function userIn(Company $company): User
    {
        return User::factory()->create(['company_id' => $company->id]);
    }

    // ── Base defaults (ADR-047 §26.4), nothing configured ───────────────────────────

    public function test_base_defaults_apply_when_nothing_is_configured(): void
    {
        $user = $this->userIn(Company::factory()->create());

        $low = $this->policy->resolveAttention($user, NotificationPriority::LOW);
        $normal = $this->policy->resolveAttention($user, NotificationPriority::NORMAL);
        $high = $this->policy->resolveAttention($user, NotificationPriority::HIGH);
        $critical = $this->policy->resolveAttention($user, NotificationPriority::CRITICAL);

        $this->assertFalse($low->popup);
        $this->assertFalse($low->sound);
        $this->assertNull($low->soundProfile);

        $this->assertTrue($normal->popup);
        $this->assertFalse($normal->sound, 'NORMAL sound is "optional" — default off per §26.4.');

        $this->assertTrue($high->popup);
        $this->assertTrue($high->sound);
        $this->assertSame('important', $high->soundProfile->value);

        $this->assertTrue($critical->popup);
        $this->assertTrue($critical->sound);
        $this->assertSame('critical', $critical->soundProfile->value);
    }

    // ── Sound preference precedence (non-mandatory priority) ───────────────────────

    public function test_user_preference_governs_sound_for_a_non_mandatory_priority(): void
    {
        $user = $this->userIn(Company::factory()->create());

        $this->userPreferences->upsert((int) $user->id, 'notifications', ['sound_enabled' => true]);
        $this->assertTrue(
            $this->policy->resolveAttention($user, NotificationPriority::NORMAL)->sound,
            'User opted in to sound for NORMAL (default off) — preference must win.',
        );

        $this->userPreferences->upsert((int) $user->id, 'notifications', ['sound_enabled' => false]);
        $this->assertFalse(
            $this->policy->resolveAttention($user, NotificationPriority::HIGH)->sound,
            'User opted out of sound for HIGH (default on) — preference must win.',
        );
    }

    public function test_user_preference_governs_popup_for_a_non_mandatory_priority(): void
    {
        $user = $this->userIn(Company::factory()->create());

        $this->userPreferences->upsert((int) $user->id, 'notifications', ['popup_enabled' => false]);

        $this->assertFalse($this->policy->resolveAttention($user, NotificationPriority::HIGH)->popup);
        $this->assertFalse($this->policy->resolveAttention($user, NotificationPriority::NORMAL)->popup);
    }

    // ── Mandatory policy overrides every lower tier ─────────────────────────────────

    public function test_critical_priority_is_always_mandatory_regardless_of_user_preference(): void
    {
        $user = $this->userIn(Company::factory()->create());

        $this->userPreferences->upsert((int) $user->id, 'notifications', [
            'popup_enabled' => false,
            'sound_enabled' => false,
        ]);

        $attention = $this->policy->resolveAttention($user, NotificationPriority::CRITICAL);

        $this->assertTrue($attention->popup, 'CRITICAL popup can never be disabled by user preference.');
        $this->assertTrue($attention->sound, 'CRITICAL sound can never be disabled by user preference.');
    }

    public function test_a_company_can_mark_a_non_critical_priority_mandatory_and_it_overrides_user_preference(): void
    {
        $company = Company::factory()->create();
        $user = $this->userIn($company);

        $this->companySettings->setCompanySetting((string) $company->id, 'notifications', 'attention_defaults', [
            'high' => ['popup' => true, 'sound' => true, 'mandatory' => true],
        ]);
        $this->userPreferences->upsert((int) $user->id, 'notifications', [
            'popup_enabled' => false,
            'sound_enabled' => false,
        ]);

        $attention = $this->policy->resolveAttention($user, NotificationPriority::HIGH);

        $this->assertTrue($attention->popup, 'Company-mandated HIGH must override the user\'s own preference.');
        $this->assertTrue($attention->sound, 'Company-mandated HIGH must override the user\'s own preference.');
    }

    // ── Company default (non-mandatory) is the fallback baseline ───────────────────

    public function test_company_default_overrides_the_base_table_when_the_user_has_not_set_a_preference(): void
    {
        $company = Company::factory()->create();
        $user = $this->userIn($company);

        $this->companySettings->setCompanySetting((string) $company->id, 'notifications', 'attention_defaults', [
            'normal' => ['sound' => true],
        ]);

        $this->assertTrue(
            $this->policy->resolveAttention($user, NotificationPriority::NORMAL)->sound,
            'Company raised the NORMAL sound default from off to on; no user preference exists to override it.',
        );
    }

    public function test_user_preference_overrides_a_non_mandatory_company_default(): void
    {
        $company = Company::factory()->create();
        $user = $this->userIn($company);

        $this->companySettings->setCompanySetting((string) $company->id, 'notifications', 'attention_defaults', [
            'normal' => ['sound' => true],
        ]);
        $this->userPreferences->upsert((int) $user->id, 'notifications', ['sound_enabled' => false]);

        $this->assertFalse(
            $this->policy->resolveAttention($user, NotificationPriority::NORMAL)->sound,
            'Company default is only the fallback baseline when non-mandatory — user preference must still win.',
        );
    }

    // ── Tenant / user isolation ──────────────────────────────────────────────────────

    public function test_one_companys_attention_defaults_never_apply_to_another_company(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $userInB = $this->userIn($companyB);

        $this->companySettings->setCompanySetting((string) $companyA->id, 'notifications', 'attention_defaults', [
            'normal' => ['sound' => true],
        ]);

        $this->assertFalse(
            $this->policy->resolveAttention($userInB, NotificationPriority::NORMAL)->sound,
            "Company A's setting must not leak into company B's resolution.",
        );
    }

    public function test_one_users_preference_never_applies_to_another_user(): void
    {
        $company = Company::factory()->create();
        $userA = $this->userIn($company);
        $userB = $this->userIn($company);

        $this->userPreferences->upsert((int) $userA->id, 'notifications', ['sound_enabled' => true]);

        $this->assertFalse(
            $this->policy->resolveAttention($userB, NotificationPriority::NORMAL)->sound,
            "User A's preference must not leak into user B's resolution, even within the same company.",
        );
    }

    // ── Endpoint round-trip ──────────────────────────────────────────────────────────

    public function test_the_attention_policy_endpoint_returns_all_four_priorities_for_the_caller(): void
    {
        $user = $this->userIn(Company::factory()->create());

        $response = $this->actingAs($user)->getJson('/api/notifications/attention-policy')->assertOk();
        $data = $response->json('data');

        foreach (['low', 'normal', 'high', 'critical'] as $priority) {
            $this->assertArrayHasKey($priority, $data);
            $this->assertArrayHasKey('popup', $data[$priority]);
            $this->assertArrayHasKey('sound', $data[$priority]);
            $this->assertArrayHasKey('sound_profile', $data[$priority]);
        }

        $this->assertTrue($data['critical']['popup']);
        $this->assertSame('critical', $data['critical']['sound_profile']);
    }

    public function test_the_attention_policy_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/notifications/attention-policy')->assertUnauthorized();
    }

    // ── Existing Preparation notifications compatibility ────────────────────────────

    public function test_resolves_cleanly_for_the_priorities_preparations_real_producers_actually_use(): void
    {
        $user = $this->userIn(Company::factory()->create());

        // WaveStarted/WaveCompleted dispatch at NotificationPriority::NORMAL (Alert/Low
        // per Task 2's own report §8) and ShortageDetected at HIGH (Exception) — both
        // must resolve without error against a real recipient, unaffected by this task's
        // change to the policy's constructor.
        $low = $this->policy->resolveAttention($user, NotificationPriority::LOW);
        $high = $this->policy->resolveAttention($user, NotificationPriority::HIGH);

        $this->assertFalse($low->popup);
        $this->assertTrue($high->popup);
        $this->assertTrue($high->sound);
    }
}
