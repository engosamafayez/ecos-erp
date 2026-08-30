<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Domain\Enums\PaymentProofState;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\PaymentProof;
use Modules\IAM\Domain\Catalog\RoleTemplateCatalog;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TASK-ORDERS-PAYMENT-PROOF-RBAC-TEMPLATE-ALIGNMENT-001.
 *
 * Locks the payment-proof permission matrix so the alignment cannot silently drift back.
 *
 * Two layers are asserted, because the platform authors them in two different places and they
 * had already drifted apart once: concrete roles come from `config/permissions.php`, template
 * roles from `RoleTemplateCatalog` (ADR-039 — templates author, roles execute). A matrix that
 * only checked one layer is what let the finance TEMPLATES hold no proof verb at all while the
 * concrete `finance-manager` held three.
 *
 * The maker-checker rule itself is NOT re-tested here — its identity-level half is enforced in
 * the actions and covered by OrderPaymentFinalCompletionTest::test_d1..d4. What this suite locks
 * is the role-level half: no role may hold both halves of the review, and upload stays Sales.
 */
final class PaymentProofRbacTemplateAlignmentTest extends TestCase
{
    use RefreshDatabase;

    private const VIEW = 'sales.orders.proof_view';

    private const UPLOAD = 'sales.orders.proof_upload';

    private const VERIFY = 'sales.orders.proof_verify';

    private const REJECT = 'sales.orders.proof_reject';

    /** The proof grant the concrete finance role holds — the contract the templates align to. */
    private const FINANCE_PROOF_SET = [self::VIEW, self::VERIFY, self::REJECT];

    // ─────────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────────

    /** @return list<string> full permission names a concrete role holds in config. */
    private function configGrants(string $roleSlug): array
    {
        $out = [];

        foreach ((array) (config('permissions.role_permissions')[$roleSlug] ?? []) as $resource => $actions) {
            foreach ((array) $actions as $action) {
                $out[] = $resource.'.'.$action;
            }
        }

        return $out;
    }

    /** @return list<string> the permission tokens a template declares (patterns included). */
    private function templateTokens(string $key): array
    {
        foreach (RoleTemplateCatalog::all() as $template) {
            if ($template['key'] === $key) {
                return array_values(array_map(
                    static fn ($t): string => (string) $t,
                    (array) ($template['definition']['permissions'] ?? []),
                ));
            }
        }

        self::fail("Role template [{$key}] is not in the catalogue.");
    }

    /** Materialises a real role holding exactly `$names` and returns a user wearing it. */
    private function userWithGrants(Company $company, string $slug, array $names): User
    {
        $role = Role::firstOrCreate(
            ['slug' => $slug],
            ['name' => $slug, 'is_system' => false],
        );

        $pivot = [];
        foreach ($names as $name) {
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                ['module' => Str::before($name, '.'), 'action' => Str::afterLast($name, '.')],
            );
            $pivot[$permission->id] = ['effect' => 'allow', 'data_scope' => 'all'];
        }
        $role->permissions()->sync($pivot);

        $user = User::factory()->create(['company_id' => $company->id]);
        $user->roles()->attach($role->id);

        return $user;
    }

    /** @return array{Company, Order, PaymentProof} */
    private function orderWithProof(): array
    {
        $company = Company::factory()->create();
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();

        $order = Order::query()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'order_number' => 'ORD-RBAC-1',
            'order_date' => now()->toDateString(),
            'status' => 'awaiting_payment',
            'payment_method_manual' => 'instapay',
            'subtotal' => 100,
            'total' => 100,
        ]);
        $order->lines()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 100,
            'line_total' => 100,
        ]);

        $proof = PaymentProof::create([
            'company_id' => $company->id,
            'order_id' => $order->id,
            'state' => PaymentProofState::Uploaded,
            'storage_disk' => 'local',
            'storage_path' => 'payment-proofs/'.$order->id.'/evidence.jpg',
            'original_filename' => 'evidence.jpg',
            'uploaded_at' => now(),
        ]);

        return [$company, $order, $proof];
    }

    // ═════════════════════════════════════════════════════════════════════════════
    // 1–2. system-auditor
    // ═════════════════════════════════════════════════════════════════════════════

    /** The read right is granted, and it is the ONLY proof verb the audit role receives. */
    public function test_1_system_auditor_holds_proof_view_and_no_other_proof_verb(): void
    {
        $grants = $this->configGrants('system-auditor');

        self::assertContains(self::VIEW, $grants);
        self::assertNotContains(self::UPLOAD, $grants);
        self::assertNotContains(self::VERIFY, $grants, 'Auditing a control and exercising it are different jobs.');
        self::assertNotContains(self::REJECT, $grants);
    }

    /** The change must not have widened the audit role into a writer. */
    public function test_2_system_auditor_gains_no_order_write_permission(): void
    {
        $grants = $this->configGrants('system-auditor');

        foreach (['view', 'create', 'update', 'delete', 'fulfill', 'override_price'] as $verb) {
            $expected = $verb === 'view';
            self::assertSame(
                $expected,
                in_array('sales.orders.'.$verb, $grants, true),
                "sales.orders.{$verb} membership changed for system-auditor.",
            );
        }

        // And nothing outside `view`/`proof_view` anywhere in its grant set.
        $writes = array_values(array_filter(
            $grants,
            static fn (string $g): bool => ! str_ends_with($g, '.view') && $g !== self::VIEW,
        ));
        self::assertSame([], $writes, 'system-auditor must remain read-only across every domain.');
    }

    /** Functional: the audit role can actually reach the gated read endpoint. */
    public function test_3_system_auditor_can_read_the_proof_list(): void
    {
        [$company, $order] = $this->orderWithProof();

        $auditor = $this->userWithGrants($company, 'system-auditor', $this->configGrants('system-auditor'));

        $this->actingAsUnprivileged($auditor)
            ->getJson("/api/orders/{$order->id}/payment-proofs")
            ->assertOk();
    }

    /** …and cannot cross from reading the evidence into acting on it. */
    public function test_4_system_auditor_cannot_upload_or_review(): void
    {
        [$company, $order, $proof] = $this->orderWithProof();

        $auditor = $this->userWithGrants($company, 'system-auditor', $this->configGrants('system-auditor'));

        $this->actingAsUnprivileged($auditor)
            ->postJson("/api/payment-proofs/{$proof->id}/verify")
            ->assertForbidden();

        $this->actingAsUnprivileged($auditor)
            ->postJson("/api/payment-proofs/{$proof->id}/reject", ['reason' => 'x'])
            ->assertForbidden();
    }

    // ═════════════════════════════════════════════════════════════════════════════
    // 3–5. Finance role templates
    // ═════════════════════════════════════════════════════════════════════════════

    /**
     * The templates must declare the proof verbs EXPLICITLY.
     *
     * `sales.orders.proof_*` lives under `sales.*`, which neither finance template holds, so no
     * wildcard can reach it — which is exactly why the templates silently had none while the
     * concrete role had three.
     *
     * @return list<array{string}>
     */
    public static function financeTemplates(): array
    {
        return [['finance-director'], ['financial-controller']];
    }

    #[DataProvider('financeTemplates')]
    public function test_5_finance_template_declares_the_approved_proof_set(string $key): void
    {
        $tokens = $this->templateTokens($key);

        foreach (self::FINANCE_PROOF_SET as $permission) {
            self::assertContains($permission, $tokens, "Template [{$key}] is missing {$permission}.");
        }
    }

    #[DataProvider('financeTemplates')]
    public function test_6_finance_template_never_receives_proof_upload(string $key): void
    {
        self::assertNotContains(
            self::UPLOAD,
            $this->templateTokens($key),
            "Template [{$key}] must not hold upload — that is a Sales capability, and holding both "
            .'halves of the review would collapse the role-level maker-checker split.',
        );
    }

    /** The templates align to the CONCRETE role, so the two must state the same contract. */
    public function test_7_the_template_proof_set_matches_the_concrete_finance_role(): void
    {
        $concrete = array_values(array_filter(
            $this->configGrants('finance-manager'),
            static fn (string $g): bool => str_starts_with($g, 'sales.orders.proof_'),
        ));

        sort($concrete);
        $expected = self::FINANCE_PROOF_SET;
        sort($expected);

        self::assertSame($expected, $concrete);
    }

    // ═════════════════════════════════════════════════════════════════════════════
    // 6–8. Untouched invariants
    // ═════════════════════════════════════════════════════════════════════════════

    /** Sales keeps upload, and gains no review verb from this alignment. */
    public function test_8_sales_roles_retain_upload_and_receive_no_review_verb(): void
    {
        foreach (['sales', 'sales-manager', 'sales-representative'] as $slug) {
            $grants = $this->configGrants($slug);

            self::assertContains(self::UPLOAD, $grants, "{$slug} lost proof_upload.");
            self::assertContains(self::VIEW, $grants, "{$slug} cannot see the evidence it uploads.");
            self::assertNotContains(self::VERIFY, $grants, "{$slug} must not review its own submissions.");
            self::assertNotContains(self::REJECT, $grants);
        }
    }

    /**
     * The role-level half of maker-checker, across the ENTIRE concrete role catalogue.
     *
     * Stated as a sweep rather than a per-role check so a future role cannot be added holding
     * both halves without this failing.
     */
    public function test_9_no_role_holds_both_upload_and_review(): void
    {
        $offenders = [];

        foreach (array_keys((array) config('permissions.role_permissions')) as $slug) {
            $grants = $this->configGrants((string) $slug);
            $reviews = in_array(self::VERIFY, $grants, true) || in_array(self::REJECT, $grants, true);

            if (in_array(self::UPLOAD, $grants, true) && $reviews) {
                $offenders[] = $slug;
            }
        }

        self::assertSame([], $offenders, 'A role holding both halves of the review collapses the split.');
    }

    /** No permission was invented: every name used here is already in the catalogue. */
    public function test_10_every_proof_permission_is_an_existing_catalogue_entry(): void
    {
        $catalogue = (array) config('permissions.modules.sales.orders', []);

        foreach ([self::VIEW, self::UPLOAD, self::VERIFY, self::REJECT] as $permission) {
            self::assertContains(
                Str::afterLast($permission, '.'),
                $catalogue,
                "{$permission} is not in config('permissions.modules.sales.orders').",
            );
        }
    }
}
