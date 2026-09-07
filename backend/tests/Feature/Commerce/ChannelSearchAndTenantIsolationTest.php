<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * CD-02 + CD-03 (TASK-ECOS-COMMERCE-PRE-USER-REVIEW-REMEDIATION-002 §3, §4).
 *
 * CD-02 — the channel search predicate used PostgreSQL-only `ilike` on a MySQL 8.4 platform,
 * so any keystroke in the Channels search box produced SQLSTATE 42000 / 1064 and a 500.
 *
 * CD-03 — `Channel` had no tenant scope at all: the list was narrowed only by a caller-supplied
 * `company_id` query parameter (omit it and every company's channels came back), and
 * `findById()` was unscoped outright, so `GET /api/channels/{id}` returned any company's row.
 *
 * The search cases deliberately assert a SUCCESSFUL RESPONSE and correct matching, not just
 * absence of an exception: a 200 with the right rows is the only evidence that the operator's
 * search path works end to end on the platform's real database engine.
 */
final class ChannelSearchAndTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $grantsBaselineAuthorization = false;

    private function channelReader(?Company $company): User
    {
        $user = User::factory()->create(['company_id' => $company?->id]);

        $permission = Permission::firstOrCreate(
            ['name' => 'sales.channels.view'],
            ['module' => 'sales', 'resource' => 'channels', 'action' => 'view'],
        );

        $role = Role::firstOrCreate(
            ['slug' => 'test-channel-reader'],
            ['name' => 'Test Channel Reader', 'is_system' => false],
        );

        if (! $role->permissions()->where('permissions.id', $permission->id)->exists()) {
            $role->permissions()->attach($permission->id);
        }

        $user->roles()->attach($role->id);
        $user->unsetRelation('roles');

        return $user;
    }

    private function channelFor(Company $company, string $name, string $storeUrl = 'https://example.test'): Channel
    {
        $brand = Brand::factory()->create(['company_id' => $company->id]);

        return Channel::factory()->create([
            'brand_id' => $brand->id,
            'name' => $name,
            'store_url' => $storeUrl,
            'is_active' => true,
        ]);
    }

    // ── CD-02: MySQL-compatible search ────────────────────────────────────────

    public function test_channel_search_returns_successfully_and_does_not_fail_on_sql_syntax(): void
    {
        $company = Company::factory()->create();
        $this->channelFor($company, 'ECOS Main Store');

        $this->actingAsUnprivileged($this->channelReader($company));

        // Before the fix this raised SQLSTATE[42000] 1064 near 'ilike ?' and surfaced as a 500.
        $this->getJson('/api/channels?search=main')->assertOk();
    }

    public function test_channel_search_matches_partially_and_case_insensitively(): void
    {
        $company = Company::factory()->create();
        $this->channelFor($company, 'ECOS Main Store');
        $this->channelFor($company, 'Wholesale Depot');

        $this->actingAsUnprivileged($this->channelReader($company));

        // Lower-case fragment against a mixed-case stored value — case-insensitivity is
        // provided by the `_ci` column collation, which is why plain `like` is sufficient.
        $names = collect($this->getJson('/api/channels?search=ecos main')->assertOk()->json('data.items'))
            ->pluck('name')->all();

        self::assertSame(['ECOS Main Store'], $names);
    }

    public function test_channel_search_also_matches_on_store_url(): void
    {
        $company = Company::factory()->create();
        $this->channelFor($company, 'Alpha', 'https://alpha-shop.test');
        $this->channelFor($company, 'Beta', 'https://beta-shop.test');

        $this->actingAsUnprivileged($this->channelReader($company));

        $names = collect($this->getJson('/api/channels?search=ALPHA-SHOP')->assertOk()->json('data.items'))
            ->pluck('name')->all();

        self::assertSame(['Alpha'], $names);
    }

    public function test_channel_search_with_no_match_returns_an_empty_list_not_an_error(): void
    {
        $company = Company::factory()->create();
        $this->channelFor($company, 'ECOS Main Store');

        $this->actingAsUnprivileged($this->channelReader($company));

        $this->getJson('/api/channels?search=zzz-no-such-channel')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 0);
    }

    // ── CD-03: tenant isolation ───────────────────────────────────────────────

    public function test_channel_list_is_scoped_to_the_actors_company_without_a_company_id_parameter(): void
    {
        $mine = Company::factory()->create();
        $theirs = Company::factory()->create();

        $this->channelFor($mine, 'My Store');
        $this->channelFor($theirs, 'Their Store');

        $this->actingAsUnprivileged($this->channelReader($mine));

        // Omitting company_id previously returned BOTH companies' channels.
        $items = $this->getJson('/api/channels')->assertOk()->json('data.items');

        self::assertCount(1, $items);
        self::assertSame('My Store', $items[0]['name']);
    }

    public function test_supplying_another_companys_id_cannot_widen_the_result(): void
    {
        $mine = Company::factory()->create();
        $theirs = Company::factory()->create();

        $this->channelFor($mine, 'My Store');
        $this->channelFor($theirs, 'Their Store');

        $this->actingAsUnprivileged($this->channelReader($mine));

        $this->getJson("/api/channels?company_id={$theirs->id}")
            ->assertOk()
            ->assertJsonPath('data.meta.total', 0);
    }

    public function test_channel_search_cannot_reach_across_companies(): void
    {
        $mine = Company::factory()->create();
        $theirs = Company::factory()->create();

        $this->channelFor($mine, 'Shared Name Store');
        $this->channelFor($theirs, 'Shared Name Store');

        $this->actingAsUnprivileged($this->channelReader($mine));

        $this->getJson('/api/channels?search=Shared Name')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1);
    }

    public function test_direct_id_read_of_another_companys_channel_is_not_served(): void
    {
        $mine = Company::factory()->create();
        $theirs = Company::factory()->create();

        $foreign = $this->channelFor($theirs, 'Their Store');

        $this->actingAsUnprivileged($this->channelReader($mine));

        // findById() was completely unscoped: this returned 200 with the foreign channel.
        $this->getJson("/api/channels/{$foreign->id}")->assertNotFound();
    }

    public function test_direct_id_read_of_own_channel_still_works(): void
    {
        $mine = Company::factory()->create();
        $own = $this->channelFor($mine, 'My Store');

        $this->actingAsUnprivileged($this->channelReader($mine));

        $this->getJson("/api/channels/{$own->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'My Store');
    }

    public function test_companyless_unprivileged_actor_fails_closed(): void
    {
        $other = Company::factory()->create();
        $this->channelFor($other, 'Their Store');

        $this->actingAsUnprivileged($this->channelReader(null));

        $this->getJson('/api/channels')->assertOk()->assertJsonPath('data.meta.total', 0);
    }

    public function test_system_role_retains_cross_company_visibility(): void
    {
        $a = Company::factory()->create();
        $b = Company::factory()->create();
        $this->channelFor($a, 'A Store');
        $this->channelFor($b, 'B Store');

        // Super-admin semantics come from existing IAM authority (is_system), never from the
        // absence of a company or from a request parameter.
        $this->actingAsUnprivileged($this->grantSystemRole(User::factory()->create(['company_id' => null])));

        $this->getJson('/api/channels')->assertOk()->assertJsonPath('data.meta.total', 2);
    }

    public function test_queue_and_console_execution_is_not_scoped(): void
    {
        // Queue workers and the PUBLIC WooCommerce webhook routes carry no authenticated
        // actor. They must still resolve channels, or inbound order import breaks.
        $company = Company::factory()->create();
        $channel = $this->channelFor($company, 'Webhook Store');

        self::assertNotNull(Channel::query()->find($channel->id));
        self::assertSame(1, Channel::query()->count());
    }
}
