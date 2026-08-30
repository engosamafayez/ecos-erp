<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-ORDER-CREATE-STATUS-INVALID-FIX-001 — HTTP regression.
 *
 * These tests exercise the REAL surface: route → FormRequest → controller.
 * That distinction is the point of this file. The defect it guards reached
 * production precisely because order-creation coverage sat at the service and
 * domain layer, where a FormRequest rule is invisible — the suite stayed green
 * while POST /orders/manual was rejecting the only status the UI can send.
 *
 * The canonical vocabulary is read from OrderStatus here too, so if the enum
 * ever changes these assertions follow it instead of pinning a second copy.
 */
class ManualOrderStatusValidationTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/orders/manual';

    private Company $company;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->product = Product::factory()->create();
    }

    private function user(): User
    {
        return User::factory()->create(['company_id' => $this->company->id]);
    }

    /**
     * A payload that is valid apart from the status under test, so any 422 is
     * attributable to `status` and nothing else.
     *
     * @return array<string, mixed>
     */
    private function payload(?string $status): array
    {
        $payload = [
            'customer_id' => $this->customer->id,
            'company_id' => $this->company->id,
            'order_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $this->product->id,
                'quantity' => 1,
                'unit_price' => 100,
            ]],
        ];

        if ($status !== null) {
            $payload['status'] = $status;
        }

        return $payload;
    }

    /** Did the request fail validation *on the status field* specifically? */
    private function statusWasRejected(\Illuminate\Testing\TestResponse $response): bool
    {
        return $response->status() === 422
            && array_key_exists('status', (array) $response->json('errors'));
    }

    // ── CASE A — SUPERSEDED BY ADR-042 ───────────────────────────────────────
    //
    // This assertion is deliberately INVERTED, and the inversion is a contract
    // change rather than a test being relaxed to go green.
    //
    // TASK-ORDER-CREATE-STATUS-INVALID-FIX-001 certified that `status = "new"`
    // must clear validation, because `new` was then the canonical initial status
    // and a stale hardcoded whitelist was rejecting it. That fix was correct for
    // the vocabulary of its day.
    //
    // ADR-042 removes `new` from the lifecycle entirely. The rule that made the
    // original defect impossible is unchanged and still asserted below: the
    // whitelist is DERIVED from OrderStatus, never hardcoded. What changed is the
    // enum it derives from. So `new` is now correctly rejected — by the same
    // mechanism that previously, correctly, accepted it.

    public function test_case_a_status_new_is_now_rejected_because_adr_042_removed_it(): void
    {
        $canonical = array_column(OrderStatus::cases(), 'value');

        // Guard the premise, exactly as the legacy-value case does.
        self::assertNotContains('new', $canonical, '`new` must not be a canonical status under ADR-042.');

        $response = $this->actingAs($this->user())
            ->postJson(self::ENDPOINT, $this->payload('new'));

        self::assertTrue(
            $this->statusWasRejected($response),
            'status="new" must be rejected now that ADR-042 removed it. Response: '.$response->getContent(),
        );
    }

    public function test_the_three_canonical_entry_statuses_all_clear_validation(): void
    {
        $user = $this->user();

        foreach (OrderStatus::entryStatuses() as $entry) {
            $response = $this->actingAs($user)->postJson(self::ENDPOINT, $this->payload($entry->value));

            self::assertFalse(
                $this->statusWasRejected($response),
                "Entry status '{$entry->value}' must clear the status rule. Response: ".$response->getContent(),
            );
        }
    }

    // ── CASE B / C — every canonical V3 status clears the status rule ────────

    public function test_case_b_and_c_every_canonical_v3_status_clears_the_status_rule(): void
    {
        $user = $this->user();

        foreach (OrderStatus::cases() as $case) {
            $response = $this->actingAs($user)->postJson(self::ENDPOINT, $this->payload($case->value));

            self::assertFalse(
                $this->statusWasRejected($response),
                "Canonical status '{$case->value}' must clear the status rule.",
            );
        }
    }

    // ── CASE D / E / F — legacy V2 values are rejected ───────────────────────

    public function test_cases_d_e_f_legacy_v2_statuses_are_rejected(): void
    {
        $user = $this->user();
        $canonical = array_column(OrderStatus::cases(), 'value');

        foreach (['pending', 'processing', 'completed'] as $legacy) {
            // Guard the premise: only assert rejection while the value genuinely
            // is not part of the enum. If it were ever added, this test should
            // fail loudly rather than silently enforce a stale expectation.
            self::assertNotContains($legacy, $canonical, "'{$legacy}' is unexpectedly a canonical status.");

            $response = $this->actingAs($user)->postJson(self::ENDPOINT, $this->payload($legacy));

            self::assertTrue(
                $this->statusWasRejected($response),
                "Legacy V2 status '{$legacy}' must be rejected by the status rule.",
            );
        }
    }

    // ── The rule is derived, not copied ──────────────────────────────────────

    public function test_status_rule_is_derived_from_the_canonical_enum(): void
    {
        $rules = (new \Modules\Commerce\Orders\Presentation\Http\Requests\StoreManualOrderRequest)->rules();

        self::assertIsArray($rules['status']);
        self::assertNotContainsEquals(
            'nullable|string|in:pending,scheduled,processing,awaiting_payment,completed,cancelled',
            $rules['status'],
            'The hardcoded V2 whitelist must not return.',
        );

        // `nullable` is a deliberate, documented difference from StoreOrderRequest.
        self::assertContains('nullable', $rules['status']);
    }

    // ── Omitted status keeps its existing optional behaviour ─────────────────

    public function test_status_may_still_be_omitted(): void
    {
        $response = $this->actingAs($this->user())->postJson(self::ENDPOINT, $this->payload(null));

        self::assertFalse(
            $this->statusWasRejected($response),
            'Omitting status must remain valid — the field is nullable by contract.',
        );
    }
}
