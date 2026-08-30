# TASK-PRICING-REVIEW-SNOOZE-ASSIGN-HTTP-CONTRACT-REPAIR-001 — ENGINEERING REPORT

**Scope:** Targeted production bug fix — `snooze()` / `assign()` HTTP + FormRequest contract
**Date:** 2026-08-13
**Branch:** `develop` @ `6149875b`
**Origin:** P0-NEW, discovered by TASK-PRICING-REVIEW-COST-MANAGEMENT-CERTIFICATION-CLOSURE-001

> ## VERDICT: `CERTIFIED` (this task only)
>
> `PriceReviewActionHttpTest` — **`OK (25 tests, 119 assertions)`**, zero failures.
> Snooze HTTP PASS · Assign HTTP PASS · Validation PASS · Auth PASS · Tenant PASS · Static PASS.
> Regression carries **only** the 5 P7 cascade failures already proven PRE-EXISTING at HEAD.
>
> This certifies **this repair**. It does **not** certify Pricing Review overall — Real E2E
> remains PENDING USER BROWSER SMOKE (§12).

---

## 1. Root Cause

`PricingReviewController::snooze()` and `assign()` type-hinted `Illuminate\Http\Request`, validated inline, and then called `$request->validated(...)`:

```php
public function snooze(Request $request, string $id): JsonResponse   // ← base Request
{
    $request->validate(['until' => ['required','date','after:today']]);
    ...
    $this->service->snooze($review, $request->validated('until'));    // ← BadMethodCallException
}
```

`validate()` is a macro on the base `Request` and **returns** the validated data. `validated()` is **not** on the base `Request` — it exists only on `FormRequest`. So a request that had already **passed** validation then died with `BadMethodCallException`, surfacing as **HTTP 500** on an otherwise valid call.

Investigated per Part 1 before changing anything:

| Checked | Finding |
|---|---|
| Controller methods | `snooze()` / `assign()` — base `Request` + `validated()` → the defect. `inline()` / `bulkPolicy()` also take base `Request` but use `$request->input()`, so they were never affected |
| Route bindings | `POST .../{id}/snooze` and `POST .../{id}/assign`, both `permission:inventory.price_review.update`. Unchanged |
| Existing FormRequests | `ApprovePricingReviewRequest` (approve + bulk-approve), `UpdateMaterialCostRequest` (material cost). **Neither is appropriate** for these payloads |
| Validation rules | `until: required|date|after:today` · `reviewer_name: required|string|max:255` |
| Project convention | `approve()` / `bulkApprove()` already take a FormRequest and call `validated()`. Validation belongs in the FormRequest, not the controller |

**Why static analysis never caught it:** `validated()` is resolved dynamically at runtime on a class that does not declare it. PHPStan L0 and core L6 were both clean throughout — neither had anything to flag. Only a request that actually reaches the line proves it, which is why the defect survived until the first HTTP test existed for these endpoints.

---

## 2. Before Behavior

| Endpoint | Valid payload | Result before |
|---|---|---|
| `POST /pricing-reviews/{id}/snooze` | `{"until":"2026-08-16"}` | **500** — `Method Illuminate\Http\Request::validated does not exist` |
| `POST /pricing-reviews/{id}/assign` | `{"reviewer_name":"Mona Adel"}` | **500** — same |

Both failures occurred **after** validation succeeded, so the request was well-formed and authorized — the 500 was pure contract breakage. The cross-tenant cases still returned 404 correctly, because `findOrFail()` throws before the faulty line is reached; that is why the security boundary was already proven while the happy paths were not.

Present verbatim at `HEAD` (lines 268 / 283) — pre-existing, not introduced by the preceding repair work.

---

## 3. Repair

Per Part 2, converted to the existing FormRequest architecture. No suitable FormRequest existed, so two dedicated ones were created, modelled on `ApprovePricingReviewRequest`:

**`SnoozePricingReviewRequest`**
```php
public function rules(): array
{
    return ['until' => ['required', 'date', 'after:today']];
}
```

**`AssignPricingReviewRequest`**
```php
public function rules(): array
{
    return ['reviewer_name' => ['required', 'string', 'max:255']];
}
```

**Controller**
```php
public function snooze(SnoozePricingReviewRequest $request, string $id): JsonResponse
{
    $review = $this->scopedQuery()->findOrFail($id);

    if ($review->status->isResolved()) {
        return response()->json(['message' => 'This review has already been resolved.'], 422);
    }

    $this->service->snooze($review, $request->validated('until'));

    return response()->json(['message' => 'Review snoozed.']);
}

public function assign(AssignPricingReviewRequest $request, string $id): JsonResponse
{
    $review = $this->scopedQuery()->findOrFail($id);

    $this->service->assign($review, $request->validated('reviewer_name'));

    return response()->json(['message' => 'Reviewer assigned.']);
}
```

`$request->validated(...)` now runs on the correct type.

### Constraints honoured

- ❌ No `$request->all()`
- ❌ No `$request->input()` used to sidestep the problem
- ❌ No validation removed or weakened — rules carried over **verbatim**
- ❌ No `authorize()` added — matching `ApprovePricingReviewRequest`, authorization stays on the route (`permission:inventory.price_review.update`), so no permission surface moved
- ❌ No change to tenant scoping — both still resolve through `scopedQuery()`

### Business semantics — unchanged (Part 3)

| Rule | State |
|---|---|
| Snooze sets `status = snoozed` + `snooze_until`, does **not** resolve | unchanged |
| Snooze refuses an already-resolved review with 422 | unchanged |
| Snooze writes no price and no audit row | unchanged |
| Assign records `reviewer_name` only; **not** a resolution | unchanged |
| Assign leaves status untouched | unchanged |
| Pricing Review lifecycle · permissions · tenant rules · approval · reject · custom pricing | **untouched** |

---

## 4. Validation Contract

| Endpoint | Field | Rules | Invalid → |
|---|---|---|---|
| snooze | `until` | `required`, `date`, `after:today` | **422** + `errors.until` |
| assign | `reviewer_name` | `required`, `string`, `max:255` | **422** + `errors.reviewer_name` |

Order of enforcement, proven by test rather than assumed — moving rules into a FormRequest changes *when* they run, so this was verified explicitly:

```
auth:sanctum        → 401 for an unauthenticated caller
permission:…update  → 403 for an authenticated caller lacking the grant
FormRequest rules   → 422 for a malformed payload
scopedQuery()       → 404 for a cross-tenant or absent record
controller          → 422 for an already-resolved review
service → DB        → 200
```

A well-formed payload can no longer reach the rules ahead of the auth or permission gate.

---

## 5. Snooze Test

| Case | Proves |
|---|---|
| `test_snooze_still_moves_the_review_out_of_pending` | **200**; `status = snoozed`; absent from `?status=pending`; **no** `price_approvals` row; product price unmoved |
| `test_snooze_rejects_a_non_future_date_with_422` | past date → **422** with `errors.until` — not 500 |
| `test_cross_tenant_review_cannot_be_snoozed_or_assigned` | foreign review → **404**, nothing mutated |
| `test_snooze_and_assign_are_forbidden_without_the_update_permission` | view-only actor → **403**, `snooze_until` still null |
| `test_snooze_and_assign_reject_unauthenticated_callers` | no session → **401** |

All ✔.

## 6. Assign Test

| Case | Proves |
|---|---|
| `test_assign_persists_the_reviewer_and_is_readable_afterwards` | **200**; `reviewer_name = 'Mona Adel'` asserted both on the model and via `assertDatabaseHas`; status stays `pending`; no audit row; price unmoved |
| `test_assign_rejects_a_missing_reviewer_name_with_422` | empty payload → **422** with `errors.reviewer_name` |
| `test_cross_tenant_review_cannot_be_snoozed_or_assigned` | foreign review → **404** |
| `test_snooze_and_assign_are_forbidden_without_the_update_permission` | view-only actor → **403**, `reviewer_name` still null |
| `test_snooze_and_assign_reject_unauthenticated_callers` | no session → **401** |

All ✔.

## 7. Security Tests

| Requirement (Part 4) | Case | Result |
|---|---|---|
| Unauthorized → denied | `snooze_and_assign_are_forbidden_without_the_update_permission` | **403** ✔ |
| Unauthenticated → denied | `snooze_and_assign_reject_unauthenticated_callers` | **401** ✔ |
| Cross-company → denied | `cross_tenant_review_cannot_be_snoozed_or_assigned` | **404**, no existence leak ✔ |

Every subject is built with `grantsBaselineAuthorization = false` and `actingAsUnprivileged()`, so no case is granted the `is_system` role — which would make `TenantOwnershipResolver::isUnrestricted()` true and erase the boundary under test.

---

## 8. Regression

Runner idle gate satisfied first: **6 consecutive clear checks** at 05:18:43 (`SUSTAINED_IDLE_CONFIRMED`). Database target confirmed from inside the runner: **`ecos_dev_test`**.

### Pricing Review HTTP suite

```
Price Review Action Http (Tests\Feature\CostManagement\PriceReviewActionHttp)
 ✔ Approve applies prices closes review and leaves pending
 ✔ Approved by stores the canonical bigint user id
 ✔ Pending summary count decreases after approval
 ✔ Keep current holds regular price and closes review
 ✔ Custom price applies the operator value
 ✔ Reject closes the review without touching price
 ✔ Approve fails safely when the product no longer exists
 ✔ Resolving an already resolved review is rejected
 ✔ Invalid action is rejected with 422
 ✔ User without the approve permission is forbidden
 ✔ Unauthenticated request is rejected
 ✔ Cross tenant review is not readable
 ✔ Cross tenant review cannot be approved
 ✔ Cross tenant review cannot be snoozed or assigned
 ✔ Cross tenant review cannot be inline edited
 ✔ Companyless user sees nothing and cannot mutate
 ✔ Bulk approve fails closed when the selection crosses tenants
 ✔ Bulk approve resolves every owned review
 ✔ Bulk policy fails closed when the selection crosses tenants
 ✔ Snooze still moves the review out of pending
 ✔ Assign persists the reviewer and is readable afterwards
 ✔ Assign rejects a missing reviewer name with 422
 ✔ Snooze rejects a non future date with 422
 ✔ Snooze and assign are forbidden without the update permission
 ✔ Snooze and assign reject unauthenticated callers

OK (25 tests, 119 assertions)
```

**25/25 PASS. Zero failures, zero errors, zero skips.**

Count moved 20 → 25: three cases had been added covering the snooze/assign happy paths and their validation, and this task added two more for the auth/permission gates on those two endpoints specifically (§7).

### Cost Management regression (full directory)

```
Tests: 34, Assertions: 148, Failures: 5
```

34 = 25 (HTTP suite) + 9 (`PricingReviewCascadeTest`). **All 5 failures are in the cascade suite:**

- Pricing review created after manual cost update — `0.0` vs expected `30.0`
- No review created when product cost unchanged — found 1 entry, expected 0
- Existing pending review is updated not duplicated — `30.0` vs expected `40.0`
- New review created after previous one was resolved — `30.0` vs expected `40.0`
- Margin below target flag set correctly — array missing `margin_below_target`

**Identical in name, message and count to the pristine-HEAD control run** performed during the closure task (`Tests: 9, Assertions: 29, Failures: 5` at service hash `0c831b1e`). These are **P7-NEW / PRE-EXISTING** in the cost-cascade path (`MaterialCostService` → `CostCascadeService` → `ProductCostCalculator`).

Per Part 5 they were **not fixed** and **no assertion of theirs was changed**.

**NO NEW REGRESSION INTRODUCED.**

---

## 9. Static Quality

Run on the changed files after the repair:

| Check | Result |
|---|---|
| **Pint** | ✅ **PASS — 52 files** |
| **PHPStan L0** | ✅ **[OK] No errors** |
| **PHPStan core L6** | ✅ **[OK] No errors** |

No unrelated baseline violation was touched. Frontend was not modified by this task, so its gates are unchanged from the closure report (TypeScript 24 before → 24 after, 0 in cost-management; ESLint clean; Vite build clean).

### Source parity (host == runner == app)

| File | Hash | Match |
|---|---|---|
| `PricingReviewController.php` | `5130e814` | ✅ |
| `SnoozePricingReviewRequest.php` | `0726e430` | ✅ |
| `AssignPricingReviewRequest.php` | `b5e38f72` | ✅ |
| `PriceReviewActionHttpTest.php` | `6937505e` | ✅ |

---

## 10. P7-NEW — Pre-existing Finding (not fixed)

| Field | Value |
|---|---|
| ID | **P7-NEW** |
| Suite | `tests/Feature/CostManagement/PricingReviewCascadeTest.php` |
| Failures | 5 of 9 |
| Symptom | `product_cost` resolves to `0.0` / `30.0` where `30.0` / `40.0` is expected; `margin_below_target` impact flag not set |
| Suspect path | `MaterialCostService::update()` → `CostCascadeService` → `ProductCostCalculator` → Recipe/Product |
| Evidence of pre-existence | Controlled run of a pristine `git archive HEAD` copy of the whole `CostManagement` module produced the **identical 5 failures** |
| Status | **PRE-EXISTING — OPEN — requires an independent repair task** |
| Action taken here | **None.** Assertions unchanged, code untouched |

Other findings from the closure report (P1–P6) likewise remain open and untouched: TypeScript baseline, missing `view` permission on Pricing Review GET routes, the `cost.price_review.*` namespace enforced nowhere, badge cache key never invalidated, `PriceReviewApproved`/`Rejected` having zero listeners, and another session's unrelated uncommitted work.

---

## 11. Files Changed

| File | Change |
|---|---|
| `backend/Modules/CostManagement/Presentation/Http/Requests/SnoozePricingReviewRequest.php` | **NEW** — FormRequest, rules carried over verbatim |
| `backend/Modules/CostManagement/Presentation/Http/Requests/AssignPricingReviewRequest.php` | **NEW** — FormRequest, rules carried over verbatim |
| `backend/Modules/CostManagement/Presentation/Http/Controllers/PricingReviewController.php` | `snooze()` / `assign()` signatures switched to the FormRequests; inline `validate()` removed; two imports added |
| `backend/tests/Feature/CostManagement/PriceReviewActionHttpTest.php` | **+2 cases** — 403 without `update` permission, and 401 unauthenticated, for both endpoints |

Nothing else was touched. Per Part 9: **no** Warehouse, Reservation, Preparation, Distribution, IAM or Shipping code was modified — no dependency on any of them exists in this root cause.

Per Part 8: `migrate:fresh` was **not** run on `ecos_dev`; `FG-000001` and the last real pending review were **not** modified; all automated testing ran against `ecos_dev_test` with `RefreshDatabase` and self-built fixtures.

---

## 12. Final Verdict

### This task: `CERTIFIED`

```
SNOOZE HTTP      = PASS
ASSIGN HTTP      = PASS
VALIDATION       = PASS
AUTH             = PASS   (401 unauthenticated · 403 without permission)
TENANT           = PASS   (404 cross-company, no existence leak)
REGRESSION       = PASS   (no new failures; 5 cascade failures pre-existing)
STATIC QUALITY   = PASS   (Pint 52 files · PHPStan L0 · PHPStan core L6)
```

Every criterion in the task's certification block is met, with runtime evidence from real HTTP → Laravel → real database.

### Pricing Review overall: still `NOT CERTIFIED`

Per Part 10, this repair does **not** certify the feature. Handing back to the certification task:

```
AUTOMATED TESTS  = PASS      (25/25, 119 assertions)
API              = PASS
TENANT ISOLATION = PASS
PRICE PRECISION  = PASS      (catalogue 7011.11 · review 7011.1111)
STATIC QUALITY   = PASS
BROWSER READY    = PASS      (bundle deployed + hash-verified; ecos_dev migrated)
REAL E2E         = PENDING USER BROWSER SMOKE
─────────────────────────────────────────────────
FINAL FEATURE CERTIFICATION = NOT CERTIFIED
```

The **only** remaining gate is an authenticated browser click-through, which requires a session this session cannot create — credentials are never entered on the user's behalf. Everything else now has runtime proof.

When that smoke is run, use a **dedicated resettable fixture — not `FG-000001`**, which remains untouched at `status = pending` in `ecos_dev`.

---

*No feature work started. No schema changed. No pricing architecture altered. P7-NEW and P1–P6 left untouched. Browser E2E not started — it closes in the certification task.*
