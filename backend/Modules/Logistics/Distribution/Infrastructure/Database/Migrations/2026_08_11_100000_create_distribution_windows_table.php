<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-SHIPPING-DISTRIBUTION-CORE-001 — the daily Distribution Window.
 *
 * The Window is the unit automatic ingestion runs inside. `closes_at` is the
 * cutoff: it stops AUTOMATIC ingestion only. It does not freeze the plan — a
 * manager may still edit assignments and manually attach late Orders afterwards
 * (business contract §5, §15, §18).
 *
 * Times are stored per Window rather than read from config at query time so a
 * later configuration change cannot retroactively reinterpret a Window that has
 * already run. Config seeds the values; the row is the record of what happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('distribution_windows')) {
            return;
        }

        Schema::create('distribution_windows', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');

            $table->date('window_date');
            $table->timestamp('opens_at');
            $table->timestamp('closes_at');
            $table->string('status', 32);

            // Set when this Window's cutoff is reached and late Orders start
            // flowing to its successor. Nullable: the successor may not exist yet.
            $table->uuid('next_window_id')->nullable();

            $table->timestamp('cutoff_reached_at')->nullable();

            $table->timestamps();

            // One Window per company per day — this is what makes "the current
            // window" a well-defined question rather than a race.
            $table->unique(['company_id', 'window_date'], 'dist_windows_company_date_unique');
            $table->index(['company_id', 'status'], 'dist_windows_company_status_idx');
            $table->index('window_date', 'dist_windows_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distribution_windows');
    }
};
