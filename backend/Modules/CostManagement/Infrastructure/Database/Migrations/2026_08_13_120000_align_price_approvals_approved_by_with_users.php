<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-PRICE-REVIEW-ACTION-REPAIR-001 — approver identity contract.
 *
 * `price_approvals.approved_by` was created as `uuid` (char(36)) by
 * 2026_07_06_140002_add_audit_fields_to_price_approvals, on the assumption of a
 * UUID user key. ECOS deliberately keeps the bigint User primary key (ADR-040),
 * so the audit column could never hold a real `users.id` — the service signature
 * (`?string $approverId`) took an int and raised a TypeError under strict_types,
 * which is why no approval has ever been written.
 *
 * The canonical convention for an actor column that references `users.id` is
 * already established across the newer modules — `unsignedBigInteger` with a
 * foreign key to `users`, nullable, `nullOnDelete`. Direct precedents with the
 * identical column name and identical approval-audit purpose:
 *
 *   supplier_returns.approved_by        bigint unsigned  FK -> users.id
 *   finance_journal_entries.approved_by bigint unsigned
 *   finance_supplier_bills.approved_by  bigint unsigned
 *   hr_payroll_runs.approved_by         bigint unsigned
 *   fleet_inspections.approved_by       bigint unsigned
 *
 * This migration aligns price_approvals with that convention. No new identity
 * mechanism is introduced and no other table is touched.
 *
 * Data safety: `price_approvals` is empty in every environment inspected
 * (ecos_dev 0 rows, ecos_erp 0 rows) because the TypeError meant a row was never
 * successfully written. The pre-step below is nevertheless defensive — numeric
 * values are preserved, and only values that cannot represent a bigint user key
 * are nulled. Nothing is deleted; no historical row is rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('price_approvals') || ! Schema::hasColumn('price_approvals', 'approved_by')) {
            return;
        }

        // Already aligned (re-run, or a fresh install created it correctly).
        if ($this->approvedByIsInteger()) {
            $this->addForeignKey();

            return;
        }

        // Preserve anything that can legitimately become a bigint user key; null
        // only values that cannot (legacy UUID-shaped writes from the previous
        // contract). Non-destructive: no row is removed.
        $this->nullNonNumericApprovers();

        Schema::table('price_approvals', function (Blueprint $table): void {
            $table->unsignedBigInteger('approved_by')->nullable()->change();
        });

        $this->addForeignKey();
    }

    public function down(): void
    {
        if (! Schema::hasTable('price_approvals') || ! Schema::hasColumn('price_approvals', 'approved_by')) {
            return;
        }

        $this->dropForeignKey();

        Schema::table('price_approvals', function (Blueprint $table): void {
            $table->uuid('approved_by')->nullable()->change();
        });
    }

    private function approvedByIsInteger(): bool
    {
        $type = Schema::getColumnType('price_approvals', 'approved_by');

        return in_array($type, ['bigint', 'integer', 'int'], true);
    }

    /**
     * `users.id` is bigint unsigned, so only an all-digit value can survive the
     * type change. Everything else becomes NULL rather than blocking the
     * migration or being silently coerced to 0.
     */
    private function nullNonNumericApprovers(): void
    {
        $driver = DB::connection()->getDriverName();

        $predicate = match ($driver) {
            'pgsql' => "approved_by !~ '^[0-9]+$'",
            'sqlite' => "CAST(approved_by AS INTEGER) = 0 AND approved_by <> '0'",
            default => "approved_by NOT REGEXP '^[0-9]+$'",
        };

        DB::statement("UPDATE price_approvals SET approved_by = NULL WHERE approved_by IS NOT NULL AND {$predicate}");
    }

    private function addForeignKey(): void
    {
        if ($this->foreignKeyExists()) {
            return;
        }

        Schema::table('price_approvals', function (Blueprint $table): void {
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function dropForeignKey(): void
    {
        if (! $this->foreignKeyExists()) {
            return;
        }

        Schema::table('price_approvals', function (Blueprint $table): void {
            $table->dropForeign(['approved_by']);
        });
    }

    private function foreignKeyExists(): bool
    {
        foreach (Schema::getForeignKeys('price_approvals') as $foreignKey) {
            if (in_array('approved_by', $foreignKey['columns'] ?? [], true)) {
                return true;
            }
        }

        return false;
    }
};
