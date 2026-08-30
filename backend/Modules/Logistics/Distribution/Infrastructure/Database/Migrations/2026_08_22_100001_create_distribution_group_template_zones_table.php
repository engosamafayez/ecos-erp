<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-OPERATIONS-DISTRIBUTION-WORKSPACE-FINALIZATION-001 — a template's Zones.
 *
 * The Zone membership half of a Group template. Its own table rather than a JSON
 * column on the template, so that "which templates use Maadi?" is an indexed read
 * and a zone reference is a row that can be counted and joined — the same shape
 * `distribution_slot_zones` already uses for the live Group→Zone relation.
 *
 * ┌─ WHAT A ROW HERE MEANS ──────────────────────────────────────────────────┐
 * │ "When this template is applied, attach this Zone to the new Group."       │
 * │                                                                          │
 * │ It is an INTENTION, not a claim on the Zone. Attaching a Zone to a live   │
 * │ Group is `distribution_slot_zones`, which is keyed by (window, warehouse, │
 * │ zone) and remains the only authority on who is actually planning a Zone.  │
 * │ Two templates may name the same Zone; that is not a conflict, because     │
 * │ neither of them plans anything until applied.                            │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * NO `company_id` HERE. The tenant is the parent template's, and duplicating it
 * would create two answers to "whose zone reference is this?" that could disagree.
 * Every read reaches these rows through the template, which is company-scoped.
 *
 * ── THE ZONE TENANCY LIMITATION, STATED RATHER THAN PAPERED OVER ────────────
 *
 * `distribution_zones` carries NO `company_id`, and its `code` and `name_ar` are
 * globally unique — zones are a shared global table today. So a zone id cannot be
 * proved to belong to the acting company at this layer, and this migration does not
 * pretend otherwise: it adds no tenant column that would imply a guarantee the
 * referenced table cannot give.
 *
 * That gap is NOT closed here (it would mean changing a certified zone contract).
 * What IS closed is the reachable damage: application writes go through the
 * existing `assignZoneToSlot`, which is already company- and warehouse-scoped, so a
 * template cannot attach a Zone to a Group outside the actor's own company or
 * warehouse regardless of what zone ids it names. Recorded as an architecture
 * follow-up in the task report.
 *
 * CONVENTIONS: no foreign keys and no check constraints, matching every other table
 * in this module. Deleting a template deletes its zone rows in the same
 * transaction, in application code, because there is no cascade to rely on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('distribution_group_template_zones')) {
            return;
        }

        Schema::create('distribution_group_template_zones', function (Blueprint $table): void {
            $table->id();

            $table->uuid('distribution_group_template_id');

            // bigint to match `distribution_zones.id`, which is a `$table->id()`.
            $table->unsignedBigInteger('distribution_zone_id');

            $table->timestamps();

            // A Zone appears at most once in a template. Without this, applying a
            // template would try to attach the same Zone twice and the second attach
            // would be a silent no-op that looked like success.
            $table->unique(
                ['distribution_group_template_id', 'distribution_zone_id'],
                'dist_group_tpl_zone_unique',
            );

            // "Which templates reference this Zone?" — needed to warn an operator
            // before a Zone is deactivated.
            $table->index('distribution_zone_id', 'dist_group_tpl_zone_zone_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distribution_group_template_zones');
    }
};
