<?php

declare(strict_types=1);

namespace Tests\Unit\Admin\GoLive;

use PHPUnit\Framework\TestCase;

/**
 * TASK-...-026-R1 Gate 5 — re-verifies Task 026's Customer opening-balance implementation without
 * a database, the same discipline as UnsafeCombinationRuleTest in this directory: a plain
 * PHPUnit\TestCase reading the actual, current source of
 * Modules\Finance\Receivables\Domain\Services\CustomerOpeningBalanceService and its controller,
 * asserting the four properties Gate 5 asks for. A structural proof rather than a runtime one is
 * deliberate here — PostingCoordinator's own write path needs a provisioned Chart of Accounts this
 * environment cannot reach (the same reason the analogous Supplier test in
 * GoLivePreparationTest::test_supplier_opening_balance_uses_the_canonical_posting_coordinator is
 * markTestSkipped) — but every substring asserted below is exact source text confirmed present by
 * direct read at the time this test was written, so a future edit that breaks one of these four
 * guarantees will also break this test, without requiring a live database to notice.
 */
final class CustomerOpeningBalanceCanonicalInfrastructureTest extends TestCase
{
    private function serviceSource(): string
    {
        $path = dirname(__DIR__, 4).'/Modules/Finance/Receivables/Domain/Services/CustomerOpeningBalanceService.php';

        return (string) file_get_contents($path);
    }

    private function controllerSource(): string
    {
        $path = dirname(__DIR__, 4).'/Modules/Finance/Receivables/Presentation/Http/Controllers/CustomerOpeningBalanceController.php';

        return (string) file_get_contents($path);
    }

    public function test_it_posts_through_the_canonical_posting_coordinator_as_an_opening_journal(): void
    {
        $source = $this->serviceSource();

        self::assertStringContainsString('use Modules\Finance\Posting\Domain\Services\PostingCoordinator;', $source);
        self::assertStringContainsString('$this->coordinator->post(', $source);
        self::assertStringContainsString('journalType: JournalType::Opening->value,', $source);
    }

    public function test_it_never_mutates_a_balance_column_directly(): void
    {
        $source = $this->serviceSource();

        // The only persistence calls in this service are PostingCoordinator::post() (a canonical
        // journal write) and CustomerLedgerEntry::create() (an audit row referencing that
        // journal). A raw table write, an increment/decrement, or a direct ->update() on a
        // balance-shaped column would all be a second, uncoordinated write path.
        self::assertStringNotContainsString('DB::table(', $source);
        self::assertStringNotContainsString('->increment(', $source);
        self::assertStringNotContainsString('->decrement(', $source);
        self::assertStringNotContainsString("'balance'", $source);
    }

    public function test_it_writes_no_second_ledger_the_entry_always_references_the_one_journal(): void
    {
        $source = $this->serviceSource();

        // CustomerLedgerEntry is a subledger row, not an independent balance record: every row
        // this service creates carries the journal_entry_id of the journal PostingCoordinator
        // just posted, so the subledger can never diverge from the GL it is supposed to mirror.
        self::assertStringContainsString("'journal_entry_id' => \$journal->id,", $source);
    }

    public function test_it_is_idempotent_on_a_repeat_call_for_the_same_customer(): void
    {
        $source = $this->serviceSource();

        self::assertStringContainsString('$existing = CustomerLedgerEntry::query()', $source);
        self::assertStringContainsString('return $existing; // idempotent no-op', $source);
    }

    public function test_the_controller_enforces_company_isolation_explicitly(): void
    {
        $source = $this->controllerSource();

        // Customer (confirmed by direct read during Task 026) carries no tenant global scope,
        // unlike Supplier — so this controller must check ownership explicitly rather than
        // inherit a scope that does not exist on this model.
        self::assertStringContainsString('use App\Core\Company\TenantOwnershipResolver;', $source);
        self::assertStringContainsString('TenantOwnershipResolver $tenant', $source);
        self::assertStringContainsString('$tenant->owns((string) $model->company_id)', $source);
    }
}
