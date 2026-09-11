<?php

declare(strict_types=1);

namespace Tests\Feature\CustomerEngagement;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\CustomerEngagement\Domain\Enums\MacroCategory;
use Modules\CustomerEngagement\Domain\Models\ConversationMacro;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * 035B — representative "Application Service" search surface (as opposed to
 * the Eloquent-repository shape covered by Brand/BusinessAccount): the
 * predicate used PostgreSQL-only `ilike` on a MySQL 8.4 platform, so any
 * keystroke in the Macros search box produced SQLSTATE 42000 / 1064 and a
 * 500. assertOk() is the load-bearing assertion in both tests below.
 */
final class MacroSearchTest extends TestCase
{
    use RefreshDatabase;

    protected bool $grantsBaselineAuthorization = false;

    private function actor(Company $company): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);

        $permission = Permission::firstOrCreate(
            ['name' => 'omnichannel.inbox.manage'],
            ['module' => 'omnichannel', 'resource' => 'inbox', 'action' => 'manage'],
        );

        $role = Role::firstOrCreate(
            ['slug' => 'test-macro-manager'],
            ['name' => 'Test Macro Manager', 'is_system' => false],
        );

        if (! $role->permissions()->where('permissions.id', $permission->id)->exists()) {
            $role->permissions()->attach($permission->id);
        }

        $user->roles()->attach($role->id);
        $user->unsetRelation('roles');

        return $user;
    }

    private function macroFor(Company $company, string $name, string $shortcut): ConversationMacro
    {
        return ConversationMacro::create([
            'company_id' => $company->id,
            'created_by' => User::factory()->create(['company_id' => $company->id])->id,
            'name' => $name,
            'shortcut' => $shortcut,
            'category' => MacroCategory::CUSTOM->value,
            'content' => 'Hello {customer_name}',
        ]);
    }

    public function test_macro_search_matches_case_insensitively_by_name(): void
    {
        $company = Company::factory()->create();
        $this->macroFor($company, 'Welcome Greeting', '/welcome');
        $this->macroFor($company, 'Refund Notice', '/refund');

        $this->actingAsUnprivileged($this->actor($company));

        // MacroController returns the resource collection's own default shape
        // (top-level `data` array + `links`/`meta`), unlike the `{data:{items,meta}}`
        // wrapper Channels/Brands/BusinessAccounts use via HasApiResponse.
        $names = collect($this->getJson("/api/omnichannel/macros?company_id={$company->id}&search=welcome greeting")->assertOk()->json('data'))
            ->pluck('name')->all();

        self::assertSame(['Welcome Greeting'], $names);
    }

    public function test_macro_search_also_matches_on_shortcut(): void
    {
        $company = Company::factory()->create();
        $this->macroFor($company, 'Alpha', '/alpha-shortcut');
        $this->macroFor($company, 'Beta', '/beta-shortcut');

        $this->actingAsUnprivileged($this->actor($company));

        $names = collect($this->getJson("/api/omnichannel/macros?company_id={$company->id}&search=ALPHA-SHORTCUT")->assertOk()->json('data'))
            ->pluck('name')->all();

        self::assertSame(['Alpha'], $names);
    }
}
