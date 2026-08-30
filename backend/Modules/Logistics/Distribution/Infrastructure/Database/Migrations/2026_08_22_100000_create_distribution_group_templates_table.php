<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-OPERATIONS-DISTRIBUTION-WORKSPACE-FINALIZATION-001 — Group Templates.
 *
 * ┌─ THE ONE FACT THIS TABLE OWNS ───────────────────────────────────────────┐
 * │ "A reusable Distribution Group CONFIGURATION: a name, a set of Zones and  │
 * │  a maximum order count, that an operator can stamp out a new Group from."  │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * A TEMPLATE IS CONFIGURATION, NOT STATE. It is emphatically not a snapshot of a
 * Group. It therefore has NO column — and can never gain one — for:
 *   • orders / assignments   — those live on `distribution_window_orders`, one row
 *                              per Order, and an Order belongs to exactly one
 *                              Window. A template that carried orders would be a
 *                              second, stale claim on that membership.
 *   • vehicle / driver       — the canonical pairing is
 *                              `driver_vehicle_assignments`; Logistics owns fleet
 *                              identity. Copying either here would be the duplicate
 *                              vehicle source the architecture forbids.
 *   • trip                   — `distribution_trips` is the dispatch anchor and is
 *                              produced by Finalize, never by configuration.
 *   • loading / prepared qty — `distribution_group_product_preparation` owns
 *                              Prepared at (Group, Product). A template predates any
 *                              Group, so it cannot have prepared anything.
 *   • window / wave          — a template outlives every operational cycle. Binding
 *                              it to one would make it single-use, which is the
 *                              opposite of its purpose.
 *   • status                 — a template is not in a lifecycle. Archived-ness is
 *                              `deleted_at`, below.
 *
 * WHY A NEW TABLE. Every `*_templates` table in this repository is domain-specific
 * (`fleet_inspection_templates`, `role_templates`, `marketing_campaign_templates`,
 * `finance_journal_templates`, `pos_receipt_templates`, `crm_resolution_templates`,
 * `cep_message_templates`, `automation_workflow_templates`,
 * `engineering_pipeline_templates`). There is no generic reusable-configuration
 * store to extend, so there is nothing to reuse. `distribution_virtual_slots`
 * cannot serve: every row there is a live Group inside one Window, with a NOT NULL
 * warehouse and a Window id.
 *
 * NO WAREHOUSE COLUMN. A Group's warehouse is chosen explicitly at creation and is
 * deliberately never inferred (see
 * `2026_08_21_100000_add_warehouse_ownership_to_distribution_groups`). A template
 * that carried one would either pre-empt that choice or disagree with it, so the
 * operator supplies the warehouse when applying the template.
 *
 * TENANT SCOPE IS A REAL COLUMN, NOT A CONVENTION. `company_id` is NOT NULL and is
 * the leading column of the lookup index, so a listing query cannot accidentally
 * become cross-tenant by omitting it. `name` is unique PER COMPANY — never
 * globally — so two tenants may both have a template called "Morning Cairo".
 *
 * ── CONVENTIONS FOLLOWED (this module's) ────────────────────────────────────
 *
 * NO FOREIGN KEYS, matching every table in this module: the 2026_08_11
 * windows/slots/window_orders wave and the 2026_08_21 preparation table all declare
 * zero FKs and carry plain indexed uuids. Adding one here would be a new convention
 * rather than a followed one. The trade-off is stated rather than hidden: nothing at
 * DB level stops a template row outliving its company. Company deletion has no path
 * in this system today, so nothing can currently produce that orphan.
 *
 * NO CHECK CONSTRAINTS — the Distribution directory contains zero `ADD CONSTRAINT`
 * statements. `capacity_orders >= 1` is enforced where the Group's own capacity rule
 * is: in request validation and in GroupCapacityGuard.
 *
 * SOFT DELETES ARE THE ARCHIVE. `distribution_zones` already uses `softDeletes()`
 * for exactly this, so archiving a template is `deleted_at`, not a new status
 * column or a second boolean. A template that has been used is never mutated by the
 * Group it produced, so archiving it can never affect a live Group.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('distribution_group_templates')) {
            return;
        }

        Schema::create('distribution_group_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // NOT NULL. A template with no tenant would be visible to every company.
            $table->uuid('company_id');

            $table->string('name', 100);

            // NULLABLE means UNCONSTRAINED, never zero — the same contract as
            // `distribution_virtual_slots.capacity_orders`, which this column exists
            // to populate. Applying a template with a null maximum produces a Group
            // with no maximum, which is the current default for every Group.
            //
            // ORDER COUNT ONLY (decision D4-C). There is deliberately no
            // capacity_stops / weight / volume counterpart: nothing in the system
            // enforces those on a Group, so a template could not meaningfully carry
            // them.
            $table->unsignedInteger('capacity_orders')->nullable();

            // bigint to match `users.id`, which is a bigint in this platform.
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Unique PER COMPANY, and only among live rows is not expressible without
            // a partial index (not portable to MySQL, and this module uses none), so
            // `deleted_at` is part of the key: archiving a template frees its name.
            $table->unique(['company_id', 'name', 'deleted_at'], 'dist_group_tpl_company_name_unique');
            $table->index(['company_id', 'deleted_at'], 'dist_group_tpl_company_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distribution_group_templates');
    }
};
