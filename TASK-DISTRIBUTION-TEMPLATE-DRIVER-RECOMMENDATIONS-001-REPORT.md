# TASK-DISTRIBUTION-TEMPLATE-DRIVER-RECOMMENDATIONS-001 — REPORT

**Status: STOPPED — MIGRATION AUTHORIZATION REQUIRED**
Date: 2026-08-24 · Branch: `develop` · No code changed, nothing committed

The task's own STOP condition was reached:

> *"If a schema change is required to persist multiple recommended Drivers, STOP before
> migration and report the exact migration required. Do not invent a migration without
> authorization."*

There is nowhere to persist a per-Template list of recommended Drivers. The exact
migration is specified in §11 and awaits your authorization.

---

## 1. Existing Driver source

There is exactly one canonical Driver entity, and it is the one to reuse:

| Fact | Value |
|---|---|
| Model | `Modules/Logistics/Drivers/Domain/Models/Driver.php` |
| Table | `logistics_drivers` |
| Primary key | `id` — `bigint unsigned` |
| Public key | `uuid` — `char(36)`, nullable |
| Tenancy | `company_id`, stamped in `booted()` and deliberately **absent** from `$fillable`, so ownership is never accepted from a client |
| List API | `GET api/logistics/drivers` (`auth:sanctum`; the write verbs carry `permission:logistics.drivers.*`) |
| Display fields | `full_name`, `driver_code`, `status` |
| **Live rows** | **0** |

No competing Driver entity or service would be created — the UI would read this list and
store nothing but ids.

**One correction to note:** an earlier project note recorded that `logistics_drivers` has
no `uuid` column. It does now (`uuid char(36)`, nullable). That note is stale and I have
corrected it.

---

## 2. Existing Template contract

| Fact | Value |
|---|---|
| Model | `DistributionGroupTemplate` |
| Table | `distribution_group_templates` |
| Columns | `id`, `company_id`, `name`, `capacity_orders`, `created_by`, `updated_by`, timestamps, `deleted_at` |
| `$fillable` | `company_id`, `name`, `capacity_orders`, `created_by`, `updated_by` |
| Relations | **`zones()` only** |
| Related tables | **`distribution_group_template_zones` only** |

Three searches, all negative:

1. `grep -i recommend` across the whole Distribution module → **no matches**.
2. `information_schema` for every column named `%template%` → the **only** table
   referencing a distribution template is `distribution_group_template_zones`.
3. The template row has **no** JSON, text, driver or recommendation column.

So there is no existing recommendation relation to reuse, and no column that could hold a
list.

---

## 3. Why no existing table can host this

I checked every generic store before concluding a migration is needed:

| Candidate | Why it does not fit |
|---|---|
| `config_company_settings` | **Company**-scoped `setting_value` JSON. Holding per-template data would mean encoding a template id inside a settings blob: no referential integrity, invisible to the template's own queries, and orphaned when a template is deleted |
| `user_preferences` | Per **user**. A template's configuration is not one operator's preference |
| `crm_customer_preferences` | Per **customer** — a different domain entirely |
| `brand_*_settings` | Per **brand** |

Using any of them would put Distribution template configuration inside another entity's
table. That is not "reusing an existing recommendation relation" — it is the competing-store
shape the brief forbids, and it would silently break on template deletion.

---

## 4. UI implementation

**Not implemented, deliberately.** The brief requires the section to *"Persist the
recommendation"* and *"Reload the Template and preserve the selections."* Without a store,
a multi-select could be rendered but every selection would vanish on reload.

Shipping a control that appears to save and does not is worse than shipping nothing: the
operator would believe a recommendation was recorded. So no selection control was added.

What already exists, from the preceding task, is honest and unchanged: a **Recommended
Drivers** section reading *"No recommendation available"* with the note that drivers are
chosen when the Group is assigned and are never stored on a template.

The intended UI, ready to build the moment the migration is authorized, is in §11.

---

## 5. Persistence approach

**None available without a schema change.** See §11 for the exact migration.

---

## 6. Group creation impact

**No impact — nothing changed.** Verified against the current code:

- `GroupTemplateService::applyToNewGroup()` reads the template's `name`,
  `capacity_orders` and `zoneIds()`. It touches no driver and no vehicle.
- The Group it creates (`distribution_virtual_slots`) has no driver or vehicle column
  populated by apply.
- Driver and Vehicle are assigned afterwards, by the operator, through the existing
  `assign-vehicle` endpoint — completely open, constrained only by tenancy.

A recommendation list, once persisted, must not change any of this: it would be read by
the UI as a hint and never passed into apply.

---

## 7. Files changed

**None.** Only this report was created. No migration was written, no model touched, no
endpoint added, no frontend file modified.

---

## 8. Tests

None added — there is no behaviour to test. The relevant existing guarantees stay green
and unmodified:

- `test_a_template_stores_no_driver_or_vehicle` — asserts against the live schema that
  `distribution_group_templates` has no driver or vehicle column, so it will trip
  deliberately if such a column is ever added rather than a pivot.
- `test_applying_a_template_copies_no_runtime_state` (existing) — apply carries no
  runtime state into the Group.

---

## 9. i18n

No new keys. The existing `distributionWorkspace.templates.recommendedDrivers`,
`noRecommendation` and `recommendationNote` remain at EN/AR parity.

---

## 10. Browser status

**BROWSER VERIFICATION PENDING** — and not a release blocker, per the brief.

Two independent reasons: there is no new UI to exercise, and **`logistings_drivers` holds
0 rows**, so even after the migration the section would render its empty state on live
data. No driver data was fabricated.

---

## 11. Migration required — exact specification

A pivot table mirroring the Zones pivot, so no new pattern is introduced:

```php
Schema::create('distribution_group_template_drivers', function (Blueprint $table): void {
    $table->id();
    $table->char('distribution_group_template_id', 36);
    $table->unsignedBigInteger('logistics_driver_id');
    $table->timestamps();

    // A driver is recommended at most once PER TEMPLATE.
    $table->unique(
        ['distribution_group_template_id', 'logistics_driver_id'],
        'dist_group_tpl_driver_unique',
    );

    // Reverse lookup: "which templates recommend this driver".
    $table->index('logistics_driver_id', 'dist_group_tpl_driver_driver_idx');
});
```

Deliberate properties:

1. **No unique key on `logistics_driver_id` alone.** This is the crucial difference from
   Zones: a Zone belongs to **one** template, but a Driver may be recommended for **many**.
   Recommendations are not ownership.
2. **No foreign keys and no CHECK constraints** — matching every existing Distribution
   migration in this module. Integrity stays in the service layer.
3. **Reversible**, and **no backfill**: existing templates start with no recommendations.
4. `logistics_driver_id` references `logistics_drivers.id` (bigint), exactly as the zones
   pivot references `distribution_zones.id`. The `uuid` stays the public API identifier.
5. Removing a recommendation deletes a pivot row and **never** touches the Driver.

Cheaper alternative, offered and **not** recommended: a `recommended_driver_ids` JSON
column on `distribution_group_templates`. One column instead of one table, but it cannot be
indexed or joined, has no per-row integrity, and diverges from the zones pattern the module
already uses. Your call.

**Estimated work once authorized:** the migration, a `recommendedDrivers()` relation, a
`replaceRecommendedDrivers()` writer alongside the existing `replaceZones()`,
`recommended_driver_ids` on the template payload, one `driver_ids` validation rule on store
and update, plus the multi-select UI with search and empty state. No new engine, no new
permission, no change to Group, Trip, Finalize, Loading or the Map.

---

## 12. Data safety

Nothing was mutated. No schema change, no data change, no automatic cleanup or
reassignment. Live state is unchanged: 5 templates, 8 template-zone rows, 0 drivers.

---

## 13. Confirmation — Template does NOT assign a Driver

**Confirmed, and it is currently structurally impossible.**
`distribution_group_templates` has no driver or vehicle column, and the only related table
is the zones pivot. There is no `assigned_driver_id`, no `assigned_vehicle_id`, and no
foreign key to either.

Under the proposed migration this stays true: the pivot is named `..._drivers`, holds
*recommendations*, is read only by the UI, and is never consulted by `applyToNewGroup()`.

---

## 14. Confirmation — Group Driver selection remains open

**Confirmed.** Applying a template creates the Group and assigns no driver. The operator
then selects any valid Driver and Vehicle for their company through the existing
assignment endpoint. Nothing narrows that list to recommended drivers, and no automatic
assignment exists anywhere in the path.

---

## 15. Stop conditions — status

| Condition | Hit? |
|---|---|
| Template → Driver assignment | No — refused by design |
| Automatic Driver assignment to Group | No |
| Group splitting / Trip introduction / new shipment abstraction | No |
| Competing Driver service or entity | No — the canonical `Driver` model would be reused |
| Fabricated Driver performance ranking | No — none invented |
| **Unauthorized migration** | **YES — this is the blocker** |

No scoring engine was created and no ranking, stars, percentages or "best match" labels
were invented. There is still no driver-performance or delivery-history service in the
repository, and with 0 drivers and no delivery history there is nothing to rank.

Wave 2 and Wave 3 were not started. Driver Home, Driver Loading, Driver Delivery and the
Map were not touched.
