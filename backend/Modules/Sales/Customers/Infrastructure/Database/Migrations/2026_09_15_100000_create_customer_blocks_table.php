<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ECOS-COMMERCE-CUSTOMERS-BATCH-02-BLOCKED-CUSTOMERS-009.
 *
 * The canonical Blocked Customer / Blocked Phone authority. Phone-first by design
 * (§4): `customer_id` is nullable because a phone may be blocked before any
 * Customer record exists for it (§4/§10/§12) — the match key that survives that
 * case is `normalized_phone`, never the Customer id.
 *
 * One row per block EPISODE (block -> optional unblock), not a mutable boolean:
 * a re-block after an unblock inserts a NEW row, so the full block/unblock history
 * for a company+phone is simply every row for that pair ordered by blocked_at —
 * no separate history table is needed for BLOCKED/UNBLOCKED events (§6).
 *
 * CONCURRENCY (§9/§39-A): `active_phone_key` is a STORED generated column that
 * collapses to `company_id:normalized_phone` while `is_active = 1` and to NULL
 * otherwise, with a UNIQUE index on it. MySQL treats multiple NULLs in a unique
 * index as distinct (the same behaviour `customers_company_phone_unique` already
 * relies on), so any number of INACTIVE rows may coexist for the same phone, but
 * at most one ACTIVE row ever can — enforced by InnoDB itself, not by an
 * application-level exists() check (§50). Two concurrent "block this phone"
 * transactions therefore cannot both insert an active row: the loser's INSERT
 * fails with a duplicate-key error (MySQL 1062), which the calling action treats
 * as "already blocked" rather than a hard failure.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_blocks')) {
            return;
        }

        Schema::create('customer_blocks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('customer_id')->nullable();
            $table->string('normalized_phone', 32);
            $table->boolean('is_active')->default(true);
            $table->string('active_phone_key', 69)
                ->nullable()
                ->storedAs("CASE WHEN is_active = 1 THEN CONCAT(company_id, ':', normalized_phone) ELSE NULL END")
                ->unique('customer_blocks_active_phone_unique');

            $table->text('block_reason');
            $table->uuid('blocked_by')->nullable();
            $table->timestamp('blocked_at');

            $table->text('unblock_reason')->nullable();
            $table->uuid('unblocked_by')->nullable();
            $table->timestamp('unblocked_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'normalized_phone']);
            $table->index(['company_id', 'customer_id']);
            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_blocks');
    }
};
