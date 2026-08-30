# TASK-LOADING-DRIVER-COMPLETE-GATE-001

## STATUS

**PARTIALLY IMPLEMENTED / BLOCKED**

The gate is implemented, server-enforced and green on focused backend + frontend tests.
**Browser E2E was NOT performed**, so per §13 this is not claimed VERIFIED — see
*Browser limitations*.

Backend focused: **41/41, 553 assertions** · Frontend focused: **47/47** (4 files)
`tsc`: **23 = baseline**, 0 in touched files · ESLint: **exit 0** · i18n parity: **0 missing**
Schema changes: **NONE** · Permission changes: **NONE** · Commit/Push/Deploy: **NONE**

Date: 2026-08-26 · Branch: `develop`

---

## The defect

`DriverLoadingController::complete()` consulted only the `VehicleAssignmentStatus` machine
(Pending → Loading → LoadingComplete). It never looked at driver confirmation, so a shipment
could close while a product the warehouse had handed over was still
`awaiting_driver_confirmation` — custody nobody had acknowledged. Your browser run found
this; no test covered it.

## Files changed

### Backend
| File | Change |
|---|---|
| `Modules/Operations/Loading/Domain/Services/LoadingCustodyService.php` | + `UNRESOLVED_STATES` and `unresolvedLoadedTasks()` — a **read** that composes the existing `stateOf()` |
| `Modules/Logistics/Distribution/Presentation/Http/Controllers/DriverLoadingController.php` | completion guard before the status flip |

**Nothing else was touched.** No custody column, no `loading_task_adjustment_log`, no
derived-state logic, no `driver_confirmed_loaded_qty`, no `quantity_loaded`, no staleness
mechanism, no warehouse workflow, no audit model, no permission, no migration. No
Distribution, Wave, Preparation, Vehicle, Trip, Inventory, Order, Procurement or Finance
file was opened.

### Frontend
| File | Change |
|---|---|
| `driver-mobile/pages/driver-loading-page.tsx` | `pendingConfirmations` count; button disabled + localized reason |

### i18n
`driver-mobile.json` (EN + AR): `pendingConfirmations` with i18next plural suffixes —
EN `_one`/`_other`, AR the full `_zero/_one/_two/_few/_many/_other` set — plus
`completeBlocked`. No hardcoded strings.

### Tests
`LoadingCustodyWorkflowTest` (+6) · `driver-loading-page.test.tsx` (+5)

## How the gate decides

It asks the **one** custody state machine, so the refusal and the manifest the driver is
reading can never disagree:

| State | Blocks? | Why |
|---|---|---|
| `awaiting_driver_confirmation` | **YES** | warehouse handed over; driver has not acknowledged — *the reported defect* |
| `awaiting_driver_reconfirmation` | **YES** | warehouse revised; the standing number is one the driver never accepted (§7 E) |
| `adjustment_requested` | no | the driver already acted by disputing it; the approved adjustment workflow owns it (§1.2, §7 D) |
| `pending_loading` | no | no warehouse handover — the legacy path where the driver records the quantity themselves |
| *nothing loaded* | no | no custody to acknowledge (§7 C) |

**A correction the regression caught.** My first version also blocked `pending_loading`,
which broke the legacy WAVE-1 self-load flow (`DriverLoadingCustodyHandoffTest::test_completing_the_loading_is_explicit_and_idempotent`
went 200 → 422). Re-reading §1, the rule is scoped to *"أي Loading Task **محمل من المخزن**"*
— loaded **by the warehouse**. A self-loaded task has no handover to acknowledge and was
never in scope. That is the rule as written, not a concession to make a test pass, and
`test_a_self_loaded_item_with_no_warehouse_confirmation_does_not_block` now pins the
boundary. Your reported case is unaffected: Product B was `awaiting_driver_confirmation`.

## Server-side enforcement (§3)

```php
$unresolved = $this->custody->unresolvedLoadedTasks((string) $assignment->id);

if ($unresolved !== []) {
    return response()->json([
        'message' => 'Loading cannot be completed until all loaded items are confirmed by the driver.',
        'pending_confirmations' => count($unresolved),
    ], 422);
}
```

422 matches the existing convention in this controller (no error-code mechanism exists, so
none was invented). The English message is the canonical one from §4; the client renders its
own localized reason from `workflow_state`, which is how the AR string is delivered without
the backend carrying translations. `pending_confirmations` is included so the client never
has to parse an English sentence.

**The button is not the protection.** The server refuses regardless of the client.

## Verification

### Focused backend — 41/41, 553 assertions
`LoadingCustodyWorkflowTest` + `DriverLoadingCustodyHandoffTest`.

| Case | Result |
|---|---|
| **A** one confirmed, one awaiting → completion refused, assignment stays `loading`, `loading_completed_at` null | **PASS** |
| **B** both confirmed → existing completion flow runs, status `loading_complete` | **PASS** |
| **C** nothing loaded → not blocked | **PASS** |
| **D** open adjustment → not blocked | **PASS** |
| **E** warehouse revises after confirmation → blocked again via the existing staleness mechanism, then clears when the driver accepts the new number | **PASS** |
| self-loaded, no warehouse confirmation → not blocked | **PASS** |

Case A asserts the response body (`pending_confirmations = 1`) *and* that the assignment did
not advance — the refusal is proven by state, not only by status code.

### Focused frontend — 47/47 (4 files)
Cases A–E as UX: disabled with reason / enabled and firing the mutation / not blocked when
nothing loaded / not blocked on an open adjustment / disabled again when stale.

One pre-existing test, `finalizes only on the explicit CTA`, used a fixture with
`quantity_loaded: 20` and the default `awaiting_driver_confirmation` — now precisely the
blocked case. Its **intent** (no auto-finalize; only the explicit CTA) is unchanged and
still tested; I updated the fixture to a completable shipment rather than weakening the
assertion.

### Regression (§8) — unchanged
Warehouse confirmation, driver sees Required / Loaded-by-warehouse, driver enters Received,
driver confirmation, adjustment workflow, append-only audit, Remaining arithmetic, populated
driver manifest, no mutation on page open, no automatic confirmation, no fabricated
quantities — all still green inside the two suites above.

**Wider suites were not re-run.** Nothing outside the two files listed changed, and the gate
is confined to one completion endpoint. Stated as not-re-run rather than assumed green.

### Browser E2E — **NOT PERFORMED**

**"Loading Complete" was NOT observed being prevented in a browser.** Stated plainly because
§13 requires it.

The blocker is mine: in TASK-DEV-DRIVER-396-PASSWORD-SETUP-001 I reset the DEV driver
password to a random value that was generated, used and discarded inside the script, and
there is no secure channel here to deliver a credential (`mail.default = array`, no
password-reset route). I cannot sign in, and I do not enter passwords into login forms.

I did **not** substitute an API-token check and call it browser verification. The server
refusal is proven by Case A over real HTTP in the focused suite; the disabled button and its
reason are proven by component tests. Neither is the browser observation you asked for.

**To close it:** set the password for `dev.driver396@ecos.local` (command in the previous
report), then walk §9 — driver 396's shipment already carries two warehouse-confirmed
products in `awaiting_driver_confirmation`, which is exactly the starting state that
scenario needs.

## Business data mutations

**NONE.** No loading task, adjustment, session, assignment, trip, vehicle, order or quantity
was created, updated or deleted by this task. The focused tests run against `ecos_dev_test`
(rebuilt per run); the dev database was not written to.

## Acceptance criteria

| | Criterion | |
|---|---|---|
| ✅ | Server blocks completion with pending driver confirmations | Case A |
| ✅ | Frontend disables Loading Complete when pending | frontend Case A |
| ✅ | Clear EN/AR reason displayed | pluralized keys, both locales |
| ✅ | All-confirmed scenario succeeds | Case B |
| ✅ | Partial/adjustment semantics correct | Cases C, D |
| ✅ | Stale reconfirmation blocks | Case E |
| ✅ | Driver manifest intact | existing tests green |
| ✅ | Existing custody tests green | 41/41 |
| ✅ | New focused tests green | 11 added |
| ❌ | **Browser E2E proves blocked → confirmed → completed** | **NOT PERFORMED** |
| ✅ | No unrelated modules changed | 2 backend, 1 frontend, i18n, tests |
| ✅ | No new permission | none |
| ✅ | No new schema | none |

## Observation, not acted on

`CompleteLoadingAction` — the **operator/session-level** completion — has its own guard (no
task left `pending`/`in_progress`) and does not consult driver confirmation. The reported
defect and §1 both concern the **driver's** completion, and §11 forbids widening scope, so I
left it alone. If an operator should also be prevented from closing a session with
unacknowledged custody, that is a separate decision for you.

---

**STOP.** No other task started.
