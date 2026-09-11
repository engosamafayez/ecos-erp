<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reconciliation for 2026_07_06_180000_migrate_channels_to_brand_ownership.
 *
 * That migration's guards were keyed on brand_id's presence, which is already
 * true the instant it runs (brand_id is added, nullable, by the migration
 * immediately before it) — so every step past the initial backfill silently
 * no-op'd, on every environment that ever ran it. company_id was never
 * actually dropped, brand_id was never made required, and the new
 * [brand_id, code] unique constraint was never added.
 *
 * That file is fixed going forward for installs that have never run it, but
 * an environment where it already ran (as a no-op) has it recorded in the
 * migrations table and will never run the fixed version. This migration is
 * the one that actually reaches those environments: idempotent, keyed on
 * real current state, safe to run whether the original left everything
 * undone, partially done, or (on a genuinely fresh install past the fix)
 * already fully done.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('channels', 'company_id')) {
            // Already fully migrated (fresh install via the fixed original
            // migration, or this reconciliation already ran here before).
            return;
        }

        // Defensive re-backfill: covers a channel created between the
        // original migration's backfill and this one, or a brand added to a
        // company after the original backfill already ran and missed it.
        DB::statement(
            'UPDATE channels SET brand_id = ('
            .' SELECT id FROM brands WHERE company_id = channels.company_id'
            .' ORDER BY created_at ASC LIMIT 1'
            .') WHERE brand_id IS NULL',
        );

        $unresolved = DB::table('channels')->whereNull('brand_id')->count();
        if ($unresolved > 0) {
            throw new RuntimeException(
                "reconcile_channels_company_id_drop: refusing to drop channels.company_id — {$unresolved} row(s) still have no brand_id (their company has no brand to backfill from). Resolve those channels' brand ownership first, then re-run this migration.",
            );
        }

        try {
            Schema::table('channels', function (Blueprint $table): void {
                $table->dropUnique(['company_id', 'code']);
            });
        } catch (Exception) {
            // Already gone
        }

        try {
            Schema::table('channels', function (Blueprint $table): void {
                $table->dropForeign(['brand_id']);
            });
        } catch (Exception) {
            // Already gone
        }

        Schema::table('channels', function (Blueprint $table): void {
            $table->uuid('brand_id')->nullable(false)->change();
        });

        try {
            Schema::table('channels', function (Blueprint $table): void {
                $table->foreign('brand_id')->references('id')->on('brands')->cascadeOnDelete();
            });
        } catch (Exception) {
            // Already present
        }

        try {
            Schema::table('channels', function (Blueprint $table): void {
                $table->unique(['brand_id', 'code'], 'channels_brand_id_code_unique');
            });
        } catch (Exception) {
            // Already present
        }

        try {
            Schema::table('channels', function (Blueprint $table): void {
                $table->dropForeign(['company_id']);
            });
        } catch (Exception) {
            // Already gone
        }

        try {
            Schema::table('channels', function (Blueprint $table): void {
                $table->dropIndex(['company_id']);
            });
        } catch (Exception) {
            // Already gone
        }

        Schema::table('channels', function (Blueprint $table): void {
            $table->dropColumn('company_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('channels', 'company_id')) {
            return;
        }

        try {
            Schema::table('channels', function (Blueprint $table): void {
                $table->dropUnique('channels_brand_id_code_unique');
            });
        } catch (Exception) {
            // Already gone
        }

        Schema::table('channels', function (Blueprint $table): void {
            $table->uuid('brand_id')->nullable()->change();
        });

        Schema::table('channels', function (Blueprint $table): void {
            $table->foreignUuid('company_id')
                ->nullable()
                ->constrained('companies')
                ->cascadeOnDelete()
                ->after('id');
        });

        Schema::table('channels', function (Blueprint $table): void {
            $table->index('company_id');
            $table->unique(['company_id', 'code']);
        });

        DB::statement('UPDATE channels SET company_id = (SELECT company_id FROM brands WHERE brands.id = channels.brand_id)');
    }
};
