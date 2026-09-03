<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ECOS-COMMERCE-CUSTOMERS-BATCH-02-BLOCKED-CUSTOMERS-009.
 *
 * The one-order override authority (§25-§27). One row per Order — `order_id` is
 * UNIQUE, which is both the record of the grant (actor/reason/timestamp/block
 * reference, §25) AND the concurrency-safe "does this Order have an approved
 * active override" read (§32/§39-C): a second concurrent grant attempt for the
 * same Order loses the race on the unique index rather than racing an
 * application-level exists() check, so it can never create conflicting
 * effective-override state.
 *
 * Deliberately does not reference `orders` with a foreign key — this module's
 * own tables (customers, customer_code_sequences) do not FK into other modules'
 * tables either; tenant/entity integrity here is enforced by the application
 * layer that writes it (OverrideOrderBlockAction), matching local convention.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('order_block_overrides')) {
            return;
        }

        Schema::create('order_block_overrides', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('order_id')->unique();
            $table->uuid('company_id');
            $table->uuid('customer_block_id')->nullable();
            $table->uuid('granted_by')->nullable();
            $table->text('reason');
            $table->timestamp('granted_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'customer_block_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_block_overrides');
    }
};
