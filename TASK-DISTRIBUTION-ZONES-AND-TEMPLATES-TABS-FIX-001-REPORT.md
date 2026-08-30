# TASK-DISTRIBUTION-ZONES-AND-TEMPLATES-TABS-FIX-001 — REPORT

**Scope:** Fix the **Zones** and **Templates** tabs of
`/app/logistics/distribution/workspace`. Map, Driver, Trip, Loading, Group
Finalize and all Distribution business rules were **not** touched.

**STATUS: IMPLEMENTED / VERIFIED** (frontend-only; no backend change, no
migration, no business-rule change).

---

## 1. Root cause — Zones tab

Both tabs failed for **one shared reason**: the workspace fed the Zones tab and
the Templates zone picker from `data.zones` — the payload of the backend
`DistributionAggregationService::zoneSummaries()`.

`zoneSummaries()` deliberately runs through the **narrow** eligibility predicate
`PreparationEligibilityReader::constrainToEligible()`, whose status list is
`config('distribution.eligible_order_statuses')` = `["in_progress","confirmed"]`.
It intentionally **excludes `ready_for_dispatch`** — its own docblock records
this as the "what can still enter planning?" question, correct for the
group‑building zone selector (LP‑1.0).

The orders list the tab actually renders (`.../windows/{id}/orders`) uses the
**wide** predicate `constrainToLoadingEligible()`
(`+ ready_for_dispatch`). So the moment a Preparation Wave starts and flips every
order to `ready_for_dispatch`, the grid still shows those orders in Maadi, Obour,
Nasr City … while `data.zones` collapses toward empty — producing **"Zones (0)"**
over a populated grid.

Confirmed on live `ecos_dev`, window `2026‑08‑21` (PREP‑202608‑000007):
11 orders `ready_for_dispatch` across 5 zones + 2 `in_progress` (Giza, both
warehouse‑unassigned, so excluded under the Main‑Warehouse scope). `zoneSummaries`
returned ~0 real zones; the orders grid returned all 5.

## 2. Root cause — Templates tab

The Templates zone picker (`DistributionTemplatesTab`) derived its options from
the **same** `zoneSummaries` payload (passed in as `zoneSummaries`). With that
list empty, **New Template → Zones = "No zones selected"**, and an existing
template could not be edited to add zones — even though 10 configured zones exist.

The reported *"Morning Cairo v2 → 0 zones"* is **correct data**: the live template
(`01a0339c…`, capacity 20) genuinely has **no rows** in
`distribution_group_template_zones` (only the soft‑deleted copy `01a02ba9…` carries
zone 7). Per the task it is left at 0 zones.

## 3. Canonical Zone source

- **Configured zones (config):** `distribution_zones` — 10 rows, all active on
  `ecos_dev`. Served by the existing `GET /logistics/distribution/zones`
  (`DistributionZoneController::index`), already consumed by the Zones‑management
  feature via `distributionZoneService.list` / `useDistributionZones`.
- **Per‑order canonical zone assignment (runtime):** `distribution_window_orders.
  distribution_zone_id`, surfaced on each order row as `zone_id` / `zone_name`.

No new zone engine, no city‑name derivation, no hard‑coding, no new zone was
created.

## 4. Template ↔ Zone relationship — already exists, read+write both correct

`distribution_group_templates` + `distribution_group_template_zones`
(`DistributionGroupTemplate::zones()`). `GroupTemplateService::create/update`
persist `zone_ids` via `replaceZones()`; the controller `payload()` returns
`zone_ids` and `zones_count`. **No mismatch, no migration needed** — the problem
was purely the *source* of the picker options, not the read/write contract.

**The problem was Read‑only (which zones the UI offered / counted), not the
persisted contract.**

## 5. Files changed

| File | Change |
|---|---|
| `frontend/src/features/logistics/distribution-workspace/pages/distribution-workspace-page.tsx` | New `reviewZones` derivation from the displayed `orders` (+ Group from `slots`); Zones‑tab count, KPI, zone sub‑tabs and per‑zone panels now use it; per‑zone header shows **Group: {code}** / **No group**. `realZones` (narrow) still passed to the Groups panel unchanged. |
| `frontend/src/features/logistics/distribution-workspace/components/distribution-templates-tab.tsx` | Zone picker options now come from canonical `distribution_zones` via `useDistributionZones({status:'active',per_page:100})`; removed the `zoneSummaries` prop. |
| `frontend/src/i18n/locales/en/logistics.json` | `distributionWorkspace.zonePanel.group`, `.noGroup`. |
| `frontend/src/i18n/locales/ar/logistics.json` | Arabic parity for the two keys. |

## 6. Zones fix

`reviewZones` groups the already‑loaded, warehouse‑scoped `orders` by canonical
`zone_id`, rolling up `order_count`, `total_value`, `products_count`,
`paid_orders`/`unpaid_orders` and `spans_slots` (distinct non‑null slots), and
attaching the Group from the planned slot→zone mapping in `slots`. The Zones tab
count, the KPI and the per‑zone panels all read this one collection — so the count
can never disagree with what the tab shows. The narrow `realZones` is still handed
to `DistributionGroupsPanel` untouched (its "what can still enter planning?"
selector is a certified, deliberate behavior).

## 7. Templates fix

The picker lists every active canonical zone. Selection, save and reload are the
**existing, unchanged** write path. A template with 0 zones stays 0 zones but can
now be edited to add zones.

## 8. Existing data handling

No automatic migration / zone assignment / template correction / group move /
order reassignment. No existing order (ORD‑00007, ORD‑00017, …) or existing
template was modified. The one write performed was a **deliberate verification
template** (`ZONE‑FIX‑VERIFY`), created through the UI and then **hard‑deleted** —
see §10.

## 9. Tests

- **Backend (control, unchanged):** the template zone_ids read/write contract is
  already covered by `tests/Feature/Logistics/DistributionWorkspaceFinalizationTest.php`
  — `test_a_template_can_be_created` (asserts `zones_count=2`, `zone_ids=[Maadi,Nasr]`),
  `test_a_template_can_be_edited` (`zone_ids=[Nasr]`),
  `test_applying_a_template_creates_a_group_with_its_configuration`,
  `test_applying_a_template_copies_no_runtime_state`, `test_templates_are_company_scoped`.
  No backend code changed, so these remain the authority for PART G #8–#12.
- **Frontend:** the repo has no established vitest suite under `src` (no `*.test.*`
  files). The fix is presentation‑layer; it was verified end‑to‑end in the browser
  against live data and live API (PART H, §10) rather than by adding the first,
  infra‑less test file.
- `tsc --noEmit -p tsconfig.app.json`: **0 errors in the changed files** (pre‑existing
  errors elsewhere — admin/configuration, orders, hr, stock‑ledger — are unrelated
  and unchanged).
- ESLint on both changed `.tsx`: clean.

## 10. Browser verification (Chrome via in‑app browser, localhost:5173, HMR)

Window PREP‑202608‑000007, Main Warehouse:

1. **Zones tab now reads "Zones (5)"** (KPI ZONES = 5) — was 0.
2. Zone sub‑tabs render **Nasr city & Masr Gedida (5), Maadi (2), Obour (1),
   Helwan (1), New Cairo (1)** — derived from the displayed orders.
3. Maadi panel header shows **"Group: DG‑001"**; Helwan panel shows **"No group"**.
4. Templates → the existing template still reads **"Morning Cairo v2 · 0 zones · 20"**
   (correct — genuinely 0 zones).
5. **New Template → picker shows all 10 canonical zones** (New Cairo, Nasr City,
   Giza, Zayed & October, Dokki & Mohandseen, Mokattam, Maadi, Helwan, Obour,
   Shrouk); no "No zones" message.
6. Selected Maadi + Obour, name `ZONE‑FIX‑VERIFY`, max 15, saved →
   `POST /group-templates` **201** with `zone_ids:[7,9]`, `zones_count:2`.
7. Reload list returned the template with `zone_ids:[7,9]`; reopening its editor
   showed **zones 7 & 9 pre‑checked**. Morning Cairo v2 unchanged at 0 zones.
8. The verification template was then removed.

## 11. Data safety (before → after cleanup of the verification template)

| Table | Count |
|---|---|
| orders | 19 → 19 |
| distribution_window_orders | 13 → 13 |
| distribution_virtual_slots (groups) | 3 → 3 |
| distribution_slot_zones | 3 → 3 |
| distribution_zones | 10 → 10 |
| distribution_group_templates (live) | 1 → 1 |
| distribution_group_template_zones | 1 → 1 |
| distribution_trips | 2 → 2 |

No unintended mutation. Orders / Groups / Zones / Trips / Assignments all
unchanged.

## 12. Remaining blockers / notes (report‑only, not fixed — out of scope)

- The **group‑building** zone selector on the Groups tab still reads *"No zone has
  orders in this window yet"* once all orders are `ready_for_dispatch`. This is the
  **deliberate, certified** narrow‑eligibility behavior of `zoneSummaries`
  (LP‑1.0) and is unchanged by this task. Not a defect introduced here; widening it
  would change a business rule (STOP condition).
- Per‑zone panels show no zone **code** (orders carry `zone_id`/`zone_name`, not the
  code) — faithful to the "derive from the displayed collection" requirement.
- `distribution_zones` has no `company_id`; zone‑level tenancy is held by the Group
  the zone attaches to (existing, documented).

No further task started.
