<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Finance\Payables\Domain\Enums\SupplierLedgerEntryType;
use Modules\Finance\Payables\Domain\Models\SupplierLedgerEntry;
use Modules\Finance\Presentation\Http\Controllers\SupplierLedgerController;
use Modules\Finance\Receivables\Domain\Enums\CustomerLedgerEntryType;
use Modules\Finance\Receivables\Domain\Models\CustomerLedgerEntry;
use Modules\Finance\Presentation\Http\Controllers\CustomerLedgerController;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-CUSTOMER-SUPPLIER-LEDGER-LINKS-CLOSURE-001.
 *
 * This task adds no new backend code — it wires Customer 360 / Supplier 360 to the
 * pre-existing CustomerLedgerController / SupplierLedgerController. What it proves
 * here is that the destination those new links point at is safe to link to: the
 * SAME authenticated-user's-own-company scoping (ResolvesFinanceContext::companyId(),
 * never a client-suppliable value) that already governs every other Finance read
 * also governs these two controllers, for both the read model's own resolved entity
 * and a cross-company id. No production code is exercised here beyond what already
 * shipped; this is verification of existing authority, not new authority.
 */
class CustomerSupplierLedgerTenantScopingTest extends TestCase
{
    use DatabaseTransactions;

    // ── Customer / AR ────────────────────────────────────────────────────────────

    public function test_customer_balance_resolves_the_correct_scoped_entity(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => (string) $company->id]);
        $customerId = (string) Str::uuid();

        CustomerLedgerEntry::create([
            'company_id' => (string) $company->id,
            'customer_id' => $customerId,
            'entry_date' => Carbon::today(),
            'entry_type' => CustomerLedgerEntryType::Invoice,
            'amount' => 750.0,
            'description' => 'test invoice',
        ]);

        $response = app(CustomerLedgerController::class)->balance(
            $this->requestAs($user),
            $customerId,
        );

        $data = json_decode((string) $response->getContent(), true)['data'];
        $this->assertSame($customerId, $data['customer_id']);
        $this->assertSame(750.0, $data['balance']);
    }

    public function test_customer_balance_rejects_cross_company_access(): void
    {
        $ownerCompany = Company::factory()->create();
        $customerId = (string) Str::uuid();
        CustomerLedgerEntry::create([
            'company_id' => (string) $ownerCompany->id,
            'customer_id' => $customerId,
            'entry_date' => Carbon::today(),
            'entry_type' => CustomerLedgerEntryType::Invoice,
            'amount' => 750.0,
            'description' => 'owner company invoice',
        ]);

        $otherCompany = Company::factory()->create();
        $otherUser = User::factory()->create(['company_id' => (string) $otherCompany->id]);

        // The other company's user requests the SAME customer id — the query is
        // scoped by the requester's own company (never a client-suppliable value),
        // so this can only ever see zero, never the owner company's real balance.
        $response = app(CustomerLedgerController::class)->balance(
            $this->requestAs($otherUser),
            $customerId,
        );

        $data = json_decode((string) $response->getContent(), true)['data'];
        $this->assertSame(0.0, $data['balance']);

        $history = app(CustomerLedgerController::class)->history($this->requestAs($otherUser), $customerId);
        $this->assertSame([], json_decode((string) $history->getContent(), true)['data']['lines'] ?? []);
    }

    // ── Supplier / AP ─────────────────────────────────────────────────────────────

    public function test_supplier_balance_resolves_the_correct_scoped_entity(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => (string) $company->id]);
        $supplierId = (string) Str::uuid();

        SupplierLedgerEntry::create([
            'company_id' => (string) $company->id,
            'supplier_id' => $supplierId,
            'entry_date' => Carbon::today(),
            'entry_type' => SupplierLedgerEntryType::Bill,
            'amount' => 400.0,
            'description' => 'test bill',
        ]);

        $response = app(SupplierLedgerController::class)->balance(
            $this->requestAs($user),
            $supplierId,
        );

        $data = json_decode((string) $response->getContent(), true)['data'];
        $this->assertSame($supplierId, $data['supplier_id']);
        $this->assertSame(400.0, $data['balance']);
    }

    public function test_supplier_balance_rejects_cross_company_access(): void
    {
        $ownerCompany = Company::factory()->create();
        $supplierId = (string) Str::uuid();
        SupplierLedgerEntry::create([
            'company_id' => (string) $ownerCompany->id,
            'supplier_id' => $supplierId,
            'entry_date' => Carbon::today(),
            'entry_type' => SupplierLedgerEntryType::Bill,
            'amount' => 400.0,
            'description' => 'owner company bill',
        ]);

        $otherCompany = Company::factory()->create();
        $otherUser = User::factory()->create(['company_id' => (string) $otherCompany->id]);

        $response = app(SupplierLedgerController::class)->balance(
            $this->requestAs($otherUser),
            $supplierId,
        );

        $data = json_decode((string) $response->getContent(), true)['data'];
        $this->assertSame(0.0, $data['balance']);
    }

    // ═══ HELPERS ═══════════════════════════════════════════════════════════════

    private function requestAs(User $user): Request
    {
        $request = Request::create('/', 'GET');
        $request->setUserResolver(fn () => $user);

        return $request;
    }
}
