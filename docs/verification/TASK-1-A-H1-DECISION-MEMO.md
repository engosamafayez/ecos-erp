# H1 DECISION MEMO — OWNER DECISION REQUIRED

**Date:** 2026-08-24 · Decision memo only. No code, tests, data, migration, API or RBAC changed.
Source: `TASK-1-A-WINDOW-RESOLUTION-CAPACITY-UI-REPORT.md` §H1.

---

## 0. Correction to my own H1 framing — this changes the recommendation

My report said adding a wave fixture "would destroy the assertion it exists to make". That was
wrong in a way that matters.

The asserting test is `test_group_ownership_changes_mutate_no_other_domain`. The contract it
protects is **"a Distribution write mutates no other domain"** — it sweeps 8 tables
(`vehicle_plans`, `vehicle_plan_slots`, `vehicle_plan_slot_orders`, `loading_sessions`,
`vehicle_assignments`, `allocation_records`, `preparation_waves`, `preparation_wave_orders`) and
asserts each is `0`. The `0` is an **artifact of the fixture never creating one**, not the
invariant. A wave created *by the fixture* does not violate "Distribution wrote nothing to
Preparation" — only the literal count breaks.

So the collision is **literal, not semantic**: rewritten as a before/after delta, those
assertions would protect exactly the same contract with a wave present. Option A/C is therefore
cheaper than I estimated. It does **not** rescue A/C on architectural grounds — see §2.

---

## 1. The three options

**A — Accept fail-closed as implemented, treat the 136 failures as fixture debt.**
A Distribution read requires an active engine Preparation Wave for the warehouse.

**B — Narrow the change to the presentation boundary.**
Keep the half that removed the danger (a read never *creates* a window). The service still
returns today's **existing** window when the wave cannot be resolved, and the *frontend*
renders the unresolved state when no warehouse is in context (`activeWarehouseId === null`),
which is the audited R1 case.

**C — Keep fail-closed and formally amend the contract.**
Same runtime behaviour as A; differs in governance: declare "Distribution without Preparation"
unsupported via an ADR and rewrite the 4 no-wave classes' intent deliberately rather than
treating them as debt.

---

## 2. The 20 points

| # | | **A — fail-closed everywhere** | **B — presentation boundary** | **C — fail-closed + contract amendment** |
|---|---|---|---|---|
| 1 | Semantic behaviour | No resolvable warehouse-scoped cycle ⇒ read returns nothing | Read returns the cycle's window if resolvable, else today's **existing** window, else nothing; never creates | Identical to A |
| 2 | Read with no wave | `no_planning_window` | Today's existing window, or unresolved if none exists | `no_planning_window` |
| 3 | Wave required? | **Yes**, to read at all | **No** — wave is a selector among windows | **Yes**, and stated as contract |
| 4 | Window independent of wave? | Effectively no | **Yes** — matches the schema | Explicitly no |
| 5 | Today's empty window auto-created? | **Never** | **Never** | **Never** |
| 6 | Groups | Hidden without a wave | Renders window contents; hidden only with no warehouse | Hidden without a wave |
| 7 | Zones | Same as Groups | Same as Groups | Same as Groups |
| 8 | Map | Same as Groups | Same as Groups | Same as Groups |
| 9 | Settings | Same as Groups (capacity edit needs `windowId`) | Same as Groups | Same as Groups |
| 10 | Templates | **CRUD unaffected** (company-scoped, `api.php:1798-1804`); only **Apply** needs a window | Same | Same |
| 11 | Certified tests | Breaks 13 classes; 4 must gain wave fixtures, 2 must convert count-0 → delta | **No baseline change** | Same as A, plus deliberate rewrite of 4 classes' stated intent |
| 12 | Expected failures | **161** (25 pre-existing + ~136 introduced) until ~136 fixtures are reworked | **≈25** — the pre-existing baseline only | Same as A |
| 13 | Architectural impact | Makes Preparation a hard runtime dependency of a Distribution *read* | Keeps the existing dependency direction: wave selects, does not gate | Elevates A to a stated platform rule |
| 14 | Conflicts with Preparation Wave contract? | No | No | No |
| 15 | Conflicts with Distribution contract? | **Yes** — asserts a prerequisite the schema does not express (§3) | No | **Yes, deliberately** |
| 16 | Changes Group identity? | No | No | No |
| 17 | Changes Group → Trip? | No | No | No |
| 18 | Migration? | No | No | No |
| 19 | API change? | Payload gains `resolution`/`resolution_reason` (additive) | Same additive fields; `window` non-null more often | Same as A |
| 20 | Recommended | — | ✅ **Recommended** | — |

---

## 3. Architectural questions

**A. Is an active wave a prerequisite for merely READING Distribution planning, or only the
source that identifies the current cycle when one exists?**

**Only the source that identifies the current cycle.** Three structural facts, verified:

1. `distribution_windows` has **no `preparation_wave_id` and no `warehouse_id` column** — it is
   keyed `(company_id, window_date)`. There is no FK, no nullable reference, nothing. The schema
   expresses no wave→window relationship at all.
2. Ingestion is wave-independent: `resolveIngestionWindow()` uses `windowFor(today)` and a
   date/clock cutoff (§16). Collection writes assignments with no wave consulted.
3. `resolvePlanningWindow()`'s own purpose (D1-A) is to pick *which* window the workspace plans
   **among windows that already exist** — an anchor, not a parent.

Requiring a wave to read therefore asserts a dependency the data model does not have.

**B. Can Distribution have a valid operational Window without an active wave?**

**Yes.** Structurally (no column, no FK) and behaviourally (collection creates and fills windows
without consulting a wave). Live confirmation: all 4 `distribution_windows` rows carry no wave
reference, and the 08-21 window legitimately holds 13 assignments and 3 Groups.

**C. If no active wave exists, what should the UI truthfully show?**

From the existing contracts only, and distinguishing two different situations that already have
distinct treatment in the codebase (`CycleHeader` already tells them apart):

- **No warehouse in context** → the unresolved state, *"Select a warehouse to continue."* This is
  the audited R1 defect and is uncontested under all three options.
- **Warehouse in context, no active wave** → show the window that exists, with the existing
  *"no active cycle"* notice — because its contents are real. Show the unresolved state only when
  no window exists at all.

No new lifecycle. `DistributionWindowStatus` unchanged; `resolution` stays a transport
discriminator.

**D. Why do the certified tests require `preparation_waves` count = 0?**

They are protecting **domain isolation**, not wave-independence. The test is
`test_group_ownership_changes_mutate_no_other_domain`: it asserts that creating a Group and
attaching a Zone writes nothing into Loading (`loading_sessions`, `vehicle_assignments`,
`allocation_records`, the three `vehicle_plan*` tables) or Preparation (`preparation_waves`,
`preparation_wave_orders`). The `0` is the fixture's starting state, used as a cheap way to say
"still nothing here".

**E. Why does requiring a wave violate those tests?**

Only **literally**. To make the read resolve, the fixture must create a wave, so the expected
count becomes 1 and `assertSame(0, …)` fails — while the protected invariant (Distribution wrote
nothing to Preparation) still holds. Converting the assertion to a before/after delta would
preserve the contract exactly. The genuine objection to A/C is not these two assertions; it is
§3-A: they encode a prerequisite the schema does not express, and require ~136 fixture edits to
do so.

---

## 4. Recommendation — Option B

1. **It fixes the audited defect completely.** R1's harm was a read that *created* an empty
   calendar window and rendered it as authoritative. B keeps both halves of that fix: no
   creation on any read, and an explicit unresolved state when no warehouse is in context.
2. **It matches the data model.** A window is `(company, window_date)`; the wave selects among
   windows. A and C assert a prerequisite that neither the schema nor collection expresses.
3. **It is a ratchet, not a cliff.** Baseline returns to ~25 pre-existing failures instead of
   161. A and C fail 136 approved tests until reworked.
4. **It is smaller and reversible.** Two call-site guards plus one client-side condition, versus
   ~136 fixture edits across 13 classes and a rewrite of 2 certified classes' intent.
5. **§3 is unaffected either way** — the Group capacity UI is already implemented, verified and
   regression-free under all three options.

If the platform genuinely intends Preparation to gate Distribution reads, **C is the honest form
of that** — it should be an ADR with the schema changed to express the dependency, not a
resolver behaviour with 136 tests taught to expect it. **A is the one option I would not
recommend**: same runtime cost as C, without the governance that would make it legitimate.

**Scope note:** this memo does not change the current tree. The fail-closed implementation
remains in place as instructed; reverting to B is three `return null;` lines, two caller guards
and one client-side condition.

---

> ## H1 DECISION MEMO — OWNER DECISION REQUIRED
> **Recommended: Option B.** Awaiting your ruling before any further change.
