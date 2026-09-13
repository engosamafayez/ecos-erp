<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-V1.1-NEXT-REVIEW-BRAND-VIEW-EDIT-SLUG-REMEDIATION-005 — backend slug authority.
 *
 * Covers the create/update slug contract the remediation depends on:
 *  - slug is optional on create (omitted OR blank) and generated from the name;
 *  - a non-Latin (e.g. Arabic) name falls back to the code-derived slug so the NOT NULL,
 *    company-unique slug column is never blank (§7);
 *  - update PRESERVES the existing slug when none is supplied — it is never regenerated from a
 *    changed name (§6);
 *  - an explicit conflicting slug on update is rejected with a validation error (§8);
 *  - generated-slug uniqueness stays company-scoped (unchanged authority).
 *
 * Per the CTO's Track-2 execution model these are source-level regression coverage; their
 * EXECUTION (RefreshDatabase → migrate) is deferred to the consolidated NEXT test pass.
 */
class BrandSlugRemediationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->company = Company::factory()->create();
    }

    private function auth(): static
    {
        return $this->actingAs($this->user);
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'company_id' => $this->company->id,
            'name' => 'Aseel',
            'is_active' => true,
        ], $overrides);
    }

    public function test_create_succeeds_when_slug_omitted(): void
    {
        $this->auth()->postJson('/api/brands', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.slug', 'aseel');
    }

    public function test_create_succeeds_when_slug_is_blank_and_generates_canonical_slug(): void
    {
        // A blank slug must submit successfully (§5) — normalised to absent, then generated.
        $this->auth()->postJson('/api/brands', $this->payload(['slug' => '']))
            ->assertCreated()
            ->assertJsonPath('data.slug', 'aseel');
    }

    public function test_generated_slug_uniqueness_is_company_scoped(): void
    {
        // Same name twice in the SAME company → second is auto-suffixed.
        $this->auth()->postJson('/api/brands', $this->payload(['name' => 'Dup Brand']))
            ->assertCreated()
            ->assertJsonPath('data.slug', 'dup-brand');

        $this->auth()->postJson('/api/brands', $this->payload(['name' => 'Dup Brand']))
            ->assertCreated()
            ->assertJsonPath('data.slug', 'dup-brand-1');

        // The SAME slug is free again in a DIFFERENT company.
        $other = Company::factory()->create();
        $this->auth()->postJson('/api/brands', $this->payload(['company_id' => $other->id, 'name' => 'Dup Brand']))
            ->assertCreated()
            ->assertJsonPath('data.slug', 'dup-brand');
    }

    public function test_arabic_name_falls_back_to_code_derived_slug(): void
    {
        // Str::slug() strips Arabic to '' — the backend must fall back to the code-derived
        // slug rather than persist a blank/duplicate slug (§7). First brand → code BRD-000001.
        $this->auth()->postJson('/api/brands', $this->payload(['name' => 'أصيل']))
            ->assertCreated()
            ->assertJsonPath('data.slug', 'brd-000001');
    }

    public function test_update_preserves_existing_slug_when_omitted(): void
    {
        $brand = Brand::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Original',
            'slug' => 'keep-me',
        ]);

        // Change only the name; do NOT supply a slug. The existing slug must survive (§6).
        $this->auth()->putJson("/api/brands/{$brand->id}", [
            'name' => 'Renamed Brand',
            'is_active' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.slug', 'keep-me')
            ->assertJsonPath('data.name', 'Renamed Brand');
    }

    public function test_update_preserves_existing_slug_when_blank(): void
    {
        $brand = Brand::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Original',
            'slug' => 'keep-me',
        ]);

        $this->auth()->putJson("/api/brands/{$brand->id}", [
            'name' => 'Renamed Again',
            'slug' => '',
            'is_active' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.slug', 'keep-me');
    }

    public function test_update_applies_an_explicitly_edited_slug(): void
    {
        $brand = Brand::factory()->create([
            'company_id' => $this->company->id,
            'slug' => 'old-slug',
        ]);

        $this->auth()->putJson("/api/brands/{$brand->id}", [
            'name' => 'Whatever',
            'slug' => 'new-custom-slug',
            'is_active' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.slug', 'new-custom-slug');
    }

    public function test_update_rejects_explicit_conflicting_slug(): void
    {
        Brand::factory()->create(['company_id' => $this->company->id, 'code' => 'BRD-000001', 'slug' => 'taken']);
        $brand = Brand::factory()->create(['company_id' => $this->company->id, 'code' => 'BRD-000002', 'slug' => 'mine']);

        $this->auth()->putJson("/api/brands/{$brand->id}", [
            'name' => 'Attempted Clash',
            'slug' => 'taken',
            'is_active' => true,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);
    }
}
