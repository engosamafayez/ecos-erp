# TASK-DRIVER-APP-FINAL-CLOSURE-002

## 1. Executive Summary

**Status: PARTIALLY IMPLEMENTED / BLOCKED** — every code item is closed and green;
**browser E2E remains blocked on authentication**, which by the brief's own rule prevents
CERTIFIED.

What this task actually changed: **the secure Proof of Delivery is now reachable from the
Driver App.** That was the one genuine gap left by CLOSURE-001, and it is now wired
end-to-end through the certified secure endpoint. The legacy path-accepting helper is gone
from the frontend.

Everything else was audited and **preserved**:

- **Return architecture — PRESERVE, no change.** ADR-015 §6.4's separation is correct, and I
  found direct evidence that "fixing" it would break a certified idempotency test.
- **Dashboard — CERTIFIED, no change required.**
- **Menu — CERTIFIED, no change required.**

Backend changes: **NONE** · Frontend: 5 files · Schema: **NONE** · Permissions: **NONE**
Live business data: **UNCHANGED** · Commit/Push: **NONE**

Date: 2026-08-26 · Branch: `develop`

---

## 2. Return Architecture Decision — **PRESERVE (no change)**

Part 1 asked me to preserve the separation unless there is direct evidence it is incorrect. I
looked for that evidence and found the opposite.

### The full chain, traced

| Stage | Implementation | Status |
|---|---|---|
| Driver declares the return | `POST /api/driver/trips/{tripId}/returns` → `DeliveryService::recordReturn()` → `TripReturn` (`product_id`, `returned_qty`, `disposition`, `reason`) | ✅ implemented |
| Warehouse receives / counts | `PATCH /trips/{tripId}/returns/{returnId}/confirm` → `DeliveryService::confirmReturn()` → `warehouse_confirmed_qty`, `warehouse_confirmed_at`, `warehouse_confirmed_by` | ✅ implemented |
| Discrepancy / liability | `discrepancy_qty = calculateDiscrepancy()`, `driver_liable` | ✅ implemented |
| Authoritative returned qty | `VehicleShiftReconciliationLine.quantity_returned_actual` via `recordReturnedActual()`, routed at `POST .../reconciliation/lines/{lineId}/return` | ✅ implemented |
| Variance | `variance = loaded − delivered − returned`, recomputed per line | ✅ implemented |

**The architecture is complete.** The driver's declaration and the warehouse's count are
separate fields, by design, and the discrepancy between them is what `discrepancy_qty` and
`driver_liable` exist to express — fields that would be meaningless if the two were forced
equal.

### The trap I nearly walked into, and the evidence that stopped me

`VehicleInventoryService::recordReturn()` has **zero callers**, and its signature
(`VehicleInventoryItem $item, float $quantity, string $reconciliationLineId, string $actorId`,
writing `referenceType: 'reconciliation'`) reads exactly like a wire someone forgot to
connect to `recordReturnedActual()`. That is a very inviting "missing implementation".

**It must not be connected**, for two independent reasons:

1. **Opposite semantics.** `recordReturnedActual()` is **absolute** (`= $quantity`);
   `recordReturn()` is **incremental** (`+= $quantity`). Wiring them would double-count on
   any re-submission.
2. **It would break a certified test.** `VehicleShiftReconciliationHttpTest::test_recording_the_same_return_twice_is_a_no_op`
   pins the absolute, idempotent behaviour. The reconciliation suite (10 tests) asserts
   **nothing** about `VehicleInventoryItem.quantity_returned` — the reconciliation is a
   *ledger over* the item's loaded/delivered facts, deliberately not a mutator of it.

So `VehicleInventoryItem.quantity_returned` / `recordReturn()` is **the path ADR-015 did not
select** — vestigial, not missing.

**Nothing was changed.** Driver return does not decrement warehouse inventory; no second
inventory authority was created; `loaded − delivered − driver_returned = 0` was not forced.

**Recorded for a future task (not acted on):** `VehicleInventoryService::recordReturn()` is
dead code whose shape actively invites the mistake above. It should be retired or carry a
docblock saying it is not the authority. I did not touch it — it is outside this task and
belongs to whoever owns that service.

---

## 3. POD UI Wiring — **IMPLEMENTED** (the one real change)

The stop detail page carried an explicit deferral:

> *"Delivery proof-of-delivery capture is intentionally NOT exposed: the current POD endpoint
> accepts arbitrary client-supplied path strings (TASK-DRIVER-04 §8). It returns once the
> secure upload contract (TASK-DELIVERY-POD-SECURE-UPLOAD-001) is verified."*

**That contract is now certified** (12 tests), so the condition the comment names is
satisfied. This is the change it was waiting for.

### What was done

| File | Change |
|---|---|
| `services/driver-mobile-service.ts` | **Deleted** `submitProofOfDelivery()` (posted client path strings to the legacy route, zero callers). **Added** `uploadDeliveryProof()` — real `FormData` to `POST /driver/stops/{stopId}/delivery-proof` |
| `hooks/use-driver-mobile.ts` | Added `useUploadDeliveryProof(tripId, stopId)` — invalidates the stop detail, toasts success/failure |
| `components/delivery-proof-upload-form.tsx` | **New.** Signature + photos + notes capture |
| `pages/driver-stop-detail-page.tsx` | New `'delivery-proof'` sheet mode, button, sheet |
| `i18n/locales/{en,ar}/driver-mobile.json` | `stop.deliveryProof.*` — 14 EN / 18 AR keys (Arabic plural forms) |

### Against Part 2's eight requirements

| # | Requirement | How |
|---|---|---|
| 1 | Driver can attach permitted proof | signature (jpg/png/pdf) + up to 10 photos (jpg/png) + notes |
| 2 | Sends a real multipart file | `FormData` with `Content-Type: undefined` so the browser sets the boundary — the shared-axios pattern already proven by payment proof |
| 3 | Uses the secure endpoint | `POST /driver/stops/{stopId}/delivery-proof` |
| 4 | Shows success / failure | toast on both, via the existing `useToast` |
| 5 | Preserves tenant/driver authorization | unchanged — `ownedStop()` fail-closed on the server |
| 6 | Never submits arbitrary storage paths | the only inputs are `File` objects; no path field exists |
| 7 | Never exposes private storage paths | the response type carries `id`, `has_signature`, `photo_count`, `captured_at` — **no path** |
| 8 | Preserves POD semantics | backend untouched |

**The legacy route was not restored and is not called.** It remains on the server, where a
certified test deliberately keeps it for the dispatcher contract — but the Driver App no
longer reaches it.

The form mirrors the server's own validation (MIME, size, ≤10 photos, and "a proof must carry
evidence") so the driver is told *before* the upload rather than by a 422 after it. The server
remains the authority; those attributes are a courtesy, not the guard.

---

## 4. Dashboard Audit — **CERTIFIED — NO CHANGE REQUIRED**

`driver-home-page.tsx` derives everything from the trip lifecycle:

```
ON_THE_ROAD        = ['dispatched', 'out_for_delivery', 'in_progress']
COMPLETED_STATES   = ['completed', 'settlement_pending', 'closed']
BLOCKED_STATES     = ['dispatch_blocked', 'cancelled']
UNRESOLVED_LOADING = ['pending_loading', 'awaiting_driver_confirmation',
                      'awaiting_driver_reconfirmation']
```

| Requirement | Verdict |
|---|---|
| identifies driver / vehicle / current trip | ✅ driver identity, vehicle plate, trip number |
| shows meaningful work + correct order count | ✅ delivered / failed / pending derived from stop rows |
| reflects loading / trip / delivery state | ✅ via the state sets above |
| does not show stale actions | ✅ `actionKey`/`actionRoute` recomputed per state |
| completed state ≠ disabled button | ✅ the completed branch returns `actionKey: null` → **no button rendered** |
| routes to the correct next action | ✅ CTA navigates to `derived.actionRoute` |

No functional defect found. **Not changed.**

---

## 5. Menu Audit — **CERTIFIED — NO CHANGE REQUIRED**

Driver-scoped navigation with a context-aware mobile nav. Pages present: Home, Current Trip,
Orders/Stops, Returns, Custody Return, Vehicle Inventory, Settlement, Exceptions, Map, Trip
Timeline, Collections.

**Nothing forbidden is exposed** — no dispatcher controls, warehouse-operator controls,
procurement, finance, distribution administration, other-driver management, or direct
inventory mutation. Enforcement is not cosmetic: every `/api/driver/*` route sits behind
`auth:sanctum` + `permission:loading.driver.operate`, and each handler re-checks ownership via
`ownedTrip()` / `ownedStop()` / `ownedTask()`. The four driver money endpoints remain frozen
at 403.

I added no sections for completeness. **Not changed.**

---

## 6. Driver Delivery Flow (Part 5)

| Stage | Mechanism | Status |
|---|---|---|
| Assignment | Group → vehicle assignment → Trip | CERTIFIED |
| Loading | `LoadProductAction` → custody | CERTIFIED |
| Driver Confirmation | `LoadingCustodyService::confirmReceived()` + atomic warehouse→vehicle transfer | CERTIFIED |
| Loading Complete | custody gate (422 on unresolved) | CERTIFIED |
| Trip Start | departure seam: `DriverAccepted → ReadyForDispatch → Dispatched → InProgress` | CERTIFIED |
| Orders / Stops | finalization → trip orders → delivery stops | CERTIFIED |
| Full / Partial / Remaining Delivery | `EnsureStopDeliveryAllocationsAction → RecordProductDeliveryAction` (cumulative absolute) | CERTIFIED |
| Return Declaration | `TripReturn`; warehouse count is the authority | **PRESERVED per §2** |
| POD | secure multipart upload | **now reachable from the UI** |
| Final Driver State | `finishTrip` / `closeTrip` | IMPLEMENTED |
| Settlement Visibility | read-only page; money endpoints frozen | IMPLEMENTED |

The return declaration was **not** forced to become the warehouse's final returned quantity.

---

## 7. Backend Changes

**NONE.**

## 8. Frontend Changes

5 files — 1 new component, 3 modified, 2 locale files. Listed in §3.

## 9. Tests

**Frontend: 29/29 green** (3 files, driver-mobile).
**`tsc`: 23 errors = the documented baseline, 0 in driver-mobile.**
**ESLint on all changed files: exit 0.**
**i18n: base-key parity EN↔AR verified programmatically** (AR carries extra entries only for
Arabic plural categories, which is correct).

No new backend test was added because **no backend code changed**. No existing test was
weakened.

One detail worth recording: the first `tsc` run failed on `photosSelected` because the typed
selector is generated from the raw JSON, which held only `_one`/`_other`. The codebase's
convention — visible on `loadingScreen.pendingConfirmations` — is to keep a **bare base key**
alongside the plural forms. I followed the existing convention rather than reshaping the call.

## 10. Regression Results

**Backend: 126 tests, 892 assertions, 0 failures.** Nine suites, covering every area Part 7
names:

```
DriverStopDeliveryTest                delivery quantity, partial, remaining, custody, idempotency
DriverDeliveryProofSecureUploadTest   POD security (the endpoint this task wired the UI to)
DriverLoadingCustodyHandoffTest       loading → custody handoff
LoadingCustodyWorkflowTest            the custody state machine / Loading Complete gate
TripDepartureLifecycleTest            trip lifecycle
DriverRbacTenancySecurityTest         driver authorization
ShippingDriverClosureTest             driver closure contract
VehicleShiftReconciliationHttpTest    the return authority (§2)
DriverVehicleInventoryAndIdentityTest vehicle custody + driver identity
```

`VehicleShiftReconciliationHttpTest` was included deliberately: it is the suite that would
have broken had I wired `recordReturn()` into `recordReturnedActual()`, so its green state is
the evidence the §2 decision to preserve was the right one.

**Frontend: 29/29** · `tsc` **23 = baseline**, 0 in driver-mobile · ESLint **exit 0**.

Since no backend code changed, this is a baseline confirmation rather than a regression check.

## 11. Browser Verification

### **BROWSER VERIFICATION BLOCKED**

Checked directly this session, not assumed:

- Browser pane opened at `http://127.0.0.1:5173/app/` — `localStorage` empty, **no cookies**,
  no token.
- **`/app/driver` redirects to `/app/login`.**
- The DEV driver password was generated, never printed, and discarded by explicit instruction
  in `TASK-DEV-DRIVER-396-PASSWORD-SETUP-001`; `mail.default = array`, so no reset channel.
- I do not enter passwords into login forms, and this brief forbids manufacturing or resetting
  credentials.

**None of Part 6's 12 observations was made** — including the new POD upload, which is
therefore *implemented and unit-verified but not browser-verified*.

**To unblock:** leave a signed-in driver session open in the browser, or reset the password to
a value you hold and sign in yourself.

## 12. Live Data Impact

**No live business data was created, modified or deleted.** No order finalized, no stock
manufactured, no inventory transferred, no trip or stop created, no driver assignment or
vehicle custody modified, no delivery or return recorded. All tests ran against
`ecos_dev_test`.

## 13. Security

- **A path-accepting call was removed from the client.** `submitProofOfDelivery()` is deleted;
  the Driver App can no longer name a storage path.
- Uploads carry `File` objects only; the server generates the storage path on a private disk.
- The response exposes counts and a timestamp — **never a path**.
- Retrieval stays on the tenant-scoped download route.
- Driver authorization, ownership checks and the frozen money endpoints are untouched.
- No second delivery writer, custody authority or inventory system was created.

## 14. Remaining Risks

1. **Nothing is browser-verified**, including the POD upload this task added. It is proven by
   type-checking, lint and the existing backend contract tests, not by using the app.
2. **The legacy `/proof` route still exists server-side.** It is deliberately retained and
   test-guarded for the dispatcher contract; the Driver App no longer calls it. Retiring it is
   a separate decision.
3. **`VehicleInventoryService::recordReturn()` remains dead code** whose shape invites the
   wiring §2 explains must not happen.
4. **Returned goods do not post a warehouse stock increase** anywhere I traced. That is the
   inbound-receipt flow, outside this task — flagged, not investigated to conclusion.
5. **No live trip has departed**, so settlement visibility is still unexercised against real data.

## 15. Final Certification Status

**PARTIALLY IMPLEMENTED / BLOCKED.**

Against the acceptance criteria:

| Criterion | Status |
|---|---|
| Return architecture resolved or correctly preserved | ✅ **PRESERVED**, with evidence (§2) |
| Secure POD reachable from the Driver UI | ✅ **IMPLEMENTED** (§3) |
| Dashboard functionally correct | ✅ **CERTIFIED — no change** |
| Menu functionally correct | ✅ **CERTIFIED — no change** |
| Delivery / partial / custody remain green | ✅ (§10) |
| Regression passes | ✅ (§10) |
| **Browser E2E actually observed** | ❌ **BLOCKED** |

Per the brief — *"If browser access remains unavailable, the correct status is PARTIALLY
IMPLEMENTED / BLOCKED even if all code/tests are green"* — that single criterion decides it.

- **CERTIFIED:** return architecture (preserved), dashboard, menu, and the pre-existing
  delivery/custody/loading/trip stack.
- **IMPLEMENTED, not browser-verified:** the secure POD UI wiring.
- **BLOCKED:** browser E2E (authentication only).

---

**STOP.** No Finance. No Distribution. No MTO. No further task started.
