<?php

declare(strict_types=1);

namespace Tests\Feature\CostManagement;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Modules\CostManagement\Domain\Enums\CostUpdateSource;
use Modules\CostManagement\Domain\Models\PricingReview;
use Modules\CostManagement\Domain\Services\MaterialCostService;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Manufacturing\BillsOfMaterials\Domain\Models\Recipe;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-NOTIFICATIONS-USER-REVIEW-REMEDIATION-007.
 *
 * Proves the real, end-to-end pipeline this task adds: a raw material cost edit that
 * opens a new PricingReview (already proven by PricingReviewCascadeTest) now also
 * produces a real, persisted Notification row for the right, IAM-resolved recipients —
 * and only those recipients. Nothing here mocks the event/listener/notification chain;
 * every assertion is against real database state, matching this suite's own
 * convention.
 */
final class PricingReviewNotificationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * These cases assert the authorization/tenant boundary itself, so no subject may
     * receive the baseline system role bypass here — see PriceReviewActionHttpTest,
     * whose scopedUser() helper this mirrors.
     */
    protected bool $grantsBaselineAuthorization = false;

    private MaterialCostService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(MaterialCostService::class);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /** @return array{0: Product, 1: Product} [material, finishedProduct] */
    private function makeChain(Company $company): array
    {
        $material = Product::factory()->rawMaterial()->create([
            'company_id' => $company->id,
            'material_cost' => 10.0,
        ]);

        $finished = Product::factory()->finishedGood()->manufacturable()->create([
            'company_id' => $company->id,
            'regular_price' => 50.0,
            'product_cost' => 20.0,
        ]);

        $recipe = Recipe::create([
            'bom_number' => 'BOM-NOTIF-TEST-'.uniqid(),
            'product_id' => $finished->id,
            'version' => '1.0',
            'bom_version_number' => 1,
            'is_active' => true,
        ]);

        $recipe->components()->create([
            'raw_material_id' => $material->id,
            'quantity' => 2.0,
        ]);

        return [$material, $finished];
    }

    private function scopedUser(Company $company, array $permissionNames, string $slug): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);

        $role = Role::firstOrCreate(
            ['slug' => $slug],
            ['name' => $slug, 'is_system' => false],
        );

        foreach ($permissionNames as $name) {
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

    private function systemRoleUserWithNoExplicitGrant(Company $company): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);

        $role = Role::firstOrCreate(
            ['slug' => 'test-super-admin'],
            ['name' => 'test-super-admin', 'is_system' => true],
        );

        $user->roles()->attach($role->id);
        $user->unsetRelation('roles');

        return $user;
    }

    private function bumpMaterialCost(Product $material, Company $company, float $newCost): void
    {
        $this->service->update(
            material: $material,
            newCost: $newCost,
            source: CostUpdateSource::Manual,
            meta: ['company_id' => $company->id],
        );
    }

    /** @return \Illuminate\Support\Collection<int, DatabaseNotification> */
    private function notificationsFor(User $user): \Illuminate\Support\Collection
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey())
            ->get();
    }

    // ── 1. The core acceptance path ─────────────────────────────────────────────

    public function test_notification_created_for_user_with_view_permission_when_new_review_is_created(): void
    {
        $company = Company::factory()->create();
        $viewer = $this->scopedUser($company, ['cost.price_review.view'], 'test-price-viewer');
        [$material] = $this->makeChain($company);

        $this->bumpMaterialCost($material, $company, 15.0);

        $review = PricingReview::query()->sole();
        $notifications = $this->notificationsFor($viewer);

        $this->assertCount(1, $notifications);
        $row = $notifications->first();
        $this->assertSame('pricing_review_required', $row->data['type']);
        $this->assertSame($review->id, $row->data['review_id']);
        $this->assertSame($company->id, $row->company_id);
        $this->assertSame('approval', $row->category);
        $this->assertSame('high', $row->priority);
        $this->assertSame("price_review_created:{$review->id}", $row->dedupe_key);
        $this->assertNull($row->read_at);

        $deepLink = json_decode($row->deep_link, true);
        $this->assertSame('pricing-review', $deepLink['entity_type']);
        $this->assertSame($review->id, $deepLink['entity_id']);
    }

    // ── 2. Users without the permission never receive it ───────────────────────

    public function test_user_without_price_review_permission_is_not_notified(): void
    {
        $company = Company::factory()->create();
        $bystander = $this->scopedUser($company, ['sales.customers.view'], 'test-unrelated-role');
        [$material] = $this->makeChain($company);

        $this->bumpMaterialCost($material, $company, 15.0);

        $this->assertCount(0, $this->notificationsFor($bystander));
    }

    // ── 3. Tenant isolation ──────────────────────────────────────────────────────

    public function test_user_in_a_different_company_is_not_notified(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $outsider = $this->scopedUser($otherCompany, ['cost.price_review.view'], 'test-price-viewer');
        [$material] = $this->makeChain($company);

        $this->bumpMaterialCost($material, $company, 15.0);

        $this->assertCount(0, $this->notificationsFor($outsider));
    }

    // ── 4. System-role bypass (the decision()-vs-can() correctness fix) ────────

    public function test_system_role_user_with_no_explicit_grant_is_still_notified(): void
    {
        $company = Company::factory()->create();
        $superAdmin = $this->systemRoleUserWithNoExplicitGrant($company);
        [$material] = $this->makeChain($company);

        $this->bumpMaterialCost($material, $company, 15.0);

        $this->assertCount(1, $this->notificationsFor($superAdmin));
    }

    // ── 5. Duplicate protection: an update to an already-open review notifies once ──

    public function test_second_cascade_onto_an_already_open_review_does_not_notify_again(): void
    {
        $company = Company::factory()->create();
        $viewer = $this->scopedUser($company, ['cost.price_review.view'], 'test-price-viewer');
        [$material] = $this->makeChain($company);

        $this->bumpMaterialCost($material, $company, 15.0);
        $this->assertCount(1, $this->notificationsFor($viewer));

        $material->refresh();
        $this->bumpMaterialCost($material, $company, 20.0);

        // Still exactly one PricingReview row (proven separately by
        // PricingReviewCascadeTest) and, the new assertion this task adds, still
        // exactly one notification — PriceReviewCreated never dispatches a second time
        // for the same review, so no duplicate can reach the recipient.
        $this->assertSame(1, PricingReview::query()->count());
        $this->assertCount(1, $this->notificationsFor($viewer));
    }
}
