<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\Fiscal\Domain\Services\FiscalCalendarService;
use Modules\Finance\Integration\Domain\Services\CommercialAccountingService;
use Modules\Finance\Ledger\Domain\Enums\AccountType;
use Modules\Finance\Ledger\Domain\Models\Account;
use Modules\Finance\Receivables\Domain\Models\CustomerReceipt;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-FINANCE-COMMERCIAL-ACCOUNTING-006 — COD collection settlement.
 * DISTINCT from delivery/revenue recognition (TASK §16/§17): recognizeRevenue
 * already created the receivable; recognizeCodCollection only settles it once
 * cash is actually collected, landing in the "Cash in Transit" clearing role
 * (cod_clearing) — never booked as bank/cash received at delivery time.
 * Covers TASK §33 items 25-31.
 */
class CommercialAccountingCodTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;

    private string $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->companyId = (string) $this->company->id;
        $this->openPeriodForCompany($this->companyId);
        $this->controlAccount('ar', AccountType::Asset);
    }

    // 25 & 26. Delivery alone (recognizeRevenue) does not record cash
    // received — collection is a separate event/call entirely.
    public function test_revenue_recognition_alone_does_not_record_cash_collection(): void
    {
        $this->seedRole('sales_revenue', AccountType::Revenue);
        $orderId = (string) Str::uuid();

        app(CommercialAccountingService::class)->recognizeRevenue(
            $this->companyId, $orderId, 'ORD-COD-1', (string) Str::uuid(), 400.0, 0.0, Carbon::today(), null,
        );

        $this->assertSame(0, CustomerReceipt::query()->where('company_id', $this->companyId)->count());
    }

    // 27 & 29 & 30 & 31. A canonical COD collection settles the AR through
    // the existing CustomerReceipt + AllocationEngine + JournalEngine
    // authority, using the cod_clearing role mapping (Cash in Transit) — no
    // bespoke Commerce/Shipping GL writer exists to bypass this.
    public function test_cod_collection_settles_ar_through_canonical_receipt_and_allocation(): void
    {
        $this->seedRole('sales_revenue', AccountType::Revenue);
        $codClearing = $this->seedRole('cod_clearing', AccountType::Asset);
        $orderId = (string) Str::uuid();
        $customerId = (string) Str::uuid();

        $service = app(CommercialAccountingService::class);
        $invoice = $service->recognizeRevenue($this->companyId, $orderId, 'ORD-COD-2', $customerId, 400.0, 0.0, Carbon::today(), null);

        $codRecordId = (string) Str::uuid();
        $receipt = $service->recognizeCodCollection($this->companyId, $orderId, $customerId, $codRecordId, 400.0, Carbon::today(), null);

        $this->assertNotNull($receipt);
        $this->assertTrue($receipt->isPosted());
        $this->assertSame($codClearing->id, (int) $receipt->deposit_account_id);
        $this->assertSame(0.0, round($invoice->fresh()->outstanding(), 4));
    }

    // 28. A duplicate collection event for the same CodRecord is idempotent —
    // no second receipt, no double allocation.
    public function test_duplicate_cod_collection_event_is_idempotent(): void
    {
        $this->seedRole('sales_revenue', AccountType::Revenue);
        $this->seedRole('cod_clearing', AccountType::Asset);
        $orderId = (string) Str::uuid();
        $customerId = (string) Str::uuid();
        $codRecordId = (string) Str::uuid();

        $service = app(CommercialAccountingService::class);
        $service->recognizeRevenue($this->companyId, $orderId, 'ORD-COD-3', $customerId, 400.0, 0.0, Carbon::today(), null);

        $first = $service->recognizeCodCollection($this->companyId, $orderId, $customerId, $codRecordId, 400.0, Carbon::today(), null);
        $second = $service->recognizeCodCollection($this->companyId, $orderId, $customerId, $codRecordId, 400.0, Carbon::today(), null);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, CustomerReceipt::query()->where('source_type', 'cod_record')->where('source_id', $codRecordId)->count());
    }

    // A COD receipt that arrives before (or without) a posted invoice still
    // posts safely — a legitimate on-account state — and is simply left
    // unallocated rather than erroring.
    public function test_cod_collection_without_a_posted_invoice_still_posts(): void
    {
        $this->seedRole('cod_clearing', AccountType::Asset);
        $orderId = (string) Str::uuid();

        $receipt = app(CommercialAccountingService::class)->recognizeCodCollection(
            $this->companyId, $orderId, (string) Str::uuid(), (string) Str::uuid(), 150.0, Carbon::today(), null,
        );

        $this->assertNotNull($receipt);
        $this->assertTrue($receipt->isPosted());
    }

    // ═══ HELPERS ═══════════════════════════════════════════════════════════════

    private function seedRole(string $role, AccountType $type): Account
    {
        $account = app(\Modules\Finance\Ledger\Domain\Services\ChartOfAccountsService::class)->create([
            'company_id' => $this->companyId,
            'code' => strtoupper(substr($role, 0, 3)).'-'.$this->suffix(),
            'name' => ucfirst(str_replace('_', ' ', $role)),
            'account_type' => $type,
            'is_postable' => true,
        ]);

        DB::table('finance_account_roles')->insert([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'role' => $role,
            'account_id' => $account->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $account;
    }

    private function controlAccount(string $subledger, AccountType $type): Account
    {
        return app(\Modules\Finance\Ledger\Domain\Services\ChartOfAccountsService::class)->create([
            'company_id' => $this->companyId,
            'code' => strtoupper($subledger).'-CTRL-'.$this->suffix(),
            'name' => strtoupper($subledger).' control',
            'account_type' => $type,
            'is_postable' => true,
            'is_control' => true,
            'control_subledger' => $subledger,
        ]);
    }

    private function suffix(): string
    {
        return substr(md5(uniqid('', true)), 0, 8);
    }

    private function openPeriodForCompany(string $companyId): void
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
    }
}
