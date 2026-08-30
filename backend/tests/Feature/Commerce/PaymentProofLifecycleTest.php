<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Domain\Enums\PaymentProofState;
use Modules\Commerce\Orders\Domain\Enums\PaymentState;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderEvent;
use Modules\Commerce\Orders\Domain\Models\PaymentProof;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-PAYMENT-PROOF-LIFECYCLE-001 §18 — payment proof lifecycle.
 * Drives the real HTTP endpoints. Proof is evidence: it never marks a payment PAID
 * and never mutates Order status or payment amount.
 */
final class PaymentProofLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->company = Company::factory()->create();
        $this->customer = Customer::factory()->create(['company_id' => $this->company->id]);
    }

    private function user(?Company $company = null): User
    {
        return User::factory()->create(['company_id' => ($company ?? $this->company)->id]);
    }

    private function makeOrder(float $total = 10000, float $deposit = 0, array $extra = []): Order
    {
        return Order::query()->create(array_merge([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-PP-'.Str::random(6),
            'order_date' => now()->toDateString(),
            'status' => 'awaiting_payment',
            'subtotal' => $total,
            'total' => $total,
            'shipping_total' => 0, 'discount_total' => 0, 'tax_total' => 0,
            'deposit_amount' => $deposit,
            'payment_method_manual' => 'instapay',
        ], $extra));
    }

    private function upload(User $u, Order $o, ?UploadedFile $file = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($u)->postJson("/api/orders/{$o->id}/payment-proofs", [
            'file' => $file ?? UploadedFile::fake()->image('receipt.jpg'),
        ]);
    }

    private function activeProof(Order $o): ?PaymentProof
    {
        return PaymentProof::where('order_id', $o->id)->whereNull('superseded_at')->latest('uploaded_at')->first();
    }

    // ── A. UPLOAD ────────────────────────────────────────────────────────────────

    public function test_1_authorized_upload_succeeds(): void
    {
        $o = $this->makeOrder();
        $this->upload($this->user(), $o)->assertOk()->assertJsonPath('data.state', 'uploaded');
    }

    public function test_2_uploaded_proof_state_is_uploaded(): void
    {
        $o = $this->makeOrder();
        $this->upload($this->user(), $o);
        self::assertSame(PaymentProofState::Uploaded, $this->activeProof($o)->state);
    }

    public function test_3_uploader_is_recorded(): void
    {
        $o = $this->makeOrder();
        $u = $this->user();
        $this->upload($u, $o);
        self::assertSame((int) $u->id, (int) $this->activeProof($o)->uploaded_by);
        self::assertNotNull($this->activeProof($o)->uploaded_at);
    }

    public function test_4_tenant_ownership_is_correct(): void
    {
        $o = $this->makeOrder();
        $this->upload($this->user(), $o);
        self::assertSame($this->company->id, $this->activeProof($o)->company_id);
    }

    public function test_5_invalid_upload_is_rejected(): void
    {
        $o = $this->makeOrder();
        $this->upload($this->user(), $o, UploadedFile::fake()->create('malware.exe', 20, 'application/octet-stream'))
            ->assertStatus(422);
        self::assertNull($this->activeProof($o));
    }

    // ── B. VERIFY ────────────────────────────────────────────────────────────────

    public function test_6_uploaded_can_be_verified(): void
    {
        $o = $this->makeOrder();
        $this->upload($this->user(), $o);
        $p = $this->activeProof($o);
        $this->actingAs($this->user())->postJson("/api/payment-proofs/{$p->id}/verify")
            ->assertOk()->assertJsonPath('data.state', 'verified');
    }

    public function test_7_verifier_is_recorded(): void
    {
        $o = $this->makeOrder();
        $this->upload($this->user(), $o);
        $p = $this->activeProof($o);
        $v = $this->user();
        $this->actingAs($v)->postJson("/api/payment-proofs/{$p->id}/verify")->assertOk();
        $p->refresh();
        self::assertSame((int) $v->id, (int) $p->verified_by);
        self::assertNotNull($p->verified_at);
    }

    public function test_8_a_verified_proof_cannot_be_verified_again(): void
    {
        $o = $this->makeOrder();
        $this->upload($this->user(), $o);
        $p = $this->activeProof($o);
        $this->actingAs($this->user())->postJson("/api/payment-proofs/{$p->id}/verify")->assertOk();
        $this->actingAs($this->user())->postJson("/api/payment-proofs/{$p->id}/verify")->assertStatus(422);
    }

    public function test_9_verification_does_not_change_payment_amount(): void
    {
        $o = $this->makeOrder(10000, 3000);
        $this->upload($this->user(), $o);
        $p = $this->activeProof($o);
        $this->actingAs($this->user())->postJson("/api/payment-proofs/{$p->id}/verify")->assertOk();
        self::assertSame(3000.0, (float) $o->refresh()->deposit_amount);
    }

    /**
     * CONTRACT CHANGE — TASK-ORDERS-PAYMENT-CONFIRMATION-FULFILLMENT-IMPLEMENTATION-001.
     *
     * This test used to assert that verifying a proof leaves Order.status untouched. That
     * held only because nothing re-evaluated the payment gate after a proof was accepted —
     * the orchestration defect that left ORD-00003 paid in full, proof verified, and stuck
     * in `awaiting_payment` forever. Verification now re-asks ConfirmOrderWorkflow, so an
     * order whose gate is satisfied does advance. That is the approved behaviour.
     *
     * What still holds, and is what this test now pins down: VerifyPaymentProofAction never
     * writes Order.status ITSELF. Any transition is performed by ConfirmOrderWorkflow via
     * FulfillmentEngine and recorded under that workflow's name — a bare status write from
     * the proof action would be rejected by the P9 OrderStatusGuard and would leave no
     * `confirm_order` event behind. Test 9 still guarantees the payment amount is untouched.
     *
     * FIXTURE CHANGED BY IMPLEMENTATION-002, and why. This test used to rely on a policy
     * hole: `makeOrder()` sets no channel_id, and the resolver used to hardcode
     * `channel_id IS NULL => 'none'`, so an UNPAID instapay order advanced on verification
     * alone. Owner decision D2-B removed that hardcode — a missing channel is a missing
     * configuration scope, not a missing requirement — so the same order now correctly
     * blocks, and D1-A requires payment AND a verified proof for a proof-required method.
     *
     * The fixture is therefore paid in full, which is what the assertions below always
     * MEANT to set up: a gate that is genuinely satisfied. Not one assertion is weakened —
     * both are unchanged, and the test now proves the transition on legitimate grounds
     * instead of on a bypass. The blocked direction is pinned by test_10b below.
     */
    public function test_10_verification_never_writes_order_status_itself(): void
    {
        $o = $this->makeOrder(10000, 10000);
        $this->upload($this->user(), $o);
        $p = $this->activeProof($o);

        $this->actingAs($this->user())->postJson("/api/payment-proofs/{$p->id}/verify")->assertOk();

        self::assertNotSame(
            'awaiting_payment',
            (string) $o->refresh()->getRawOriginal('status'),
            'A satisfied gate must advance the order — that is the repaired orchestration.',
        );
        self::assertSame(
            1,
            OrderEvent::query()->where('order_id', $o->id)->where('event_type', 'confirm_order')->count(),
            'The transition must come from ConfirmOrderWorkflow, never from the proof action.',
        );
    }

    /**
     * The direction the old fixture could not express (Owner decision D1-A + D2-B).
     *
     * Same channel-less, proof-required order — but UNPAID. Verifying the proof satisfies
     * only one of the two required facts, so the order must stay parked. Before D2-B this
     * order advanced, because a NULL channel resolved the requirement to 'none'.
     */
    public function test_10b_verification_alone_does_not_advance_an_unpaid_proof_required_order(): void
    {
        $o = $this->makeOrder(10000, 0);
        $this->upload($this->user(), $o);
        $p = $this->activeProof($o);

        $this->actingAs($this->user())->postJson("/api/payment-proofs/{$p->id}/verify")->assertOk();

        self::assertSame(
            'awaiting_payment',
            (string) $o->refresh()->getRawOriginal('status'),
            'A verified proof is not a payment; both facts are required.',
        );
        self::assertSame(
            0,
            OrderEvent::query()->where('order_id', $o->id)->where('event_type', 'confirm_order')->count(),
        );
    }

    // ── C. REJECT ────────────────────────────────────────────────────────────────

    public function test_11_uploaded_can_be_rejected(): void
    {
        $o = $this->makeOrder();
        $this->upload($this->user(), $o);
        $p = $this->activeProof($o);
        $this->actingAs($this->user())->postJson("/api/payment-proofs/{$p->id}/reject", ['reason' => 'Invalid transaction'])
            ->assertOk()->assertJsonPath('data.state', 'rejected');
    }

    public function test_12_rejection_requires_a_reason(): void
    {
        $o = $this->makeOrder();
        $this->upload($this->user(), $o);
        $p = $this->activeProof($o);
        $this->actingAs($this->user())->postJson("/api/payment-proofs/{$p->id}/reject", ['reason' => ''])
            ->assertStatus(422);
        self::assertSame(PaymentProofState::Uploaded, $p->refresh()->state);
    }

    public function test_13_rejector_is_recorded(): void
    {
        $o = $this->makeOrder();
        $this->upload($this->user(), $o);
        $p = $this->activeProof($o);
        $r = $this->user();
        $this->actingAs($r)->postJson("/api/payment-proofs/{$p->id}/reject", ['reason' => 'Blurry'])->assertOk();
        $p->refresh();
        self::assertSame((int) $r->id, (int) $p->rejected_by);
        self::assertSame('Blurry', $p->rejection_reason);
    }

    public function test_14_rejected_proof_remains_as_evidence(): void
    {
        $o = $this->makeOrder();
        $this->upload($this->user(), $o);
        $p = $this->activeProof($o);
        $this->actingAs($this->user())->postJson("/api/payment-proofs/{$p->id}/reject", ['reason' => 'Wrong ref'])->assertOk();
        self::assertNotNull(PaymentProof::find($p->id), 'Rejected proof must be retained.');
        self::assertTrue(Storage::disk('local')->exists($p->storage_path), 'Rejected evidence file must remain.');
    }

    // ── D. REPLACEMENT ───────────────────────────────────────────────────────────

    public function test_15_to_19_replacement_retains_history_and_activates_new_proof(): void
    {
        $o = $this->makeOrder();
        $this->upload($this->user(), $o);
        $first = $this->activeProof($o);
        // Reject then replace.
        $this->actingAs($this->user())->postJson("/api/payment-proofs/{$first->id}/reject", ['reason' => 'Invalid'])->assertOk();
        $this->upload($this->user(), $o)->assertOk();

        $first->refresh();
        $second = $this->activeProof($o);

        self::assertNotNull(PaymentProof::find($first->id), '15: old proof retained');
        self::assertNotSame($first->id, $second->id, '16: new proof created');
        self::assertSame(PaymentProofState::Uploaded, $second->state, '17: new proof starts UPLOADED');
        self::assertNull($second->superseded_at, '18: new proof is active');
        self::assertNotNull($first->superseded_at, '18: old proof is superseded');
        self::assertSame($first->id, $second->replaces_proof_id, 'predecessor linked');

        $history = PaymentProof::where('order_id', $o->id)->get();
        self::assertCount(2, $history, '19: history contains both proofs');
        self::assertTrue(Storage::disk('local')->exists($first->storage_path), 'old evidence not deleted');
    }

    // ── E. TENANT ISOLATION ──────────────────────────────────────────────────────

    public function test_20_to_24_cross_tenant_access_is_denied(): void
    {
        $o = $this->makeOrder();
        $this->upload($this->user(), $o);
        $p = $this->activeProof($o);

        $companyB = Company::factory()->create();
        $userB = $this->user($companyB);

        // 20 read
        $this->actingAs($userB)->getJson("/api/orders/{$o->id}/payment-proofs")->assertNotFound();
        // 21 upload against Company A order
        $this->actingAs($userB)->postJson("/api/orders/{$o->id}/payment-proofs", ['file' => UploadedFile::fake()->image('x.jpg')])->assertNotFound();
        // 22 verify
        $this->actingAs($userB)->postJson("/api/payment-proofs/{$p->id}/verify")->assertNotFound();
        // 23 reject
        $this->actingAs($userB)->postJson("/api/payment-proofs/{$p->id}/reject", ['reason' => 'x'])->assertNotFound();
        // 24 replace (upload against A order) already covered by 21; download too
        $this->actingAs($userB)->getJson("/api/orders/{$o->id}/payment-proofs/{$p->id}/download")->assertNotFound();

        self::assertSame(PaymentProofState::Uploaded, $p->refresh()->state, 'Company B changed nothing.');
    }

    // ── F. PAYMENT CONTRACT ──────────────────────────────────────────────────────

    public function test_25_proof_upload_does_not_make_payment_paid(): void
    {
        $o = $this->makeOrder(10000, 0);
        $this->upload($this->user(), $o);
        self::assertSame(PaymentState::Unpaid, PaymentState::fromAmounts((float) $o->refresh()->deposit_amount, (float) $o->total));
    }

    public function test_26_proof_verification_does_not_make_payment_paid(): void
    {
        $o = $this->makeOrder(10000, 0);
        $this->upload($this->user(), $o);
        $p = $this->activeProof($o);
        $this->actingAs($this->user())->postJson("/api/payment-proofs/{$p->id}/verify")->assertOk();
        self::assertSame(PaymentState::Unpaid, PaymentState::fromAmounts((float) $o->refresh()->deposit_amount, (float) $o->total));
    }

    public function test_27_partial_deposit_stays_partially_paid_with_proof(): void
    {
        $o = $this->makeOrder(10000, 3000);
        $this->upload($this->user(), $o);
        self::assertSame(PaymentState::PartiallyPaid, PaymentState::fromAmounts((float) $o->refresh()->deposit_amount, (float) $o->total));
    }

    public function test_28_full_payment_stays_paid_with_proof(): void
    {
        $o = $this->makeOrder(10000, 10000);
        $this->upload($this->user(), $o);
        self::assertSame(PaymentState::Paid, PaymentState::fromAmounts((float) $o->refresh()->deposit_amount, (float) $o->total));
    }

    // ── G. CONFIRMATION / PERMISSION SEPARATION ──────────────────────────────────

    public function test_32_proof_actions_never_mutate_order_status(): void
    {
        $o = $this->makeOrder(10000, 0, ['status' => 'awaiting_payment']);
        $before = (string) $o->getRawOriginal('status');
        $this->upload($this->user(), $o);
        $p = $this->activeProof($o);
        $this->actingAs($this->user())->postJson("/api/payment-proofs/{$p->id}/reject", ['reason' => 'x'])->assertOk();
        self::assertSame($before, (string) $o->refresh()->getRawOriginal('status'));
    }

    public function test_upload_permission_does_not_grant_verify(): void
    {
        // A non-system user granted ONLY proof_upload can upload but not verify.
        $perm = Permission::firstOrCreate(
            ['name' => 'sales.orders.proof_upload'],
            ['module' => 'sales', 'resource' => 'orders', 'action' => 'proof_upload'],
        );
        $role = Role::create(['slug' => 'uploader-'.Str::random(6), 'name' => 'Uploader', 'is_system' => false]);
        $role->permissions()->attach($perm->id);

        $u = $this->user();
        $u->roles()->attach($role->id);

        $o = $this->makeOrder();
        $this->actingAsUnprivileged($u)
            ->postJson("/api/orders/{$o->id}/payment-proofs", ['file' => UploadedFile::fake()->image('r.jpg')])
            ->assertOk();

        $p = $this->activeProof($o);
        $this->actingAsUnprivileged($u)->postJson("/api/payment-proofs/{$p->id}/verify")->assertStatus(403);
    }

    public function test_unauthorized_user_cannot_upload(): void
    {
        $o = $this->makeOrder();
        $this->actingAsUnprivileged($this->user())
            ->postJson("/api/orders/{$o->id}/payment-proofs", ['file' => UploadedFile::fake()->image('r.jpg')])
            ->assertStatus(403);
    }
}
