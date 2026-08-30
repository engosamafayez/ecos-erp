# TASK-DRIVER-APP-SHELL-HOME-NAVIGATION-FINAL-001

## 1. Executive Summary

**You were right, and my previous conclusion was wrong.**

In TASK-DRIVER-APP-UX-FOUNDATION-001 I reported the driver navigation as *"already
satisfied — CERTIFIED, no change required."* That verdict came from reading navigation
**config** (`mobile-bottom-nav.tsx`, `module-navigation.ts`) in isolation. I never checked
what actually **renders around** driver pages, and that is where the defect was.

**The cause:** driver routes were children of `AppShell` (`router.ts:244`), which
unconditionally mounts the full ERP chrome — the **ModuleRail**, the enterprise
**AppSidebar**, a topbar carrying the **Company/Warehouse switchers and global search**, and
a **MobileMenu rendering `APP_MODULES.map(...)`**, i.e. *every* ERP module. Only
`MobileBottomNav` was driver-aware, which is precisely why a config-level audit passed while
your screenshots showed ERP navigation.

**The fix:** driver routes now render inside a dedicated **`DriverShell`**, a *sibling* of
`AppShell` matched first, so `/driver/*` can never resolve into the ERP shell again. The
shell imports no ERP chrome at all.

Also delivered: the Home **end-of-day state**, which was genuinely missing —
`settlement_pending` was collapsed into "completed", telling a driver the day was over while
a settlement was still owed.

**Status: IMPLEMENTED — BROWSER VERIFICATION BLOCKED.** By your success criteria (#10, and
"do not claim completion based solely on static source inspection when the actual UI can be
observed") this is **not** complete until you can see it.

Backend: **NONE** · Frontend: 6 files · Live data: **UNCHANGED** · Commit/Deploy: **NONE**

Date: 2026-08-26 · Branch: `develop`

---

## 2. Before State

Driver routes were 14 entries inside the `AppShell` children array. Everything the driver saw
around their page was the enterprise shell.

```
ProtectedRoute
└── AppShell                    ← ERP chrome, unconditional
    ├── AppTopbar               CompanySwitcher · WarehouseSwitcher · GlobalSearch · SmartCreate
    ├── ModuleRail              every ERP module
    ├── AppSidebar              enterprise context sidebar
    ├── MobileMenu              APP_MODULES.map(...)  ← the whole ERP
    ├── MobileBottomNav         (the ONLY driver-aware piece)
    └── …14 driver routes
```

---

## 3. Actual Driver Menu — BEFORE

| Surface | What the driver actually got |
|---|---|
| Topbar | Company switcher, Warehouse switcher, global search, smart-create |
| Module Rail (md+) | All ERP modules |
| Sidebar (lg+) | Enterprise context sidebar |
| **Mobile menu** | **`APP_MODULES.map(...)` — Commerce, Inventory, Purchasing, Marketing, CRM, HR, Finance, System…** |
| Bottom nav | ✅ correct — driver items only |

So one surface out of five was right. My previous audit looked at that one.

---

## 4. Driver Menu — AFTER

`DriverShell` renders only the driver's own destinations, from its own list:

**Bottom bar (thumb reach, 4):** Home · Loading · Orders · Vehicle stock
**Menu sheet:** Home · Orders · Loading · Vehicle stock · Returns* · Wallet*

`*` rendered as a **disabled row with a reason** ("From current trip") rather than a dead
link — the menu tells the truth about what is reachable now. Returns and Settlement exist
only as **trip-scoped** screens (`/driver/trips/:tripId/…`), and the Wallet has no backend
read at all (§14).

**Not present, by construction:** Commerce, Inventory administration, Purchasing, Marketing,
CRM, HR, Finance administration, System/Company/Warehouse administration, dispatcher and
operator tools. `DriverShell` never imports `APP_MODULES`, `ModuleRail`, `AppSidebar`,
`MobileMenu`, `CompanySwitcher`, `WarehouseSwitcher` or `GlobalSearch` — verified:

```
grep "^import" driver-shell.tsx  → react, react-i18next, react-router-dom, lucide,
                                    header{NotificationCenter,UserMenu,HeaderProvider},
                                    Button, Sheet, Company/OrganizationProvider, cn, ROUTES
```

The only matches for those ERP names in the file are inside the docblock explaining why they
are absent.

---

## 5. Driver Shell

`src/components/layout/driver-shell.tsx` — mobile-first, one-handed:

- **Slim identity header (h-14):** menu button · "Driver Operations" · notifications · user
  menu. No tenant switchers, no global search — a driver works one shipment, not a tenant.
- **Full-width content**, padded for the bottom bar.
- **Fixed bottom bar (h-16, 4 columns)** — thumb-reachable, active state by route.
- **Menu sheet** with the driver's lifecycle destinations.

**Why a sibling shell rather than a flag on `AppShell`:** branching inside `AppShell` would
thread driver conditionals through every ERP user's shell. A sibling keeps them apart — the
ERP shell is untouched, and this one *cannot* render an ERP module because it never imports
one.

**Routing (Part 15), proven programmatically:**

```
driver routes inside the DriverShell branch : 14
ROUTES.driver* still under AppShell         : 0
DriverShell declared BEFORE AppShell        : True
```

Operator/dispatcher routes were not touched. Deep links stay protected — both shells sit
inside the same `ProtectedRoute`.

---

## 6. Home Redesign

The existing Home was already lifecycle-derived (a previous task built that), so this task
did **not** rearrange cards for their own sake. It closed the one structural hole: **the day
never ended.**

`COMPLETED_STATES` lumped `settlement_pending` together with `completed` and `closed`, all
returning `actionKey: null`. A driver who still owed a settlement was shown "completed" and
no action — the app said the day was over when it was not.

**Changed:** `settlement_pending` is now its own state with its own action, and a **Day
Summary** block renders at end of day only.

---

## 7. Start-of-Day State (A/B/C)

Already present and unchanged — driven by canonical state, not by a UI machine:

| State | Condition | Shows | Action |
|---|---|---|---|
| A/B Loading | `pending_loading` / `awaiting_driver_confirmation` / `awaiting_driver_reconfirmation` | product count, awaiting-confirmation count | Start / Continue loading, Confirm received |
| C Ready | `loading_completed` | orders/stops, delivery progress 0 | View orders |

---

## 8. During-Day State (D)

`ON_THE_ROAD = ['dispatched','out_for_delivery','in_progress']` → current work
"In delivery", remaining-stop count, and the orders block showing **total / delivered /
remaining / failed**, each derived from canonical `DeliveryStop.status`.

---

## 9. End-of-Day State (E) — NEW

Renders only when the driving is over, so mid-day Home stays uncluttered (pinned by a test).

```
Day summary
  Orders   Delivered   Failed   Remaining
  Vehicle stock  →  "N to return"  |  "Clear"
  Settlement     →  "Pending"      |  "Complete"
```

- **Order outcomes** — from the stop rows Home already holds.
- **Vehicle stock** — from `useVehicleInventory()`, already fetched by Home.
- **Settlement** — from the canonical trip status (`settlement_pending` vs `closed`).

`settlement_pending` → **"Settlement due"** + a **Start settlement** CTA routed to the
canonical trip settlement screen. `closed`/`completed` → summary and **no action button at
all** — a finished day looks finished, not disabled.

**No money is shown.** The brief asks for a collection summary "if available"; the cross-trip
financial read does not exist (§14), and per-trip amounts are not on this screen's reads. I
did not aggregate them client-side.

---

## 10. Current Trip

The Home header carries `currentTrip.trip_number` and `currentTrip.vehicle_plate`; the work
block carries trip status; the orders block carries stops/delivered/remaining/failed; the
loading detail carries loading state. These were already present and are unchanged.

---

## 11. Next Action

One primary CTA (`h-14`, full width), derived from canonical lifecycle state — never
hardcoded. Actions: `startLoading` · `continueLoading` · `confirmReceived` ·
`loadingComplete` · `viewOrders` · `nextStop` · **`startSettlement` (new)** · none when
complete.

---

## 12. Vehicle / Load Summary

Present on Home from `useVehicleInventory()` — loaded vs on-hand — and surfaced again in the
Day Summary as "to return / clear". Driver custody view only; no warehouse administration.

---

## 13. Exceptions

The failed/exception count renders in the orders block, and the Day Summary shows failed
alongside delivered. Per Part 9, no empty warning card is rendered when there is nothing to
act on. A dedicated Attention section beyond this was **not** added — its remaining triggers
(payment/POD issue, return pending, settlement issue) belong to workflows Part 17 defers.

---

## 14. Financial / Wallet Dependency — **BLOCKED (missing read contract)**

Every driver financial read is **trip-scoped**, enumerated from the live route table:

```
GET  /api/driver/trips/{tripId}/settlement
GET  /api/driver/trips/{tripId}/collections
POST /api/driver/trips/{tripId}/settlement/submit
```

There is **no** `/api/driver/wallet`, `/financial*`, `/earnings` or `/settlements`. A Wallet
is by definition cross-trip, so building it now would mean aggregating trip responses in
React — a second financial computation, which Part 10 forbids. The Wallet is therefore in the
menu as a **disabled row with a reason**, not a fake screen.

### MISSING BACKEND FINANCIAL READ CONTRACT

```
GET /api/driver/wallet?from={date}&to={date}
  auth   : auth:sanctum + permission:loading.driver.operate
  scope  : the authenticated driver only, fail-closed via logistics_drivers.user_id
           (never a driver_id parameter — that would let one driver read another's)

  {
    "period": { "from": date, "to": date },
    "expected_collection":   number,   // what the trips say should have been collected
    "actual_collection":     number,   // what was actually collected
    "cash":                  number,
    "electronic":            number,   // InstaPay / wallet / card, split if supported
    "difference":            number,   // expected − actual
    "advances":              number,   // only if officially supported; else omit
    "expenses":              number,   // only if officially supported; else omit
    "settlement_status":     "none" | "pending" | "submitted" | "settled" | "disputed",
    "last_settlement_at":    string|null,
    "trips": [ { trip_id, trip_number, date, expected, collected, difference, status } ]
  }
```

**Every field must be a projection over the existing canonical settlement/collection tables.**
It must not recompute money the Settlement service already owns, and it must not become a
second financial authority. No Finance accounting entries in that task either.

---

## 15. Data Source Map (Part 16)

| Home field | Hook | API | Controller | Source |
|---|---|---|---|---|
| Driver name | `useAuthStore` | `/api/auth/me` | Auth | `users` |
| Vehicle plate | `useDriverTrips` | `GET /api/driver/trips` | `DriverRuntimeController::trips` | `vehicle.plate_number` via assignment |
| Trip number | `useDriverTrips` | same | same | `distribution_trips.trip_number` |
| Trip status | `useDriverTrips` | same | same | `distribution_trips.status` |
| Total orders/stops | `useDriverStops` | `GET /api/driver/trips/{id}/stops` | `DriverRuntimeController::stops` | `distribution_delivery_stops` |
| Delivered | `useDriverStops` | same | same | `DeliveryStop.status = delivered` |
| Remaining | `useDriverStops` | same | same | `status ∈ {pending,in_progress}` |
| Failed / exception | `useDriverStops` | same | same | `status = failed` |
| Loading status | `useDriverLoading` | `GET /api/driver/loading` | `DriverLoadingController::manifest` | `loading_tasks` + `LoadingCustodyService` |
| Awaiting confirmation | `useDriverLoading` | same | same | custody state machine |
| Vehicle loaded / on hand | `useVehicleInventory` | driver vehicle-inventory read | `DriverRuntimeController` | `vehicle_inventory_items` |
| Settlement status | `useDriverTrips` | `GET /api/driver/trips` | same | trip status (`settlement_pending`/`closed`) |
| Next action | — | — | — | **derived** from the above; no independent source |
| **Collection amounts** | — | — | — | **MISSING — §14** |
| **Cross-trip financial** | — | — | — | **MISSING — §14** |

Every displayed number has an identified canonical source. Nothing on Home is computed from a
second authority or from stale client state.

---

## 16. Security Verification

**UI hiding is not treated as security, and nothing was relied on it.**

- Server-side unchanged: every `/api/driver/*` route is behind `auth:sanctum` +
  `permission:loading.driver.operate`, with per-request ownership (`ownedTrip` / `ownedStop` /
  `ownedTask`) returning **404** on another driver's data.
- The driver app calls **only** `/api/driver/*` (plus `/api/auth/me`) — no
  `/logistics/distribution/*` call exists in the feature.
- The canonical `driver` role holds **no orders permission**, so the enterprise Orders API
  refuses a driver token regardless of navigation.
- Both shells remain inside `ProtectedRoute`; deep links are unchanged.
- **No permission was changed in this task.**

The shell change removes the *ability to navigate* to ERP surfaces; the *authorization* that
would refuse them was already in place and is untouched.

---

## 17. Files Changed

| File | Change |
|---|---|
| `components/layout/driver-shell.tsx` | **NEW** — dedicated driver shell |
| `router/router.ts` | 14 driver routes moved from `AppShell` to a `DriverShell` sibling matched first |
| `driver-mobile/pages/driver-home-page.tsx` | `settlement_pending` split out; Day Summary block |
| `driver-mobile/pages/driver-home-page.test.tsx` | +3 tests |
| `i18n/locales/en/driver-mobile.json` | `shell.*`, `home.daySummary.*`, 2 state keys |
| `i18n/locales/ar/driver-mobile.json` | same |

**Not touched:** `AppShell`, `ModuleRail`, `AppSidebar`, `MobileMenu`, `MobileBottomNav`,
enterprise routes, the ECOS design system, and every backend file.

---

## 18. Tests

| Check | Result |
|---|---|
| Driver frontend tests | **32 / 32 green** (was 29; +3 for the end-of-day split) |
| TypeScript | **23 = documented baseline**, **0** in driver-mobile / driver-shell / router |
| ESLint (changed files) | **exit 0** |
| i18n EN↔AR base-key parity | **OK** (verified programmatically over the whole namespace) |
| Backend regression | **not run — no backend file was touched** |

New tests: settlement-pending shows a day summary **and** a settlement action (not
"completed"); closed shows the summary with **no action at all**; mid-day does **not** show
the summary.

No test was weakened; no certified backend behaviour was altered.

---

## 19. Browser Verification

### **BROWSER VERIFICATION BLOCKED**

Re-checked after the change: `http://127.0.0.1:5173/app/driver/home` **redirects to
`/app/login`**. No session, no cookies, no token.

The DEV driver password was generated, never printed, and discarded by explicit instruction in
TASK-DEV-DRIVER-396-PASSWORD-SETUP-001; `mail.default = array`, so there is no reset channel.
This brief forbids creating or resetting credentials, so I did neither.

**None of Part 18's 10 observations was made.** In particular, the central claim of this task —
that the driver no longer sees ERP navigation — is proven **structurally** (routes, imports,
0 `ROUTES.driver*` under `AppShell`) but **not visually**.

**To unblock:** leave a signed-in driver session open in the browser, or sign in yourself with
a password you hold and hand me the authenticated tab.

---

## 20. Demo Data Considerations

No DEV business data was read as business truth, and none was modified to make the UI look
correct. The Home states are driven by the **canonical lifecycle** (`TripStatus`, custody
state machine, `DeliveryStop.status`), not by the shape of current demo rows.

Worth noting for whoever does browser verification: **no live trip has ever departed** —
`dispatched_at` and `trip_started_at` are NULL on all three DEV trips — so States **D** (in
delivery) and **E** (end of day) have **no demo data that can exercise them**. Verifying them
in the browser needs a trip driven through dispatch first, which this task is forbidden from
doing.

---

## 21. DEV RBAC Drift (reported separately, not acted on)

Unchanged from the previous report, repeated here because Part 14 requires it:

The live DEV `driver` role holds **4** permissions; the canonical catalogue
(`config/permissions.php:506`) grants **2**. The extras —
`logistics.distribution.view` and `logistics.distribution.update` — were **already revoked in
the catalogue**, with a comment recording that granting them let a driver "record a payment
and verify it, on any company's trip". `logistics.distribution.update` guards **43** routes,
including `PATCH /trips/{tripId}/returns/{returnId}/confirm`.

The code is correct and guarded by
`DriverRbacTenancySecurityTest::test_a1_…_no_dispatcher_authority`, which passes. **The drift
is DEV data only.**

**I did not run the RBAC seeder and did not mutate any DEV user**, per Part 14.

---

## 22. Remaining Driver App Gaps

1. **Browser verification** — the whole task, unobserved.
2. **Driver Wallet backend read** — §14 contract; blocks the Wallet screen.
3. **Returns / Settlement as top-level destinations** — both exist only trip-scoped; promoting
   them needs either a current-trip resolver in the shell or cross-trip reads.
4. **States D and E have no exercisable demo data** (§20).
5. **Deferred by Part 17:** Loading confirmation detail, Delivery, Payment, POD, Failed
   delivery, Returns execution, Reconciliation, Finance.
6. **DEV RBAC drift** — needs your authorization to re-seed.

---

## 23. Final Status

**IMPLEMENTED — BROWSER VERIFICATION BLOCKED.**

Against your success criteria:

| # | Criterion | Status |
|---|---|---|
| 1 | Driver UI no longer presents generic ERP navigation | ✅ structurally proven — 0 driver routes under `AppShell`, no ERP chrome imported |
| 2 | Dedicated operational menu | ✅ `DriverShell` bottom bar + menu sheet |
| 3 | Home is a Daily Command Center | ✅ lifecycle states A–E, end-of-day added |
| 4 | Supports start-of-day and end-of-day | ✅ |
| 5 | Current trip and next action immediately understandable | ✅ single primary CTA |
| 6 | All metrics have canonical sources | ✅ §15 map |
| 7 | Missing financial contract documented, not fabricated | ✅ §14 |
| 8 | Certified backend intact | ✅ no backend file touched |
| 9 | Tests green | ✅ 32/32, tsc baseline, ESLint 0, i18n parity |
| 10 | **Browser verification honestly reported** | ⚠️ **BLOCKED — reported, not claimed** |

Criterion 10 is the one that decides it, and your brief is explicit that routes being
driver-scoped is not sufficient — which is exactly the mistake I made last time. I am not
repeating it: **this is implemented and structurally proven, not verified.**

---

**STOP.** Task 1 only. No Loading, Delivery, Payment, Returns or Finance work started.
