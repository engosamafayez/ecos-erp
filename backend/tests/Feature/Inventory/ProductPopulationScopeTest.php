<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\IAM\Domain\Models\Role;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Organization\Brands\Domain\Models\Brand;
use Tests\TestCase;

/**
 * TASK-PHASE3-GD1-STEP3-CLOSE-001 — Product population (Step 3).
 *
 * The KPI endpoint always scoped to the authenticated company; the list scoped
 * only when the caller supplied a filter. With no filter the KPI counted one
 * company while the table showed several — the "All Materials = 0 above 2 rows"
 * symptom. Both now resolve population through the same certified RC-6 path.
 *
 * `$grantsBaselineAuthorization = false`: actingAs() would otherwise grant the
 * is_system role that authorizes cross-company access.
 */
final class ProductPopulationScopeTest extends TestCase
{
    use RefreshDatabase;

    protected bool $grantsBaselineAuthorization = false;

    private function operatorFor(?Company $company): User
    {
        $user = User::factory()->create(['company_id' => $company?->id]);

        $role = Role::firstOrCreate(
            ['slug' => 'test-product-operator'],
            ['name' => 'Test Product Operator', 'is_system' => false],
        );
        $user->roles()->attach($role->id);
        $user->unsetRelation('roles');

        return $user;
    }

    private function unrestrictedUser(): User
    {
        return $this->grantSystemRole(User::factory()->create(['company_id' => null]));
    }

    private function productFor(Company $company): Product
    {
        $brand = Brand::factory()->create(['company_id' => $company->id]);

        return Product::factory()->create([
            'brand_id' => $brand->id,
            'product_type' => 'raw_material',
        ]);
    }

    /** @return array{stats:int, list:int} */
    private function populations(string $query = ''): array
    {
        $suffix = $query === '' ? '' : '?'.$query;

        $stats = $this->getJson("/api/products/stats{$suffix}")->assertOk()->json('data');
        $list = $this->getJson("/api/products{$suffix}")->assertOk()->json('data');

        $statsTotal = $stats['total_count'] ?? null;

        return [
            'stats' => is_numeric($statsTotal) ? (int) $statsTotal : -1,
            'list' => (int) ($list['meta']['total'] ?? -1),
        ];
    }

    // ── 1 + 2. Company-scoped list and statistics ─────────────────────────────

    public function test_company_user_sees_only_their_own_products(): void
    {
        $own = Company::factory()->create();
        $foreign = Company::factory()->create();
        $mine = $this->productFor($own);
        $this->productFor($foreign);

        $this->actingAsUnprivileged($this->operatorFor($own));

        $items = $this->getJson('/api/products?product_types=raw_material')->assertOk()->json('data.items');

        self::assertSame([$mine->id], array_column($items, 'id'));
    }

    // ── 3 + the original symptom ──────────────────────────────────────────────

    /**
     * The regression test for "All Materials = 0 while the table contains rows".
     * With no company filter the KPI must not describe a different population
     * from the table.
     */
    public function test_statistics_and_list_describe_the_same_population_without_a_filter(): void
    {
        $own = Company::factory()->create();
        $foreign = Company::factory()->create();
        $this->productFor($own);
        $this->productFor($foreign);
        $this->productFor($foreign);

        $this->actingAsUnprivileged($this->operatorFor($own));

        $p = $this->populations('product_types=raw_material');

        self::assertSame(1, $p['list'], 'List must show only the caller\'s company.');
        self::assertSame(
            $p['list'],
            $p['stats'],
            'KPI must describe the same population as the table.',
        );
    }

    public function test_search_does_not_cause_population_divergence(): void
    {
        $own = Company::factory()->create();
        $this->productFor($own);
        $this->productFor(Company::factory()->create());

        $this->actingAsUnprivileged($this->operatorFor($own));

        $p = $this->populations('product_types=raw_material&status=all');

        self::assertSame($p['list'], $p['stats']);
    }

    // ── 4. A foreign company filter cannot escape scope ───────────────────────

    public function test_company_filter_cannot_widen_beyond_the_authoritative_scope(): void
    {
        $own = Company::factory()->create();
        $foreign = Company::factory()->create();
        $this->productFor($own);
        $this->productFor($foreign);

        $this->actingAsUnprivileged($this->operatorFor($own));

        $items = $this->getJson("/api/products?product_types=raw_material&company_id={$foreign->id}")
            ->assertOk()->json('data.items');

        self::assertSame([], $items, 'A foreign company filter must yield nothing, not other rows.');
    }

    // ── 5. NULL-company non-system user fails closed ──────────────────────────

    public function test_companyless_non_privileged_user_sees_no_products(): void
    {
        $this->productFor(Company::factory()->create());
        $this->productFor(Company::factory()->create());

        $this->actingAsUnprivileged($this->operatorFor(null));

        $p = $this->populations('product_types=raw_material');

        self::assertSame(0, $p['list'], 'A NULL company must not mean "return everything".');
        self::assertSame(0, $p['stats']);
    }

    // ── 6. The documented is_system capability still works ────────────────────

    public function test_unrestricted_user_retains_cross_company_visibility(): void
    {
        $this->productFor(Company::factory()->create());
        $this->productFor(Company::factory()->create());

        $this->actingAsUnprivileged($this->unrestrictedUser());

        $p = $this->populations('product_types=raw_material');

        self::assertSame(2, $p['list'], 'The certified is_system capability must be preserved.');
        self::assertSame($p['list'], $p['stats']);
    }

    // ── 8. Existing functionality intact ──────────────────────────────────────

    public function test_pagination_metadata_still_returned(): void
    {
        $own = Company::factory()->create();
        $this->productFor($own);

        $this->actingAsUnprivileged($this->operatorFor($own));

        $meta = $this->getJson('/api/products?product_types=raw_material&per_page=1')
            ->assertOk()->json('data.meta');

        self::assertArrayHasKey('current_page', $meta);
        self::assertArrayHasKey('per_page', $meta);
        self::assertArrayHasKey('total', $meta);
        self::assertArrayHasKey('last_page', $meta);
    }
}
