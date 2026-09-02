<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Finance OS — TASK-ECOS-FINANCE-COMMERCIAL-ACCOUNTING-006.
 *
 * ┌─ COMPLETING AN EXISTING, DELIBERATELY-EMPTY CATALOG ENTRY ──────────────┐
 * │ BusinessEventType::DeliveryConfirmation ('shipping.delivery_confirmation') │
 * │ has existed since EPIC F3 as "no GL by default" — no rule was ever seeded  │
 * │ for it, so PostingRuleResolver::resolve() returns null and the event is     │
 * │ correctly recorded as skipped. This migration adds the ONE global template  │
 * │ the F3 engineering audit (TASK-ECOS-FINANCE-FULL-ACCOUNTING-RECONCILIATION- │
 * │ 005, FIN-EXEC-02) identified as missing: COGS recognised the moment a        │
 * │ commercial order is confirmed delivered. It follows the exact pattern of      │
 * │ 2026_08_18_100003_seed_finance_posting_rules.php (global template, company   │
 * │ override wins, existence-checked, additive).                                 │
 * │                                                                              │
 * │ Revenue/AR for the same event is NOT here — it is posted through the         │
 * │ existing F2 AccountsReceivableService (a real CustomerInvoice, not a bare     │
 * │ journal), by Modules\Finance\Integration\Domain\Services\                    │
 * │ CommercialAccountingService. This rule covers only the COGS leg, which is    │
 * │ pure GL (no subledger document), exactly what the F3 bridge is for.          │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
return new class extends Migration
{
    private const EVENT_CODE = 'shipping.delivery_confirmation';

    /** @return array<int, array{side:string, role:string, source:string}> */
    private function legs(): array
    {
        return [
            ['side' => 'debit', 'role' => 'cost_of_goods_sold', 'source' => 'cogs'],
            ['side' => 'credit', 'role' => 'finished_goods', 'source' => 'cogs'],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('finance_posting_rules')) {
            return;
        }

        $exists = DB::table('finance_posting_rules')
            ->where('code', self::EVENT_CODE)
            ->whereNull('company_id')
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('finance_posting_rules')->insert([
            'uuid' => (string) Str::uuid(),
            'company_id' => null,
            'code' => self::EVENT_CODE,
            'event_type' => self::EVENT_CODE,
            'description' => 'F3 global template — COGS on commercial delivery',
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
            ->where('code', self::EVENT_CODE)
            ->delete();
    }
};
