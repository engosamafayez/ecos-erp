<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Configuration\Domain\Models\ConfigAuditEntry;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\Inventory\InventoryItems\Domain\Enums\GoodsInwardMode;
use Modules\Inventory\InventoryItems\Domain\Services\GoodsInwardAuthority;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-PROCUREMENT-GOODS-INWARD-CONFIGURATION-UI-001 — the Configuration API.
 *
 * The setting decides which document is a company's authoritative goods-inward path, so the
 * endpoint is guarded like any other configuration write: authenticated, permission-gated, and
 * addressable only for the actor's OWN company.
 *
 * `$grantsBaselineAuthorization = false`: TestCase::actingAs() grants an is_system role to a
 * role-less user, and is_system bypasses exactly the checks under test.
 */
final class GoodsInwardModeConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $grantsBaselineAuthorization = false;

    private const ENDPOINT = '/api/configuration/procurement/goods-inward-mode';

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
    }

    /** @param  list<string>  $permissions */
    private function actor(Company $company, array $permissions = []): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);

        $slug = 'test-cfg-'.substr(md5(implode(',', $permissions).$company->id), 0, 12);
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => 'Test Cfg '.$slug, 'is_system' => false]);

        foreach ($permissions as $name) {
            [$module, $resource, $action] = explode('.', $name);
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                ['module' => $module, 'resource' => $resource, 'action' => $action],
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user->roles()->attach($role->id);
        $user->unsetRelation('roles');

        return $user;
    }

    private function manager(?Company $company = null): User
    {
        return $this->actor($company ?? $this->company, ['configuration.settings.manage']);
    }

    private function storedMode(Company $company): ?string
    {
        $value = DB::table('companies')->where('id', $company->id)->value('goods_inward_mode');

        return $value === null ? null : (string) $value;
    }

    // ── 5 / 6 — authentication and permission ─────────────────────────────────

    public function test_5_reading_the_setting_requires_authentication(): void
    {
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
        $this->putJson(self::ENDPOINT, ['mode' => 'supplier_invoice'])->assertUnauthorized();
    }

    public function test_6_changing_the_setting_requires_the_configuration_permission(): void
    {
        $this->actingAsUnprivileged($this->actor($this->company));   // authenticated, no permission

        $this->putJson(self::ENDPOINT, ['mode' => 'supplier_invoice'])->assertForbidden();

        self::assertNull($this->storedMode($this->company), 'A forbidden request wrote the setting.');
    }

    // ── 1 / 10 — reading, and the default ─────────────────────────────────────

    public function test_1_and_10_reading_an_unset_company_resolves_to_the_goods_receipt_default(): void
    {
        self::assertNull($this->storedMode($this->company), 'Fixture no longer starts unset.');

        $this->actingAsUnprivileged($this->manager());

        $this->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('data.mode', GoodsInwardMode::GoodsReceipt->value)
            ->assertJsonPath('data.is_default', true)
            ->assertJsonPath('data.default_mode', GoodsInwardMode::GoodsReceipt->value)
            ->assertJsonCount(2, 'data.options');
    }

    public function test_1b_an_explicitly_set_company_is_not_reported_as_default(): void
    {
        DB::table('companies')->where('id', $this->company->id)
            ->update(['goods_inward_mode' => GoodsInwardMode::SupplierInvoice->value]);

        $this->actingAsUnprivileged($this->manager());

        $this->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('data.mode', GoodsInwardMode::SupplierInvoice->value)
            ->assertJsonPath('data.is_default', false);
    }

    // ── 3 / 8 / 9 / 11 — writing both modes, and persistence ──────────────────

    public function test_3_and_9_and_11_supplier_invoice_is_accepted_and_persisted(): void
    {
        $this->actingAsUnprivileged($this->manager());

        $this->putJson(self::ENDPOINT, ['mode' => GoodsInwardMode::SupplierInvoice->value])
            ->assertOk()
            ->assertJsonPath('data.mode', GoodsInwardMode::SupplierInvoice->value)
            ->assertJsonPath('data.is_default', false);

        self::assertSame(GoodsInwardMode::SupplierInvoice->value, $this->storedMode($this->company));

        // Persistence across a fresh request, not just the mutation's own response body.
        $this->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('data.mode', GoodsInwardMode::SupplierInvoice->value);
    }

    public function test_8_goods_receipt_is_accepted_and_persisted(): void
    {
        DB::table('companies')->where('id', $this->company->id)
            ->update(['goods_inward_mode' => GoodsInwardMode::SupplierInvoice->value]);

        $this->actingAsUnprivileged($this->manager());

        $this->putJson(self::ENDPOINT, ['mode' => GoodsInwardMode::GoodsReceipt->value])
            ->assertOk()
            ->assertJsonPath('data.mode', GoodsInwardMode::GoodsReceipt->value);

        self::assertSame(GoodsInwardMode::GoodsReceipt->value, $this->storedMode($this->company));
    }

    // ── 7 — validation ────────────────────────────────────────────────────────

    public function test_7_an_unsupported_mode_is_rejected(): void
    {
        $this->actingAsUnprivileged($this->manager());

        foreach (['mode_3', 'purchase_order', 'GOODS_RECEIPT', '', 'null'] as $bad) {
            $this->putJson(self::ENDPOINT, ['mode' => $bad])
                ->assertStatus(422)
                ->assertJsonValidationErrors('mode');
        }

        $this->putJson(self::ENDPOINT, [])->assertStatus(422)->assertJsonValidationErrors('mode');

        self::assertNull($this->storedMode($this->company), 'A rejected value was written.');
    }

    // ── 12 — idempotency ──────────────────────────────────────────────────────

    public function test_12_re_selecting_the_same_mode_is_a_successful_no_op(): void
    {
        $this->actingAsUnprivileged($this->manager());

        $this->putJson(self::ENDPOINT, ['mode' => GoodsInwardMode::SupplierInvoice->value])->assertOk();
        $this->putJson(self::ENDPOINT, ['mode' => GoodsInwardMode::SupplierInvoice->value])->assertOk();
        $this->putJson(self::ENDPOINT, ['mode' => GoodsInwardMode::SupplierInvoice->value])->assertOk();

        self::assertSame(GoodsInwardMode::SupplierInvoice->value, $this->storedMode($this->company));

        // Only the real transition is audited — repeats do not pad the trail.
        self::assertSame(
            1,
            ConfigAuditEntry::query()
                ->where('company_id', $this->company->id)
                ->where('config_key', 'goods_inward_mode')
                ->count(),
        );
    }

    // ── 2 / 4 — tenant isolation ──────────────────────────────────────────────

    /**
     * There is no company identifier anywhere in the route or payload — the controller takes
     * it from the authenticated actor — so a caller cannot address another company at all.
     * These prove the property rather than the absence of a parameter: an actor of Company A
     * sees and writes ONLY Company A, while Company B is untouched.
     */
    public function test_2_reading_returns_only_the_actors_own_company_setting(): void
    {
        $other = Company::factory()->create();
        DB::table('companies')->where('id', $other->id)
            ->update(['goods_inward_mode' => GoodsInwardMode::SupplierInvoice->value]);

        $this->actingAsUnprivileged($this->manager());   // actor of $this->company

        $this->getJson(self::ENDPOINT)
            ->assertOk()
            // Own company is unset → default. If the endpoint leaked, it would report Mode 3.
            ->assertJsonPath('data.mode', GoodsInwardMode::GoodsReceipt->value)
            ->assertJsonPath('data.is_default', true);
    }

    public function test_4_writing_cannot_reach_another_companys_setting(): void
    {
        $other = Company::factory()->create();

        $this->actingAsUnprivileged($this->manager());   // actor of $this->company

        // Company id is supplied in the payload in every shape a caller might try.
        $this->putJson(self::ENDPOINT, [
            'mode' => GoodsInwardMode::SupplierInvoice->value,
            'company_id' => $other->id,
            'company' => $other->id,
        ])->assertOk();

        self::assertSame(GoodsInwardMode::SupplierInvoice->value, $this->storedMode($this->company));
        self::assertNull($this->storedMode($other), "Another company's setting was written.");
    }

    // ── PART 17 — the setting actually drives the certified inbound authority ──

    /**
     * The UI must not re-implement the inbound decision, so the proof that this endpoint
     * MEANS anything is that `GoodsInwardAuthority` — the certified business authority —
     * changes its answer after the setting is written through the API.
     */
    public function test_17_the_certified_inbound_authority_follows_the_configured_mode(): void
    {
        $this->actingAsUnprivileged($this->manager());

        $this->getJson(self::ENDPOINT)->assertOk();
        $authority = app(GoodsInwardAuthority::class);
        self::assertTrue($authority->receiptMayPost((string) $this->company->id));
        self::assertFalse($authority->invoiceMayPost((string) $this->company->id));

        $this->putJson(self::ENDPOINT, ['mode' => GoodsInwardMode::SupplierInvoice->value])->assertOk();

        // Resolved fresh, as a subsequent request would.
        $authority = app(GoodsInwardAuthority::class);
        self::assertFalse($authority->receiptMayPost((string) $this->company->id), 'Receipt still authoritative after switching to Mode 3.');
        self::assertTrue($authority->invoiceMayPost((string) $this->company->id), 'Invoice did not become authoritative.');

        $this->putJson(self::ENDPOINT, ['mode' => GoodsInwardMode::GoodsReceipt->value])->assertOk();

        $authority = app(GoodsInwardAuthority::class);
        self::assertTrue($authority->receiptMayPost((string) $this->company->id));
    }

    // ── PART 13 — audit reuses the existing configuration trail ───────────────

    public function test_13_the_change_is_recorded_in_the_existing_configuration_audit(): void
    {
        $actor = $this->manager();
        $this->actingAsUnprivileged($actor);

        $this->putJson(self::ENDPOINT, [
            'mode' => GoodsInwardMode::SupplierInvoice->value,
            'reason' => 'Switching to Mode 3 for the pilot.',
        ])->assertOk();

        $entry = ConfigAuditEntry::query()
            ->where('company_id', $this->company->id)
            ->where('config_key', 'goods_inward_mode')
            ->latest('occurred_at')
            ->firstOrFail();

        self::assertSame('procurement', $entry->module);
        self::assertSame('goods_inward', $entry->category);
        self::assertSame('update', $entry->action);
        self::assertSame(GoodsInwardMode::GoodsReceipt->value, $entry->old_value['value'] ?? null);
        self::assertSame(GoodsInwardMode::SupplierInvoice->value, $entry->new_value['value'] ?? null);
        // actor_id is a bigint column surfaced as a string by the driver — compare as int.
        self::assertSame((int) $actor->id, (int) $entry->actor_id);
        self::assertSame('Switching to Mode 3 for the pilot.', $entry->reason);
        self::assertNotNull($entry->occurred_at);
    }
}
