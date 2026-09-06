<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Finance\Fiscal\Domain\Models\FiscalPeriod;
use Modules\Finance\Fiscal\Domain\Services\FiscalCalendarService;
use Modules\Finance\Ledger\Domain\Enums\AccountType;
use Modules\Finance\Ledger\Domain\Exceptions\FinanceException;
use Modules\Finance\Ledger\Domain\Models\Account;
use Modules\Finance\Ledger\Domain\Services\ChartOfAccountsService;
use Modules\Finance\Payables\Domain\Enums\SupplierDocumentType;
use Modules\Finance\Payables\Domain\Models\SupplierBill;
use Modules\Finance\Payables\Domain\Services\AccountsPayableService;
use Modules\Finance\Payables\Domain\Services\SupplierLedgerService;
use Modules\Finance\Payables\Domain\Services\SupplierOpeningBalanceService;
use Modules\Finance\Presentation\Http\Controllers\SupplierBillController;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-PROCUREMENT-SUPPLIERS-BATCH-01-FINAL-IMPLEMENTATION-CLOSURE-002 —
 * SupplierBillController::applyAdvance(): the explicit, single-bill "Apply Supplier
 * Advance" HTTP write surface, its CommandIdempotencyGuard wiring, and its
 * dedicated finance.ap.advance.apply authorization gate.
 *
 * Business-logic and idempotency cases call the controller method directly with a
 * constructed Request, mirroring SupplierPaymentIdempotencyEndpointTest's established
 * convention for this exact concern (this codebase's Finance tests consistently do
 * this rather than route through full HTTP for guard-wiring cases). Authorization is
 * proven separately through the real route (mirroring FinanceApiTest's
 * userWith()/actingAs()->postJson() convention), since permission middleware does not
 * run on a directly-invoked controller method.
 *
 * Not re-tested here (already covered elsewhere, per this task's own instruction not
 * to duplicate): the domain method's own eligibility/capping/locking correctness
 * (SupplierOpeningBalanceTest, SupplierAdvanceSettlementConcurrencyTest) and the
 * guard's own replay/fingerprint mechanics (CommandIdempotencyGuardTest). This file
 * proves the CONTROLLER WIRING: that applyAdvance() reaches the existing domain
 * method unchanged, that a duplicate submission of the same command cannot double-
 * apply, and that the route is gated by the correct, dedicated permission.
 */
class SupplierAdvanceApplicationEndpointTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;

    private string $companyId;

    private User $actingUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->companyId = (string) $this->company->id;
        $this->actingUser = User::factory()->create(['company_id' => $this->companyId]);
        $this->openPeriodForToday();
    }

    // ── Wiring: first call reaches the domain method and returns the expected shape ──

    public function test_first_command_succeeds_and_applies_the_advance(): void
    {
        $supplier = $this->supplierWithAdvance(1000.0);
        $bill = $this->postedBill($supplier, 1000.0);

        $response = app(SupplierBillController::class)->applyAdvance(
            $this->applyRequest($bill->uuid, 400.0, (string) Str::uuid()),
            $bill->uuid,
        );

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('false', $response->headers->get('Idempotent-Replay'));

        $data = json_decode((string) $response->getContent(), true)['data'];
        $this->assertSame($bill->uuid, $data['bill_id']);
        $this->assertSame($supplier, $data['supplier_id']);
        $this->assertSame(400.0, $data['amount_applied']);
        $this->assertSame(600.0, $data['bill_outstanding']);
        $this->assertSame(600.0, $data['available_advance']);
    }

    // ── The core requirement: a duplicate submission of the same command cannot double-apply ──

    public function test_same_key_and_same_payload_replays_without_double_applying(): void
    {
        $supplier = $this->supplierWithAdvance(1000.0);
        $bill = $this->postedBill($supplier, 1000.0);
        $key = (string) Str::uuid();

        $first = app(SupplierBillController::class)->applyAdvance($this->applyRequest($bill->uuid, 400.0, $key), $bill->uuid);
        $second = app(SupplierBillController::class)->applyAdvance($this->applyRequest($bill->uuid, 400.0, $key), $bill->uuid);

        $this->assertSame(201, $first->getStatusCode());
        $this->assertSame(200, $second->getStatusCode());
        $this->assertSame('true', $second->headers->get('Idempotent-Replay'));

        // Not 200 (1,000 - 2×400): the second call never re-executed the command.
        $this->assertSame(600.0, app(SupplierLedgerService::class)->availableAdvance($this->companyId, $supplier));
        $this->assertSame(600.0, $bill->fresh()->outstanding());
    }

    // Simulates a user double-click / browser retry firing three times, not two.
    public function test_same_key_called_repeatedly_cannot_apply_more_than_once(): void
    {
        $supplier = $this->supplierWithAdvance(1000.0);
        $bill = $this->postedBill($supplier, 1000.0);
        $key = (string) Str::uuid();

        app(SupplierBillController::class)->applyAdvance($this->applyRequest($bill->uuid, 400.0, $key), $bill->uuid);
        app(SupplierBillController::class)->applyAdvance($this->applyRequest($bill->uuid, 400.0, $key), $bill->uuid);
        app(SupplierBillController::class)->applyAdvance($this->applyRequest($bill->uuid, 400.0, $key), $bill->uuid);

        $this->assertSame(600.0, app(SupplierLedgerService::class)->availableAdvance($this->companyId, $supplier));
    }

    public function test_same_key_and_different_amount_conflicts(): void
    {
        $supplier = $this->supplierWithAdvance(1000.0);
        $bill = $this->postedBill($supplier, 1000.0);
        $key = (string) Str::uuid();

        app(SupplierBillController::class)->applyAdvance($this->applyRequest($bill->uuid, 400.0, $key), $bill->uuid);

        $this->expectException(FinanceException::class);
        app(SupplierBillController::class)->applyAdvance($this->applyRequest($bill->uuid, 999.0, $key), $bill->uuid);
    }

    public function test_different_key_permits_a_second_legitimate_application(): void
    {
        $supplier = $this->supplierWithAdvance(1000.0);
        $billA = $this->postedBill($supplier, 1000.0);
        $billB = $this->postedBill($supplier, 1000.0);

        app(SupplierBillController::class)->applyAdvance($this->applyRequest($billA->uuid, 400.0, (string) Str::uuid()), $billA->uuid);
        app(SupplierBillController::class)->applyAdvance($this->applyRequest($billB->uuid, 300.0, (string) Str::uuid()), $billB->uuid);

        $this->assertSame(300.0, app(SupplierLedgerService::class)->availableAdvance($this->companyId, $supplier));
    }

    public function test_no_key_runs_uncoordinated_matching_the_documented_convention(): void
    {
        $supplier = $this->supplierWithAdvance(1000.0);
        $bill = $this->postedBill($supplier, 1000.0);

        $response = app(SupplierBillController::class)->applyAdvance($this->applyRequest($bill->uuid, 300.0, null), $bill->uuid);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('false', $response->headers->get('Idempotent-Replay'));
    }

    // ── Eligibility / capping — authoritative in the domain method, exercised through the controller ──

    public function test_unposted_bill_is_rejected(): void
    {
        $supplier = $this->supplierWithAdvance(1000.0);
        $draft = app(AccountsPayableService::class)->createDocument(
            companyId: $this->companyId,
            supplierId: $supplier,
            number: 'BILL-'.$this->suffix(),
            documentDate: Carbon::today(),
            lines: [['expense_account_id' => (int) $this->expenseAccountFor($this->companyId)->id, 'net_amount' => 500.0]],
            type: SupplierDocumentType::Bill,
            dueDate: Carbon::today(),
        ); // never posted

        $this->expectException(FinanceException::class);
        app(SupplierBillController::class)->applyAdvance($this->applyRequest($draft->uuid, 100.0, null), $draft->uuid);
    }

    public function test_amount_exceeding_available_advance_is_rejected(): void
    {
        $supplier = $this->supplierWithAdvance(100.0);
        $bill = $this->postedBill($supplier, 1000.0);

        $this->expectException(FinanceException::class);
        app(SupplierBillController::class)->applyAdvance($this->applyRequest($bill->uuid, 150.0, null), $bill->uuid);
    }

    public function test_amount_exceeding_the_bills_own_outstanding_is_rejected(): void
    {
        $supplier = $this->supplierWithAdvance(10000.0);
        $bill = $this->postedBill($supplier, 100.0);

        $this->expectException(FinanceException::class);
        app(SupplierBillController::class)->applyAdvance($this->applyRequest($bill->uuid, 500.0, null), $bill->uuid);
    }

    public function test_a_non_positive_amount_is_rejected_by_validation(): void
    {
        $this->expectException(ValidationException::class);
        app(SupplierBillController::class)->applyAdvance(
            $this->applyRequest((string) Str::uuid(), 0.0, null),
            (string) Str::uuid(),
        );
    }

    // ── Tenant boundary ──────────────────────────────────────────────────────────

    public function test_cross_company_bill_404s(): void
    {
        $otherCompany = Company::factory()->create();
        $otherUser = User::factory()->create(['company_id' => (string) $otherCompany->id]);

        $supplier = $this->supplierWithAdvance(1000.0);
        $bill = $this->postedBill($supplier, 500.0);

        $request = $this->applyRequest($bill->uuid, 100.0, null);
        $request->setUserResolver(fn () => $otherUser);

        $this->expectException(ModelNotFoundException::class);
        app(SupplierBillController::class)->applyAdvance($request, $bill->uuid);
    }

    public function test_key_ownership_respects_company_tenant(): void
    {
        $otherCompany = Company::factory()->create();
        $otherUser = User::factory()->create(['company_id' => (string) $otherCompany->id]);
        $this->openPeriodForCompany((string) $otherCompany->id);

        $supplier = $this->supplierWithAdvance(1000.0);
        $bill = $this->postedBill($supplier, 500.0);

        $otherSupplier = (string) Str::uuid();
        app(SupplierOpeningBalanceService::class)->postOpeningAdvance(
            (string) $otherCompany->id, $otherSupplier, 'SUP-'.$this->suffix(), 1000.0, Carbon::today(), null, null, 1,
        );
        $otherBill = $this->postedBillFor((string) $otherCompany->id, $otherSupplier, 500.0);

        $key = (string) Str::uuid();
        $mine = app(SupplierBillController::class)->applyAdvance($this->applyRequest($bill->uuid, 200.0, $key), $bill->uuid);

        $theirsRequest = $this->applyRequest($otherBill->uuid, 200.0, $key);
        $theirsRequest->setUserResolver(fn () => $otherUser);
        $theirs = app(SupplierBillController::class)->applyAdvance($theirsRequest, $otherBill->uuid);

        // The same key claimed by two different companies is not a collision — each succeeds.
        $this->assertSame(201, $mine->getStatusCode());
        $this->assertSame(201, $theirs->getStatusCode());
    }

    // ── Authorization — through the real route, so the permission middleware runs ──

    public function test_the_route_requires_the_dedicated_advance_apply_permission(): void
    {
        $supplier = $this->supplierWithAdvance(1000.0);
        $bill = $this->postedBill($supplier, 500.0);

        $viewOnly = $this->userWith(['finance.ap.view']);

        $this->actingAs($viewOnly)
            ->postJson("/api/finance/ap/bills/{$bill->uuid}/apply-advance", ['amount' => 100.0])
            ->assertForbidden();
    }

    public function test_the_route_succeeds_for_a_user_holding_the_dedicated_permission(): void
    {
        $supplier = $this->supplierWithAdvance(1000.0);
        $bill = $this->postedBill($supplier, 500.0);

        $grantee = $this->userWith(['finance.ap.view', 'finance.ap.advance.apply']);

        $this->actingAs($grantee)
            ->postJson("/api/finance/ap/bills/{$bill->uuid}/apply-advance", ['amount' => 200.0])
            ->assertCreated()
            ->assertJsonPath('data.amount_applied', 200.0)
            ->assertJsonPath('data.bill_outstanding', 300.0);
    }

    // ═══ HELPERS ═══════════════════════════════════════════════════════════════

    private function applyRequest(string $billUuid, float $amount, ?string $key): Request
    {
        $request = Request::create("/finance/ap/bills/{$billUuid}/apply-advance", 'POST', ['amount' => $amount]);
        $request->setUserResolver(fn () => $this->actingUser);

        if ($key !== null) {
            $request->headers->set('Idempotency-Key', $key);
        }

        return $request;
    }

    private function supplierWithAdvance(float $amount): string
    {
        $supplier = (string) Str::uuid();
        app(SupplierOpeningBalanceService::class)->postOpeningAdvance(
            $this->companyId, $supplier, 'SUP-'.$this->suffix(), $amount, Carbon::today(), null, null, 1,
        );

        return $supplier;
    }

    private function postedBill(string $supplierId, float $amount): SupplierBill
    {
        return $this->postedBillFor($this->companyId, $supplierId, $amount);
    }

    private function postedBillFor(string $companyId, string $supplierId, float $amount): SupplierBill
    {
        $bill = app(AccountsPayableService::class)->createDocument(
            companyId: $companyId,
            supplierId: $supplierId,
            number: 'BILL-'.$this->suffix(),
            documentDate: Carbon::today(),
            lines: [['expense_account_id' => (int) $this->expenseAccountFor($companyId)->id, 'net_amount' => $amount]],
            type: SupplierDocumentType::Bill,
            dueDate: Carbon::today(),
        );

        return app(AccountsPayableService::class)->postDocument($bill);
    }

    private function expenseAccountFor(string $companyId): Account
    {
        return app(ChartOfAccountsService::class)->create([
            'company_id' => $companyId,
            'code' => 'E-'.$this->suffix(),
            'name' => 'Expense account',
            'account_type' => AccountType::Expense,
            'is_postable' => true,
        ]);
    }

    /** A user holding exactly the given finance permissions (mirrors FinanceApiTest::userWith()). */
    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['company_id' => $this->companyId]);
        $role = Role::create([
            'name' => 'Fin '.$this->suffix(),
            'slug' => 'fin-'.$this->suffix(),
            'is_system' => false,
        ]);
        $ids = Permission::whereIn('name', $permissions)->pluck('id');
        $role->permissions()->attach($ids);
        $user->roles()->attach($role->id);

        return $user;
    }

    private function suffix(): string
    {
        return substr(md5(uniqid('', true)), 0, 8);
    }

    private function openPeriodForToday(): FiscalPeriod
    {
        return $this->openPeriodForCompany($this->companyId);
    }

    private function openPeriodForCompany(string $companyId): FiscalPeriod
    {
        $start = Carbon::today()->subMonths(3)->startOfMonth();
        $year = app(FiscalCalendarService::class)->createYear(
            $companyId, 'FY-'.$this->suffix(), $start, $start->copy()->addMonths(11)->endOfMonth(),
        );

        foreach ($year->periods as $period) {
            if ($period->status->value !== 'open') {
                app(FiscalCalendarService::class)->openPeriod($period);
            }
        }

        return $year->periods()->where('period_number', 1)->firstOrFail();
    }
}
