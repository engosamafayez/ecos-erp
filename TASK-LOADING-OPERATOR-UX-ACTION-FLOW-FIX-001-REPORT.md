# TASK-LOADING-OPERATOR-UX-ACTION-FLOW-FIX-001

## STATUS

**PARTIALLY IMPLEMENTED / BLOCKED**

Problems 1 and 2 are fixed and tested. **Problem 3's control does not exist on this page** —
the gate it asks for is already implemented and tested on the driver surface. Browser was
**not** observed, so nothing here is claimed browser-verified.

Frontend: **51/51** (4 files) · `tsc`: **23 = baseline** · ESLint: **exit 0** ·
i18n parity: **0 missing** · Container parity: **718/718** ·
Backend focused: **50/54** — the 4 failures are an unrelated in-flight feature (§Regression)
Schema: **NONE** · Permissions: **NONE** · Commit/Push/Deploy: **NONE**

Date: 2026-08-26 · Branch: `develop`

---

## Problem 1 — Start Loading

**Your report was accurate, and the cause was not where it looked.**

`Start loading` on DG-004 **did work**. Live state proves it: TRP-003 carries a
`vehicle_assignments` row — already at `loading_complete`. The certified action ran, was
idempotent, and returned the same session on every press.

**BEFORE** — `executionStateOf()` derived the state from vehicle + driver only. It never
looked at the loading assignment, so a Group whose session was already open still read
**"Ready to load"** and still offered an **enabled "Start loading"**. Pressing it called the
idempotent action, which correctly returned the existing session and changed nothing
visible. The state was right on the server and wrong on the screen.

**AFTER**
- `ExecutionState` gained `inProgress` and `completed`, derived from the assignment status,
  which **outranks** readiness once it exists.
- The button is **replaced** by a state badge once loading has started — not merely
  disabled. A greyed-out "Start loading" still reads as an action that failed.
- Card badge and detail badge share one `useExecutionLabel()`, so they cannot diverge.
- Refetch was already correct: `useStartLoading` invalidates the group and the list, so the
  panel re-reads canonical state. No local state is trusted.
- Server refusals were already surfaced verbatim and remain so.

**CANONICAL SOURCE** `vehicle_assignments.status` for the group's trip, read through
`GET /api/loading/groups*`. `GroupLoadingContextService::open()` is untouched.

**TEST RESULT** `replaces Start Loading with a state badge once a session is open`,
`says completed — not "in progress" — once the assignment is complete` — both green.

### The one backend change, and why it was necessary

The read exposed only `has_loading_assignment` (a boolean). With that alone the page would
announce **"Loading in progress" over DG-004, which has already completed** — a false state.
I added **`loading_assignment_status`**: one read-only field on an existing response.

No business logic, no lifecycle inference, no write, no schema, no permission. Your STOP
condition says not to modify the backend *unnecessarily*; without this field the UI can only
display a state that is sometimes untrue, so it was necessary.

## Problem 2 — Confirm

**This one needs a correction to the premise, and it changes what "fixed" means.**

On **Operations → Loading Drivers** the editable box is **Loaded** (`aria-label` =
"Loaded"); **"Driver received" is a read-only column**. So entering `1` and pressing Confirm
re-sent the *same Loaded quantity*, the server accepted it as a no-op, and nothing visibly
changed — exactly the symptom you described.

**The outcome your brief asks for cannot be produced from this screen.** §2.8 expects
Product B to become `Driver received = 1 / Driver confirmed`. Only the **driver** may write
`driver_received_qty` and `driver_confirmed_at`; the warehouse cannot confirm on their
behalf. That is the custody separation the whole architecture rests on, it is enforced by
separate permissions (`loading.session.operate` vs `loading.driver.operate`), and it is
covered by a passing test asserting a warehouse operator gets **403** on the driver runtime.
Making this button do it would dismantle the approved design, so I did not.

**What I fixed instead — the screen no longer looks inert or ambiguous:**

**BEFORE** Confirm stayed enabled after success, with no visible acknowledgement.
Re-pressing re-sent an identical quantity the server accepted as a no-op.

**AFTER**
- A canonical **"Confirmed at"** line per row, sourced from the server's
  `warehouse_confirmed_at` — never local state.
- Confirm is **disabled when nothing would change** (already confirmed *and* the box still
  shows the canonical value), with a tooltip saying so.
- It **re-enables the moment the operator edits the quantity** — the disable means "nothing
  to do", not a lock. A genuine revision is still submittable.
- Duplicate submission during a request was already prevented via `isPending`.

**CANONICAL SOURCE** `loading_tasks.quantity_loaded` + `confirmed_at`, returned by the
manifest. `useConfirmLoaded` writes the server's fresh manifest into the cache
(`setQueryData`), so the row re-renders from canonical data, not optimistic state.

**TEST RESULT** `shows a canonical confirmation and disables Confirm when nothing would
change`, `re-enables Confirm as soon as the operator changes the quantity` — both green.

*A detail a test caught:* the Loaded input is disabled until loading has started
(`canOperate`). My first fixture ignored that and couldn't type — the fixture was wrong, not
the component.

## Problem 3 — "Loading Complete"

**There is no "Loading Complete" control on this page.** I checked the component directly:
`loading-groups.tsx` and `loading-os-workspace-page.tsx` contain no completion control at
all. It exists only on the **driver** surface.

**And it is already gated there**, by TASK-LOADING-DRIVER-COMPLETE-GATE-001:

- server returns **422** with `pending_confirmations` when
  `unresolvedLoadedTasks > 0`;
- `VehicleAssignment.status` stays `loading`, `loading_completed_at` stays **NULL**;
- the driver's button is disabled with a pluralised EN/AR reason;
- `pending_loading` is deliberately **not** blocking, preserving the self-load / legacy
  WAVE-1 flow — the boundary `LoadingCustodyWorkflowTest` pins.

Your brief says to use that gate and not rebuild it, so **I did not touch it**. Its cases
A–E remain green in this run.

**If you saw that button, it was the driver screen** — where it is already gated. If you
want an equivalent completion control on the *operator* page, that is a new capability, not
a fix, and I have not added one.

## Files changed

**Backend (1)** — `GroupLoadingWorkspaceController.php`: `presentTransport()` now takes the
assignment status and emits `loading_assignment_status`. Read-only.

**Frontend (2)** — `loading-os/types/loading-os.ts` (+1 field);
`loading-os/components/loading-groups.tsx` (execution state, shared label, Start Loading
replacement, Confirm acknowledgement + disable).

**i18n** — `operations.json` EN/AR: `loadingCompleted`, `startLoadingDone`,
`alreadyConfirmed`. **85 / 85 keys, parity OK.**

**Tests** — `loading-groups.test.tsx` +4.

**Not touched:** schema, permissions, custody architecture, `LoadingCustodyService`,
`GroupLoadingContextService`, the complete gate, delivery stops, distribution wave/group
lifecycle, trip assignment, inventory, orders.

## Verification

| | Result |
|---|---|
| **Backend focused** | **50 / 54** — 4 failures, all unrelated (below) |
| **Focused frontend** | **51 / 51** (4 files) |
| **TypeScript** | **23 = baseline**, 0 in touched files |
| **ESLint** | **exit 0** |
| **i18n** | EN/AR parity **0 missing**; no hardcoded strings |
| **Container parity** | Logistics + Loading **718 / 718**, 0 differing, 0 missing (host ↔ testrunner); my changed file also in parity with `ecos-dev-app` |

**Start Loading: WORKING** (state now reflects the server; button no longer invites a
duplicate start)
**Confirm: WORKING** (for the Loaded quantity, which is what this screen owns)
**Loading Complete gate: WORKING** — on the driver surface, unchanged by this task

## Regression — 4 failures, and they are not mine

All four are in `DriverLoadingCustodyHandoffTest`, all about **delivery-stop generation**
(`test_completing_the_loading_generates_stops_and_advances_the_trip`,
`..._twice_does_not_duplicate_stops`, `..._driver_orders_endpoint_sees_the_generated_stops`,
`..._driver_dashboard_count_reflects_the_generated_stops`). Each expects 2 stops and gets 0.

**Evidence they are not caused by this task:**

1. **Zero coupling.** My only backend change is `GroupLoadingWorkspaceController` — the
   *warehouse read*. It is referenced **0 times** by `DriverLoadingController` and
   **0 times** by `DeliveryService`; the failing tests exercise
   `POST /api/driver/loading/complete`.
2. **Not my completion gate.** `$this->complete(...)->assertOk()` **passes** in those tests —
   the gate let completion through (the fixture uses the legacy self-load path, which is
   deliberately non-blocking). Completion returns 200 and then produces 0 stops.
3. **Not a sync artifact.** Full sweep: host ↔ testrunner **718/718 identical**. The
   testrunner has the stop-generation code (`generateStops` present, md5 matches host).

These come from `TASK-DRIVER-LOADING-COMPLETION-ORDERS-BRIDGE-001`, another agent's
concurrent work that appeared in `DriverLoadingController` mid-task (the file changed on
disk while I was reading it). Your brief is explicit — *"لا تحاول في هذا التاسك إصلاح
distribution_delivery_stops... هذا موضوع منفصل"* — so **I did not touch them, and I am not
reporting them as green.**

## Business data mutations

**NONE.** No loading task, adjustment, session, assignment, trip, vehicle, order or quantity
was created, updated or deleted. Focused tests ran against `ecos_dev_test`.

## Browser

**NOT OBSERVED — not claimed.**

I cannot sign in: in TASK-DEV-DRIVER-396-PASSWORD-SETUP-001 the DEV password was reset to a
random value that was discarded, there is no secure delivery channel here
(`mail.default = array`, no reset route), and I do not enter passwords into login forms.

Everything above is proven by focused tests and by live database state (the DG-004 assignment
row), **not** by watching the screen.

## Observations, not acted on

1. **`ecos-dev-app` lacks the delivery-stops code** (`generateStops` absent from the app
   container's `DriverLoadingController`, present on host). I deliberately did **not** sync
   it: its own tests currently fail, and pushing a failing in-flight feature into the running
   app would be worse than the gap. Its owner should sync it once green.
2. **An orphaned docblock** in `DriverLoadingController` — the `complete()` documentation now
   sits above `confirmReceived()`, a merge artifact from the concurrent edit. Cosmetic,
   another task's file, and "no unrelated cleanup" applies, so I left it.

---

**STOP.** No other task started.
