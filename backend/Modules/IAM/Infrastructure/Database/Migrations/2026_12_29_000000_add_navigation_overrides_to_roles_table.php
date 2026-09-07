<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User-review remediation (Batch 02, item I/12) — per-role navigation VISIBILITY settings
 * ("القائمة والتنقل" — "Menu and navigation").
 *
 * One nullable JSON column: `nav_item_key => 'visible' | 'hidden'`. A key absent from the
 * map means "inherit" — the item's visibility is decided purely by the existing
 * permission-gated navigation registry (`module-navigation.ts`'s `GATE`), exactly as
 * before this migration. This is UX POLICY ONLY: it can hide an item a role's permissions
 * would otherwise show, and can be explicitly set to "visible" for an item the role's own
 * navigation whitelist would otherwise omit — but it can never widen backend authorization,
 * because no route or API permission check ever reads this column. Nothing about current
 * authorization changes for any existing role: every row starts with `navigation_overrides`
 * null, which is defined as "inherit everything," identical to today's behavior.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        Schema::table('roles', function (Blueprint $table) {
            if (! Schema::hasColumn('roles', 'navigation_overrides')) {
                $table->json('navigation_overrides')->nullable()->after('archived_by');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        Schema::table('roles', function (Blueprint $table) {
            if (Schema::hasColumn('roles', 'navigation_overrides')) {
                $table->dropColumn('navigation_overrides');
            }
        });
    }
};
