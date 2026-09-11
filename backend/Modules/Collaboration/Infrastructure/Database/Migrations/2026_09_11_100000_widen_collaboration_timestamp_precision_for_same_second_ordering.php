<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ECOS-V1-REMEDIATION-COLLABORATION-INTEGRITY-035D-R1 §3 — same-second message
 * polling/unread fix.
 *
 * `collaboration_conversation_participants.joined_at`/`last_read_at` compare directly against
 * `collaboration_messages.created_at` (see both tables' original migrations: "last_read_at drives
 * unread-count derivation directly against messages.created_at, so it works correctly independent
 * of UUID sortability"). That claim held only as long as two independent writes could never land
 * on the same stored instant — but `timestampTz()` with no precision argument defaults to
 * whole-SECOND MySQL `TIMESTAMP` columns, so a message created in the same wall-clock second as a
 * participant's `last_read_at`/`joined_at` write is stored as an EQUAL value, and both
 * `GetConversationForUserAction` and `ListConversationsForUserAction` compare with strict `>`,
 * silently excluding it from the unread count.
 *
 * FIX (unread-count side): widen precision to microseconds (MySQL 5.6.4+/8.x support up to 6),
 * matching what PHP's `now()` (Carbon) already produces at the application layer — this rounds
 * away less, not adds precision from nowhere. No comparison operator or read-model change; the
 * existing timestamp-cursor architecture now has enough resolution for its own stated intent to
 * actually hold. Two independent writes landing on the exact same microsecond is the same order of
 * residual risk this module's own `(created_at, id)` message-pagination note already accepted —
 * not a new, weaker guarantee.
 *
 * FIX (polling side): `GetConversationMessagesAction`'s `afterMessageId`/`beforeMessageId` cursor
 * now compares `id`, not `created_at` — see that class's own updated docblock for why
 * `collaboration_messages.id` (a UUIDv7) is both always-available (a cursor message is always
 * loaded first) and strictly finer-grained than even a microsecond timestamp. That change makes
 * the existing `(conversation_id, created_at, id)` index no longer serve this query's WHERE/ORDER
 * BY on `id` — this migration adds the `(conversation_id, id)` index that shape actually needs,
 * leaving the original index in place for anything still ordering by `created_at` for display.
 *
 * Raw DDL (`MODIFY`, not `->change()`): this project has no doctrine/dbal dependency, and `MODIFY`
 * needs no full column-definition restatement to preserve the columns' existing
 * nullability/indexes — the same pattern already used by, e.g.,
 * `Purchasing/GoodsReceipts/.../2026_08_21_100000_add_purchase_material_anchor_to_goods_receipt_lines.php`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('collaboration_messages')) {
            DB::statement('ALTER TABLE `collaboration_messages` MODIFY `created_at` TIMESTAMP(6) NOT NULL');

            if (! $this->indexExists('collaboration_messages', 'collaboration_messages_conversation_id_id_index')) {
                Schema::table('collaboration_messages', function (Blueprint $table): void {
                    $table->index(['conversation_id', 'id'], 'collaboration_messages_conversation_id_id_index');
                });
            }
        }

        if (Schema::hasTable('collaboration_conversation_participants')) {
            DB::statement('ALTER TABLE `collaboration_conversation_participants` MODIFY `joined_at` TIMESTAMP(6) NOT NULL');
            DB::statement('ALTER TABLE `collaboration_conversation_participants` MODIFY `last_read_at` TIMESTAMP(6) NULL');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('collaboration_conversation_participants')) {
            DB::statement('ALTER TABLE `collaboration_conversation_participants` MODIFY `last_read_at` TIMESTAMP NULL');
            DB::statement('ALTER TABLE `collaboration_conversation_participants` MODIFY `joined_at` TIMESTAMP NOT NULL');
        }

        if (Schema::hasTable('collaboration_messages')) {
            if ($this->indexExists('collaboration_messages', 'collaboration_messages_conversation_id_id_index')) {
                Schema::table('collaboration_messages', function (Blueprint $table): void {
                    $table->dropIndex('collaboration_messages_conversation_id_id_index');
                });
            }

            DB::statement('ALTER TABLE `collaboration_messages` MODIFY `created_at` TIMESTAMP NOT NULL');
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $indexName)
            ->exists();
    }
};
