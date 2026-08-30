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
use Modules\Operations\Loading\Domain\Services\VehicleShiftReconciliationService;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * T-09 HTTP closure — expose RecordProductDeliveryAction over HTTP (ADR-015 §6.4).
 *
 *   POST api/loading/sessions/{sessionId}/assignments/{assignmentId}/allocation/deliver
 *
 * Every test drives the real stack — HTTP → AllocationController → the real
 * RecordProductDeliveryAction. Nothing writes quantity_delivered onto a row
 * directly: `loaded` is seeded through LoadProductAction, `delivered` only ever
 * flows through the endpoint under test, and the reconciliation case reads back
 * through the real VehicleShiftReconciliationService. A fixture that hand-seeded
 * delivered would prove only that arithmetic works on placed numbers — the exact
 * false-green this suite exists to avoid.
 */
class RecordProductDeliveryHttpTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        // Role-less: the base TestCase::actingAs() grants the production system
        // role to a role-less user, which LoadingSessionPolicy::allocate() honours
        // via PermissionService::userHasSystemRole(). The forbidden-access test
        // uses actingAsUnprivileged() to opt out of that baseline grant.
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

    private function deliverUrl(VehicleAssignment $assignment): string
    {
        return "/api/loading/sessions/{$assignment->loading_session_id}"
            ."/assignments/{$assignment->id}/allocation/deliver";
    }

    // ── A + E + F + G. Authorized delivery succeeds through the real Action ──────

    public function test_authorized_delivery_updates_quantities_through_the_action(): void
    {
        $shift = $this->makeShift();
        $item = $this->load($shift, 100);
        $record = $this->allocate($item, 100);

        $response = $this->actingAs($this->user)->postJson($this->deliverUrl($shift), [
            'allocation_record_id' => $record->id,
            'quantity_delivered' => 90,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', AllocationRecordStatus::PartialDelivery->value);
        // Numbers are asserted loosely: decimal(18,4) serialises to a whole number
        // here, and assertJsonPath compares strictly (90 !== 90.0).
        $this->assertEquals(90.0, $response->json('data.quantity_delivered'));   // F
        $this->assertEquals(10.0, $response->json('data.quantity_remaining'));   // G

        // E — the write reached the persisted allocation row AND propagated to the
        // vehicle inventory aggregate, which only the real Action does.
        $this->assertSame(90.0, (float) $record->refresh()->quantity_delivered);
        $this->assertSame(10.0, (float) $record->quantity_remaining);
        $this->assertSame(90.0, (float) $item->refresh()->quantity_delivered);
        $this->assertSame(10.0, (float) $item->quantity_on_hand);
    }

    public function test_full_delivery_sets_delivered_status(): void
    {
        $shift = $this->makeShift();
        $record = $this->allocate($this->load($shift, 100), 100);

        $response = $this->actingAs($this->user)->postJson($this->deliverUrl($shift), [
            'allocation_record_id' => $record->id,
            'quantity_delivered' => 100,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', AllocationRecordStatus::Delivered->value);
        $this->assertEquals(0.0, $response->json('data.quantity_remaining'));
    }

    // ── B. Invalid quantity fails validation ────────────────────────────────────

    public function test_negative_quantity_fails_validation(): void
    {
        $shift = $this->makeShift();
        $record = $this->allocate($this->load($shift, 100), 100);

        $this->actingAs($this->user)->postJson($this->deliverUrl($shift), [
            'allocation_record_id' => $record->id,
            'quantity_delivered' => -5,
        ])->assertStatus(422)->assertJsonValidationErrors('quantity_delivered');

        // Untouched: a rejected request must not have written anything.
        $this->assertSame(0.0, (float) $record->refresh()->quantity_delivered);
    }

    public function test_missing_quantity_fails_validation(): void
    {
        $shift = $this->makeShift();
        $record = $this->allocate($this->load($shift, 100), 100);

        $this->actingAs($this->user)->postJson($this->deliverUrl($shift), [
            'allocation_record_id' => $record->id,
        ])->assertStatus(422)->assertJsonValidationErrors('quantity_delivered');
    }

    // ── C. Over-delivery follows the existing (fail-closed) contract ─────────────

    public function test_over_delivery_is_refused_per_existing_contract(): void
    {
        $shift = $this->makeShift();
        $record = $this->allocate($this->load($shift, 100), 100);

        $response = $this->actingAs($this->user)->postJson($this->deliverUrl($shift), [
            'allocation_record_id' => $record->id,
            'quantity_delivered' => 110,
        ]);

        // The controller surfaces the domain's fail-closed refusal as a 422. The
        // body is Laravel's default abort() shape ({message}), carrying the
        // Action's own explanation verbatim.
        $response->assertStatus(422);
        $this->assertStringContainsString('exceeds the allocated quantity', (string) $response->json('message'));

        // The refusal is real: nothing was written.
        $this->assertSame(0.0, (float) $record->refresh()->quantity_delivered);
    }

    // ── D. Tenant isolation — Company A cannot deliver against Company B ─────────

    public function test_company_a_cannot_post_delivery_against_company_b_allocation(): void
    {
        $otherCompany = Company::factory()->create();

        $mine = $this->makeShift();
        $theirs = $this->makeShift($otherCompany);
        $theirRecord = $this->allocate($this->load($theirs, 70), 70);

        // Primary vector — the attacker uses their OWN session in the path (which
        // findSession resolves for their company), but names the other company's
        // assignment + allocation. The assignment is not in the attacker's session,
        // so the tenant chain 404s before the allocation is ever reached.
        $viaMySession = "/api/loading/sessions/{$mine->loading_session_id}"
            ."/assignments/{$theirs->id}/allocation/deliver";

        $this->actingAs($this->user)->postJson($viaMySession, [
            'allocation_record_id' => $theirRecord->id,
            'quantity_delivered' => 70,
        ])->assertStatus(404);

        // Secondary vector — naming the other company's session id directly.
        // findSession scopes to the actor's company, so the session is invisible
        // and the request is blocked. (The shared findSession throws a bare
        // RuntimeException on a miss, which the framework renders 500 across every
        // Loading endpoint — a pre-existing module-wide behaviour, not introduced
        // here; the invariant asserted is only that the request does NOT succeed.)
        $blocked = $this->actingAs($this->user)->postJson($this->deliverUrl($theirs), [
            'allocation_record_id' => $theirRecord->id,
            'quantity_delivered' => 70,
        ]);
        $this->assertGreaterThanOrEqual(400, $blocked->status(), 'cross-company session must be blocked');

        // Nothing crossed the boundary.
        $this->assertSame(0.0, (float) $theirRecord->refresh()->quantity_delivered);
    }

    // ── H. Replay must not double-add ────────────────────────────────────────────

    public function test_replaying_the_same_delivery_does_not_double_add(): void
    {
        $shift = $this->makeShift();
        $item = $this->load($shift, 100);
        $record = $this->allocate($item, 100);

        $payload = ['allocation_record_id' => $record->id, 'quantity_delivered' => 90];

        $this->actingAs($this->user)->postJson($this->deliverUrl($shift), $payload)->assertOk();
        $this->actingAs($this->user)->postJson($this->deliverUrl($shift), $payload)->assertOk();
        $this->actingAs($this->user)->postJson($this->deliverUrl($shift), $payload)->assertOk();

        // Absolute semantics — the aggregate holds 90, not 270.
        $this->assertSame(90.0, (float) $record->refresh()->quantity_delivered);
        $this->assertSame(90.0, (float) $item->refresh()->quantity_delivered);
        $this->assertSame(10.0, (float) $item->quantity_on_hand);

        // And the vehicle ledger recorded exactly one delivery movement.
        $this->assertCount(1, $item->movements()->where('movement_type', 'delivered')->get());
    }

    // ── I. Reconciliation reads back the HTTP-delivered quantity ─────────────────

    public function test_reconciliation_reads_the_quantity_delivered_over_http(): void
    {
        $shift = $this->makeShift();
        $item = $this->load($shift, 100);
        $record = $this->allocate($item, 100);

        $this->actingAs($this->user)->postJson($this->deliverUrl($shift), [
            'allocation_record_id' => $record->id,
            'quantity_delivered' => 90,
        ])->assertOk();

        $reconciliation = app(VehicleShiftReconciliationService::class)
            ->open($shift, (string) $this->user->id);
        $line = $reconciliation->lines()->firstOrFail();

        $this->assertSame(100.0, (float) $line->quantity_loaded);
        $this->assertSame(90.0, (float) $line->quantity_delivered);
        $this->assertSame(10.0, (float) $line->quantity_returned_expected);
        // ADR-015 §6.4: 100 - 90 - 0 = 10 outstanding until the return is counted.
        $this->assertSame(10.0, (float) $line->variance);
    }

    // ── J. Permission / authentication contract ─────────────────────────────────

    public function test_unauthenticated_request_is_rejected(): void
    {
        $shift = $this->makeShift();
        $record = $this->allocate($this->load($shift, 100), 100);

        $this->postJson($this->deliverUrl($shift), [
            'allocation_record_id' => $record->id,
            'quantity_delivered' => 90,
        ])->assertStatus(401);
    }

    public function test_actor_without_permission_is_forbidden(): void
    {
        $shift = $this->makeShift();
        $record = $this->allocate($this->load($shift, 100), 100);

        // Same company, but no system role and no loading.allocation.manage grant.
        // actingAsUnprivileged() opts out of the baseline system-role grant, so the
        // policy is exercised as it would be for a real under-permissioned user.
        $unprivileged = User::factory()->create(['company_id' => $this->company->id]);

        $this->actingAsUnprivileged($unprivileged)->postJson($this->deliverUrl($shift), [
            'allocation_record_id' => $record->id,
            'quantity_delivered' => 90,
        ])->assertStatus(403);

        $this->assertSame(0.0, (float) $record->refresh()->quantity_delivered);
    }
}
