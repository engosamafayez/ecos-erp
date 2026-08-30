# TASK-DRIVER-02 — DRIVER RBAC & TENANCY SECURITY REPAIR

**Mode:** Implementation with focused verification.
**Date:** 2026-08-24
**No commit. No deploy. No migration. No schema change. No business-data mutation.**

---

## 1. Executive Summary

Six confirmed defects from `TASK-DRIVER-01-EXPERIENCE-CUSTODY-AUDIT-001` are closed. Three items
are **STOPPED for owner decision** rather than worked around.

**Closed:**

1. **The driver role can now reach the driver runtime.** `loading.driver.operate` — a permission whose
   own registered description is *"Operate the driver runtime (own assigned trips only)"* — is granted
   to `driver`. Verified: it gates exactly one route group and no policy.
2. **Dispatcher authority revoked from `driver`.** `logistics.distribution.view|update` removed. This
   is the RBAC half of the maker-checker fix: the driver can no longer reach the cash ledger at all.
3. **Trip tenancy closed at all three unscoped call sites** — `SettlementController`,
   `DeliveryController`, reusing the fix already applied to `TripController`. Not one method: the
   audit found three, and every method on both controllers funnels through them.
4. **Maker-checker enforced by identity** on the cash ledger. A collector can no longer verify *or*
   reject their own collection — enforced in the domain, so `is_system` roles are subject to it too.
5. **Seven unprotected reads gated.** The payment ledger, settlement and cash position were bare
   `auth:sanctum`.
6. **A real-authorization test suite** that does not use `actingAs()` — the exact blind spot that let
   the headline defect ship green.

**Result: `OK (21 tests, 42 assertions)`.**

**Stopped:** reconciling `distribution_payment_collections` with the canonical `payment_proofs`
lifecycle (redesign); separating record from verify by permission (no safe existing separation);
and treating `image_path` as a real proof (needs an upload contract that does not exist).

---

## 2. Original Security Findings

| # | Finding | Status |
|---|---|---|
| 1 | `/api/driver/*` returns 403 for the driver role | **CLOSED** |
| 2 | Driver tests use `actingAs()` → false confidence | **CLOSED** |
| 3 | Driver nav unreachable | **OUT OF SCOPE** (D-01/D-03, deliberately untouched) |
| 4 | `resolveTrip()` not tenant-safe | **CLOSED** (3 sites) |
| 5 | `distribution_payment_collections` is a second payment/proof mechanism | 🛑 **STOPPED** — §12 |
| 6 | Client-supplied `image_path` | 🟡 **HARDENED**, architecture 🛑 **STOPPED** — §12 |
| 7 | Record and verify share one permission | 🛑 **STOPPED** — §9 |
| 8 | Driver can reach the payment-collection path | **CLOSED** (finding 2 of §4) |
| 9 | `verifyPayment()` has no self-review protection | **CLOSED** |
| 10 | Settlement reads have no permission | **CLOSED** |
| 11 | Cross-company risk via unscoped trip resolution | **CLOSED** |

---

## 3. Permission Model — Before

```php
// backend/config/permissions.php
'driver' => [
    'logistics.shipping'    => ['view'],
    'logistics.distribution' => ['view', 'update'],
],
```

- **No driver-runtime permission** → 403 on every `/api/driver/*` endpoint.
- **`logistics.distribution.update`** gated `POST …/payments`, `PATCH …/payments/{id}/verify` and
  `PATCH …/payments/{id}/reject` — the same verb for both halves of the review.
- `GET …/payments`, `…/settlement`, `…/financial-summary`, `…/stops`, `…/stops/{id}`,
  `…/exceptions`, `…/returns` carried **no permission at all**.

---

## 4. Permission Model — After

```php
'driver' => [
    'logistics.shipping' => ['view'],
    'loading.driver'     => ['operate'],
],
```

**No permission was created.** Both names already existed in `config/permissions.php`.

**Why granting `loading.driver.operate` is minimum privilege, not a widening** — verified, not assumed:

| Check | Evidence |
|---|---|
| What it gates | **exactly one** route group — `routes/api.php:3116`, `Route::middleware(['auth:sanctum','permission:loading.driver.operate'])->prefix('driver')`. Nothing else. |
| Referenced by any policy? | **No.** A repo-wide sweep finds it only in the route, `config/permissions.php`, its seed migration, and one test. |
| Its own registered description | *"Operate the driver runtime (own assigned trips only)"* — `seed_loading_os_permissions.php:52` |
| Is the route group itself safe? | Yes, independently. `DriverRuntimeController` resolves the driver from `logistics_drivers.user_id` and admits only trips that are **both** the actor's company **and** that driver's own (`:293-332`). |

**This reverses a documented decision, deliberately and with reasons recorded in the config.**
`seed_loading_os_permissions.php:30-33` withheld the grant because *"the driver identity is resolved
per-request in the driver runtime, not via a role"*. That conflates identity with authorization:
identity **is** resolved per request, but `RequirePermissionMiddleware` runs first — so the permission
named "Operate the driver runtime" was held by nobody who operates the driver runtime, and every real
driver got 403.

**Why revoking `logistics.distribution.*` is correct:** that is the **dispatcher** surface
(`/api/logistics/distribution/*`). Nothing driver-facing needs it — the driver frontend calls only
`/driver/*`, and the runtime authorises on `loading.driver.operate`.
`TASK-SHIPPING-DRIVER-CLOSURE-001-ENGINEERING-REPORT.md:158-161` recorded this exact risk and
recommended exactly this revocation.

⚠ **Not applied to any running environment.** `config/permissions.php` is the source; a grant lands
only when `RbacSeeder` runs. That is a deployment step and was **not taken** — `ecos_dev` still shows
the old grants. Verification was performed against `ecos_dev_test`.

---

## 5. Driver Authorization Flow

```
request → auth:sanctum
        → permission:loading.driver.operate            (RequirePermissionMiddleware)
        → DriverRuntimeController::driver()            Driver::where('user_id', Auth::id())  → 403 if none
        → DriverRuntimeController::ownedTrip($uuid)    company_id = actor's  AND
                                                       whereHas('driverVehicleAssignment', driver_id = own)
        → ownedStop($id) re-runs ownedTrip() on the parent — "never trust the stop id alone"
```

Three independent gates: **permission → identity → assignment**. Holding the permission is not
enough (`test_a4`); being a driver is not enough (`test_a3`).

---

## 6. Trip Tenant Isolation

| File | Method | Before | After |
|---|---|---|---|
| [SettlementController.php:214](backend/Modules/Logistics/Distribution/Presentation/Http/Controllers/SettlementController.php:214) | `resolveTrip()` | `Trip::where('uuid',$id)->firstOrFail()` | `+ ->where('company_id', $this->companyId())` |
| [DeliveryController.php:255](backend/Modules/Logistics/Distribution/Presentation/Http/Controllers/DeliveryController.php:255) | `resolveTrip()` | same defect | same fix |
| `TripController.php:372` | `resolveTrip()` | — | **already fixed** by an earlier task; copied verbatim so the three cannot drift |

`Trip` has no global tenant scope, so a uuid was a bearer token. Both controllers funnel **every**
method through `resolveTrip()` / `findStop()` / `findSettlement()`, so one line closes all of them.

**Fail-closed and NOT-FOUND, never 403** — a foreign trip must read as non-existent so the endpoint
cannot be used to probe which uuids are real. `companyId()` aborts 403 when the actor has no company;
the `->when($companyId, …)` idiom used elsewhere in Logistics silently drops the filter and returns
every tenant's rows, and is deliberately not copied.

**No new tenancy engine. Trip identity, Group→Trip, capacity and vehicle/driver semantics untouched.**

Proved by `test_c1` (payments), `test_c2` (financial summary), `test_c3` (delivery stops), `test_e4`
(a collection on another company's trip).

---

## 7. Driver Assignment Authorization

Unchanged and re-verified, not re-implemented — `logistics_driver_vehicle_assignments` remains the
canonical pairing ledger (VP-1 D3-D).

| Case | Test | Result |
|---|---|---|
| Own trip → reachable | `test_b4` | 200 |
| Another company's trip | `test_b1` | 404 |
| Another driver's trip, same company | `test_b2` | 404 |
| Unassigned trip, guessed uuid | `test_b3` | 404 |

---

## 8. Order Isolation

Driver order access is derived **only** from an owned stop on an owned trip
(`ownedStop()` → `ownedTrip()`). With `resolveTrip()` now company-scoped on the dispatcher surface,
`GET …/trips/{uuid}/stops` — which carries the order payload — no longer crosses companies
(`test_c3`).

---

## 9. Payment Collection Security

| Control | Before | After |
|---|---|---|
| Driver reaches the record path | yes | **no** — role no longer holds the verb (`test_d3`) |
| Driver reaches the verify path | yes | **no** (`test_d2`, `test_d3`) |
| Cross-company record/verify | yes | **no** (`test_e4`) |
| Self-verify | yes | **no** (`test_e1`) |
| Self-reject | yes | **no** (`test_e2`) |
| Legitimate second reviewer | — | **yes** (`test_e3`) |

### 🛑 STOP-1 — record/verify permission separation

**Blocker.** `logistics.distribution` offers `view | create | update | delete`. Both halves currently
take `update`. Repurposing `create` for "record" would silently change authorization for ~20 other
routes in the same group and for four roles that hold it (`dispatcher`, `shipping-coordinator`,
`fleet-manager`, `company-admin`).

**Per §6 of the brief — "only if the existing permission catalog supports the separation" — it does
not, so no separation was made.**

**Impact of not doing it:** low, because the objective is met by two other means — the driver no
longer holds the verb at all, and the identity-level maker-checker binds every actor including
`is_system`. A permission split would be defence in depth, not the control.

**Decision required:** add `logistics.settlement.record` / `logistics.settlement.verify` (a new
permission pair — explicitly out of bounds without approval), or accept the current shape.

---

## 10. Maker-Checker Enforcement

**Rule:** `collected_by != verified_by`, enforced **by identity, in the domain**.

| Artefact | Location |
|---|---|
| Predicate (one implementation) | [PaymentCollection::isSelfReviewBy()](backend/Modules/Logistics/Distribution/Domain/Models/PaymentCollection.php:64) |
| Verify guard | [SettlementService::verifyPayment()](backend/Modules/Logistics/Distribution/Domain/Services/SettlementService.php:63) → 403 |
| Reject guard | [SettlementService::rejectPayment()](backend/Modules/Logistics/Distribution/Domain/Services/SettlementService.php:88) → 403 |

Deliberately the same shape as the certified `PaymentProof::isSelfReviewBy()` +
`VerifyPaymentProofAction`, and in the **domain rather than the route** for the same recorded reason:
a permission split can never establish that two different *people* were involved — a user assigned
both roles, or any `is_system` role, passes the middleware. Reject is guarded as well as verify
because they are two halves of one reviewer act; guarding only one would let the collector choose the
half they control.

An unattributed collection (`collected_by IS NULL`) is not a self-review — the record route sits
behind `auth:sanctum` and always stamps the actor, so a NULL collector can only come from a console
or test path with no submitter identity.

**Canonical maker-checker untouched and re-asserted** — `test_g2`.

---

## 11. Settlement Authorization

Seven reads moved from bare `auth:sanctum` to the existing `logistics.distribution.view`
(`routes/api.php:1844-1878`): `stops`, `stops/{id}`, `exceptions`, `returns`, `payments`,
`settlement`, `financial-summary`.

**No new permission** — this is the read verb the rest of the group already uses. Proved by `test_d1`
(a user without it gets 403 on all three financial reads) and `test_d2` (a driver is refused).

**Driver-scoped settlement read is NOT granted.** §7 of the brief says to STOP rather than invent
ownership if unresolved — DRIVER-01 established that `distribution_trip_settlements` has **no
`driver_id`** and the parent `distribution_trips` deliberately has none either. There is no contract
by which a driver owns a settlement, so none was invented. The driver's own money endpoints remain
403-frozen, unchanged.

---

## 12. Image / Proof Handling

### 🛑 STOP-2 — `distribution_payment_collections` cannot be reconciled with `payment_proofs` without redesign

`payment_proofs` requires a validated `UploadedFile`, a private disk, sniffed MIME, `size_bytes`, a
supersede chain, `company_id`, and a tenant-scoped download route.
`distribution_payment_collections` has a `varchar(500)` string, **no `company_id`**, and no upload
path anywhere. Closing that gap is a schema + storage + endpoint change — a redesign, which §5 and §8
instruct me to STOP on rather than attempt.

**What was done instead — hardening only, no new mechanism.** `image_path` is now constrained to a
plain relative storage path
([SettlementController.php:64](backend/Modules/Logistics/Distribution/Presentation/Http/Controllers/SettlementController.php:64)),
rejecting URL schemes (`javascript:`, `data:`, `http:` → stored XSS / external fetch when rendered as
an image source), absolute paths, UNC paths and `..` traversal. Regex verified against 9 cases;
behaviour proved by `test_f1`.

**This does not make the field a proof, and the code says so.** It remains untrusted, and it can
never satisfy `PaymentFulfillmentGate` — the two systems write different tables (`test_g1`).

**Decision required:** (a) route driver/dispatcher payment evidence through the canonical
`payment_proofs` lifecycle; (b) build a real upload for the distribution ledger reusing
`DriverController::storeDocument` or the generic `documents` table; or (c) drop `image_path` and treat
`reference_number` as the only evidence. This is D-05 territory.

---

## 13. Endpoint Security Matrix

| Route | Permission before | Permission after | Tenant boundary | Test |
|---|---|---|---|---|
| `GET /api/driver/*` (15 routes) | `loading.driver.operate` — held by nobody who drives | same, **now granted to `driver`** | company + driver assignment, in-controller | `a2, a3, a4, b1–b4` |
| `POST /api/driver/*` money (4) | 403 frozen | **403 frozen, unchanged** | — | — |
| `GET …/distribution/trips/{id}/payments` | **none** | `logistics.distribution.view` | company | `c1, d1, d2` |
| `GET …/trips/{id}/settlement` | **none** | `logistics.distribution.view` | company | `d1` |
| `GET …/trips/{id}/financial-summary` | **none** | `logistics.distribution.view` | company | `c2, d1` |
| `GET …/trips/{id}/stops`, `stops/{id}`, `exceptions`, `returns` | **none** | `logistics.distribution.view` | company | `c3` |
| `POST …/trips/{id}/stops/{id}/payments` | `logistics.distribution.update` (driver held it) | same verb, **driver no longer holds it** | company | `d3, f1` |
| `PATCH …/payments/{id}/verify` | `logistics.distribution.update` | same + **self-review 403** | company | `e1, e3, e4` |
| `PATCH …/payments/{id}/reject` | `logistics.distribution.update` | same + **self-review 403** | company | `e2` |
| all other `…/distribution/*` writes | `logistics.distribution.update` | unchanged | **company (newly scoped)** | `c1–c3` |

---

## 14. Tests

**New:** [DriverRbacTenancySecurityTest.php](backend/tests/Feature/Security/DriverRbacTenancySecurityTest.php)
— **`OK (21 tests, 42 assertions)`**.

Every case uses `actingAsUnprivileged()` on a user wearing a role materialised from the **real**
`config/permissions.php` grant list. No case uses `actingAs()` — that helper auto-grants `is_system`,
which passes the middleware unconditionally, and is precisely why the headline defect shipped green.

| Acceptance criterion (§12) | Test |
|---|---|
| 1. Real driver role reaches authorized endpoints | `a1`, `a2` |
| 2. Unauthorized user → 403 | `a3`, `a4`, `d1` |
| 3. Driver A cannot reach company B's trip | `b1` |
| 4. Driver cannot reach another trip in the same company | `b2`, `b3` |
| 5. No cross-company order via a trip | `c3` |
| 6. No cross-company collection | `e4` |
| 7. Driver cannot verify their own collection | `e1`, `e2`, `d2`, `d3` |
| 8. No settlement data belonging to another driver | `d2` + §11 (no driver-scoped read exists) |
| 9. Settlement endpoint no longer unprotected | `d1` |
| 10. No `PaymentFulfillmentGate` behaviour change | `g1` |
| 11. No maker-checker regression | `g2`, `e3` |
| 12. No new permission invented | `a1` + §4 |

### Focused regression — 67 tests, 172 assertions, 1 failure

`DriverRbacTenancySecurityTest` + `PaymentProofRbacTemplateAlignmentTest` +
`ShippingDriverClosureTest` + `PaymentProofLifecycleTest`.

**The single failure is NOT from D-02 — proven by control run.**

| | |
|---|---|
| Failure | `PaymentProofLifecycleTest::test_10_verification_never_writes_order_status_itself` — *"Failed asserting that 0 is identical to 1"* (no `confirm_order` event) |
| Order-dependent? | **No** — fails identically in isolation |
| Control | Re-ran the single test in the container with **both** D-02 `config/permissions.php` and `routes/api.php` reverted to their pre-D-02 state → **identical failure** |
| Classification | **INTRODUCED — by another, concurrent workstream** |
| Cause | `PaymentFulfillmentGate::permitsAdvance()` was added by another agent during this task (owner decisions BL-2-A, Q3/O3) and is now called by `ConfirmOrderWorkflow:119`, `ProcessOrderWorkflow:208` and `ReevaluateOrderFulfillmentAction:140`. This suite was green earlier in this session, before that change landed. |
| Action | **None.** §10 forbids D-02 from touching `PaymentFulfillmentGate`, `ReevaluateOrderFulfillmentAction` or `ConfirmOrderWorkflow`. Reported to the owning workstream. |

### Static

| Gate | Result |
|---|---|
| `php -l` | clean, all 7 files |
| **Pint** | **passed** |
| **PHPStan** on the 4 changed classes | **`[OK] No errors`** |
| PHPStan, whole `Modules/Logistics/Distribution` | 1 error — `GroupVehicleAssignmentService.php:220` instantiates a phantom `RuntimeException` (no import → resolves inside the service namespace → fatal at throw). **An untracked file from another workstream; not touched by D-02.** Reported, not fixed. |
| Frontend | **no frontend files changed** — this repair is backend-only by design |

---

## 15. Data Safety

**No business data mutated.** `ecos_dev` unchanged and re-verified after the work: orders 19 · trips 2
· payment collections 0 · delivery stops 0 · drivers 0 · vehicles 0 · payment proofs 4 · users 1.
No drivers, vehicles, trips, orders, payment collections, payment proofs or settlements were created.

All fixtures live in `ecos_dev_test` under `DatabaseTransactions` and roll back. Test fixtures create
real `Order` rows because `distribution_delivery_stops.order_id` carries a genuine FK — inside the
transaction only.

**No migration. No schema change. No API contract change** (routes gained middleware; no route was
added, removed or re-shaped).

⚠ The RBAC change is **not applied to any environment** — `RbacSeeder` was deliberately not run
(§ "No deploy"). `ecos_dev` still shows `driver → logistics.shipping.view, logistics.distribution.view,
logistics.distribution.update`.

---

## 16. Remaining Risks

1. **The grant is not live** until `RbacSeeder` runs. Until then a real driver still gets 403.
2. **No permission separation** between record and verify (STOP-1). Mitigated by the identity check
   and by the driver no longer holding the verb.
3. **`image_path` is still not a proof** (STOP-2). Hardened against injection; architecturally unresolved.
4. **`distribution_payment_collections` still has no `company_id`.** Tenancy is now enforced through
   the parent trip on every route, but the row itself carries no tenant — a future writer that does
   not go through `resolveTrip()` would be unscoped. Closing it is a migration → out of bounds here.
5. **`delivery_cod_records`** — a third payment-verify lifecycle — is untouched. Currently reachable
   only by `is_system` roles (its seed grants nothing).
6. **Other `Trip::where('uuid',…)` sites outside Distribution** remain: `DispatchOperationsController:259`
   and `RoutingController:178`. Out of D-02's scope; reported.
7. **The concurrent `permitsAdvance()` change** has one failing contract test (§14).

---

## 17. Explicit Non-Goals

Not touched: Order lifecycle · `PaymentFulfillmentGate` · `ReevaluateOrderFulfillmentAction` ·
`ConfirmOrderWorkflow` · payment-proof maker-checker · Group identity · Group→Trip · Trip capacity ·
Vehicle identity · Driver identity · Loading Preparation · Vehicle Inventory · Waste/Liability ·
Driver Wallet · Driver Expenses · Driver Closing · Monthly Statement · Distribution eligibility ·
Preparation Wave · driver navigation and UI (D-01/D-03).

No new business status. No new financial ledger. No second source of truth. No new tenancy engine.
No new permission. No frontend change.

---

## 18. Architecture Decisions Required

| # | Decision | Blocking |
|---|---|---|
| **AD-A** | Approve the `loading.driver.operate` grant to `driver`, reversing the note in `seed_loading_os_permissions.php:30-33`. Evidence in §4. | The driver app is unusable without it |
| **AD-B** | Record/verify permission separation — add a new permission pair, or accept the current shape | STOP-1 |
| **AD-C** | Payment-evidence contract for the distribution ledger — canonical `payment_proofs`, a real upload, or drop `image_path` | STOP-2 |
| **AD-D** | Add `company_id` to `distribution_payment_collections` (migration) | Risk 4 |
| **AD-E** | Is a driver ever entitled to read their own settlement? No ownership contract exists today | §11 |

---

## 19. Final Verdict

# IMPLEMENTED / BLOCKED — OWNER DECISION REQUIRED

The primary security goal is met and focused-verified: a driver can operate only their own company's
trips, assigned to them through the canonical ledger, and can no longer reach — let alone
self-approve — the cash ledger. Cross-company access by uuid is closed on every affected controller.
No permission was invented, no migration was written, no business data was touched.

Three items are stopped rather than worked around (§9, §12), one grant needs owner approval to be
applied (§4), and one adjacent test failure belongs to a concurrent workstream and was proven so by
control run rather than assumed.

**Not certified. Not browser verified** — 0 drivers and 0 vehicles exist, and fabricating fleet data
to produce a screenshot is out of bounds.

**No commit. No deploy.**
