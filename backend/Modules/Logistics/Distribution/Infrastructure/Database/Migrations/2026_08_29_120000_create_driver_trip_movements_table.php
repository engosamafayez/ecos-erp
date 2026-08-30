<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-DRIVER-APP-OPERATIONAL-FLOW-VNEXT-001 §34 — Driver Trip Operational Movement.
 *
 * A minimal OPERATIONAL cash-movement authority for the Driver App: fuel / road toll / other
 * expense (cash OUT) and advances (cash IN), scoped to one driver's active trip/custody. NOT a
 * General-Ledger table and NOT a settlement — the operational fact a future Driver Closing /
 * Operations approval step consumes. MySQL-portable (Schema-Builder + guarded CHECKs), matching
 * the module's existing migration style.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('driver_trip_movements')) {
            return;
        }

        Schema::create('driver_trip_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // ── Tenancy + scope (all resolved server-side from the authenticated driver) ──
            $table->uuid('company_id');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();

            $table->foreignId('driver_id')->constrained('logistics_drivers')->restrictOnDelete();
            $table->foreignId('trip_id')->constrained('distribution_trips')->cascadeOnDelete();

            // ── The movement ──────────────────────────────────────────────────
            $table->string('category', 20);   // fuel | road_toll | advance | other
            $table->string('direction', 10);  // cash_out | cash_in (derived from category)
            $table->decimal('amount', 14, 2);
            $table->string('note', 1000)->nullable();
            $table->timestamp('occurred_at');
            $table->string('status', 20)->default('pending'); // pending | approved | rejected | settled

            // ── Optional receipt evidence (private disk, server-generated path) ──
            $table->string('storage_disk', 30)->nullable();
            $table->string('receipt_path', 500)->nullable();
            $table->string('receipt_mime', 150)->nullable();
            $table->unsignedBigInteger('receipt_size')->nullable();

            // ── Operator review (a future approval step writes these; driver never does) ──
            $table->string('reviewed_by', 64)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();

            $table->string('created_by', 64);
            $table->string('updated_by', 64);
            $table->timestamps();

            $table->index(['company_id', 'driver_id']);
            $table->index(['trip_id', 'status']);
        });

        // Defence-in-depth value constraints (guarded so an unsupported engine does not fail the
        // migration — the same pattern the reconciliation-lines migration uses).
        try {
            DB::statement("ALTER TABLE driver_trip_movements ADD CONSTRAINT chk_driver_trip_movement_category CHECK (category IN ('fuel','road_toll','advance','other'))");
        } catch (Throwable) {
            // constraint already present / engine unsupported
        }
        try {
            DB::statement("ALTER TABLE driver_trip_movements ADD CONSTRAINT chk_driver_trip_movement_direction CHECK (direction IN ('cash_out','cash_in'))");
        } catch (Throwable) {
        }
        try {
            DB::statement("ALTER TABLE driver_trip_movements ADD CONSTRAINT chk_driver_trip_movement_status CHECK (status IN ('pending','approved','rejected','settled'))");
        } catch (Throwable) {
        }
        try {
            DB::statement('ALTER TABLE driver_trip_movements ADD CONSTRAINT chk_driver_trip_movement_amount CHECK (amount >= 0)');
        } catch (Throwable) {
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_trip_movements');
    }
};
