<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Finance\Fiscal\Domain\Services\FiscalCalendarService;
use Modules\Finance\Ledger\Domain\Enums\AccountType;
use Modules\Finance\Ledger\Domain\Models\Account;
use Modules\Finance\Payables\Domain\Enums\SupplierDocumentType;
use Modules\Finance\Payables\Domain\Services\AccountsPayableService;
use Modules\Finance\Payables\Domain\Services\SupplierLedgerService;
use Modules\Finance\Payables\Domain\Services\SupplierOpeningBalanceService;
use Modules\Finance\Shared\Domain\Services\CompanyFinanceProvisioner;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-PROC-SUPPLIER-OPENING-BALANCE-001 — approved-contract proof (A–H).
 *
 * The two opening types post through the canonical Finance engine (JournalType::Opening,
 * ledger-derived, idempotent) against the real coded accounts the provisioner seeds — AP control
 * 2110, Supplier Advances 1520, and the new Opening Balance Equity 3600. Payable and Advance are
 * kept in separate display buckets. No Purchase/PO/GR/Inventory is created.
 */
class SupplierOpeningBalanceTest extends TestCase
{
    use DatabaseTransactions;

    private string $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = (string) Company::factory()->create()->id;
        app(CompanyFinanceProvisioner::class)->provision($this->companyId); // seeds CoA incl 3600
        $this->openAllPeriods();
    }

    private function service(): SupplierOpeningBalanceService
    {
        return app(SupplierOpeningBalanceService::class);
    }

    private function ledger(): SupplierLedgerService
    {
        return app(SupplierLedgerService::class);
    }

    // ── The dedicated 3600 account is provisioned ────────────────────────────

    public function test_opening_balance_equity_3600_is_provisioned(): void
    {
        $account = Account::query()->where('company_id', $this->companyId)->where('code', '3600')->first();

        $this->assertNotNull($account, '3600 Opening Balance Equity must be seeded by the provisioner.');
        $this->assertSame('equity', $account->account_type->value);
        $this->assertTrue((bool) $account->is_postable);
        $this->assertFalse((bool) $account->is_control);
    }

    // ── A/B — opening payable increases AP + shows in outstanding + statement ─

    public function test_opening_payable_increases_outstanding_and_writes_an_opening_journal(): void
    {
        $supplier = (string) Str::uuid();

        $entry = $this->service()->postOpeningPayable(
            $this->companyId, $supplier, 'SUP-A', 50000.0, Carbon::today(), 'OB-A', 'pre-ECOS debt', 1,
        );

        $this->assertNotNull($entry->journal_entry_id);
        $this->assertSame('opening_payable', $entry->entry_type->value);
        $this->assertSame(50000.0, $this->ledger()->outstandingPayable($this->companyId, $supplier));
        $this->assertSame(0.0, $this->ledger()->availableAdvance($this->companyId, $supplier));

        // The statement carries the movement with its journal reference.
        $statement = $this->ledger()->statement($this->companyId, $supplier, Carbon::today()->subYear(), Carbon::today()->addDay());
        $this->assertSame(50000.0, $statement['closing_balance']);
        $this->assertNotEmpty($statement['movements']);
    }

    // ── E/G — advance is a separate Available Advance, never a debt ───────────

    public function test_opening_advance_is_available_advance_and_not_a_payable(): void
    {
        $supplier = (string) Str::uuid();

        $this->service()->postOpeningAdvance(
            $this->companyId, $supplier, 'SUP-B', 30000.0, Carbon::today(), 'OA-B', 'prepaid to supplier', 1,
        );

        // Shown SEPARATELY as an available advance …
        $this->assertSame(30000.0, $this->ledger()->availableAdvance($this->companyId, $supplier));
        // … and NEVER inside the Outstanding Payable figure (not a debt).
        $this->assertSame(0.0, $this->ledger()->outstandingPayable($this->companyId, $supplier));
        // Net position reflects the prepaid credit.
        $this->assertSame(-30000.0, $this->ledger()->balance($this->companyId, $supplier));
    }

    // ── C — idempotent: re-posting never double-counts ───────────────────────

    public function test_opening_payable_is_idempotent(): void
    {
        $supplier = (string) Str::uuid();

        $this->service()->postOpeningPayable($this->companyId, $supplier, 'SUP-C', 50000.0, Carbon::today(), null, null, 1);
        $this->service()->postOpeningPayable($this->companyId, $supplier, 'SUP-C', 50000.0, Carbon::today(), null, null, 1);

        $this->assertSame(50000.0, $this->ledger()->outstandingPayable($this->companyId, $supplier));
        $this->assertSame(1, DB::table('finance_supplier_ledger_entries')
            ->where('supplier_id', $supplier)->where('entry_type', 'opening_payable')->count());
    }

    // ── D — no inventory / purchase / GR side effects ────────────────────────

    public function test_opening_balance_touches_no_inventory_or_purchase(): void
    {
        $supplier = (string) Str::uuid();

        $before = $this->inventoryFootprint();
        $this->service()->postOpeningPayable($this->companyId, $supplier, 'SUP-D', 12000.0, Carbon::today(), null, null, 1);
        $this->service()->postOpeningAdvance($this->companyId, $supplier, 'SUP-D', 8000.0, Carbon::today(), null, null, 1);
        $after = $this->inventoryFootprint();

        $this->assertSame($before, $after, 'Opening balances must create no purchase/GR/stock rows.');
    }

    // ── F — advance settles against a posted bill (reduces both) ─────────────

    public function test_advance_settles_against_a_posted_bill(): void
    {
        $supplier = (string) Str::uuid();
        $expense = $this->postableAccount(AccountType::Expense);

        // Available advance 30,000.
        $this->service()->postOpeningAdvance($this->companyId, $supplier, 'SUP-F', 30000.0, Carbon::today(), null, null, 1);

        // A real posted bill of 20,000 (via the canonical AP service).
        $bill = app(AccountsPayableService::class)->createDocument(
            companyId: $this->companyId, supplierId: $supplier, number: 'BILL-'.substr(md5(uniqid()), 0, 6),
            documentDate: Carbon::today(), lines: [['expense_account_id' => (int) $expense->id, 'net_amount' => 20000.0]],
            type: SupplierDocumentType::Bill, dueDate: Carbon::today(),
        );
        app(AccountsPayableService::class)->postDocument($bill);

        $this->assertSame(20000.0, $this->ledger()->outstandingPayable($this->companyId, $supplier));

        // Apply 20,000 of the advance to the bill.
        $this->service()->applyAdvanceToBill($bill->fresh(), 20000.0, 1);

        // Payable cleared; advance reduced to 10,000 — both derived from the ledger.
        $this->assertSame(0.0, $this->ledger()->outstandingPayable($this->companyId, $supplier));
        $this->assertSame(10000.0, $this->ledger()->availableAdvance($this->companyId, $supplier));
    }

    public function test_over_applying_an_advance_is_refused(): void
    {
        $supplier = (string) Str::uuid();
        $expense = $this->postableAccount(AccountType::Expense);
        $this->service()->postOpeningAdvance($this->companyId, $supplier, 'SUP-OV', 5000.0, Carbon::today(), null, null, 1);

        $bill = app(AccountsPayableService::class)->createDocument(
            companyId: $this->companyId, supplierId: $supplier, number: 'BILL-'.substr(md5(uniqid()), 0, 6),
            documentDate: Carbon::today(), lines: [['expense_account_id' => (int) $expense->id, 'net_amount' => 20000.0]],
            type: SupplierDocumentType::Bill, dueDate: Carbon::today(),
        );
        app(AccountsPayableService::class)->postDocument($bill);

        $this->expectException(\RuntimeException::class);
        $this->service()->applyAdvanceToBill($bill->fresh(), 6000.0, 1); // > 5000 available
    }

    public function test_opening_amount_must_be_positive(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service()->postOpeningPayable($this->companyId, (string) Str::uuid(), 'SUP-Z', 0.0, Carbon::today(), null, null, 1);
    }

    // ── Tenant isolation — the supplier scope fails closed cross-company ──────

    public function test_supplier_is_invisible_to_another_company(): void
    {
        if (! Schema::hasColumn('suppliers', 'company_id')) {
            $this->markTestSkipped('suppliers.company_id not present in this schema snapshot.');
        }

        $otherCompany = (string) Company::factory()->create()->id;
        $supplierId = (string) Str::uuid();
        DB::table('suppliers')->insert([
            'id' => $supplierId, 'company_id' => $this->companyId, 'code' => 'SUP-T'.substr(md5(uniqid()), 0, 4),
            'name' => 'Scoped Supplier', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // A NON-system actor from another company: the Supplier tenant global scope excludes the
        // row entirely, so the endpoint's findOrFail 404s (fail closed). actingAsUnprivileged is
        // required because the base actingAs auto-grants a system role that bypasses the scope.
        $intruder = User::factory()->create(['company_id' => $otherCompany]);
        $this->actingAsUnprivileged($intruder);

        $this->assertNull(
            \Modules\Purchasing\Suppliers\Domain\Models\Supplier::query()->find($supplierId),
            'A supplier owned by company A must be invisible to a company-B actor.',
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function openAllPeriods(): void
    {
        $start = Carbon::today()->subMonths(3)->startOfMonth();
        $year = app(FiscalCalendarService::class)->createYear(
            $this->companyId, 'FY-'.substr(md5(uniqid()), 0, 6), $start, $start->copy()->addMonths(11)->endOfMonth(),
        );
        foreach ($year->periods as $period) {
            if ($period->status->value !== 'open') {
                app(FiscalCalendarService::class)->openPeriod($period);
            }
        }
    }

    private function postableAccount(AccountType $type): Account
    {
        return app(\Modules\Finance\Ledger\Domain\Services\ChartOfAccountsService::class)->create([
            'company_id' => $this->companyId,
            'code' => strtoupper($type->value[0]).'-'.substr(md5(uniqid()), 0, 6),
            'name' => ucfirst($type->value).' account',
            'account_type' => $type,
            'is_postable' => true,
        ]);
    }

    /** @return array{purchase_materials:int, goods_receipts:int, stock:int} */
    private function inventoryFootprint(): array
    {
        return [
            'purchase_materials' => Schema::hasTable('purchase_materials') ? DB::table('purchase_materials')->count() : 0,
            'goods_receipts' => Schema::hasTable('goods_receipts') ? DB::table('goods_receipts')->count() : 0,
            'stock' => Schema::hasTable('stock_ledger_entries') ? DB::table('stock_ledger_entries')->count() : 0,
        ];
    }
}
