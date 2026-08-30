<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\Operations\Loading\Application\Actions\LoadProductAction;
use Modules\Operations\Loading\Domain\Enums\AllocationRecordStatus;
use Modules\Operations\Loading\Domain\Enums\DriverAssignmentStatus;
use Modules\Operations\Loading\Domain\Enums\LoadingSessionStatus;
use Modules\Operations\Loading\Domain\Enums\SessionType;
use Modules\Operations\Loading\Domain\Enums\VehicleAssignmentStatus;
use Modules\Operations\Loading\Domain\Models\AllocationRecord;
use Modules\Operations\Loading\Domain\Models\DriverAssignment;
use Modules\Operations\Loading\Domain\Models\LoadingSession;
use Modules\Operations\Loading\Domain\Models\VehicleAssignment;
use Modules\Operations\Loading\Domain\Models\VehicleInventoryItem;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * T-04/T-05 convergence — vehicle-shift reconciliation over HTTP (ADR-015 §6.4).
 *
 *   GET  api/loading/sessions/{s}/assignments/{a}/reconciliation
 *   POST api/loading/sessions/{s}/assignments/{a}/reconciliation/open
 *   POST api/loading/sessions/{s}/assignments/{a}/reconciliation/lines/{line}/return
 *
 *   quantity_variance = loaded - delivered - returned   (must be 0)
 *
 * Every quantity flows through its real writer over HTTP where one exists:
 *   loaded    LoadProductAction (fixture)          — the loading authority
 *   delivered POST .../allocation/deliver (T-09)    — the real delivery endpoint
 *   returned  POST .../reconciliation/lines/{}/return — the endpoint under test
 * Nothing hand-seeds quantity_delivered or a reconciliation line; the whole
 * operator chain Load → Deliver → Reconcile is exercised through the HTTP stack.
 */
class VehicleShiftReconciliationHttpTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        // Role-less user: base TestCase::actingAs() grants the system role, which
        // LoadingSessionPolicy honours. The 403 test uses actingAsUnprivileged().
        $this->user = User::factory()->create(['company_id' => $this->company->id]);
    }

    // ── Fixtures — shift skeleton + loaded quantity via real writers only ────────

    private function makeShift(?Company $company = null): VehicleAssignment
    {
        $company ??= $this->company;
        $suffix = substr(md5(uniqid('', true)), 0, 8);

        $session = LoadingSession::create([
            'company_id' => $company->id,
            'warehouse_id' => (string) Str::uuid(),
            'session_number' => 'LS-'.$suffix,
            'operational_date' => '2026-08-18',
            'status' => LoadingSessionStatus::Loading->value,
            'session_type' => SessionType::Standard->value,
            'created_by' => (string) $this->user->id,
            'updated_by' => (string) $this->user->id,
        ]);

        $assignment = VehicleAssignment::create([
            'company_id' => $company->id,
            'loading_session_id' => $session->id,
            'vehicle_id' => (string) Str::uuid(),
            'vehicle_registration_snapshot' => 'REG-'.$suffix,
            'vehicle_type_snapshot' => 'van',
            'capacity_weight_kg_snapshot' => 1000,
            'capacity_volume_m3_snapshot' => 10,
            'assignment_number' => 'VA-'.$suffix,
            'status' => VehicleAssignmentStatus::Loading->value,
            'created_by' => (string) $this->user->id,
            'updated_by' => (string) $this->user->id,
        ]);

        DriverAssignment::create([
            'company_id' => $company->id,
            'vehicle_assignment_id' => $assignment->id,
            'loading_session_id' => $session->id,
            'vehicle_id' => $assignment->vehicle_id,
            'driver_id' => (string) Str::uuid(),
            'driver_name_snapshot' => 'Test Driver',
            'status' => DriverAssignmentStatus::Assigned->value,
            'assigned_by' => (string) $this->user->id,
            'created_by' => (string) $this->user->id,
            'updated_by' => (string) $this->user->id,
        ]);

        return $assignment->refresh();
    }

    private function load(VehicleAssignment $assignment, float $quantity, ?string $productId = null): VehicleInventoryItem
    {
        $productId ??= (string) Str::uuid();

        app(LoadProductAction::class)->execute(
            assignment: $assignment,
            poolEntryId: (string) Str::uuid(),
            productId: $productId,
            skuSnapshot: 'SKU-'.substr(md5($productId), 0, 6),
            nameSnapshot: 'Test Product',
            preparationWaveId: (string) Str::uuid(),
            quantityPlanned: $quantity,
            quantityLoaded: $quantity,
            loadedBy: (string) $this->user->id,
        );

        return VehicleInventoryItem::where('vehicle_assignment_id', $assignment->id)
            ->where('product_id', $productId)
            ->firstOrFail();
    }

    private function allocate(VehicleInventoryItem $item, float $quantity): AllocationRecord
    {
        return AllocationRecord::create([
            'company_id' => $item->company_id,
            'vehicle_assignment_id' => $item->vehicle_assignment_id,
            'loading_session_id' => VehicleAssignment::find($item->vehicle_assignment_id)?->loading_session_id,
            'vehicle_id' => $item->vehicle_id,
            'order_id' => (string) Str::uuid(),
            'order_line_id' => (string) Str::uuid(),
            'order_number_snapshot' => 'ORD-'.substr(md5(uniqid('', true)), 0, 6),
            'product_id' => $item->product_id,
            'sku_snapshot' => $item->sku_snapshot,
            'vehicle_inventory_item_id' => $item->id,
            'allocation_mode' => 'full_auto',
            'priority_rank' => 1,
            'quantity_requested' => $quantity,
            'quantity_allocated' => $quantity,
            'quantity_loaded' => 0.0,
            'quantity_delivered' => 0.0,
            'quantity_remaining' => $quantity,
            'is_partial' => false,
            'status' => AllocationRecordStatus::Allocated->value,
            'allocated_at' => now(),
            'allocated_by' => 'system',
            'created_by' => (string) $this->user->id,
            'updated_by' => (string) $this->user->id,
        ]);
    }

    private function base(VehicleAssignment $a): string
    {
        return "/api/loading/sessions/{$a->loading_session_id}/assignments/{$a->id}";
    }

    /** Records delivered through the REAL T-09 HTTP endpoint (never hand-seeded). */
    private function deliverOverHttp(VehicleAssignment $shift, AllocationRecord $record, float $qty): void
    {
        $this->actingAs($this->user)
            ->postJson($this->base($shift).'/allocation/deliver', [
                'allocation_record_id' => $record->id,
                'quantity_delivered' => $qty,
            ])
            ->assertOk();
    }

    // ── 1. open builds lines from the real loaded + HTTP-delivered facts ─────────

    public function test_open_builds_lines_from_loaded_and_delivered_over_http(): void
    {
        $shift = $this->makeShift();
        $item = $this->load($shift, 100);
        $this->deliverOverHttp($shift, $this->allocate($item, 100), 90);

        $response = $this->actingAs($this->user)->postJson($this->base($shift).'/reconciliation/open');

        $response->assertOk();
        $this->assertEquals(100.0, $response->json('data.lines.0.quantity_loaded'));
        $this->assertEquals(90.0, $response->json('data.lines.0.quantity_delivered'));
        $this->assertEquals(10.0, $response->json('data.lines.0.quantity_returned_expected'));
        // 100 - 90 - 0 = 10 outstanding until the return is counted.
        $this->assertEquals(10.0, $response->json('data.lines.0.variance'));
        $this->assertEquals(10.0, $response->json('data.total_variance'));
        $this->assertTrue($response->json('data.has_variance'));
    }

    // ── 2. Scenario 7 — Loaded 10, Delivered 8, Returned 2 → Variance 0 ──────────

    public function test_full_account_reconciles_to_zero_variance_over_http(): void
    {
        $shift = $this->makeShift();
        $item = $this->load($shift, 10);
        $this->deliverOverHttp($shift, $this->allocate($item, 10), 8);

        $open = $this->actingAs($this->user)->postJson($this->base($shift).'/reconciliation/open');
        $open->assertOk();
        $lineId = $open->json('data.lines.0.id');

        $reconciled = $this->actingAs($this->user)->postJson(
            $this->base($shift)."/reconciliation/lines/{$lineId}/return",
            ['quantity_returned_actual' => 2],
        );

        $reconciled->assertOk();
        $this->assertEquals(0.0, $reconciled->json('data.lines.0.variance'));
        $this->assertEquals(0.0, $reconciled->json('data.total_variance'));
        $this->assertFalse($reconciled->json('data.has_variance'));
    }

    // ── 3. A real variance — short return ────────────────────────────────────────

    public function test_short_return_leaves_a_real_variance_over_http(): void
    {
        $shift = $this->makeShift();
        $item = $this->load($shift, 10);
        $this->deliverOverHttp($shift, $this->allocate($item, 10), 8);

        $lineId = $this->actingAs($this->user)
            ->postJson($this->base($shift).'/reconciliation/open')
            ->json('data.lines.0.id');

        $response = $this->actingAs($this->user)->postJson(
            $this->base($shift)."/reconciliation/lines/{$lineId}/return",
            ['quantity_returned_actual' => 1, 'resolution_notes' => 'One unit unaccounted for'],
        );

        // 10 - 8 - 1 = 1
        $response->assertOk();
        $this->assertEquals(1.0, $response->json('data.total_variance'));
        $this->assertTrue($response->json('data.has_variance'));
    }

    // ── 4. show returns null before open, the reconciliation after ───────────────

    public function test_show_returns_null_before_open_then_the_reconciliation(): void
    {
        $shift = $this->makeShift();
        $this->deliverOverHttp($shift, $this->allocate($this->load($shift, 50), 50), 50);

        $this->actingAs($this->user)->getJson($this->base($shift).'/reconciliation')
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->actingAs($this->user)->postJson($this->base($shift).'/reconciliation/open')->assertOk();

        // assertJsonPath compares strictly (50 !== 50.0); read the value and compare loosely.
        $show = $this->actingAs($this->user)->getJson($this->base($shift).'/reconciliation')->assertOk();
        $this->assertNotNull($show->json('data'));
        $this->assertEquals(50.0, $show->json('data.total_quantity_loaded'));
    }

    // ── 5. Recording the return is idempotent (absolute) over HTTP ───────────────

    public function test_recording_the_same_return_twice_is_a_no_op(): void
    {
        $shift = $this->makeShift();
        $this->deliverOverHttp($shift, $this->allocate($this->load($shift, 10), 10), 8);

        $lineId = $this->actingAs($this->user)
            ->postJson($this->base($shift).'/reconciliation/open')
            ->json('data.lines.0.id');

        $url = $this->base($shift)."/reconciliation/lines/{$lineId}/return";
        $this->actingAs($this->user)->postJson($url, ['quantity_returned_actual' => 2])->assertOk();
        $final = $this->actingAs($this->user)->postJson($url, ['quantity_returned_actual' => 2]);

        $final->assertOk();
        $this->assertEquals(2.0, $final->json('data.total_quantity_returned'));
        $this->assertEquals(0.0, $final->json('data.total_variance'));
    }

    // ── 6. Validation — a negative return is rejected ────────────────────────────

    public function test_negative_return_fails_validation(): void
    {
        $shift = $this->makeShift();
        $this->deliverOverHttp($shift, $this->allocate($this->load($shift, 10), 10), 8);

        $lineId = $this->actingAs($this->user)
            ->postJson($this->base($shift).'/reconciliation/open')
            ->json('data.lines.0.id');

        $this->actingAs($this->user)->postJson(
            $this->base($shift)."/reconciliation/lines/{$lineId}/return",
            ['quantity_returned_actual' => -1],
        )->assertStatus(422)->assertJsonValidationErrors('quantity_returned_actual');
    }

    // ── 7. Tenant isolation — open ───────────────────────────────────────────────

    public function test_company_a_cannot_open_company_b_reconciliation(): void
    {
        $otherCompany = Company::factory()->create();

        $mine = $this->makeShift();
        $theirs = $this->makeShift($otherCompany);

        // Attacker's own session in the path (resolves for their company) but the
        // other company's assignment. The assignment is not in the attacker's
        // session, so the tenant chain 404s before any reconciliation is created.
        $url = "/api/loading/sessions/{$mine->loading_session_id}"
            ."/assignments/{$theirs->id}/reconciliation/open";

        $this->actingAs($this->user)->postJson($url)->assertStatus(404);

        // No reconciliation was created for the victim's shift.
        $this->actingAs(User::factory()->create(['company_id' => $otherCompany->id]))
            ->getJson($this->base($theirs).'/reconciliation')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    // ── 8. Tenant isolation — record return against another company's line ───────

    public function test_company_a_cannot_record_return_on_company_b_line(): void
    {
        $otherCompany = Company::factory()->create();
        $otherUser = User::factory()->create(['company_id' => $otherCompany->id]);

        $theirs = $this->makeShift($otherCompany);
        $this->actingAs($otherUser)
            ->postJson($this->base($theirs).'/allocation/deliver', [
                'allocation_record_id' => $this->allocate($this->load($theirs, 10), 10)->id,
                'quantity_delivered' => 8,
            ])->assertOk();

        $theirLineId = $this->actingAs($otherUser)
            ->postJson($this->base($theirs).'/reconciliation/open')
            ->json('data.lines.0.id');

        // Attacker (company A) uses their own shift in the path but the victim's line id.
        $mine = $this->makeShift();
        $this->actingAs($this->user)->postJson(
            $this->base($mine)."/reconciliation/lines/{$theirLineId}/return",
            ['quantity_returned_actual' => 5],
        )->assertStatus(404);
    }

    // ── 9. Authentication ────────────────────────────────────────────────────────

    public function test_unauthenticated_open_is_rejected(): void
    {
        $shift = $this->makeShift();

        $this->postJson($this->base($shift).'/reconciliation/open')->assertStatus(401);
    }

    // ── 10. Permission ───────────────────────────────────────────────────────────

    public function test_actor_without_permission_cannot_open(): void
    {
        $shift = $this->makeShift();
        $unprivileged = User::factory()->create(['company_id' => $this->company->id]);

        $this->actingAsUnprivileged($unprivileged)
            ->postJson($this->base($shift).'/reconciliation/open')
            ->assertStatus(403);
    }
}
