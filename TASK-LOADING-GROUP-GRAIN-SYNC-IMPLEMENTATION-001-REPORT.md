# TASK-LOADING-GROUP-GRAIN-SYNC-IMPLEMENTATION-001

**Status: IMPLEMENTED / STATICALLY VERIFIED**

Browser: **NOT RUN** · Tests: **NOT RUN** · Data mutation: **NONE** · Migration: **NONE**
Commit: **NONE** · Push: **NONE** · Deploy: **NONE**

Date: 2026-08-26 · Branch: `develop`

> The Loading Workspace now opens on **Distribution Groups**, not Loading Sessions.
> A Group holding loadable orders appears immediately, with its products and live
> Required quantities, whether or not a Vehicle, Driver, Trip or Session exists.

**Frontend-only. Zero backend files changed. Zero schema changes. Zero migrations.
No event, no listener, no snapshot, no synchronization mechanism.**

---

## 1. Files changed

| File | Change | Status |
|---|---|---|
| `features/operations/loading-os/components/loading-groups.tsx` | **NEW** — group list, group card, products table, execution panel | new file |
| `features/operations/loading-os/pages/loading-os-workspace-page.tsx` | entry point moved from sessions to Groups | modified |
| `i18n/locales/en/operations.json` | +42 keys under `loadingOs.groups` | modified |
| `i18n/locales/ar/operations.json` | +42 keys under `loadingOs.groups` | modified |

**Backend files changed: none.** `LoadingSessionController`, `CreateLoadingSessionAction`,
`GroupLoadingContextService`, `LoadProductAction`, `VehicleInventoryService`,
`DistributionAggregationService` and `GroupPreparationService` are all untouched.

*Note:* `features/operations/loading-os/` is **untracked** in git (an uncommitted feature
from a prior task), so `git diff` renders nothing for it. That is pre-existing, not caused
by this task.

## 2. How Loading became Group-grain

The page previously opened on `GET /loading/sessions`. A Loading Session is a
**warehouse-day execution artefact** that cannot exist before a Vehicle does, so asking for
sessions first made every un-assigned Group invisible and printed *"No loading sessions."*
while Groups sat full of orders.

The entry point is now the Group:

```
useCurrentDistributionWindow(activeWarehouseId)     GET …/windows/current
    → window + slots (the Groups) in ONE round trip
    → LoadingGroupList  (left column, primary)
        → per card: useGroupRequiredProducts(windowId, slotId, warehouseId)
                    useGroupTrips(windowId, slotId)
    → LoadingGroupDetail (right column, on select)
        → products table + execution-availability panel
```

Sessions remain — they are where execution is recorded — but they are now a **consequence**,
not the door. The session picker renders only when sessions actually exist.

## 3. Source of products and quantities

**The existing canonical live projection, exactly as approved. No new projection.**

```
GET /logistics/distribution/windows/{window}/products?slot_id={groupId}
  → DistributionWindowController::products()
  → groupLoadingPreparation($group)
       Required  = DistributionAggregationService::productAggregation(...)   (live SUM)
       Prepared  = GroupPreparationService::preparedByProduct($groupId)
       Remaining = max(0, Required − Prepared)          derived server-side
       Over-prep = max(0, Prepared − Required)          derived server-side
```

Consumed through the **existing** `useGroupRequiredProducts` hook and the **existing**
`distributionWorkspaceService.getGroupRequiredProducts` method — neither was written for
this task and neither was modified. This is the same projection `openGroupLoading` embeds,
so the Loading screen and the Loading write path read one manifest.

**Nothing is recomputed in the UI.** `Remaining` and `Over-prepared` are rendered as the
server derives them; the only client-side arithmetic is summing the server's rows into the
card totals, and rounding float noise (`10.000000000000002`) for display.

## 4. How a Group appears with no Vehicle / Driver / Trip

Because **nothing in the read path touches them.** The products query is keyed on
`(window, slot, warehouse)` only. There is no join to `distribution_trips`, no
`vehicle_assignments` row, no `loading_sessions` row and no session id anywhere in the path.

The transport read is **deliberately decoupled** in `useGroupTransport`:

```ts
const execution: ExecutionState = query.isError
  ? 'unknown'
  : trip !== null && vehicle !== null && driver !== null
    ? 'ready'
    : 'blocked';
```

- Products render from their **own** query and are never gated on transport.
- A **failed** transport read yields `unknown`, not `blocked` — reporting a read failure as
  "no vehicle assigned" would be a false statement about the domain. This matters concretely
  today: `GET …/slots/{slot}/trips` currently 500s in the running container because of a
  stale file (see §9), and under that failure the Group and every product still render.
- `Planning only` is a **neutral** badge, not an error. A Group with no vehicle is healthy.

**Nothing is fabricated.** No placeholder vehicle, no default driver, no synthetic trip, no
empty Loading Session is created to make the screen look complete.

## 5. Loaded quantities before and after assignment

| | Before Vehicle/Driver | After Vehicle/Driver |
|---|---|---|
| Group visible | **Yes** | Yes |
| Products + Required/Prepared/Remaining | **Yes** | Yes |
| Loaded quantities | **Not shown** — none exist | Recorded via the existing execution path |
| Execution notice | `executionBlocked` — states the precondition plainly | `executionReady` |

Before assignment there is genuinely no loaded quantity: `loading_tasks` rows are parented by
`vehicle_assignment_id` (NOT NULL), so none can exist. The screen says so instead of showing
a fabricated `0` that would read as "nothing loaded yet" when the truth is "loading has not
started". The products and quantities above the notice are explicitly described as complete.

After assignment, the **existing, unchanged** path applies: `GroupLoadingContextService::open`
(idempotent locate-or-create) → `LoadProductAction` (absolute-set on
`(vehicle_assignment_id, product_id)`) → `VehicleInventoryService` (delta to custody). The
certified execution contract was not touched.

## 6. No snapshot, no synchronization — confirmed

**Confirmed. None was added, and none should be.**

- No new table, no snapshot, no projection store.
- **No event, no listener, no subscriber, no scheduler.** `DistributionAssignmentChanged`
  remains unwired.
- No `loading_sessions` row is created for visibility.

Required is derived live on every read, so there is no copy to keep in step:

| Group change | Reflected | Mechanism |
|---|---|---|
| Order added | next read | none needed |
| Order removed | next read | none needed |
| Order quantity changed | next read | none needed |
| Order leaves eligibility | next read | none needed |

This is the reason the screen **cannot** drift, double-count or lose quantities — the
idempotency requirements in the brief are satisfied structurally, by writing nothing.

React Query keys the products read per `(window, slot, warehouse)`, so a Group's **card** and
its **detail table** share one cached fetch. They cannot disagree, and selecting a Group
issues no second request.

## 7. No schema changes — confirmed

**Confirmed. Zero migrations, zero DDL, zero schema edits.**

`vehicle_assignments.vehicle_id` remains **NOT NULL**.
`loading_tasks.vehicle_assignment_id` remains **NOT NULL**.
`loading_sessions` is unchanged.

Nothing in this task required relaxing them, exactly as the approved architecture predicted:
visibility needs no write, so it needs no column.

The pre-existing untracked migration `allow_group_grain_loading_null_pool_provenance` was
**not** applied and **not** modified — it belongs to the WAVE-1 driver write path, not here.

## 8. No unrequested backend architecture changes — confirmed

**Confirmed. No backend file was opened for editing.** No route added, no controller changed,
no service changed, no permission created, no policy changed, no Distribution architecture
change, no Order architecture change, no Geocoding/Maps change, no Driver/Vehicle assignment
behaviour change.

## 9. Findings that affect this feature in the running environment

Both are pre-existing, both are recorded rather than fixed, per the freeze.

**9.1 — Permission gap (the most important item in this report).**

The two Distribution reads this page now consumes carry `logistics.distribution.view`:

| Permission | Held by |
|---|---|
| `logistics.distribution.view` | Company Admin, Dispatcher, Shipping Coordinator, Fleet Manager, Driver, Fulfillment Supervisor, System Auditor, Operations Director, Shipping Manager, Warehouse Director, CEO, CFO, COO, CTO, Branch Manager |
| **Not held by** | **Warehouse Manager, Warehouse Operator, Preparation Supervisor, Production Manager** |

So the warehouse roles who physically load will receive **403** from the Group reads.

This is **not a regression**: the page's previous entry point required `loading.session.view`,
held only by **Viewer and Company Admin**, so those roles could not use the screen before
either — and saw nothing at all. Supervisory coverage is now substantially wider.

I implemented exactly what the brief specified (item 4 names the endpoint), and did **not**
add a backend route, because backend changes were out of scope and no test could be run to
verify one. **The fix, when approved, is small and has an established precedent:** a thin
Loading-side read route reusing the same `groupLoadingPreparation` projection and carrying
**`operations.preparation.view`** — an existing permission already held by Warehouse Manager,
Warehouse Operator, Preparation Supervisor, Production Manager and the directors. No new
permission, no new projection. The precedent is `PUT …/slots/{slot}/preparation/{product}`,
which is deliberately gated by ACTOR (`operations.preparation.update`) rather than by owning
module, with a route comment stating that gating it on Distribution *"would lock out every
role that performs the work"*.

**Recommend this as the immediate follow-up task.**

**9.2 — Stale container files.** `GET …/slots/{slot}/trips` currently 500s in `ecos-dev-app`
because `GroupLoadingContextService.php` in the container predates `readiness()`
(diagnosed in TASK-DISTRIBUTION-TRIP-STATE-DIAGNOSIS-001). This page **tolerates** that by
design: transport shows `Unavailable` and every Group and product still renders. Not fixed
here — `docker cp` is forbidden by the freeze.

## 10. Static verification performed

`tsc`, ESLint, Vitest, PHPUnit, regression suites and the browser were **NOT** run, per the
freeze. The following are static inspections only:

| Check | Result |
|---|---|
| TSX syntax parse (`@babel/parser`, `typescript`+`jsx` plugins — **no type check, no lint, no emit**) | **PASS** — both files |
| JSON validity, both locales | **PASS** |
| `loadingOs.groups` key count | **42 in en, 42 in ar** |
| i18n key resolution — every `$.loadingOs.*` reference in my code resolves to a string | **76 referenced, 0 missing** |
| `operations` namespace en→ar parity | **0 keys missing in ar** |
| Arabic string literals in source | **0** in both files |
| Bracket balance `{} () []` | balanced in both files |
| Imported symbols exist | `useCurrentDistributionWindow`, `useGroupRequiredProducts`, `useGroupTrips`, `SlotSummary`, `GroupRequiredProduct`, `useOrganizationContext` — all confirmed exported |
| Type compatibility | `activeWarehouseId: string \| null` matches both hook signatures |
| `noUnusedLocals: true` | every import and local reviewed as used |

The i18n resolution check is deliberate: it is the specific class of error `tsc` would
normally catch on this codebase's selector-typed `t()`, and it is the main risk of editing
translated UI without a type-checker.

**Remaining unverified:** full type-checking, lint and any runtime behaviour. Those need
`tsc`, ESLint, Vitest and a browser, all frozen. **This is not claimed as verified beyond
static inspection.**

The `ar` namespace reports 28 keys absent from `en`; all are Arabic ICU plural categories
(`_zero`, `_two`, `_few`, `_many`) under `distribution.*` and `loading.*`. Correct ICU
pluralization, pre-existing, unrelated to this task.

## 11. Data safety confirmation

**No business data was changed.** No INSERT, UPDATE, DELETE, migration, seed, factory,
loading session, vehicle assignment, trip, group finalization or order mutation.

Database access was **read-only** (`SELECT` / `information_schema`) and used only to read the
role→permission matrix for §9.1. No `docker cp`, no restart, no rebuild, no container
mutation, no external/Google API call.

Live state, unchanged: `loading_sessions` 0 · `vehicle_assignments` 0 ·
`distribution_trips` 2 · `distribution_virtual_slots` 3 · grouped orders 9
(DG-001 = 8 loading-eligible, DG-003 = 1, DG-TPL-VERIFY = 0).

## 12. Contract compliance

| # | Requirement | Status |
|---|---|---|
| 1 | Group with eligible orders appears immediately | **Done** — Groups are the entry point |
| 2 | No Vehicle/Driver/Trip/Session prerequisite for visibility | **Done** — read path touches none of them |
| 3 | Group grain, not Session grain | **Done** |
| 4 | Use the existing canonical projection | **Done** — existing hook + service, unmodified |
| 5 | No dummy Loading Sessions | **Done** — none created |
| 6 | Products not tied to Vehicle/Driver/Trip | **Done** — separate query, renders on transport failure |
| 7 | Vehicle/Driver/Trip gate execution only | **Done** — stated in the execution panel, not enforced in the UI |
| 8 | Group edits reflected on next read | **Done** — live derivation, no sync |
| 9 | No listener / stored projection / duplicated quantities | **Done** — none added |
| 10 | No schema/nullability/execution-contract changes | **Done** |
| 11 | Sessions not the entry point; required card + detail fields | **Done** — see §13 |
| 12 | Respect existing eligibility contract | **Done** — server-side, untouched |
| 13–16 | No Distribution / Order / Geocoding / assignment changes | **Done** |
| 17 | No migration | **Done** |
| 18 | No synchronization/event/listener | **Done** |

## 13. Screen contents

**Group card:** Group Code · Zones · Orders count · Products count · Total Required · Total
Prepared · Total Remaining · Over-prepared (only when non-zero) · Vehicle · Driver ·
readiness badge (`Ready to load` / `Planning only` / `Unavailable`).

**Group detail:** products table — **Product | SKU | Required | Prepared | Remaining |
Status** — with a totals line, followed by the execution panel showing Vehicle, Driver and
Trip and one of three plain-language messages.

Status per product: `Over-prepared` when over-prepared > 0; `Ready` when remaining ≈ 0;
`Partly prepared` when some prepared; otherwise `Not started`. Float comparisons use the same
`EPS = 0.00005` the operator workspace already uses.

**"No loading sessions." is no longer rendered.** Empty states now distinguish two different
facts that the old screen collapsed into one: *no distribution window is open* versus *no
Group holds loadable orders*. Telling an operator to create Groups when the real blocker is
that no cycle is open would send them to the wrong place.

---

## Final status

**IMPLEMENTED / STATICALLY VERIFIED**

Browser: **NOT RUN** · Tests: **NOT RUN** · Data mutation: **NONE** · Migration: **NONE**
Commit: **NONE** · Push: **NONE** · Deploy: **NONE**

Loading is now Group-grain. A Distribution Group holding eligible orders appears with its
products and live Required quantities the moment it exists, and Vehicle, Driver and Trip have
been reduced from preconditions for *seeing* the work to preconditions for *recording* it.

The one item needing a decision before real use is the permission gap in §9.1.

**Stopping here. No follow-up task started.**
