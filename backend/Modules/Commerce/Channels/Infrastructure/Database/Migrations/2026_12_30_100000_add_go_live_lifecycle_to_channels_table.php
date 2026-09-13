<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ECOS-V1.1-WOO-04-GO-LIVE-LIFECYCLE — architecture authority 042A-R1 §5.
 *
 * `lifecycle_state` defaults to 'draft' for every channel, including existing rows: no channel
 * in this codebase has ever had an explicit go-live concept before this migration, and every
 * channel in this environment is already `connection_status = disconnected` — introducing this
 * gate makes nothing that was working today stop working, since nothing currently relies on a
 * "live" state that never existed. Only TransitionChannelToLiveAction ever writes 'live'.
 *
 * `customer_sync_policy` and `shipping_mapping_reviewed_at` back two of the seven go-live
 * readiness gates (§5) that have no other existing signal to read from — mirroring the
 * already-established `orders_initial_import_policy` column's own pattern (a nullable field,
 * explicitly set once by an operator, whose non-null-ness IS the "chosen"/"acknowledged" signal)
 * rather than inventing a new kind of column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', static function (Blueprint $table): void {
            $table->string('lifecycle_state', 20)->default('draft')->after('connection_status');
            $table->string('customer_sync_policy', 30)->nullable()->after('lifecycle_state');
            $table->timestamp('shipping_mapping_reviewed_at')->nullable()->after('customer_sync_policy');
        });
    }

    public function down(): void
    {
        Schema::table('channels', static function (Blueprint $table): void {
            $table->dropColumn(['lifecycle_state', 'customer_sync_policy', 'shipping_mapping_reviewed_at']);
        });
    }
};
