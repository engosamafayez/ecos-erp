<?php

declare(strict_types=1);

namespace Tests\Feature\CostManagement;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\CostManagement\Domain\Enums\PricingReviewStatus;
use Modules\CostManagement\Domain\Models\PriceApproval;
use Modules\CostManagement\Domain\Models\PricingReview;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-PRICE-REVIEW-ACTION-REPAIR-001 — Pricing Decision Center row actions.
 *
 * These are HTTP-level cases on purpose. The defect this suite exists to prevent
 * lived between the controller and the service: the controller passed an int
 * `users.id` into a `?string` parameter, which is a TypeError under strict_types
 * and a 500 at runtime. The pre-existing suite only ever called
 * `PricingReview::resolve()` on the model, so it stayed green while every row
 * action in the product was dead. Nothing below may bypass the route.
 *
 * The certified contract is Approve = approve + apply + close:
 *   200 → product price written → review resolved → audit row → out of Pending.
 */
final class PriceReviewActionHttpTest extends TestCase
{
    use RefreshDatabase;

    /**
     * These cases exercise the authorization and tenant boundary themselves, so
     * no subject may receive the baseline system role — it would make the tenant
     * resolver unrestricted and erase the very boundary under test.
     */
    protected bool $grantsBaselineAuthorization = false;

    private const BASE = '/api/cost-management/pricing-reviews';

    /** Mirrors the live fixture the diagnostic was proven against. */
    private const COST = 3155.0;

    private const REGULAR = 7044.0;

    private const SALE = 6600.0;

    private const SUGGESTED_REGULAR = 7011.1111;

    private const SUGGESTED_SALE = 6310.0;

    /**
     * `products.regular_price` / `sale_price` are decimal(12,2) while
     * `pricing_reviews.*` carry decimal(15,4). Applying a 4dp decision to the
     * catalogue therefore stores it at 2dp. That is the pre-existing schema
     * contract, not something this task introduced, so the catalogue assertions
     * compare against the 2dp value and the 4dp figure is asserted on the review.
     */
    private const APPLIED_REGULAR = 7011.11;

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function company(): Company
    {
        return Company::factory()->create();
    }

    /**
     * A pending review for a finished product owned by $company.
     *
     * Brand margin 55% / discount 10% reproduces the production brand: the
     * suggested regular is cost / (1 - 0.55) and the suggested sale is that
     * less 10%.
     */
    private function pendingReview(Company $company): PricingReview
    {
        $brand = Brand::factory()->create([
            'company_id' => $company->id,
            'default_target_margin' => 55.0,
            'default_discount_pct' => 10.0,
        ]);

        $product = Product::factory()->finishedGood()->create([
            'brand_id' => $brand->id,
            'company_id' => $company->id,
            'regular_price' => self::REGULAR,
            'sale_price' => self::SALE,
            'product_cost' => self::COST,
        ]);

        return PricingReview::query()->create([
            'product_id' => $product->id,
            'company_id' => $company->id,
            'channel_id' => null,
            'product_cost' => self::COST,
            'previous_product_cost' => 3000.0,
            'cost_difference' => 155.0,
            'selling_price' => self::REGULAR,
            'current_sale_price' => self::SALE,
            'suggested_selling_price' => self::SUGGESTED_REGULAR,
            'suggested_sale_price' => self::SUGGESTED_SALE,
            'target_margin' => 55.0,
            'current_margin' => 55.21,
            'impacts' => ['cost_increased'],
            'status' => PricingReviewStatus::Pending->value,
        ]);
    }

    /**
     * A user in $company holding exactly $permissionNames and no system role.
     *
     * Deliberately not the baseline system role: that flag makes the tenant
     * resolver unrestricted, which is correct for an administrator but would
     * erase the very boundary the isolation cases assert.
     */
    private function scopedUser(?Company $company, array $permissionNames, string $slug): User
    {
        $user = User::factory()->create(['company_id' => $company?->id]);

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

    private function approver(?Company $company): User
    {
        return $this->scopedUser($company, [
            'inventory.price_review.view',
            'inventory.price_review.update',
            'inventory.price_review.approve',
            'inventory.price_review.publish',
        ], 'test-price-approver');
    }

    // ── A. Approve success ────────────────────────────────────────────────────

    public function test_approve_applies_prices_closes_review_and_leaves_pending(): void
    {
        $company = $this->company();
        $review = $this->pendingReview($company);
        $user = $this->approver($company);

        $this->actingAsUnprivileged($user);

        $response = $this->postJson(self::BASE."/{$review->id}/approve", [
            'action' => 'approve_suggested',
        ]);

        $response->assertOk();

        // N. Product carries the approved decision — this is the whole point.
        $product = $review->product->fresh();
        self::assertEqualsWithDelta(self::APPLIED_REGULAR, (float) $product->regular_price, 0.0001);
        self::assertEqualsWithDelta(self::SUGGESTED_SALE, (float) $product->sale_price, 0.0001);

        // Review closed.
        $review->refresh();

        // The full-precision decision is recorded on the review itself.
        self::assertEqualsWithDelta(self::SUGGESTED_REGULAR, (float) $review->approved_price, 0.0001);
        self::assertEqualsWithDelta(self::SUGGESTED_SALE, (float) $review->approved_sale_price, 0.0001);

        self::assertSame(PricingReviewStatus::Approved, $review->status);
        self::assertNotNull($review->resolved_at);

        // Part 27 — no hidden second publish step.
        self::assertSame('published', $review->publish_status);
        self::assertNotNull($review->published_at);

        // Audit row.
        $approval = PriceApproval::query()->where('pricing_review_id', $review->id)->first();
        self::assertNotNull($approval);
        self::assertSame('approve_suggested', $approval->action);
        self::assertEqualsWithDelta(self::REGULAR, (float) $approval->old_selling_price, 0.0001);
        self::assertEqualsWithDelta(self::SUGGESTED_REGULAR, (float) $approval->new_selling_price, 0.0001);

        // Pending queue no longer contains it.
        $list = $this->getJson(self::BASE.'?status=pending');
        $list->assertOk();
        self::assertNotContains($review->id, array_column($list->json('data'), 'id'));
    }

    // ── L. Approver identity ──────────────────────────────────────────────────

    public function test_approved_by_stores_the_canonical_bigint_user_id(): void
    {
        $company = $this->company();
        $review = $this->pendingReview($company);
        $user = $this->approver($company);

        $this->actingAsUnprivileged($user);
        $this->postJson(self::BASE."/{$review->id}/approve", ['action' => 'approve_suggested'])
            ->assertOk();

        $approval = PriceApproval::query()->where('pricing_review_id', $review->id)->firstOrFail();

        // The regression was an int being passed to a ?string parameter. Assert the
        // stored value IS the user key, and is an integer — not "1" in a uuid column.
        self::assertSame($user->id, $approval->approved_by);
        self::assertIsInt($approval->approved_by);
        self::assertSame($user->id, $approval->approver?->id);
    }

    // ── M. Summary counter ────────────────────────────────────────────────────

    public function test_pending_summary_count_decreases_after_approval(): void
    {
        $company = $this->company();
        $review = $this->pendingReview($company);
        $user = $this->approver($company);

        $this->actingAsUnprivileged($user);

        $before = $this->getJson(self::BASE.'?status=pending')->json('summary.pending');
        self::assertSame(1, $before);

        $this->postJson(self::BASE."/{$review->id}/approve", ['action' => 'approve_suggested'])
            ->assertOk();

        $after = $this->getJson(self::BASE.'?status=pending')->json('summary.pending');
        self::assertSame(0, $after);
    }

    // ── C. Keep current price ─────────────────────────────────────────────────

    public function test_keep_current_holds_regular_price_and_closes_review(): void
    {
        $company = $this->company();
        $review = $this->pendingReview($company);

        $this->actingAsUnprivileged($this->approver($company));

        $this->postJson(self::BASE."/{$review->id}/approve", [
            'action' => 'keep_current',
            'reason' => 'Holding price this quarter.',
        ])->assertOk();

        $product = $review->product->fresh();
        // Regular price is held; the sale price still tracks the live discount.
        self::assertEqualsWithDelta(self::REGULAR, (float) $product->regular_price, 0.0001);
        self::assertEqualsWithDelta(self::REGULAR * 0.9, (float) $product->sale_price, 0.0001);

        $review->refresh();
        self::assertSame(PricingReviewStatus::Kept, $review->status);
        self::assertNotNull($review->resolved_at);

        self::assertSame('keep_current', PriceApproval::query()
            ->where('pricing_review_id', $review->id)->firstOrFail()->action);
    }

    // ── D. Custom price ───────────────────────────────────────────────────────

    public function test_custom_price_applies_the_operator_value(): void
    {
        $company = $this->company();
        $review = $this->pendingReview($company);

        $this->actingAsUnprivileged($this->approver($company));

        $this->postJson(self::BASE."/{$review->id}/approve", [
            'action' => 'custom_price',
            'custom_price' => 8000.0,
            'reason' => 'Premium positioning.',
        ])->assertOk();

        $product = $review->product->fresh();
        self::assertEqualsWithDelta(8000.0, (float) $product->regular_price, 0.0001);
        self::assertEqualsWithDelta(7200.0, (float) $product->sale_price, 0.0001);

        $review->refresh();
        self::assertSame(PricingReviewStatus::CustomPrice, $review->status);

        $approval = PriceApproval::query()->where('pricing_review_id', $review->id)->firstOrFail();
        self::assertSame('custom_price', $approval->action);
        self::assertEqualsWithDelta(8000.0, (float) $approval->custom_price, 0.0001);
    }

    // ── E. Reject ─────────────────────────────────────────────────────────────

    public function test_reject_closes_the_review_without_touching_price(): void
    {
        $company = $this->company();
        $review = $this->pendingReview($company);

        $this->actingAsUnprivileged($this->approver($company));

        $this->postJson(self::BASE."/{$review->id}/approve", [
            'action' => 'reject',
            'reason' => 'Cost movement is temporary.',
        ])->assertOk();

        // Reject is the one action that never writes a price — unchanged contract.
        $product = $review->product->fresh();
        self::assertEqualsWithDelta(self::REGULAR, (float) $product->regular_price, 0.0001);
        self::assertEqualsWithDelta(self::SALE, (float) $product->sale_price, 0.0001);

        $review->refresh();
        self::assertSame(PricingReviewStatus::Rejected, $review->status);
        self::assertNotNull($review->resolved_at);
        self::assertNull($review->publish_status);

        self::assertSame('reject', PriceApproval::query()
            ->where('pricing_review_id', $review->id)->firstOrFail()->action);
    }

    // ── B. Controlled business failure ────────────────────────────────────────

    public function test_approve_fails_safely_when_the_product_no_longer_exists(): void
    {
        $company = $this->company();
        $review = $this->pendingReview($company);
        $review->product->delete(); // soft delete — the relation now resolves null

        $this->actingAsUnprivileged($this->approver($company));

        $this->postJson(self::BASE."/{$review->id}/approve", ['action' => 'approve_suggested'])
            ->assertStatus(422);

        // The failure is a business condition, not a 500, and nothing moved.
        $review->refresh();
        self::assertSame(PricingReviewStatus::Pending, $review->status);
        self::assertNull($review->resolved_at);
        self::assertDatabaseCount('price_approvals', 0);
    }

    // ── J. Already resolved ───────────────────────────────────────────────────

    public function test_resolving_an_already_resolved_review_is_rejected(): void
    {
        $company = $this->company();
        $review = $this->pendingReview($company);
        $review->resolve(PricingReviewStatus::Approved);

        $this->actingAsUnprivileged($this->approver($company));

        $this->postJson(self::BASE."/{$review->id}/approve", ['action' => 'approve_suggested'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This review has already been resolved.');

        self::assertDatabaseCount('price_approvals', 0);
    }

    // ── K. Validation ─────────────────────────────────────────────────────────

    public function test_invalid_action_is_rejected_with_422(): void
    {
        $company = $this->company();
        $review = $this->pendingReview($company);

        $this->actingAsUnprivileged($this->approver($company));

        $this->postJson(self::BASE."/{$review->id}/approve", ['action' => 'not_a_real_action'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('action');

        $this->postJson(self::BASE."/{$review->id}/approve", ['action' => 'custom_price'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('custom_price');

        self::assertDatabaseCount('price_approvals', 0);
    }

    // ── F. Unauthorized ───────────────────────────────────────────────────────

    public function test_user_without_the_approve_permission_is_forbidden(): void
    {
        $company = $this->company();
        $review = $this->pendingReview($company);

        // Same company, but read-only: the permission gate must still refuse.
        $viewer = $this->scopedUser($company, ['inventory.price_review.view'], 'test-price-viewer');
        $this->actingAsUnprivileged($viewer);

        $this->postJson(self::BASE."/{$review->id}/approve", ['action' => 'approve_suggested'])
            ->assertStatus(403);

        $review->refresh();
        self::assertSame(PricingReviewStatus::Pending, $review->status);
        self::assertDatabaseCount('price_approvals', 0);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $company = $this->company();
        $review = $this->pendingReview($company);

        $this->postJson(self::BASE."/{$review->id}/approve", ['action' => 'approve_suggested'])
            ->assertStatus(401);
    }

    // ── G. Cross-tenant read ──────────────────────────────────────────────────

    public function test_cross_tenant_review_is_not_readable(): void
    {
        $owner = $this->company();
        $review = $this->pendingReview($owner);

        $intruder = $this->approver($this->company());
        $this->actingAsUnprivileged($intruder);

        $this->getJson(self::BASE."/{$review->id}/detail")->assertStatus(404);

        // And it is absent from the list and the counters, with no existence leak.
        $list = $this->getJson(self::BASE.'?status=pending');
        $list->assertOk();
        self::assertSame([], $list->json('data'));
        self::assertSame(0, $list->json('summary.pending'));
    }

    // ── H. Cross-tenant write ─────────────────────────────────────────────────

    public function test_cross_tenant_review_cannot_be_approved(): void
    {
        $owner = $this->company();
        $review = $this->pendingReview($owner);
        $originalPrice = (float) $review->product->regular_price;

        $this->actingAsUnprivileged($this->approver($this->company()));

        $this->postJson(self::BASE."/{$review->id}/approve", ['action' => 'approve_suggested'])
            ->assertStatus(404);

        $review->refresh();
        self::assertSame(PricingReviewStatus::Pending, $review->status);
        self::assertEqualsWithDelta($originalPrice, (float) $review->product->fresh()->regular_price, 0.0001);
        self::assertDatabaseCount('price_approvals', 0);
    }

    public function test_cross_tenant_review_cannot_be_snoozed_or_assigned(): void
    {
        $owner = $this->company();
        $review = $this->pendingReview($owner);

        $this->actingAsUnprivileged($this->approver($this->company()));

        $this->postJson(self::BASE."/{$review->id}/snooze", [
            'until' => now()->addDays(3)->toDateString(),
        ])->assertStatus(404);

        $this->postJson(self::BASE."/{$review->id}/assign", [
            'reviewer_name' => 'Intruder',
        ])->assertStatus(404);

        $review->refresh();
        self::assertSame(PricingReviewStatus::Pending, $review->status);
        self::assertNull($review->reviewer_name);
        self::assertNull($review->snooze_until);
    }

    public function test_cross_tenant_review_cannot_be_inline_edited(): void
    {
        $owner = $this->company();
        $review = $this->pendingReview($owner);

        $this->actingAsUnprivileged($this->approver($this->company()));

        $this->patchJson(self::BASE."/{$review->id}/inline", ['regular_price' => 1.0])
            ->assertStatus(404);

        self::assertEqualsWithDelta(
            self::REGULAR,
            (float) $review->product->fresh()->regular_price,
            0.0001,
        );
    }

    /**
     * A user with no company at all is closed out, not widened to the global pool.
     */
    public function test_companyless_user_sees_nothing_and_cannot_mutate(): void
    {
        $review = $this->pendingReview($this->company());

        $this->actingAsUnprivileged($this->approver(null));

        $list = $this->getJson(self::BASE.'?status=pending');
        $list->assertOk();
        self::assertSame([], $list->json('data'));

        $this->postJson(self::BASE."/{$review->id}/approve", ['action' => 'approve_suggested'])
            ->assertStatus(404);

        $review->refresh();
        self::assertSame(PricingReviewStatus::Pending, $review->status);
    }

    // ── I. Cross-tenant bulk ──────────────────────────────────────────────────

    public function test_bulk_approve_fails_closed_when_the_selection_crosses_tenants(): void
    {
        $mine = $this->company();
        $ownReview = $this->pendingReview($mine);
        $foreignReview = $this->pendingReview($this->company());

        $this->actingAsUnprivileged($this->approver($mine));

        $this->postJson(self::BASE.'/bulk-approve', [
            'ids' => [$ownReview->id, $foreignReview->id],
            'action' => 'approve_suggested',
        ])->assertStatus(404);

        // Fail closed: NOTHING was mutated, not even the row the caller owns.
        $ownReview->refresh();
        $foreignReview->refresh();
        self::assertSame(PricingReviewStatus::Pending, $ownReview->status);
        self::assertSame(PricingReviewStatus::Pending, $foreignReview->status);
        self::assertEqualsWithDelta(
            self::REGULAR,
            (float) $foreignReview->product->fresh()->regular_price,
            0.0001,
        );
        self::assertDatabaseCount('price_approvals', 0);
    }

    public function test_bulk_approve_resolves_every_owned_review(): void
    {
        $company = $this->company();
        $first = $this->pendingReview($company);
        $second = $this->pendingReview($company);

        $this->actingAsUnprivileged($this->approver($company));

        $this->postJson(self::BASE.'/bulk-approve', [
            'ids' => [$first->id, $second->id],
            'action' => 'approve_suggested',
        ])->assertOk()->assertJsonPath('resolved', 2);

        foreach ([$first, $second] as $review) {
            $review->refresh();
            self::assertSame(PricingReviewStatus::Approved, $review->status);
            self::assertEqualsWithDelta(
                self::APPLIED_REGULAR,
                (float) $review->product->fresh()->regular_price,
                0.0001,
            );
        }

        self::assertDatabaseCount('price_approvals', 2);
    }

    public function test_bulk_policy_fails_closed_when_the_selection_crosses_tenants(): void
    {
        $mine = $this->company();
        $ownReview = $this->pendingReview($mine);
        $foreignReview = $this->pendingReview($this->company());
        $foreignMargin = (float) $foreignReview->target_margin;

        $this->actingAsUnprivileged($this->approver($mine));

        $this->postJson(self::BASE.'/bulk-policy', [
            'ids' => [$ownReview->id, $foreignReview->id],
            'action' => 'set_target_margin',
            'value' => 12.0,
        ])->assertStatus(404);

        self::assertEqualsWithDelta($foreignMargin, (float) $foreignReview->fresh()->target_margin, 0.0001);
        self::assertEqualsWithDelta(55.0, (float) $ownReview->fresh()->target_margin, 0.0001);
    }

    // ── Snooze remains correct (Part 10 — audited, not redesigned) ────────────

    public function test_snooze_still_moves_the_review_out_of_pending(): void
    {
        $company = $this->company();
        $review = $this->pendingReview($company);

        $this->actingAsUnprivileged($this->approver($company));

        $this->postJson(self::BASE."/{$review->id}/snooze", [
            'until' => now()->addDays(3)->toDateString(),
        ])->assertOk();

        $review->refresh();
        self::assertSame(PricingReviewStatus::Snoozed, $review->status);

        $list = $this->getJson(self::BASE.'?status=pending');
        self::assertNotContains($review->id, array_column($list->json('data'), 'id'));

        // Snooze is not a resolution: it writes no price and no audit row.
        self::assertDatabaseCount('price_approvals', 0);
        self::assertEqualsWithDelta(self::REGULAR, (float) $review->product->fresh()->regular_price, 0.0001);
    }

    /**
     * Assign had no happy-path coverage, which is why the defect below survived.
     *
     * `snooze()` and `assign()` both typehint Illuminate\Http\Request and then
     * called `$request->validated(...)`. `validate()` is a macro on the base
     * Request and returns the validated data, but `validated()` exists only on
     * FormRequest — so a request that PASSED validation then died with
     * BadMethodCallException and surfaced as a 500.
     *
     * Static analysis could not see it: `validated()` is resolved dynamically at
     * runtime, so neither PHPStan nor Pint had anything to flag. Only a request
     * that reaches the line proves it.
     *
     * This test fails on the pre-fix code (500 instead of 200) and passes after.
     */
    public function test_assign_persists_the_reviewer_and_is_readable_afterwards(): void
    {
        $company = $this->company();
        $review = $this->pendingReview($company);

        $this->actingAsUnprivileged($this->approver($company));

        $this->postJson(self::BASE."/{$review->id}/assign", [
            'reviewer_name' => 'Mona Adel',
        ])->assertOk();

        // Persistence, not just a 200.
        self::assertSame('Mona Adel', $review->fresh()->reviewer_name);
        $this->assertDatabaseHas('pricing_reviews', [
            'id' => $review->id,
            'reviewer_name' => 'Mona Adel',
        ]);

        // Assigning is not a resolution — the review stays pending and no price moves.
        self::assertSame(PricingReviewStatus::Pending, $review->fresh()->status);
        self::assertDatabaseCount('price_approvals', 0);
        self::assertEqualsWithDelta(self::REGULAR, (float) $review->product->fresh()->regular_price, 0.0001);
    }

    /** The validation contract must still reject a missing reviewer with 422, not 500. */
    public function test_assign_rejects_a_missing_reviewer_name_with_422(): void
    {
        $company = $this->company();
        $review = $this->pendingReview($company);

        $this->actingAsUnprivileged($this->approver($company));

        $this->postJson(self::BASE."/{$review->id}/assign", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reviewer_name');
    }

    /** Same for snooze: a past date is a validation error, not a server error. */
    public function test_snooze_rejects_a_non_future_date_with_422(): void
    {
        $company = $this->company();
        $review = $this->pendingReview($company);

        $this->actingAsUnprivileged($this->approver($company));

        $this->postJson(self::BASE."/{$review->id}/snooze", [
            'until' => now()->subDay()->toDateString(),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('until');
    }

    /**
     * Both endpoints are gated on inventory.price_review.update, and moving
     * validation into a FormRequest must not let a request reach the rules
     * before the permission gate has run.
     */
    public function test_snooze_and_assign_are_forbidden_without_the_update_permission(): void
    {
        $company = $this->company();
        $review = $this->pendingReview($company);

        // Same company, read-only grant.
        $viewer = $this->scopedUser($company, ['inventory.price_review.view'], 'test-price-viewer');
        $this->actingAsUnprivileged($viewer);

        $this->postJson(self::BASE."/{$review->id}/snooze", [
            'until' => now()->addDays(3)->toDateString(),
        ])->assertStatus(403);

        $this->postJson(self::BASE."/{$review->id}/assign", [
            'reviewer_name' => 'Mona Adel',
        ])->assertStatus(403);

        $review->refresh();
        self::assertSame(PricingReviewStatus::Pending, $review->status);
        self::assertNull($review->reviewer_name);
        self::assertNull($review->snooze_until);
    }

    // ── Final Decision Price vs Suggested Price ───────────────────────────────
    //
    // Approve must apply the operator's FINAL DECISION, which is only the
    // suggestion when they have not priced the row themselves. The inline editor
    // (PATCH /inline) is the "edit old price" control; it writes the operator's
    // figure to products + pricing_reviews.selling_price and marks the row as
    // manually decided. Approve previously ignored that and applied the
    // suggestion unconditionally, discarding the operator's decision.

    /** TEST 1 — no manual edit → the canonical suggested price is applied. */
    public function test_approve_applies_the_suggested_price_when_no_manual_edit_was_made(): void
    {
        $company = $this->company();
        $review = $this->pendingReview($company);

        $this->actingAsUnprivileged($this->approver($company));

        $this->postJson(self::BASE."/{$review->id}/approve", ['action' => 'approve_suggested'])
            ->assertOk();

        self::assertEqualsWithDelta(self::APPLIED_REGULAR, (float) $review->product->fresh()->regular_price, 0.0001);
        self::assertEqualsWithDelta(self::SUGGESTED_REGULAR, (float) $review->fresh()->approved_price, 0.0001);
    }

    /** TEST 2 — manual regular price edit → the EDITED price wins, not the suggestion. */
    public function test_approve_applies_the_manually_edited_regular_price(): void
    {
        $company = $this->company();
        $review = $this->pendingReview($company);

        $this->actingAsUnprivileged($this->approver($company));

        // The operator edits the existing price through the inline editor.
        $this->patchJson(self::BASE."/{$review->id}/inline", ['regular_price' => 6900])
            ->assertOk();

        self::assertEqualsWithDelta(6900.0, (float) $review->fresh()->manual_regular_price, 0.0001);

        $this->postJson(self::BASE."/{$review->id}/approve", ['action' => 'approve_suggested'])
            ->assertOk();

        $product = $review->product->fresh();
        self::assertEqualsWithDelta(6900.0, (float) $product->regular_price, 0.0001);

        // CASE E - sale follows the canonical relationship off the DECIDED regular
        // (6900 x 0.9), never the stale suggested sale derived from 7011.11.
        self::assertEqualsWithDelta(6210.0, (float) $product->sale_price, 0.0001);

        // Explicitly: the suggestion did NOT overwrite the operator's decision.
        self::assertNotEqualsWithDelta(
            self::APPLIED_REGULAR,
            (float) $product->regular_price,
            0.0001,
            'Approve overwrote the operator’s manual price with the suggested price.',
        );

        $review->refresh();
        self::assertEqualsWithDelta(6900.0, (float) $review->approved_price, 0.0001);
        self::assertSame(PricingReviewStatus::Approved, $review->status);
    }

    /** TEST 3 — manual sale price edit → the EDITED sale price wins. */
    public function test_approve_applies_the_manually_edited_sale_price(): void
    {
        $company = $this->company();
        $review = $this->pendingReview($company);

        $this->actingAsUnprivileged($this->approver($company));

        $this->patchJson(self::BASE."/{$review->id}/inline", ['sale_price' => 6500])
            ->assertOk();

        self::assertEqualsWithDelta(6500.0, (float) $review->fresh()->manual_sale_price, 0.0001);

        $this->postJson(self::BASE."/{$review->id}/approve", ['action' => 'approve_suggested'])
            ->assertOk();

        $product = $review->product->fresh();
        self::assertEqualsWithDelta(6500.0, (float) $product->sale_price, 0.0001);
        self::assertNotEqualsWithDelta(
            self::SUGGESTED_SALE,
            (float) $product->sale_price,
            0.0001,
            'Approve overwrote the operator’s manual sale price with the suggested sale price.',
        );

        // The regular price was NOT edited, so it still follows the suggestion.
        self::assertEqualsWithDelta(self::APPLIED_REGULAR, (float) $product->regular_price, 0.0001);
    }

    /** TEST 4 — both edited → both final values are applied. */
    public function test_approve_applies_both_manually_edited_prices(): void
    {
        $company = $this->company();
        $review = $this->pendingReview($company);

        $this->actingAsUnprivileged($this->approver($company));

        $this->patchJson(self::BASE."/{$review->id}/inline", ['regular_price' => 6900])->assertOk();
        $this->patchJson(self::BASE."/{$review->id}/inline", ['sale_price' => 6500])->assertOk();

        $this->postJson(self::BASE."/{$review->id}/approve", ['action' => 'approve_suggested'])
            ->assertOk();

        $product = $review->product->fresh();
        self::assertEqualsWithDelta(6900.0, (float) $product->regular_price, 0.0001);
        self::assertEqualsWithDelta(6500.0, (float) $product->sale_price, 0.0001);

        $review->refresh();
        self::assertEqualsWithDelta(6900.0, (float) $review->approved_price, 0.0001);
        self::assertEqualsWithDelta(6500.0, (float) $review->approved_sale_price, 0.0001);
    }

    /** TEST 5 — edit then approve: full lifecycle on the edited value. */
    public function test_edited_price_then_approve_closes_the_review_and_audits_the_effective_price(): void
    {
        $company = $this->company();
        $review = $this->pendingReview($company);

        $this->actingAsUnprivileged($this->approver($company));

        $this->patchJson(self::BASE."/{$review->id}/inline", ['regular_price' => 6900])->assertOk();

        $before = $this->getJson(self::BASE.'?status=pending');
        self::assertSame(1, $before->json('summary.pending'));

        $this->postJson(self::BASE."/{$review->id}/approve", ['action' => 'approve_suggested'])
            ->assertOk();

        // Product carries the final edited price.
        self::assertEqualsWithDelta(6900.0, (float) $review->product->fresh()->regular_price, 0.0001);

        // Review resolved.
        $review->refresh();
        self::assertSame(PricingReviewStatus::Approved, $review->status);
        self::assertNotNull($review->resolved_at);

        // Pending list and KPI both drop it — because the backend state changed.
        $after = $this->getJson(self::BASE.'?status=pending');
        self::assertNotContains($review->id, array_column($after->json('data'), 'id'));
        self::assertSame(0, $after->json('summary.pending'));

        // Audit records the EFFECTIVE approved price, not the suggestion.
        $approval = PriceApproval::query()->where('pricing_review_id', $review->id)->firstOrFail();
        self::assertEqualsWithDelta(6900.0, (float) $approval->new_selling_price, 0.0001);
        self::assertSame('approve_suggested', $approval->action);
    }

    /**
     * Re-deriving supersedes an earlier manual figure: asking the engine for a
     * target margin recomputes the suggestion, so Approve follows it again.
     */
    public function test_setting_a_target_margin_clears_an_earlier_manual_price_decision(): void
    {
        $company = $this->company();
        $review = $this->pendingReview($company);

        $this->actingAsUnprivileged($this->approver($company));

        $this->patchJson(self::BASE."/{$review->id}/inline", ['regular_price' => 6900])->assertOk();
        self::assertEqualsWithDelta(6900.0, (float) $review->fresh()->manual_regular_price, 0.0001);

        // Operator now asks the engine to derive from a 50% target margin.
        $this->patchJson(self::BASE."/{$review->id}/inline", ['target_margin' => 50])->assertOk();

        $review->refresh();
        self::assertNull($review->manual_regular_price);
        self::assertNull($review->manual_sale_price);

        $expected = round(self::COST / (1 - 0.50), 4); // 6310.0
        self::assertEqualsWithDelta($expected, (float) $review->suggested_selling_price, 0.0001);

        $this->postJson(self::BASE."/{$review->id}/approve", ['action' => 'approve_suggested'])
            ->assertOk();

        self::assertEqualsWithDelta(
            round($expected, 2),
            (float) $review->product->fresh()->regular_price,
            0.0001,
        );
    }

    /** An unauthenticated caller is refused before validation, not after. */
    public function test_snooze_and_assign_reject_unauthenticated_callers(): void
    {
        $review = $this->pendingReview($this->company());

        $this->postJson(self::BASE."/{$review->id}/snooze", [
            'until' => now()->addDays(3)->toDateString(),
        ])->assertStatus(401);

        $this->postJson(self::BASE."/{$review->id}/assign", [
            'reviewer_name' => 'Mona Adel',
        ])->assertStatus(401);

        $review->refresh();
        self::assertSame(PricingReviewStatus::Pending, $review->status);
        self::assertNull($review->reviewer_name);
    }

    /** TEST 3 - no suggestion at all, but a manual final decision was entered. */
    public function test_approve_applies_the_manual_price_when_no_suggestion_exists(): void
    {
        $company = $this->company();
        $review = $this->pendingReview($company);
        // The engine produced nothing for this row.
        $review->forceFill(['suggested_selling_price' => null, 'suggested_sale_price' => null])->save();

        $this->actingAsUnprivileged($this->approver($company));

        // A NULL suggestion is surfaced as null, never fabricated as 0.
        $row = $this->getJson(self::BASE.'?status=pending')->json('data.0');
        self::assertNull($row['suggested_selling_price']);
        self::assertNull($row['final_regular_price']);

        // Approving with nothing decided is refused rather than writing 0.
        $this->postJson(self::BASE."/{$review->id}/approve", ['action' => 'approve_suggested'])
            ->assertStatus(422);

        $this->patchJson(self::BASE."/{$review->id}/inline", ['regular_price' => 6900])->assertOk();

        $this->postJson(self::BASE."/{$review->id}/approve", ['action' => 'approve_suggested'])
            ->assertOk();

        self::assertEqualsWithDelta(6900.0, (float) $review->product->fresh()->regular_price, 0.0001);
        self::assertEqualsWithDelta(6900.0, (float) $review->fresh()->approved_price, 0.0001);
    }

    /** TESTS 7 + 8 - final gross profit and final margin follow the manual decision. */
    public function test_final_gross_profit_and_margin_follow_the_manual_decision(): void
    {
        $company = $this->company();
        $review = $this->pendingReview($company);

        $this->actingAsUnprivileged($this->approver($company));

        $before = $this->getJson(self::BASE.'?status=pending')->json('data.0');
        // Both metrics are PERCENTAGES, and they measure different things:
        //   Gross Profit % on the REGULAR price  -> (7011.1111 - 3155) / 7011.1111
        //   Net Margin %   on the SALE price     -> (6310 - 3155) / 6310
        self::assertEqualsWithDelta(55.0, (float) $before['final_gross_profit_pct'], 0.01);
        self::assertEqualsWithDelta(50.0, (float) $before['final_margin_pct'], 0.01);

        $this->patchJson(self::BASE."/{$review->id}/inline", ['regular_price' => 6900])->assertOk();

        $after = $this->getJson(self::BASE.'?status=pending')->json('data.0');
        // Regular 6900 -> gross 54.28%; derived sale 6210 -> net margin 49.19%.
        self::assertEqualsWithDelta(
            round((6900.0 - self::COST) / 6900.0 * 100, 2),
            (float) $after['final_gross_profit_pct'],
            0.01,
        );
        self::assertEqualsWithDelta(
            round((6210.0 - self::COST) / 6210.0 * 100, 2),
            (float) $after['final_margin_pct'],
            0.01,
        );

        // Neither field may be a currency amount.
        self::assertLessThan(100.0, (float) $after['final_gross_profit_pct']);
        self::assertLessThan(100.0, (float) $after['final_margin_pct']);
    }

    /** TEST 12 - CURRENT and SUGGESTED layers survive every manual edit. */
    public function test_current_and_suggested_layers_are_immutable_under_manual_editing(): void
    {
        $company = $this->company();
        $review = $this->pendingReview($company);

        $this->actingAsUnprivileged($this->approver($company));

        $this->patchJson(self::BASE."/{$review->id}/inline", ['regular_price' => 6900])->assertOk();
        $this->patchJson(self::BASE."/{$review->id}/inline", ['sale_price' => 6500])->assertOk();

        $row = $this->getJson(self::BASE.'?status=pending')->json('data.0');

        // CURRENT - untouched.
        self::assertEqualsWithDelta(self::REGULAR, (float) $row['selling_price'], 0.0001);
        self::assertEqualsWithDelta(self::SALE, (float) $row['sale_price'], 0.0001);
        self::assertEqualsWithDelta(
            round((self::REGULAR - self::COST) / self::REGULAR * 100, 2),
            (float) $row['old_gross_profit_pct'],
            0.01,
        );

        // SUGGESTED - untouched.
        self::assertEqualsWithDelta(self::SUGGESTED_REGULAR, (float) $row['suggested_selling_price'], 0.0001);
        self::assertEqualsWithDelta(self::SUGGESTED_SALE, (float) $row['suggested_sale_price'], 0.0001);

        // MANUAL - the decision.
        self::assertEqualsWithDelta(6900.0, (float) $row['manual_regular_price'], 0.0001);
        self::assertEqualsWithDelta(6500.0, (float) $row['manual_sale_price'], 0.0001);

        // Editing must not touch the live catalogue - Approve does that.
        $product = $review->product->fresh();
        self::assertEqualsWithDelta(self::REGULAR, (float) $product->regular_price, 0.0001);
        self::assertEqualsWithDelta(self::SALE, (float) $product->sale_price, 0.0001);

        // And the CURRENT snapshot still holds after approval.
        $this->postJson(self::BASE."/{$review->id}/approve", ['action' => 'approve_suggested'])->assertOk();
        $review->refresh();
        self::assertEqualsWithDelta(self::REGULAR, (float) $review->selling_price, 0.0001);
        self::assertEqualsWithDelta(self::SALE, (float) $review->current_sale_price, 0.0001);
        self::assertEqualsWithDelta(self::SUGGESTED_REGULAR, (float) $review->suggested_selling_price, 0.0001);
    }

    // ── Inline update of the MANUAL / FINAL layer ─────────────────────────────
    //
    // TASK-PRICE-REVIEW-INLINE-UPDATE-REGRESSION-DIAGNOSTIC-001. These cases run
    // the real PATCH surface. The production failure was a deployment drift -
    // `ecos_dev` was missing manual_regular_price / manual_sale_price while the
    // container already ran the code that writes them, so every inline edit died
    // with SQLSTATE[42S22] and surfaced as "Server Error". A suite that exercises
    // the endpoint end to end is what makes that class of drift detectable.

    /** Decimal values must round-trip on both fields, not just integers. */
    public function test_inline_update_persists_decimal_manual_prices(): void
    {
        $company = $this->company();
        $review = $this->pendingReview($company);

        $this->actingAsUnprivileged($this->approver($company));

        $this->patchJson(self::BASE."/{$review->id}/inline", ['regular_price' => 6912.3456])
            ->assertOk();
        $this->patchJson(self::BASE."/{$review->id}/inline", ['sale_price' => 6221.1111])
            ->assertOk();

        $review->refresh();
        self::assertEqualsWithDelta(6912.3456, (float) $review->manual_regular_price, 0.0001);
        self::assertEqualsWithDelta(6221.1111, (float) $review->manual_sale_price, 0.0001);

        $this->assertDatabaseHas('pricing_reviews', ['id' => $review->id]);

        // Surfaced back through the API as the FINAL decision.
        $row = $this->getJson(self::BASE.'?status=pending')->json('data.0');
        self::assertEqualsWithDelta(6912.3456, (float) $row['final_regular_price'], 0.0001);
        self::assertEqualsWithDelta(6221.1111, (float) $row['final_sale_price'], 0.0001);
    }

    /** A negative price is a validation error, never a 500 and never persisted. */
    public function test_inline_update_rejects_an_invalid_price(): void
    {
        $company = $this->company();
        $review = $this->pendingReview($company);

        $this->actingAsUnprivileged($this->approver($company));

        $this->patchJson(self::BASE."/{$review->id}/inline", ['regular_price' => -5])
            ->assertStatus(422)
            ->assertJsonValidationErrors('regular_price');

        $this->patchJson(self::BASE."/{$review->id}/inline", ['sale_price' => -1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('sale_price');

        $review->refresh();
        self::assertNull($review->manual_regular_price);
        self::assertNull($review->manual_sale_price);
    }

    /** The inline surface is gated on inventory.price_review.update. */
    public function test_inline_update_is_forbidden_without_the_update_permission(): void
    {
        $company = $this->company();
        $review = $this->pendingReview($company);

        $viewer = $this->scopedUser($company, ['inventory.price_review.view'], 'test-price-viewer');
        $this->actingAsUnprivileged($viewer);

        $this->patchJson(self::BASE."/{$review->id}/inline", ['regular_price' => 6900])
            ->assertStatus(403);

        self::assertNull($review->fresh()->manual_regular_price);
    }

    /** An unauthenticated caller cannot reach the inline surface at all. */
    public function test_inline_update_rejects_unauthenticated_callers(): void
    {
        $review = $this->pendingReview($this->company());

        $this->patchJson(self::BASE."/{$review->id}/inline", ['regular_price' => 6900])
            ->assertStatus(401);

        self::assertNull($review->fresh()->manual_regular_price);
    }

    /**
     * The whole point of the MANUAL layer: editing decides, Approve applies.
     * An inline edit must never reach the catalogue on its own.
     */
    public function test_inline_update_never_touches_the_catalogue_before_approval(): void
    {
        $company = $this->company();
        $review = $this->pendingReview($company);

        $this->actingAsUnprivileged($this->approver($company));

        $this->patchJson(self::BASE."/{$review->id}/inline", ['regular_price' => 6900])->assertOk();
        $this->patchJson(self::BASE."/{$review->id}/inline", ['sale_price' => 6500])->assertOk();

        // Catalogue untouched while the review is still pending.
        $product = $review->product->fresh();
        self::assertEqualsWithDelta(self::REGULAR, (float) $product->regular_price, 0.0001);
        self::assertEqualsWithDelta(self::SALE, (float) $product->sale_price, 0.0001);
        self::assertSame(PricingReviewStatus::Pending, $review->fresh()->status);

        // Only Approve publishes the decision.
        $this->postJson(self::BASE."/{$review->id}/approve", ['action' => 'approve_suggested'])->assertOk();

        $product = $review->product->fresh();
        self::assertEqualsWithDelta(6900.0, (float) $product->regular_price, 0.0001);
        self::assertEqualsWithDelta(6500.0, (float) $product->sale_price, 0.0001);
    }

    // ── Zero / negative final price guard ─────────────────────────────────────
    //
    // TASK-PRICE-REVIEW-ZERO-PRICE-GUARD-REPAIR-001.
    // THE invariant: a published regular price must be strictly positive. The
    // previous guard tested `=== null`, but the reachable invalid value is 0 -
    // `min:0` accepted it, Laravel's filled(0) is true, and 0 is not null, so
    // `products.regular_price = 0` was written and every ProductMapping flipped
    // to Pending. Backend is the final authority: these cases bypass the UI.

    /** A manual regular price of 0 must never be storable in the first place. */
    public function test_inline_rejects_a_zero_manual_regular_price(): void
    {
        $company = $this->company();
        $review = $this->pendingReview($company);

        $this->actingAsUnprivileged($this->approver($company));

        $this->patchJson(self::BASE."/{$review->id}/inline", ['regular_price' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('regular_price');

        self::assertNull($review->fresh()->manual_regular_price);
    }

    /**
     * TEST A - zero final regular price is refused, and the catalogue is untouched.
     *
     * The manual price is planted directly so the guard is tested even if
     * validation were ever loosened again. Backend must be the final authority.
     */
    public function test_approve_refuses_a_zero_final_regular_price(): void
    {
        $company = $this->company();
        $review = $this->pendingReview($company);
        $review->forceFill(['manual_regular_price' => 0])->save();

        $this->actingAsUnprivileged($this->approver($company));

        $this->postJson(self::BASE."/{$review->id}/approve", ['action' => 'approve_suggested'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('final_regular_price');

        $this->assertCatalogueUntouched($review);
    }

    /** TEST B - negative final regular price is refused. */
    public function test_approve_refuses_a_negative_final_regular_price(): void
    {
        $company = $this->company();
        $review = $this->pendingReview($company);
        $review->forceFill(['manual_regular_price' => -1])->save();

        $this->actingAsUnprivileged($this->approver($company));

        $this->postJson(self::BASE."/{$review->id}/approve", ['action' => 'approve_suggested'])
            ->assertStatus(422);

        $this->assertCatalogueUntouched($review);
    }

    /** The same invariant covers custom_price, which publishes directly. */
    public function test_approve_refuses_a_zero_custom_price(): void
    {
        $company = $this->company();
        $review = $this->pendingReview($company);

        $this->actingAsUnprivileged($this->approver($company));

        $this->postJson(self::BASE."/{$review->id}/approve", [
            'action' => 'custom_price',
            'custom_price' => 0,
            'reason' => 'Attempted zero.',
        ])->assertStatus(422)->assertJsonValidationErrors('custom_price');

        $this->assertCatalogueUntouched($review);
    }

    /**
     * TEST D - the minimum valid price (0.01) is accepted by the guard and published.
     *
     * Deliberately built on a low-cost product. On the standard fixture (cost 3155)
     * a price of 0.01 is ~0.0003% of cost, which drives margin_pct to -31,549,900
     * and overflows `price_approvals.margin_pct decimal(8,4)` - a PRE-EXISTING
     * defect in the audit column, unrelated to this guard and recorded as finding
     * P15. The spec's own wording is "succeeds IF all other approval requirements
     * are valid"; approving far below cost violates one of those. This fixture
     * isolates the variable actually under test: that 0.01 passes the
     * zero/negative invariant and reaches the catalogue.
     */
    public function test_approve_accepts_the_minimum_valid_price(): void
    {
        $company = $this->company();

        $brand = Brand::factory()->create([
            'company_id' => $company->id,
            'default_target_margin' => 55.0,
            'default_discount_pct' => 10.0,
        ]);

        $product = Product::factory()->finishedGood()->create([
            'brand_id' => $brand->id,
            'company_id' => $company->id,
            'regular_price' => 1.00,
            'sale_price' => 0.90,
            'product_cost' => 0.005,
        ]);

        $review = PricingReview::query()->create([
            'product_id' => $product->id,
            'company_id' => $company->id,
            'channel_id' => null,
            'product_cost' => 0.005,
            'previous_product_cost' => 0.004,
            'cost_difference' => 0.001,
            'selling_price' => 1.00,
            'current_sale_price' => 0.90,
            'suggested_selling_price' => 0.02,
            'suggested_sale_price' => 0.018,
            'target_margin' => 55.0,
            'current_margin' => 99.5,
            'impacts' => ['cost_increased'],
            'status' => PricingReviewStatus::Pending->value,
        ]);

        $this->actingAsUnprivileged($this->approver($company));

        $this->patchJson(self::BASE."/{$review->id}/inline", ['regular_price' => 0.01])
            ->assertOk();

        $this->postJson(self::BASE."/{$review->id}/approve", ['action' => 'approve_suggested'])
            ->assertOk();

        self::assertEqualsWithDelta(0.01, (float) $product->fresh()->regular_price, 0.0001);
        self::assertSame(PricingReviewStatus::Approved, $review->fresh()->status);
    }

    /**
     * PART 10 - a valid manual decision must still win when the engine produced
     * no suggestion. The guard must not reject a legitimate manual price.
     */
    public function test_a_valid_manual_price_still_approves_when_no_suggestion_exists(): void
    {
        $company = $this->company();
        $review = $this->pendingReview($company);
        $review->forceFill([
            'suggested_selling_price' => null,
            'suggested_sale_price' => null,
            'manual_regular_price' => 6900,
        ])->save();

        $this->actingAsUnprivileged($this->approver($company));

        $this->postJson(self::BASE."/{$review->id}/approve", ['action' => 'approve_suggested'])
            ->assertOk();

        self::assertEqualsWithDelta(6900.0, (float) $review->product->fresh()->regular_price, 0.0001);
    }

    /** TEST C / E - one zero-priced row rejects the whole bulk, atomically. */
    public function test_bulk_approve_refuses_the_whole_batch_when_any_final_price_is_zero(): void
    {
        $company = $this->company();
        $valid = $this->pendingReview($company);
        $zero = $this->pendingReview($company);
        $zero->forceFill(['manual_regular_price' => 0])->save();

        $this->actingAsUnprivileged($this->approver($company));

        $this->postJson(self::BASE.'/bulk-approve', [
            'ids' => [$valid->id, $zero->id],
            'action' => 'approve_suggested',
        ])->assertStatus(422)->assertJsonValidationErrors('final_regular_price');

        // Atomic, matching this endpoint's existing fail-closed contract for
        // unknown ids and missing products: NOTHING was mutated, not even the
        // valid row.
        $this->assertCatalogueUntouched($valid);
        $this->assertCatalogueUntouched($zero);
        self::assertDatabaseCount('price_approvals', 0);
    }

    /** TEST F - same guarantee for a negative price in a bulk selection. */
    public function test_bulk_approve_refuses_the_whole_batch_when_any_final_price_is_negative(): void
    {
        $company = $this->company();
        $valid = $this->pendingReview($company);
        $negative = $this->pendingReview($company);
        $negative->forceFill(['manual_regular_price' => -25])->save();

        $this->actingAsUnprivileged($this->approver($company));

        $this->postJson(self::BASE.'/bulk-approve', [
            'ids' => [$valid->id, $negative->id],
            'action' => 'approve_suggested',
        ])->assertStatus(422);

        $this->assertCatalogueUntouched($valid);
        $this->assertCatalogueUntouched($negative);
        self::assertDatabaseCount('price_approvals', 0);
    }

    /** Bulk stays capable of approving a wholly valid selection. */
    public function test_bulk_approve_still_succeeds_when_every_final_price_is_valid(): void
    {
        $company = $this->company();
        $first = $this->pendingReview($company);
        $second = $this->pendingReview($company);

        $this->actingAsUnprivileged($this->approver($company));

        $this->postJson(self::BASE.'/bulk-approve', [
            'ids' => [$first->id, $second->id],
            'action' => 'approve_suggested',
        ])->assertOk()->assertJsonPath('resolved', 2);

        self::assertDatabaseCount('price_approvals', 2);
    }

    /**
     * PART 8 - a refused approval must leave the catalogue, the review state and
     * the audit trail all untouched. No false success anywhere.
     */
    private function assertCatalogueUntouched(PricingReview $review): void
    {
        $product = $review->product->fresh();
        self::assertEqualsWithDelta(self::REGULAR, (float) $product->regular_price, 0.0001);
        self::assertEqualsWithDelta(self::SALE, (float) $product->sale_price, 0.0001);

        $fresh = $review->fresh();
        self::assertSame(PricingReviewStatus::Pending, $fresh->status);
        self::assertNull($fresh->resolved_at);
        self::assertNull($fresh->approved_price);
        self::assertNull($fresh->publish_status);

        self::assertDatabaseMissing('price_approvals', ['pricing_review_id' => $review->id]);
    }
}
