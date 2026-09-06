<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ECOS-NOTIFICATIONS-FOUNDATION-002 (ADR-047 §24).
 *
 * Extends the existing, real `notifications` table — never a second/parallel table.
 * `company_id` is a denormalized copy for direct tenant scoping (ADR-047 §19), matching
 * the established pattern on this codebase's other log/audit-like tables (`audit_logs`,
 * `timeline_events`, `documents`, `enterprise_events`) rather than the FK'd `users.company_id`
 * column — this table's rows outlive the pace of any single source module's own
 * company_id migration, per ADR-047 §19.
 *
 * `dedupe_key` is unique per-recipient only (ADR-047 §10): two different users may
 * legitimately share the same key, so uniqueness is composite with `notifiable_*`, not
 * global. NULL dedupe_key values never conflict with each other under this constraint
 * (standard SQL NULL semantics) — most notifications have none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            if (! Schema::hasColumn('notifications', 'company_id')) {
                $table->string('company_id', 36)->nullable()->after('notifiable_id')->index();
            }
            if (! Schema::hasColumn('notifications', 'priority')) {
                $table->string('priority', 20)->nullable()->after('data')->index();
            }
            if (! Schema::hasColumn('notifications', 'category')) {
                $table->string('category', 30)->nullable()->after('priority')->index();
            }
            if (! Schema::hasColumn('notifications', 'source_module')) {
                $table->string('source_module', 64)->nullable()->after('category')->index();
            }
            if (! Schema::hasColumn('notifications', 'deep_link')) {
                $table->json('deep_link')->nullable()->after('source_module');
            }
            if (! Schema::hasColumn('notifications', 'dedupe_key')) {
                $table->string('dedupe_key', 191)->nullable()->after('deep_link');
            }
            if (! Schema::hasColumn('notifications', 'group_key')) {
                $table->string('group_key', 191)->nullable()->after('dedupe_key')->index();
            }
            if (! Schema::hasColumn('notifications', 'expires_at')) {
                $table->timestamp('expires_at')->nullable()->after('group_key');
            }
            if (! Schema::hasColumn('notifications', 'dismissed_at')) {
                $table->timestamp('dismissed_at')->nullable()->after('expires_at');
            }
        });

        if (! $this->hasIndex('notifications', 'notifications_dedupe_unique')) {
            Schema::table('notifications', function (Blueprint $table): void {
                $table->unique(['notifiable_type', 'notifiable_id', 'dedupe_key'], 'notifications_dedupe_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            if ($this->hasIndex('notifications', 'notifications_dedupe_unique')) {
                $table->dropUnique('notifications_dedupe_unique');
            }

            foreach (['company_id', 'priority', 'category', 'source_module', 'deep_link', 'dedupe_key', 'group_key', 'expires_at', 'dismissed_at'] as $column) {
                if (Schema::hasColumn('notifications', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();

        return collect($connection->getSchemaBuilder()->getIndexes($table))
            ->contains(fn (array $index): bool => $index['name'] === $indexName);
    }
};
