<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separates Missing Material from Preparation Eligibility (owner decision, ADR-027 §18.4 v1.6).
 *
 * `missing_qty` keeps meaning the REAL physical shortage in every case. Whether preparation
 * may nevertheless proceed is a distinct, per-product answer, carried by the two columns
 * added to `wave_product_demand`. `allow_negative` is persisted on the material row so a
 * BLOCKING shortage is distinguishable from one that is drawable on open credit.
 *
 * Additive and backward-compatible: the defaults reproduce today's behaviour until the
 * projection is next rebuilt.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wave_material_demand') && ! Schema::hasColumn('wave_material_demand', 'allow_negative')) {
            Schema::table('wave_material_demand', function (Blueprint $table): void {
                $table->boolean('allow_negative')->default(false)->after('coverage_pct');
            });
        }

        if (Schema::hasTable('wave_product_demand')) {
            Schema::table('wave_product_demand', function (Blueprint $table): void {
                if (! Schema::hasColumn('wave_product_demand', 'material_status')) {
                    // 'ready' | 'waiting_material' — see ProductReadinessCalculator.
                    $table->string('material_status', 20)->default('ready')->after('completion_pct');
                }

                if (! Schema::hasColumn('wave_product_demand', 'blocking_materials_count')) {
                    $table->unsignedInteger('blocking_materials_count')->default(0)->after('material_status');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('wave_product_demand')) {
            Schema::table('wave_product_demand', function (Blueprint $table): void {
                foreach (['material_status', 'blocking_materials_count'] as $column) {
                    if (Schema::hasColumn('wave_product_demand', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('wave_material_demand') && Schema::hasColumn('wave_material_demand', 'allow_negative')) {
            Schema::table('wave_material_demand', function (Blueprint $table): void {
                $table->dropColumn('allow_negative');
            });
        }
    }
};
