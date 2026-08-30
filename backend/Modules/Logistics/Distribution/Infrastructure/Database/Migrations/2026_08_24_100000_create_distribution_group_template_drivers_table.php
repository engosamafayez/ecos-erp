<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-LOGISTICS-TEMPLATE-DRIVER-RECOMMENDATIONS-AND-VEHICLE-CREATION-FIX-001 —
 * a template's Recommended Drivers.
 *
 * The Driver-recommendation half of a Group template, mirroring the Zone pivot
 * (`distribution_group_template_zones`) rather than a JSON column, so "which
 * templates recommend this Driver?" is an indexed read and a recommendation is a
 * row that can be counted and joined.
 *
 * ┌─ WHAT A ROW HERE MEANS ──────────────────────────────────────────────────┐
 * │ "This Driver is SUGGESTED to the operator for a Group made from this      │
 * │ template." It is informational only.                                     │
 * │                                                                          │
 * │ It is NOT an assignment and NOT a claim on the Driver. Applying a         │
 * │ template creates a Group with OPEN Driver selection; the Group's actual   │
 * │ Driver + Vehicle are chosen afterwards through the existing assignment    │
 * │ endpoint. `applyToNewGroup()` never reads this table.                    │
 * └────────────────────────────────────────────────────────────────────────────┘
 *
 * CRUCIAL DIFFERENCE FROM THE ZONE PIVOT: there is NO unique key on
 * `logistics_driver_id` alone. A Zone belongs to at most one template, but the
 * SAME Driver may be recommended by MANY templates — recommendations are not
 * ownership. Uniqueness is only per (template, driver).
 *
 * NO `company_id` HERE. The tenant is the parent template's; duplicating it would
 * create two answers to "whose recommendation is this?" that could disagree. Every
 * read reaches these rows through the company-scoped template, and driver-id
 * eligibility is validated in the service against the tenant-scoped Driver model.
 *
 * CONVENTIONS: no foreign keys and no check constraints, matching every other
 * table in this module. Deleting a template deletes its recommendation rows in the
 * same transaction, in application code, because there is no cascade to rely on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('distribution_group_template_drivers')) {
            return;
        }

        Schema::create('distribution_group_template_drivers', function (Blueprint $table): void {
            $table->id();

            $table->uuid('distribution_group_template_id');

            // bigint to match `logistics_drivers.id`, which is a `$table->id()`.
            $table->unsignedBigInteger('logistics_driver_id');

            $table->timestamps();

            // A Driver is recommended at most ONCE per template. There is deliberately
            // no unique on `logistics_driver_id` alone: one Driver may be recommended
            // by many templates.
            $table->unique(
                ['distribution_group_template_id', 'logistics_driver_id'],
                'dist_group_tpl_driver_unique',
            );

            // Reverse lookup: "which templates recommend this Driver?".
            $table->index('logistics_driver_id', 'dist_group_tpl_driver_driver_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distribution_group_template_drivers');
    }
};
