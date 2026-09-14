<?php

declare(strict_types=1);

namespace Tests\Feature\CustomerEngagement\Voice;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Crm\Customers\Domain\Models\Customer;
use Modules\Crm\Customers\Domain\Models\CustomerPreference;
use Modules\CustomerEngagement\Domain\Enums\ChannelProviderStatus;
use Modules\CustomerEngagement\Domain\Models\ChannelProvider;
use Modules\CustomerEngagement\Voice\Domain\Exceptions\OutboundCallNotEligibleException;
use Modules\CustomerEngagement\Voice\Domain\Exceptions\TelephonyProviderUnavailableException;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-V1.1-CRM-03-BRAND-VOICE-IDENTITY-FINAL-REMEDIATION-017 §9 — 10 focused tests
 * covering all 12 enumerated concerns (items 4/11 are each exercised from two angles inside one
 * test method rather than duplicated into a second method). Item 12 (Human Transfer/caller
 * verification behaviour unchanged) is not re-tested here — this ticket touches neither
 * HumanTransferService nor VoiceAIToolInvoker at all; ticket 016's own
 * TransferDestinationResolverTest/VoiceVerificationEnforcementTest remain the live coverage for
 * that surface, unaffected by anything in this file.
 */
final class VoiceBrandIdentityScopeTest extends TestCase
{
    use DatabaseTransactions;

    protected bool $grantsBaselineAuthorization = false;

    private function grant(User $user, string ...$permissionNames): void
    {
        $role = Role::create(['name' => 'Test Role '.$user->id, 'slug' => 'test-role-'.$user->id, 'is_system' => false]);
        $ids = Permission::query()->whereIn('name', $permissionNames)->pluck('id');
        $role->permissions()->syncWithoutDetaching($ids);
        $user->assignRole($role);
    }

    private function makeProvider(string $companyId, ?string $brandId, array $overrides = []): ChannelProvider
    {
        return ChannelProvider::create(array_merge([
            'company_id' => $companyId,
            'brand_id' => $brandId,
            'channel' => 'voice',
            'display_name' => 'Voice Line',
            'phone_number' => '201099999999',
            'status' => ChannelProviderStatus::ACTIVE->value,
            'credentials' => [],
        ], $overrides));
    }

    // ── 1/2/4: list is Brand-scoped, another Brand's identities absent, shared fallback included ──

    public function test_channel_providers_list_scoped_to_brand_includes_brand_and_shared_only(): void
    {
        $company = Company::factory()->create();
        $brandA = Brand::factory()->create(['company_id' => $company->id]);
        $brandB = Brand::factory()->create(['company_id' => $company->id]);
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->grant($user, 'cep.voice.use');

        $providerA = $this->makeProvider($company->id, $brandA->id, ['display_name' => 'Brand A Line']);
        $providerB = $this->makeProvider($company->id, $brandB->id, ['display_name' => 'Brand B Line']);
        $providerShared = $this->makeProvider($company->id, null, ['display_name' => 'Shared Line']);

        $response = $this->actingAsUnprivileged($user)
            ->getJson("/api/cep/voice/channel-providers?company_id={$company->id}&brand_id={$brandA->id}");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($providerA->id, $ids);
        $this->assertContains($providerShared->id, $ids, 'the documented brand_id=NULL company-wide fallback must be visible from any Brand');
        $this->assertNotContains($providerB->id, $ids, 'a different Brand\'s identity must never leak into this Brand\'s list');
    }

    // ── 3: Company B identities never visible ────────────────────────────────────────────────

    public function test_channel_providers_list_rejects_a_request_for_a_different_companys_scope(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $brandB = Brand::factory()->create(['company_id' => $companyB->id]);
        $this->makeProvider($companyB->id, $brandB->id, ['display_name' => 'Company B Line']);

        // Acting user actually belongs to Company A but asks for Company B's scope.
        $user = User::factory()->create(['company_id' => $companyA->id]);
        $this->grant($user, 'cep.voice.use');

        $response = $this->actingAsUnprivileged($user)
            ->getJson("/api/cep/voice/channel-providers?company_id={$companyB->id}&brand_id={$brandB->id}");

        $response->assertStatus(403);
    }

    // ── 10: unresolved Brand never falls back to the full company list ──────────────────────

    public function test_channel_providers_list_without_a_brand_id_returns_an_explicit_unresolved_state_not_every_identity(): void
    {
        $company = Company::factory()->create();
        $brand = Brand::factory()->create(['company_id' => $company->id]);
        $this->makeProvider($company->id, $brand->id);
        $this->makeProvider($company->id, null);
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->grant($user, 'cep.voice.use');

        $response = $this->actingAsUnprivileged($user)
            ->getJson("/api/cep/voice/channel-providers?company_id={$company->id}");

        $response->assertOk();
        $response->assertJson(['brand_context_required' => true, 'data' => []]);
    }

    // ── 5: outbound initiation accepts a valid current-Brand identity ───────────────────────

    public function test_outbound_initiation_accepts_a_valid_current_brand_identity(): void
    {
        $company = Company::factory()->create();
        $brand = Brand::factory()->create(['company_id' => $company->id]);
        $provider = $this->makeProvider($company->id, $brand->id);
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->grant($user, 'cep.voice.use');

        // No concrete telephony vendor exists in any environment (unchanged since ticket 015) —
        // reaching TelephonyProviderUnavailableException (rather than a 403/422 from this
        // ticket's own new checks) is the precise proof that company/Brand/active/channel all
        // passed and the request reached the real provider-bridge attempt.
        $this->withoutExceptionHandling();
        $this->expectException(TelephonyProviderUnavailableException::class);

        $this->actingAsUnprivileged($user)
            ->postJson("/api/cep/voice/channel-providers/{$provider->id}/calls", [
                'to_number' => '201000000001',
                'purpose' => 'support',
                'brand_id' => $brand->id,
            ]);
    }

    // ── 6: outbound initiation rejects a different Brand's identity in the same Company ─────

    public function test_outbound_initiation_rejects_a_different_brands_identity_in_the_same_company(): void
    {
        $company = Company::factory()->create();
        $brandA = Brand::factory()->create(['company_id' => $company->id]);
        $brandB = Brand::factory()->create(['company_id' => $company->id]);
        $providerB = $this->makeProvider($company->id, $brandB->id);
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->grant($user, 'cep.voice.use');

        $response = $this->actingAsUnprivileged($user)
            ->postJson("/api/cep/voice/channel-providers/{$providerB->id}/calls", [
                'to_number' => '201000000001',
                'purpose' => 'support',
                'brand_id' => $brandA->id,
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseCount('cep_calls', 0);
    }

    // ── 7: outbound initiation rejects another Company's identity ───────────────────────────

    public function test_outbound_initiation_rejects_another_companys_identity(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $brandB = Brand::factory()->create(['company_id' => $companyB->id]);
        $providerB = $this->makeProvider($companyB->id, $brandB->id);
        $user = User::factory()->create(['company_id' => $companyA->id]);
        $this->grant($user, 'cep.voice.use');

        $response = $this->actingAsUnprivileged($user)
            ->postJson("/api/cep/voice/channel-providers/{$providerB->id}/calls", [
                'to_number' => '201000000001',
                'purpose' => 'support',
                'brand_id' => $brandB->id,
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseCount('cep_calls', 0);
    }

    // ── 8: inactive identity rejected ────────────────────────────────────────────────────────

    public function test_outbound_initiation_rejects_an_inactive_identity(): void
    {
        $company = Company::factory()->create();
        $brand = Brand::factory()->create(['company_id' => $company->id]);
        $provider = $this->makeProvider($company->id, $brand->id, ['status' => ChannelProviderStatus::INACTIVE->value]);
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->grant($user, 'cep.voice.use');

        $response = $this->actingAsUnprivileged($user)
            ->postJson("/api/cep/voice/channel-providers/{$provider->id}/calls", [
                'to_number' => '201000000001',
                'purpose' => 'support',
                'brand_id' => $brand->id,
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('cep_calls', 0);
    }

    // ── 9: manipulated/stale frontend — no Brand context sent for a Brand-scoped identity ───

    public function test_outbound_initiation_rejects_a_brand_scoped_identity_when_no_brand_context_is_supplied(): void
    {
        $company = Company::factory()->create();
        $brand = Brand::factory()->create(['company_id' => $company->id]);
        $provider = $this->makeProvider($company->id, $brand->id);
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->grant($user, 'cep.voice.use');

        $response = $this->actingAsUnprivileged($user)
            ->postJson("/api/cep/voice/channel-providers/{$provider->id}/calls", [
                'to_number' => '201000000001',
                'purpose' => 'support',
                // brand_id deliberately omitted — an unresolved/stale frontend must never be
                // able to ride on a Brand-scoped identity by simply not declaring a Brand.
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseCount('cep_calls', 0);
    }

    // ── 4 (outbound half): the shared company-wide identity works with no resolved Brand ───

    public function test_outbound_initiation_accepts_the_shared_company_wide_identity_without_a_resolved_brand(): void
    {
        $company = Company::factory()->create();
        $provider = $this->makeProvider($company->id, null);
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->grant($user, 'cep.voice.use');

        $this->withoutExceptionHandling();
        $this->expectException(TelephonyProviderUnavailableException::class);

        $this->actingAsUnprivileged($user)
            ->postJson("/api/cep/voice/channel-providers/{$provider->id}/calls", [
                'to_number' => '201000000001',
                'purpose' => 'support',
            ]);
    }

    // ── 11: existing outbound consent/purpose policy remains enforced after the new checks pass ──

    public function test_outbound_initiation_still_enforces_the_existing_opt_out_policy_once_brand_and_company_checks_pass(): void
    {
        $company = Company::factory()->create();
        $brand = Brand::factory()->create(['company_id' => $company->id]);
        $provider = $this->makeProvider($company->id, $brand->id);
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->grant($user, 'cep.voice.use');

        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Opted Out']);
        CustomerPreference::create(['customer_id' => $customer->id, 'key' => 'voice_call_opt_out', 'value' => 'true']);

        $this->withoutExceptionHandling();
        $this->expectException(OutboundCallNotEligibleException::class);

        $this->actingAsUnprivileged($user)
            ->postJson("/api/cep/voice/channel-providers/{$provider->id}/calls", [
                'to_number' => '201000000001',
                'purpose' => 'support',
                'customer_id' => $customer->id,
                'brand_id' => $brand->id,
            ]);
    }
}
