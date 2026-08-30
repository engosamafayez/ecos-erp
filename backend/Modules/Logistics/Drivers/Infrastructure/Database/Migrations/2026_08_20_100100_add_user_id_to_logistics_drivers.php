<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-SHIPPING-DRIVER-CLOSURE-001 §G10 — Driver ⇄ auth User identity bridge.
 *
 * The driver runtime (/api/driver/*) must resolve "the logged-in driver" so it can
 * scope every read/write to that driver's own trips. logistics_drivers had no link
 * to the users table, so there was no way to go from an authenticated request to a
 * driver record. This adds a nullable, unique user_id FK. Nullable because most
 * existing driver rows are master data with no login; unique because one user maps
 * to at most one driver. bigint to match users' PK (preserved per IAM decision).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('logistics_drivers') || Schema::hasColumn('logistics_drivers', 'user_id')) {
            return;
        }

        Schema::table('logistics_drivers', function (Blueprint $table): void {
            $table->foreignId('user_id')
                ->nullable()
                ->after('driver_code')
                ->constrained('users')
                ->nullOnDelete();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('logistics_drivers') || ! Schema::hasColumn('logistics_drivers', 'user_id')) {
            return;
        }

        Schema::table('logistics_drivers', function (Blueprint $table): void {
            $table->dropUnique('logistics_drivers_user_id_unique');
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
