<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Finance OS — EPIC F3. Global posting-rule template for approved payroll
 * (TASK-ECOS-FIN-03-PAYROLL-FINANCE-POSTING-CLOSURE-001).
 *
 * ┌─ WHY FIVE LEGS, AND WHY THEY ALWAYS BALANCE ────────────────────────────┐
 * │ HR's own formula is net = basic + bonus + commission − advances − approved │
 * │ deductions (Modules\Hr\Compensation\Domain\Services\CompensationCalculator).│
 * │ Summed across a run:                                                       │
 * │                                                                            │
 * │   Dr salaries (basic+bonus) + Dr commission        = total gross           │
 * │   Cr net_payable + Cr deductions + Cr advances      = total gross           │
 * │                                                                            │
 * │ — the same identity, read as debits on one side and credits on the other.  │
 * │ No leg is invented beyond what that identity already requires.             │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * Deliberately NOT included: an employer-contribution/social-insurance leg.
 * 2330/2340 exist in the chart, but the approved PayrollRun/Payslip fact this
 * rule reads carries no employer-contribution component to post from — adding
 * a role or a leg for it here would be inventing an amount, not posting one.
 */
return new class extends Migration
{
    /** @return array<int, array{side:string, role:string, source:string}> */
    private function legs(): array
    {
        return [
            ['side' => 'debit', 'role' => 'salaries_expense', 'source' => 'salaries'],
            ['side' => 'debit', 'role' => 'commission_expense', 'source' => 'commission'],
            ['side' => 'credit', 'role' => 'salaries_payable', 'source' => 'net_payable'],
            ['side' => 'credit', 'role' => 'employee_deductions_payable', 'source' => 'deductions'],
            ['side' => 'credit', 'role' => 'employee_advance_receivable', 'source' => 'advances'],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('finance_posting_rules')) {
            return;
        }

        $code = 'hr.payroll_approved';

        $exists = DB::table('finance_posting_rules')
            ->where('code', $code)
            ->whereNull('company_id')
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('finance_posting_rules')->insert([
            'uuid' => (string) Str::uuid(),
            'company_id' => null,
            'code' => $code,
            'event_type' => $code,
            'description' => 'F3 global template — '.$code,
            'legs' => json_encode($this->legs()),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('finance_posting_rules')) {
            return;
        }

        DB::table('finance_posting_rules')
            ->whereNull('company_id')
            ->where('code', 'hr.payroll_approved')
            ->delete();
    }
};
