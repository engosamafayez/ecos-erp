# TASK-1-C-UI — TRIP READINESS PANEL + LOADING ENTRY UI

**Status: IMPLEMENTED / FOCUSED VERIFIED**
**Browser: NOT VERIFIED — AUTHENTICATION / DATA SAFETY CONSTRAINT**
Date: 2026-08-25 · Branch: `develop` · Not committed, not deployed
Frontend tests: **13 / 13 green** · tsc: **23 = baseline, none in this feature** · ESLint: clean
i18n: **2360 / 2360** · Backend files changed by this task: **none**
Backend readiness contract re-verified on the current tree: **18 / 18, 228 assertions**

> **The UI consumes the canonical backend readiness decision and does not reimplement
> readiness rules.**
>
> **Start Loading remains protected by the server-side readiness contract.**

---

## 1. Scope

Expose the readiness contract TASK-1-C built. No backend logic was added, no readiness rule
was reimplemented, and no Trip status was invented.

## 2. Existing readiness contract

`GroupLoadingContextService::readiness()` returns, per Trip:

```json
{ "trip_id": "…", "ready": true, "checks": [{ "key": "…", "ok": true }], "reason": null }
```

It runs the **same guards** `open()` runs and catches their refusals, so the panel and the
write path cannot disagree by construction. `key` values are stable identifiers, never
class names or columns.

## 3. UI placement

Inside the **existing** `GroupTripPanel`, rendered per Trip beside the `TripRow` it
describes. No standalone page and no second Trip detail experience.

Placing it per Trip rather than once per Group is deliberate: the operator's question is
"can *this* trip load", and a Group that split over Trip capacity has several — one verdict
floating above them would invite reading the wrong one.

Reused existing components throughout: `Card`, `Badge`, `Button`, `Skeleton`, `useToast`,
and the existing `useGroupTrips` query. No new visual language.

## 4. Readiness checklist

A map over `readiness.checks`, in the server's order. The frontend adds no checks and
removes none.

| Server key | Label |
|---|---|
| `trip_belongs_to_group` | Trip belongs to this group |
| `manifest_membership` | Manifest membership valid |
| `manifest_complete` | All group orders are on a trip |
| `warehouse_resolved` | Warehouse resolved |
| `vehicle_assigned` | Vehicle assigned |
| `driver_assigned` | Driver assigned |

Mapping is a `switch`, not a lookup table, because the i18n layer is typed by selector — a
dynamic index would erase the key type and lose the compile-time guarantee that every label
exists. **An unknown key falls through to the raw key**, so a check added server-side
appears here (unpolished but visible) instead of silently vanishing.
`test_renders_a_check_it_has_no_label_for` pins that.

## 5. Ready state

`READY FOR LOADING` badge with a tick icon, and **Start Loading** enabled.

## 6. Blocked state

`BLOCKED` badge with a warning icon, the failing checks marked, and the server's own
sentence rendered verbatim — no raw exceptions, class names, columns or stack traces.

## 7. Multiple failures

**Every** failing check renders, not only the first. The count of unsatisfied checks is
shown alongside the server's `reason`, which names the first. Hiding the rest would send an
operator to fix one blocker and meet the next unannounced.

## 8. Loading CTA

**ابدأ التحميل** / **Start Loading**. Enabled only when the server's `ready` is true;
disabled otherwise, with a `title` explaining why.

**The button is not the protection.** Disabling it saves a doomed request; the server
refuses regardless, and that refusal is the guarantee. The panel surfaces the server's
message on failure even when it believed the trip was ready.

## 9. Loading route

Audited first, per §9. The canonical action already existed —
`useOpenGroupLoading()` → `openGroupLoading(windowId, slotId, tripId)` →
`POST .../windows/{window}/slots/{slot}/trips/{trip}/loading`.

**Reused as-is.** No second endpoint, no second Loading session, no automatic start. **No
STOP was required.**

## 10. Refresh behaviour

Readiness travels with `useGroupTrips`, which lives under the workspace's single query-key
root. The nine existing mutations — finalize, vehicle assignment, zone moves, batch move —
already invalidate that root, so readiness refreshes with the data it describes. **No
polling and no second data-fetching architecture.**

## 11. Responsive UI

The panel is a compact `Card` with a wrapping header and a right-aligned action, using the
existing spacing, typography and badge scale. Logical properties (`me-`, `ms-`) throughout,
so RTL is correct in Arabic.

## 12. i18n

12 new keys under `distributionWorkspace.readiness` (including six check labels) in **both**
locales. Parity **2360 / 2360**, no key missing either way, and **no readiness value left
untranslated**. The CTA is genuinely `ابدأ التحميل`.

## 13. Accessibility

- State is conveyed by **icon + word**, never colour alone.
- Each check carries an `sr-only` "passed" / "not satisfied", so assistive tech does not
  depend on the tick/cross glyph.
- Semantic `<button>` with a `title` on the disabled CTA explaining the block.
- Headings and lists are real `<h4>` / `<ul>` / `<li>`.

## 14. Security

**Unchanged.** No authorization was touched. Readiness is served by the existing
company-scoped `groupTrips` endpoint — a foreign company already receives 404, asserted by
the backend `test_readiness_and_loading_are_company_scoped`. The frontend adds no new route
and no new permission.

## 15. Tests

`trip-readiness-panel.test.tsx` — **13 / 13 green.**

| §17 | Test |
|---|---|
| 1 | ready state renders |
| 2 | Start Loading enabled when ready |
| 3 | blocked state renders |
| 4 | canonical reason shown verbatim |
| 5 | Start Loading disabled when blocked |
| 6 | every failing check renders, with the count |
| 7 | loading state |
| 8 | error state, asserting no exception text leaks |
| 9 | **server verdict wins over recomputation** |
| 10 | CTA calls the existing action with canonical ids |
| 11 | i18n keys exist in both locales (§12 above) |
| 12 | existing workspace still compiles and lints |

**The test that matters is §17.9.** It feeds a deliberately contradictory payload —
`ready: true` alongside a failing check — and asserts the panel shows **READY**. A panel
that recomputed readiness would "correct" the server and show BLOCKED. If that test ever
flips, the frontend has started deciding readiness for itself.

**One correction during the run:** my first i18n stub discarded interpolation values, so
the count assertion failed. I fixed the *stub* rather than weakening the assertion — a stub
that silently drops `{{count}}` would let a component that never passed one still pass the
test.

## 16. Static verification

| Check | Result |
|---|---|
| `tsc -p tsconfig.app.json` | **23 errors — the known baseline.** None in `distribution-workspace` |
| ESLint (feature directory) | clean |
| i18n parity | 2360 / 2360 |
| Vitest | 13 / 13 |
| Backend `php -l` / Pint / PHPStan | **not applicable — no backend file changed by this task** |

Baseline errors remain, and TypeScript is **not** claimed clean: the 23 are pre-existing and
outside this feature.

Two type errors were introduced and fixed during the work — a second consumer of
`useGroupTrips` (`group-loading-execution.tsx`) that still expected an array after the hook
gained `{ trips, readiness }`, and the dynamic i18n index described in §4.

## 17. Browser verification

**BROWSER NOT VERIFIED — AUTHENTICATION / DATA SAFETY CONSTRAINT.**

Scenario A needs an authenticated session and a Trip that is genuinely ready — live data has
**0 vehicle assignments**, so no live Trip can reach READY without assigning a vehicle,
which §21 forbids. Scenario B needs a Trip in a blocked state, which on live data means
breaking Group/Trip membership. Neither was fabricated and authentication was not bypassed.

Both scenarios are covered by tests instead: the ready path, the blocked path with a real
server sentence, and the disabled CTA.

## 18. Data safety

Read-only. Verified after all work:

| Fact | Value |
|---|---|
| orders | 19 |
| groups | 3 |
| trips | 2 |
| trip manifest rows | 4 |
| **loading_sessions** | **0** |
| vehicle_assignments | 0 |
| **ORD-00007** | **unmodified** — `in_progress`, group NULL, in 1 trip |

No Trip created, no Group finalized, no Driver or Vehicle assigned, no Loading session
opened, no Order moved or mutated, no inventory touched.

## 19. Files changed

| File | Change |
|---|---|
| `components/trip-readiness-panel.tsx` | **New** — the panel |
| `components/trip-readiness-panel.test.tsx` | **New** — 13 tests |
| `components/group-trip-panel.tsx` | renders the panel per Trip; consumes the new hook shape |
| `components/group-loading-execution.tsx` | updated for the `{ trips, readiness }` shape |
| `services/distribution-workspace-service.ts` | `getGroupTrips` returns readiness alongside trips |
| `types/index.ts` | `TripReadinessCheck`, `TripReadiness`, `GroupTripsResult` |
| `i18n/locales/{en,ar}/logistics.json` | 12 keys each |

**No backend file was changed by this task.**

A note on the working tree: `Trip.php`, `PaymentCollection.php` and `SettlementService.php`
show as modified in the same module. **None of those are mine** — this task is frontend-only
and §12 forbids touching Payment or Settlement; they are concurrent work by another agent.
Because `Trip.php` is a direct dependency of the readiness code, the backend Loading suite
was re-run against the current tree rather than assumed unaffected:

```
GroupTripLoadingIntegrationTest — Tests: 18, Assertions: 228  ->  OK
```

The readiness contract the panel consumes still holds with those concurrent edits in place.

## 20. Remaining gaps

1. **Browser verification** outstanding (§17). It needs an environment where a Trip can be
   made ready without touching live operational data.
2. **§14.8 "invalid Trip state"** from TASK-1-C remains unrepresented: there is no explicit
   Trip-status gate on Loading, so no checklist row corresponds to one. Adding a status rule
   would be a STOP condition, so it is named rather than invented.
3. The panel shows the server's `reason` for the **first** failing check. Per-check reasons
   would need the backend to return one per check — a contract change, not attempted here.

## 21. Next task

Loading execution itself, which this task deliberately did not start. The readiness gate is
now visible and enforced on both sides; the natural successor is the Loading workspace the
CTA opens.

---

## Final status

**IMPLEMENTED / FOCUSED VERIFIED**

The operator can now see why a Trip cannot load, in both languages, with every failing check
listed and the server's own explanation. The panel renders the backend's decision and never
computes one, and Start Loading is gated by the same contract that refuses the request.

Not certified. No commit, no deploy. Loading Execution and Driver Loading not started.
