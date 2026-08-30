# TASK-DISTRIBUTION-WAREHOUSE-ASSIGNMENT-RESOLUTION-001 — ENGINEERING REPORT

**Status: IMPLEMENTED / FOCUSED VERIFIED**
Date: 2026-08-24 · Branch: `develop` · Not committed, not deployed

---

## 1. Headline

**The entire backend for this feature already existed.** Discovery found a registered
route, a manual assignment service, an audit table, a permission gate and a tenancy
contract already in place — so this task added **no endpoint, no engine, no permission,
no audit mechanism and no migration**.

What was missing was the operator's way to reach it. That is what was built: an
order-level **Assign warehouse** action on the existing *Orders Awaiting Group Assignment*
surface, plus a React mutation hook, plus the correction of a latent 404 in the one
frontend client method that already targeted the endpoint.

| Layer | Verdict |
|---|---|
| Order ↔ Warehouse relationship | **Existed** — `orders.assigned_warehouse_id` |
| Manual assignment service | **Existed** — `WarehouseAssignmentEngine::override()` |
| HTTP endpoint | **Existed** — `POST api/orders/{order}/override-warehouse` |
| Permission | **Existed** — `permission:sales.orders.update` |
| Company scope | **Existed** — Order `tenant` global scope + explicit warehouse check |
| Audit trail | **Existed** — `warehouse_assignment_overrides` |
| Frontend client method | **Existed but broken** — wrong path, zero callers → corrected |
| React mutation hook | **Written** |
| UI action | **Written** |
| Migration | **None required** |

---

## 2. Discovery: what already existed (§1)

`§1` required inspecting the existing warehouse relationship, services and validation
before writing anything, and forbade a second engine. The inspection found:

- **`BranchAssignmentEngine`** is the sole writer of `orders.assigned_warehouse_id`
  (lines 141, 183), with a manual entry point `override(Order, Branch, string $reason)`
  at line 168.
- **`WarehouseAssignmentEngine::override()`** (line 72) wraps the write in a transaction,
  creates the audit row (line 81), stamps
  `warehouse_assignment_source = manual_override`, and dispatches `WarehouseAssigned`
  (line 96).
- **`warehouse_assignment_overrides`** already carries exactly the four audit facts §18
  requires — `overridden_by`, `overridden_at`, `previous_warehouse_id`,
  `new_warehouse_id` — plus `reason`.
- It had **one pre-existing row**: ORD-00001, assigned 2026-08-19 04:16:53 by user 1,
  reason *"Manual assignment for validation (no coverage configured)"*. The manual path
  had been exercised before; it simply had no UI.

**Conclusion: no new selection engine was created, because one existed.**

---

## 3. The two endpoints are not interchangeable — and only one is legitimate here

`routes/api.php:582-584` registers three routes. Two of them assign a warehouse, and the
difference is the whole point of this task:

| Route | Behaviour | Used here? |
|---|---|---|
| `POST orders/{order}/assign-warehouse` | Calls `engine->assign()`, which runs `findMatchingPolicy()` and **the system chooses** | **No — forbidden** |
| `POST orders/{order}/override-warehouse` | Operator supplies `warehouse_id` + `reason`; stored verbatim | **Yes** |
| `GET orders/{order}/assignment-history` | Reads the audit trail | Not wired (see §21) |

The task states: *"Warehouse assignment is a MANUAL OPERATOR DECISION. The system MUST NOT
automatically choose a Warehouse."* `assign-warehouse` does exactly what is forbidden, so
it is deliberately never called by this feature. `override-warehouse` is the reuse target.

---

## 4. Nothing infers the warehouse (§2)

The prohibition was against inferring the warehouse from Zone, City, Governorate, Driver,
Vehicle, Group or Trip. Holding to it:

- The Select opens with **no pre-selection** — no default, no ranking, no highlight, no
  "suggested" option. The operator must pick.
- No zone, city, governorate, group, trip, driver or vehicle value is read anywhere in the
  dialog, the hook, or the request payload. The payload is exactly
  `{ warehouse_id, reason }`.
- The one code path that *does* infer (`findMatchingPolicy`) is not invoked.

This is proven, not asserted:
`test_the_warehouse_sent_wins_over_any_policy_that_would_match` creates a
`WarehouseAssignmentPolicy` that matches the order's governorate **and** zone and points
at warehouse B, then sends warehouse A — and A is what gets stored.

---

## 5. Eligibility: the existing minimum rule, enforced server-side (§3)

There was no pre-existing "warehouse eligibility" contract beyond company ownership, so
§3's minimum rule applies: **active warehouses of the same company**.

- **Where the list comes from**: `GET /api/warehouses?status=active` →
  `EloquentWarehouseRepository` applies `where('is_active', true)`; company scope comes
  from the `Warehouse` `tenant` global scope, which the caller **cannot widen**
  (`TASK-GOLIVE-RC6-REPAIR-001`).
- **Where the write is authorised**: independently re-checked at the endpoint —
  `Warehouse::where('id', ...)->where('company_id', $user->company_id)->firstOrFail()`.

The selector narrows what is *offered*; it authorises nothing. A tampered `warehouse_id`
fails at the endpoint regardless of what the dropdown contained.

**One honest gap, pre-existing and unchanged:** the write endpoint checks *company* but
not `is_active`. An API caller could therefore still assign an inactive warehouse of its
own company. The UI never offers one. I did not add an active-check to the shared
endpoint, because that would be a new eligibility rule imposed on a certified path used
by other callers — it is reported here rather than silently changed.

---

## 6. Authorization is the existing permission (§19)

`permission:sales.orders.update` — the permission already governing order modification.
**No new permission was created, and the existing one is not ambiguous**, so §19's STOP
condition did not trigger: it is the same verb already used by 5 sibling order-mutation
routes on adjacent lines (`verify-payment`, `record-payment`, and the assign/override
pair itself).

Proven by `test_the_existing_order_update_permission_is_required` (403) and
`test_the_endpoint_requires_authentication` (401).

---

## 7. Company scope is enforced in the backend, never the frontend (§21)

Two independent server-side barriers, both pre-existing:

1. **The order** — `Order` carries a fail-closed `tenant` global scope
   (`TASK-GOLIVE-RC6-REPAIR-001`): a null company yields `whereRaw('1 = 0')` rather than
   an unfiltered query, and cross-company access requires an explicit `is_system` role.
   So `Order::where('id', $orderId)->firstOrFail()` is already company-scoped → 404.
2. **The warehouse** — the explicit same-company check quoted in §5 → 404.

**A note on the controller's `authorizeOrderAccess()`**: it is an **empty method body**
with a comment saying full RBAC "can be added when permissions are wired". I verified this
is *redundant*, not a hole — the model-level global scope does the work. It is worth
knowing it provides nothing, so it is recorded here.

**Why the tenancy tests use a hand-built role**: `TestCase::actingAs()` attaches the
`is_system` role, which switches the Order tenant scope **off** by design. A cross-company
test written with `actingAs()` would pass while proving nothing. Those cases therefore
create a non-system role holding exactly `sales.orders.update`.

Proven by `test_a_warehouse_belonging_to_another_company_is_refused` and
`test_an_order_belonging_to_another_company_is_not_addressable`.

---

## 8. §7 — the order moves to the next real blocker, not out of sight

The requirement: after assignment the order must move from `WAREHOUSE_UNASSIGNED` to the
appropriate existing Zone/Group exception state.

**This needed no code**, because `ordersAwaitingGroup()` recomputes the blocker on every
read from `$order['warehouse_id']`, in a fixed precedence: warehouse → zone → awaiting.
Assigning a warehouse makes the first condition false, and the order re-classifies.

Two tests pin both outcomes:

| Scenario | Before | After |
|---|---|---|
| Zone in no Group | `warehouse_unassigned` | `zone_not_in_group` |
| Zone already in a Group | `warehouse_unassigned` | `awaiting_group_assignment` |

In both cases `summary.total` stays **1**. The order is *not* resolved by getting a
warehouse — it remains an exception, correctly, under a different reason.

---

## 9. No automatic grouping, and no Trip (§7)

*"Do NOT automatically move the Order to a Group. Do NOT automatically create a Group."*

- `test_the_assignment_creates_no_group_and_joins_none` — `distribution_virtual_slots` and
  `distribution_slot_zones` counts unchanged, and the order's `virtual_slot_id` stays
  `NULL`.
- `test_the_assignment_creates_no_trip` — `distribution_trips` count unchanged.
- The dialog says so in the UI, in both languages, so a cleared warehouse blocker is never
  misread as "planned".

**On events**: `WarehouseAssigned` is dispatched, as it always has been for both automatic
and manual assignment. Two pre-existing listeners subscribe —
`ExecuteReservationOnWarehouseAssigned` (ADR-027 H3) and Preparation's
`WarehouseAssignedListener`. Neither assigns a Group or a Trip. **This task changed
neither listener and did not alter the event contract.** Reusing the existing engine means
inheriting the existing consequences of assignment, which is the intended behaviour.

`test_the_order_status_is_not_rewritten_by_the_assignment` confirms the endpoint performs
no status write of its own. Scoped honestly: that fixture has no reservable inventory, so
it proves the *endpoint* writes no status — not that ADR-027 H3 can never advance an order
under other conditions.

---

## 10. Bulk assignment: deliberately NOT built (§14)

§14 made bulk optional, required it to be atomic ("if any selected Order cannot be
assigned: NONE should be partially updated"), and instructed: *if it needs new mutation
architecture, STOP and keep the feature Order-level only.*

The existing endpoint assigns **one** order per request. Atomic multi-order assignment
would require a new endpoint wrapping N assignments in one transaction — new mutation
architecture, by definition. **Per the instruction, the feature is Order-level only.**
A client-side loop was rejected outright: it produces exactly the partial-update outcome
§14 forbids.

---

## 11. UI placement (§12)

Placed on the **existing** *Orders Awaiting Group Assignment* card on the Distribution
Groups board. **No standalone Warehouse page was created.**

- The button renders **only** on rows whose server-computed blocker is
  `warehouse_unassigned`. An order stranded on its Zone already has a warehouse, and
  offering "assign warehouse" there would invite re-assigning a correct one to fix an
  unrelated problem.
- One dialog serves the whole list, not one per row.
- Arabic label: **تعيين المخزن**, exactly as specified.

---

## 12. What the operator sees

1. **Warehouse** — active, company-scoped, no pre-selection.
2. **Reason** — required, ≥10 characters (mirrors the server's `min:10`), max 500. The hint
   states that it is stored in the audit trail.
3. **Visibility warning** — if the chosen warehouse differs from the board's warehouse
   filter, the dialog says the order will leave this view. Verified by
   `test_choosing_another_warehouse_moves_the_row_out_of_a_filtered_view`: the row vanishes
   from the filtered payload and appears in the unfiltered one. Without this the row would
   silently disappear and read as data loss.
4. **"This does not group the order"** — stated in plain language.
5. **Errors stay on screen** with the *server's own* message. 403, 404 and 422 mean
   different things, and the operator's typed reason survives a rejection.

Submit is disabled until a warehouse is chosen and the reason is long enough.

---

## 13. The latent 404 found on the way

`preparationService.overrideWarehouse` already existed and built its URL from
`BASE = '/preparation'`, producing `/preparation/orders/{id}/override-warehouse`.
`php artisan route:list` confirms the only registered URI is
`api/orders/{order}/override-warehouse`. **The method had always 404'd.**

It had **zero callers**, so nothing regressed and nothing was silently broken before now.
The path was corrected in place rather than duplicated, keeping one client for the
endpoint.

---

## 14. Files changed

**Backend: no source changes.** One test file added.

| File | Change |
|---|---|
| `backend/tests/Feature/Operations/OrderWarehouseManualAssignmentTest.php` | **New** — 21 tests |
| `frontend/.../distribution-workspace/components/assign-warehouse-dialog.tsx` | **New** — the dialog |
| `frontend/.../distribution-workspace/components/distribution-groups-panel.tsx` | Button on `warehouse_unassigned` rows + dialog mount + row-type import |
| `frontend/.../distribution-workspace/hooks/use-distribution-workspace.ts` | `useAssignOrderWarehouse()` |
| `frontend/src/features/operations/services/preparation-service.ts` | Corrected the 404 path (§13) |
| `frontend/src/i18n/locales/en/logistics.json` | +15 keys |
| `frontend/src/i18n/locales/ar/logistics.json` | +15 keys |

No migration. No new route. No new permission. No new model. No new engine.

---

## 15. Tests — 21/21 green

`GATE_WAIT=2400 sh scripts/test-gate.sh --filter OrderWarehouseManualAssignmentTest`

```
Tests: 21, Assertions: 99   →   OK
```

| # | Group | Test |
|---|---|---|
| 1 | Recorded | a warehouse-less order can be assigned a warehouse |
| 2 | Recorded | the assignment is stamped as a manual override |
| 3 | Recorded | the audit trail records who, when and both warehouses |
| 4 | Recorded | a second assignment appends a row carrying the previous warehouse |
| 5 | No inference | the warehouse sent wins over any policy that would match |
| 6 | No inference | an absent warehouse is refused rather than chosen |
| 7 | Validation | a reason shorter than ten characters is rejected |
| 8 | Validation | a missing reason is rejected |
| 9 | Validation | a reason longer than the column allows is rejected |
| 10 | Validation | an unknown warehouse is rejected |
| 11 | Tenancy | a warehouse belonging to another company is refused |
| 12 | Tenancy | an order belonging to another company is not addressable |
| 13 | Authorization | the existing order-update permission is required |
| 14 | Authorization | the endpoint requires authentication |
| 15 | No side-effects | the assignment creates no group and joins none |
| 16 | No side-effects | the assignment creates no trip |
| 17 | No side-effects | only the named order is changed |
| 18 | No side-effects | the order status is not rewritten by the assignment |
| 19 | §7 transition | the order moves from the warehouse blocker to the zone blocker |
| 20 | §7 transition | a covered zone leaves the order awaiting group assignment |
| 21 | §7 transition | choosing another warehouse moves the row out of a filtered view |

Every rejection test also asserts the order is **still unassigned** and that **no audit row
was written** — a 422 that half-applied would be worse than the original problem.

---

## 16. Static checks

| Check | Result |
|---|---|
| `tsc --noEmit -p tsconfig.app.json` | **23 errors — exactly the pre-existing baseline**, none in my files |
| ESLint (4 changed files) | **Clean** |
| i18n parity | **2181 / 2181**, zero missing either way |
| New Arabic keys | 15/15 genuinely translated (no EN copies) |
| Hardcoded English in the new component | none |
| Directional CSS (RTL) | none — logical properties only |

The type-check passing on `t(($) => $.distributionWorkspace.assignWarehouse.…)` is itself
the proof the new keys are correctly registered in the typed namespace.

---

## 17. Data safety — before/after (§26)

My only writes were to `ecos_dev_test` via the test runner. Every query against
`ecos_dev` in this task was a `SELECT`. Confirmed:
`ecos-dev-app → ecos_dev`, `ecos-dev-testrunner → ecos_dev_test`.

| Fact | Value | Verdict |
|---|---|---|
| `orders` | 19 | unchanged |
| `distribution_virtual_slots` | 3 | unchanged |
| `distribution_trips` | 2 | unchanged |
| `distribution_trip_orders` | 4 | unchanged |
| **`warehouse_assignment_overrides`** | **1** (the 2026-08-19 row) | **unchanged — I created zero live assignments** |

**One live change I did not cause, reported rather than glossed over:** 11 orders
(ORD-00001/2/6/7/9/10/11/12/16/18/19) all show `updated_at = 2026-08-24 05:00:01` — a
single-timestamp bulk touch by a scheduled dev job. All were already `ready_for_dispatch`;
no status changed, and ORD-00007's `virtual_slot_id` is still `NULL`. None of the files I
touched can write to live data.

---

## 18. The specifically protected records

| Record | Requirement | Actual |
|---|---|---|
| **ORD-00013 / ORD-00014** (§8) | Do not modify during automated verification | `assigned_warehouse_id = NULL`, `updated_at = 2026-08-21` — **untouched** |
| **ORD-00001** (§9) | Do not invent a Zone | No zone invented; its 2026-08-19 audit row is intact |
| **ORD-00007** (§10) | DO NOT MODIFY | `virtual_slot_id = NULL`, status `ready_for_dispatch` — unchanged by me (see the scheduled-job note in §17) |
| **ORD-00017** (§11) | DO NOT MODIFY | `awaiting_payment`, `updated_at = 2026-08-22 21:50:05` — **untouched** |

No warehouse assignment was fabricated on any live order (§8, §25).

---

## 19. Browser verification

**BROWSER NOT VERIFIED — DATA SAFETY / AUTHENTICATION CONSTRAINT.**

The only live orders that would render this button are ORD-00013 and ORD-00014, and §8
forbids modifying them absent explicit authorization for operator/browser testing, which
was not given. §25 likewise forbids mutating live data for demonstration. Exercising the
action end-to-end in the browser would require doing precisely that. Signing in would also
require entering credentials, which I do not do.

**I do not claim Browser Verified.** The evidence offered is instead: 21 passing feature
tests against the real HTTP endpoint (including the exact `warehouse_unassigned` →
`zone_not_in_group` transition the UI depends on), a clean type-check proving the component
and its i18n keys compile, and clean lint.

---

## 20. Required statements

- **No new Warehouse-selection engine was created.** The existing
  `WarehouseAssignmentEngine` is used.
- **No second Warehouse relationship, model or audit mechanism was created.**
- **The system does not choose the Warehouse.** No inference from Zone, City, Governorate,
  Driver, Vehicle, Group or Trip.
- **The existing permission `sales.orders.update` governs the action.** No new permission.
- **Company scope is enforced backend-side**, twice, independently of the frontend.
- **No automatic Group assignment was implemented. No Group is created.**
- **No Trip is created.**
- **No migration was required.**
- **Bulk assignment was not implemented** — it would need new mutation architecture (§14).
- **No live business data was mutated.**

---

## 21. Open items for the owner (not defects introduced here)

1. **`authorizeOrderAccess()` is an empty method** in `WarehouseAssignmentController`.
   Harmless today because the `Order` global scope enforces tenancy, but it reads like a
   guard and provides nothing. Worth deleting or implementing so nobody trusts it.
2. **The write endpoint does not check `is_active`** on the target warehouse (§5). The UI
   never offers an inactive one; the API still would accept one.
3. **`GET orders/{order}/assignment-history` has no permission middleware** — auth-only,
   unlike the two write routes beside it. It exposes the assignment audit trail. Not wired
   into this UI, so no new exposure was introduced.
4. **6 live orders currently have no warehouse**, not 2. ORD-00013/00014 are the two named
   in the brief; the other four are also unplannable and will surface in this UI.

---

## 22. What was deliberately not done

- No Group → warehouse inference, and no "suggested warehouse".
- No bulk action, atomic or otherwise.
- No standalone Warehouse page, and no new navigation entry.
- No change to `assign-warehouse` (the automatic path) or to either `WarehouseAssigned`
  listener.
- No repair of the four unrelated warehouse-less live orders, and no touch of the
  protected records.
- No commit, no deploy.

---

## 23. Final status

**IMPLEMENTED / FOCUSED VERIFIED**

A manual, audited, company-scoped, permission-gated warehouse assignment is now reachable
by an operator from the surface where the problem is visible — built almost entirely from
parts that already existed. 21/21 focused tests green, type-check at baseline, i18n at
parity in both languages, zero live data mutated, and one latent 404 fixed on the way.

Not certified: no browser exercise, for the data-safety and authentication reasons in §19.
