# TASK-DISTRIBUTION-DAILY-TEMPLATE-GROUP-LIFECYCLE-AND-NAV-001 — REPORT

**FINAL STATUS: PARTIALLY IMPLEMENTED — Part 1 (Navigation) IMPLEMENTED / VERIFIED;
Part 2–12 (Daily Template→Group lifecycle) HELD by owner decision pending rule
definitions.**

Discovery was completed first (per the task's mandate). The owner then decided:
Part 1A → **move route + redirect (A1)**; Part 1B → **promote Distribution Planning,
retire the "Distributor Orders" group**; Part 2–12 → **HOLD** (do not implement or
migrate until the capacity-overflow, old-group-retirement, and auto-creation rules are
explicitly approved; Wave 1 frozen; no Wave 3). Part 1 is implemented and
browser-verified below; Part 2–12 remains STOPPED with the owner's intended behavior
recorded in §5 for the follow-up definition. No data was mutated.

---

## 1. Discovery (method)

Read-only inspection of the frontend nav/router layer and the backend Distribution
Template/Group/Wave lifecycle (a dedicated exploration mapped all seven lifecycle
questions). No file was edited; one read-only DB snapshot was taken (§12).

## 2. Existing architecture

**Navigation (frontend).** `frontend/src/config/module-navigation.ts` is the
canonical IA (guarded by `module-navigation.test.ts`).
- **Operations** module (`defaultPath: waveWorkspace`) → items: `preparation-workspace`,
  a **`distributor-orders` group** (`isGroup`, `subtree: '/logistics/distribution'`,
  no path of its own) with children `logistics-distribution-plan`
  (→ `/logistics/distribution/workspace`) and `logistics-distribution-zones`
  (→ `/logistics/distribution/zones`), and `loading-drivers`.
- **Shipping** module → a `geo-section` with `egypt-geography` (→ `/logistics/geography`).
  Zones was **already moved OUT of Shipping into Operations** by the prior certified
  task TASK-OPERATIONS-DISTRIBUTOR-ORDERS-LOADING-001 §15.
- `findModuleByPath()` is **first-match by module order** (Operations precedes Shipping)
  over `ownedBases` using `pathname === base || startsWith(base + '/')`. `ownedBases`
  yields one prefix per item (`subtree ?? path`), plus a group's own `subtree`.
- The four Distribution routes are **siblings** under `/logistics/distribution/`:
  `workspace`, `planning` (legacy, 500-ing, not navigable but route kept for deep
  links), `trips`, `zones`. Operations owns all four today via the broad
  `/logistics/distribution` group subtree.
- **"Distributor Orders" is a group label, not a page.** There is no distinct
  Distributor-Orders route/page; the workspace (`…/workspace`) is the canonical and
  ONLY navigable Distribution surface, labelled "Distribution Planning".

**Backend lifecycle.** All under `Modules/Logistics/Distribution/`.
- A Group = `distribution_virtual_slots` row, tied to a day **only** via
  `distribution_window_id`. Windows are `(company_id, window_date)` unique — one per
  company per day — but created **lazily** by `DistributionWindowService::windowFor`,
  and the workspace resolves its window via the **governing Preparation Wave**, not
  the calendar (`resolvePlanningWindow`).
- **Group creation is always an explicit operator action** — `GroupTemplateService::applyToNewGroup`
  (Apply template) or `DistributionWindowController::storeSlot` (Create group). There
  is **no automatic or scheduled group creation**, and therefore **no auto-created
  empty Draft groups** today.
- `DistributionCollectionService` (the operator "collect/Refresh") only writes
  `distribution_window_orders` and fills `virtual_slot_id` from the **pre-existing
  zone→slot map** (`distribution_slot_zones`). It never creates a group.
- `distribution_virtual_slots` has **no status column and no soft-delete**. The only
  thing separating an old-day group from a new-day group is `distribution_window_id`.
- Template→zone link = `distribution_group_template_zones` (an *intention* to attach,
  enforced exclusively by `GroupTemplateService::claimZones()`). There is **no existing
  query** that, given a day's eligible orders, returns which templates' zones hold
  orders.
- Safe wave hook exists: `WaveStarted` / `WavePreparationStarted` events, wired in
  `Modules/Commerce/Orders/…/OrderServiceProvider.php`, carrying `companyId`,
  `warehouseId`, `planningDate`, `orderIds`. Nothing there touches Distribution today.

## 3. Conflict / dependency audit

Coexists with the listed tasks. This task changed **nothing**, so no file owned by
TASK-…-ZONES-ORDERS-PANEL-UX-004, TASK-…-ZONES-TABLE-UX-001, the Map tasks,
TASK-…-TEMPLATES-ZONE-EXCLUSIVITY-…-001, or the Driver Wave tasks was touched.
`claimZones()`, the Zones tab card/table, Map, and driver waves are untouched. (A
concurrent agent's earlier template-ownership work is already merged and compiling.)

## 4. Navigation changes — IMPLEMENTED (owner: A1 + promote/retire) & VERIFIED

### Part 1A — Distribution Zones moved to Shipping → Geography (Option A1)
Because `workspace`, `planning`, `trips` and `zones` are siblings under
`/logistics/distribution`, no prefix could keep the first three under Operations while
releasing `zones` to Shipping. Per the owner's A1 decision, the Zones **route moved** to
`/logistics/geography/distribution-zones` (now owned by Shipping's geography paths) and
the old `/logistics/distribution/zones` **redirects** to it, so deep links resolve. The
page component, domain logic and permissions are unchanged; the nav entry now sits in
the Shipping `geo-section` beside Egypt Geography. Operations' Distribution subtree
(`/logistics/distribution`) now covers only workspace/planning/trips.

### Part 1B — Distribution Planning promoted to the primary page
The redundant `distributor-orders` group (a label, not a page) was **retired** and
`logistics-distribution-plan` promoted to a **top-level Operations item** →
`/logistics/distribution/workspace`, carrying `subtree: '/logistics/distribution'` so
the legacy `/planning` and `/trips` deep links keep the Operations shell. The legacy
planning route stays registered and the orders pool remains inside the workspace, so
"Distributor Orders" functionality is preserved and accessible.

## 5. New-day Template lifecycle — HELD (owner will define the rules)

Owner decision: **do not implement or migrate anything** in Part 2–12 until the
capacity-overflow, old-group-retirement, and auto-creation rules are explicitly
approved. The integration point exists (a `WaveStarted`/`WavePreparationStarted`
listener, or the `collect` sweep), and the template-selection query is implementable
from existing relationships with **no migration and no new status** — but it introduces
a **second source of group creation** beside the certified operator-only invariant, and
depends on the still-undefined capacity (§9) and old-group (§8) rules.

**Owner's stated intended behavior (recorded for the follow-up definition):**
- At the start of each new Preparation Day/Wave, evaluate the currently active
  Templates using their **latest saved version**.
- Groups from the previous day/wave must **not** remain as reusable Draft groups for the
  new day.
- Templates **with** eligible orders auto-create/populate their Groups for the new wave.
- Templates **without** eligible orders create **no** empty Group.
- If orders become eligible later in the same wave, the applicable Template may
  populate/create its Group per the approved lifecycle rules.
- Previous-wave Groups remain **historical/runtime records** — never silently reused or
  mutated into the new wave.

Still required before implementation (owner to define): the capacity-overflow rule
(§9), the old-group retirement/transition rule and any `status`/`retired_at` migration
(§8), and explicit authorization of automatic creation + the chosen hook.

## 6. Empty-template behavior — already satisfied

Today no groups are auto-created, so there are **no empty Draft groups** for templates
with zero orders. Part 4's feared UI ("T2 — Draft — 0 orders") does not occur unless an
operator explicitly applies a template. No change needed for the "don't create empty
groups" requirement itself.

## 7. Lazy Group creation — STOPPED (see §5 + §9)

Implementable in principle via the collect sweep / wave listener + a new
template-selection query, but blocked by the capacity (§9) and old-draft (§8) STOP
conditions and the source-of-truth decision (§5).

## 8. Old Draft Group handling — STOP P17.5

`distribution_virtual_slots` has **no status/state and no soft-delete**. The invariant
"OLD DAY GROUP ≠ NEW DAY GROUP" holds automatically **only when a new wave anchors a new
window**; when a wave spans midnight and reuses the same window, yesterday's groups
carry forward and there is **no defined mechanism to retire them**. Defining one
(archive/close/new-day status, or a window-roll rule) is a **new lifecycle** — the task
says STOP and treat as an owner decision. **Decision needed: how should a genuinely new
operational day retire/transition the previous day's Draft groups?** (Migration for a
`status`/`retired_at` column would likely be required — flagged, not created.)

## 9. Capacity behavior — STOP P17.4

Auto-creating a Group from a template and attaching its zone runs
`ManualAssignmentService::assignZoneToSlot` under `GroupCapacityGuard`. If a template's
zone holds MORE eligible orders than the template's `capacity_orders` (e.g. cap 20, 25
orders), the guard **refuses** — so lazy auto-creation would fail or partially apply.
Overflow behavior for template-driven creation is **undefined**. **Decision needed: what
happens when a template's eligible orders exceed its capacity on auto-creation?** (The
task forbids silently creating multiple groups to bypass capacity.)

## 10. Tests

- **`module-navigation.test.ts`** updated to the new IA and **green (21 passed)** —
  asserts Operations exposes `[preparation-workspace, logistics-distribution-plan,
  loading-drivers]`, that Distribution Planning is a top-level item → workspace with no
  `distributor-orders` group, that Distribution Zones lives under Shipping and
  `findModuleByPath(zones) === 'shipping'`, and that Operations still owns
  workspace/planning/trips.
- `tsc --noEmit -p tsconfig.app.json`: touched files clean (pre-existing baseline errors
  elsewhere unchanged).
- ESLint: **zero violations** on the touched files (verified with
  `--pass-on-unpruned-suppressions`). The plain run exits non-zero only because my edits
  *removed* a previously-suppressed lint occurrence, leaving one stale count in the
  shared `eslint-suppressions.json`; I did **not** prune that shared, concurrently-edited
  bookkeeping file (a routine `--prune-suppressions` will clear it).
- i18n EN/AR parity: the `logistics-distribution-zones` label updated in both
  (`Distribution Zones` / `مناطق التوزيع`); the now-unused `distributor-orders` key is
  retained so `NavItemKey` stays stable.
- Backend suites untouched (no backend change); prior green results still stand.
- The Part 2–12 lifecycle test scenarios (task §15 #1–11) are deferred with that part.

## 11. Browser verification — Part 1 DONE (read-only)

Chrome, localhost:5173, wave PREP-202608-000008 active:
- Old deep link `/app/logistics/distribution/zones` → **redirects** to
  `/app/logistics/geography/distribution-zones`; the Zones page renders unchanged
  (Total Zones 10 …), breadcrumb **Home › Shipping › Distribution Zones**.
- Shipping sidebar geo-section lists **Egypt Geography** + **Distribution Zones**
  (→ `/app/logistics/geography/distribution-zones`).
- `/app/logistics/distribution/workspace` breadcrumb is **Home › Operations ›
  Distribution Planning**; the Operations sidebar shows **Distribution Planning** as a
  top-level item and **no "Distributor Orders"** group.
- No data was created, mutated or deleted during verification.

## 12. Data-safety snapshot (read-only, unchanged)

| Table | Count |
|---|---|
| orders | 19 |
| distribution_windows | 4 |
| distribution_window_orders | 13 |
| distribution_virtual_slots (groups) | 3 |
| distribution_slot_zones | 3 |
| distribution_zones | 10 |
| distribution_group_templates (live) | 4 |
| distribution_group_template_zones | 8 |
| distribution_trips | 2 |

No writes were performed by this task.

## 13. Files changed (Part 1 only)

- `frontend/src/router/routes.ts` — `logisticsDistributionZones` now
  `/logistics/geography/distribution-zones`; added `logisticsDistributionZonesLegacy`
  for the redirect.
- `frontend/src/router/router.ts` — Zones route at the new path (same component) + a
  redirect route from the legacy path.
- `frontend/src/config/module-navigation.ts` — retired the `distributor-orders` group,
  promoted `logistics-distribution-plan` to a top-level Operations item, added
  `logistics-distribution-zones` to the Shipping geo-section.
- `frontend/src/config/module-navigation.test.ts` — updated to the new IA (green).
- `frontend/src/features/logistics/distribution-workspace/pages/distribution-workspace-page.tsx`
  — breadcrumbs drop the `distributor-orders` crumb (both states).
- `frontend/src/features/logistics/distribution-zones/pages/distribution-zones-page.tsx`
  — breadcrumb now Shipping › Distribution Zones.
- `frontend/src/i18n/locales/{en,ar}/common.json` — `logistics-distribution-zones` label.

**No backend change. No migration.** (The legacy `distribution-planning-page.tsx`
breadcrumb still references the retained `distributor-orders` key — it is the defunct,
non-navigable page and was left out of scope.)

## 14. STOP conditions encountered

- **P17.4** — capacity-overflow behavior for template-driven creation is undefined (§9).
- **P17.5** — old-Draft-Group retirement needs a status/lifecycle not currently defined (§8).
- **P17.6** — lazy auto-creation introduces a second group-creation source vs the certified operator-only invariant (§5).
- **Part 1A route architecture** — a clean Zones move requires a route move+redirect or a resolver change (§4).

## 15. Remaining owner decisions

1. **Part 1A:** route move + redirect (A1) or resolver change (A2)?
2. **Part 1B:** confirm promoting Distribution Planning to primary and retiring the "Distributor Orders" group label (there is no separate Distributor-Orders page), or specify the intended secondary surface.
3. **Part 8 (old groups):** the new-day retirement/transition rule for previous-day Draft groups (likely needs a `status`/`retired_at` migration — authorize?).
4. **Part 9 (capacity):** the overflow rule when a template's eligible orders exceed its capacity.
5. **Part 5 (source of truth):** authorize automatic (collect-sweep / wave-listener) group creation as a NEW source beside operator-only creation, and which hook.

## 16. Final status

**PARTIALLY IMPLEMENTED.**
- **Part 1 (Navigation): IMPLEMENTED / VERIFIED** — Zones under Shipping → Geography
  (route moved + redirect), Distribution Planning promoted to the primary top-level
  Operations page, "Distributor Orders" group retired; nav test green; browser-verified.
- **Part 2–12 (Daily Template→Group lifecycle): HELD — OWNER DECISION REQUIRED** — not
  implemented and no migration created, per the owner's instruction. The intended
  behavior is recorded in §5; the capacity-overflow (§9) and old-group-retirement (§8)
  rules and auto-creation authorization must be defined before implementation. Wave 1
  left frozen; Wave 3 not started.
