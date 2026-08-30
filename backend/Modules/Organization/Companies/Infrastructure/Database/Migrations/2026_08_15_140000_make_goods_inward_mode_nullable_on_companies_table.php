<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make "never chosen" distinguishable from "chose Goods Receipt".
 *
 * The G-1 column was introduced as `VARCHAR(30) NOT NULL DEFAULT 'goods_receipt'`. That
 * defaulted correctly, but it also meant every row stored a value and none was ever NULL — so
 * the Configuration API could not tell an explicit choice apart from an untouched company, and
 * the "Default" state the UI is required to show was unreachable in practice. The setting would
 * have rendered plausibly while that half of its contract was permanently dead.
 *
 * NULL now means "not configured". Behaviour is unchanged: `GoodsInwardAuthority` already
 * resolves NULL (and any unrecognised value) to `goods_receipt`, so the effective mode for an
 * unconfigured company is exactly what it was before — this migration moves where the default
 * lives, from the column to the application, and does not change what the default IS.
 *
 * THE ONE-SHOT NORMALISATION IS SAFE AND IS NOT A GUESS. Rows currently holding
 * 'goods_receipt' cannot represent a deliberate choice: until this task there was no UI and no
 * API to make one, and the value could only have arrived from the column default. Rows holding
 * 'supplier_invoice' ARE deliberate and are left untouched.
 *
 * `DB::statement` with raw MODIFY rather than a Blueprint change(): the codebase avoids
 * doctrine/dbal-dependent column changes, consistently with the rest of these migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('companies', 'goods_inward_mode')) {
            return;
        }

        DB::statement('ALTER TABLE companies MODIFY goods_inward_mode VARCHAR(30) NULL DEFAULT NULL');

        DB::statement(<<<'SQL'
            UPDATE companies
            SET goods_inward_mode = NULL
            WHERE goods_inward_mode = 'goods_receipt'
        SQL);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('companies', 'goods_inward_mode')) {
            return;
        }

        DB::statement(<<<'SQL'
            UPDATE companies
            SET goods_inward_mode = 'goods_receipt'
            WHERE goods_inward_mode IS NULL
        SQL);

        DB::statement("ALTER TABLE companies MODIFY goods_inward_mode VARCHAR(30) NOT NULL DEFAULT 'goods_receipt'");
    }
};
