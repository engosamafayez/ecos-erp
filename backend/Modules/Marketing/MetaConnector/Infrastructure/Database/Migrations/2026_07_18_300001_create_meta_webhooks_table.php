<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * IDEMPOTENT MIGRATION — safe to run in any of these states:
 *
 *  A) Table does not exist               → creates it normally.
 *  B) Table exists + FK present          → skips (already fully migrated).
 *  C) Table exists + FK missing + empty  → drops & recreates automatically.
 *     This is the typical local-dev corruption: a prior migration run created
 *     the table body before `marketing_connections` existed, then failed on the
 *     FK line. No manual SQL required in this case.
 *  D) Table exists + FK missing + has rows → adds only the missing FK.
 *     This covers a rare production scenario where data arrived before the FK
 *     could be enforced. No data is deleted.
 *
 * ONE-TIME MANUAL ACTION (state D only, local dev):
 *   If your local DB hit state D because you seeded `meta_webhooks` rows that
 *   point to non-existent connections, the FK add will fail with a constraint
 *   violation.  Run the following to clear stale rows, then re-run migrate:
 *
 *     DELETE FROM meta_webhooks
 *      WHERE marketing_connection_id NOT IN (SELECT id FROM marketing_connections);
 *     php artisan migrate
 *
 *   This situation cannot occur in CI (empty DB) or production (migrations run
 *   in order before seeding), so no automated recovery is needed there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('meta_webhooks')) {
            // current_schema() is PostgreSQL-standard (ECOS runs PostgreSQL-only).
            $hasFk = (bool) DB::selectOne(
                "SELECT COUNT(*) AS cnt
                   FROM information_schema.TABLE_CONSTRAINTS
                  WHERE CONSTRAINT_TYPE = 'FOREIGN KEY'
                    AND TABLE_SCHEMA    = current_schema()
                    AND TABLE_NAME      = 'meta_webhooks'
                    AND CONSTRAINT_NAME LIKE 'meta_webhooks_marketing_connection%'"
            )?->cnt;

            if ($hasFk) {
                return; // Already fully migrated — nothing to do.
            }

            // Corrupted state: table present but FK is absent.
            if (DB::table('meta_webhooks')->count() === 0) {
                // Empty table — safe to drop and recreate cleanly.
                Schema::drop('meta_webhooks');
            } else {
                // Data present — only add the missing FK (see header comment for D).
                Schema::table('meta_webhooks', static function (Blueprint $table): void {
                    $table->foreign('marketing_connection_id')
                        ->references('id')->on('marketing_connections')
                        ->onDelete('cascade');
                });
                return;
            }
        }

        Schema::create('meta_webhooks', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('marketing_connection_id')->index();

            $table->string('object_type', 50)->index();
            $table->string('object_id', 200)->nullable()->index();

            $table->string('callback_url', 1000);
            $table->text('verify_token');
            $table->json('subscribed_fields');

            $table->string('status', 30)->default('pending_verification')->index();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_delivery_at')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->timestamp('last_verified_at')->nullable();

            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('marketing_connection_id')
                ->references('id')->on('marketing_connections')
                ->onDelete('cascade');

            $table->unique(['marketing_connection_id', 'object_type', 'object_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_webhooks');
    }
};
