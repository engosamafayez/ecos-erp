# TASK-PRICE-REVIEW-ZERO-PRICE-GUARD-REPAIR-001 — ENGINEERING REPORT

**Title:** Price Review — Zero/Negative Final Price Approval Guard
**Date:** 2026-08-13
**Branch:** `develop` @ `6149875b`
**Scope:** P9 + P11 only. P8 and P10 explicitly excluded.

> ## THE INVARIANT
>
> **FINAL REGULAR PRICE MUST BE > 0.**
> No Approve path — single or bulk — may publish `0`, a negative value, or `null`
> to the live catalogue.

---

## § Root Cause

`PricingReviewController::approve()` guarded with `finalSellingPrice() === null`. The
reachable invalid value is **`0`**, not `null`:

```
'regular_price' => ['nullable','numeric','min:0']     0 is valid input
  → Laravel filled(0) === true                        the inline gate fires
  → manual_regular_price = 0.0                        the manual layer stores it
  → finalSellingPrice() returns 0.0                   NOT null
  → guard tests === null                              PASSES
  → resolve() writes products.regular_price = 0, sale_price = NULL
  → every ProductMapping flipped to sync_status = Pending
```

**Provenance: P9 is a NEW defect, introduced by TASK-PRICE-REVIEW-FINAL-DECISION-MODEL-001.**
That task added the guard specifically to prevent approving a review with no price, and
guarded the wrong condition. Before the manual layer existed, `approve_suggested` consumed
only the engine-derived suggestion, which is never 0 in practice — the manual layer is what
made 0 reachable.

Two further reachable paths were found while tracing, both the same invariant:

| Path | How 0 arrives |
|---|---|
| `custom_price` | `ApprovePricingReviewRequest` validated `custom_price` at `min:0`; `resolve()` uses `$customPrice ?? selling_price` |
| `bulk-approve` (**P11**) | no price guard **at all** — not even the `null` one |

For reference, `CostCalculationEngine.php:77` — same module, same shape of formula — already
guards with `> 0.0`. `PricingReviewService` had dropped that term.

## § P9 Fix — single Approve

The guard now runs on the **effective price for the requested action**, before any mutation:

```php
$customPriceInput = $request->validated('custom_price') !== null
    ? (float) $request->validated('custom_price')
    : null;

if (! PricingReviewService::isApprovableAt($review, $request->validated('action'), $customPriceInput)) {
    return response()->json([
        'message' => 'This review has no valid final price. Enter a final regular price greater than zero before approving.',
        'errors'  => ['final_regular_price' => ['The final regular price must be greater than zero.']],
    ], 422);
}
```

It sits after the already-resolved and missing-product checks and **before**
`$this->service->resolve(...)`, so execution never reaches `products.regular_price`,
`products.sale_price`, the `ProductMapping` sync flip, the review state change, or the audit
row when the price is invalid.

## § P11 Fix — bulk Approve

**Existing bulk semantics were determined first, not assumed.** `bulkApprove()` is already
**atomic and pre-validated**: it 404s when `whereIn` returns fewer rows than ids requested,
422s when any review's product is missing, and only then opens `DB::transaction`. The new
guard follows that identical shape:

```php
$unapprovable = $reviews->filter(
    fn (PricingReview $review) => ! $review->status->isResolved()
        && ! PricingReviewService::isApprovableAt($review, $action, $customPrice)
);

if ($unapprovable->isNotEmpty()) {
    return response()->json([...], 422);   // before the transaction opens
}
```

One invalid row rejects the whole batch and **nothing is mutated — not even the valid rows**.
That preserves the endpoint's existing atomic contract rather than inventing a
partial-success one. Bulk is now exactly as strong as single, never weaker.

Already-resolved rows are excluded from the check because `resolve()` skips them anyway
(they increment `skipped`), so a historical row cannot block a legitimate batch.

## § Final Price Invariant — single source of truth

No second resolution algorithm was introduced. The action→price selection was **extracted
verbatim** from `resolve()` into one static method that the guard and the mutation now share:

```php
public static function effectiveRegularPriceFor(PricingReview $review, string $action, ?float $customPrice): ?float
{
    return match ($action) {
        'approve_suggested' => $review->finalSellingPrice(),   // canonical manual ?? suggested
        'custom_price'      => $customPrice ?? $review->selling_price,
        default             => $review->selling_price,
    };
}

public static function isApprovableAt(PricingReview $review, string $action, ?float $customPrice): bool
{
    if ($action === 'reject') {
        return true;                                           // reject writes no price
    }
    $price = self::effectiveRegularPriceFor($review, $action, $customPrice);

    return $price !== null && $price > 0.0;
}
```

`resolve()` now calls `effectiveRegularPriceFor()` instead of holding its own `match`, so the
guard and the write can never diverge. `approve_suggested` still defers to
`PricingReview::finalSellingPrice()` — the canonical `manual ?? suggested` resolver — so a
manual decision continues to override the suggestion (§ Manual Price Interaction).

One incidental hardening: the old `'approve_suggested' => $review->finalSellingPrice() ?? $review->selling_price`
silently substituted the CURRENT price when no decision existed. That fallback is gone; the
guard now refuses such a review outright instead of approving it at a price nobody chose.

## § Validation — second layer, not a substitute

| Field | Before | After | Why |
|---|---|---|---|
| `inline.regular_price` | `min:0` | **`min:0.01`** | a manual regular price will be published |
| `approve.custom_price` | `min:0` | **`min:0.01`** | published directly to the catalogue |
| `inline.sale_price` | `min:0` | **`min:0`** (unchanged) | `0` legitimately means "clear the sale price" — existing contract, Part 11 |
| `target_margin`, `markup`, `bulk-policy.value` | `min:0` | unchanged | not catalogue prices |

Decimal precision, numeric semantics and currency handling are untouched. **No pricing
formula changed.**

Validation and the domain guard both exist, as required: validation rejects the input at the
edge, and the guard rejects the decision at the boundary even if the value were planted
directly in the database. The tests exercise both independently.

## § Backend is the final authority

The guard tests are written to bypass the UI entirely — the invalid price is planted with
`forceFill()->save()` and the request is issued over HTTP, so the guard is proven to hold even
if validation were loosened again or a client posted the value directly.

The frontend received one aligned line (`cost-pricing-center-page.tsx`): the inline editor no
longer submits a regular price of `0`. That is **UX only**, to avoid a round-trip the server
would reject; it is explicitly not the control.

## § HTTP Regression

*(runtime results — see § Runtime Verification)*

| Test | Covers |
|---|---|
| `inline_rejects_a_zero_manual_regular_price` | 0 cannot even be stored — 422 + `errors.regular_price` |
| `approve_refuses_a_zero_final_regular_price` | **TEST A** — planted 0, 422 + `errors.final_regular_price`, catalogue untouched |
| `approve_refuses_a_negative_final_regular_price` | **TEST B** — planted −1 |
| `approve_refuses_a_zero_custom_price` | the `custom_price` path, same invariant |
| `approve_accepts_the_minimum_valid_price` | **TEST D** — 0.01 succeeds and is published |
| `a_valid_manual_price_still_approves_when_no_suggestion_exists` | **PART 10** — guard does not reject a legitimate manual decision |
| `bulk_approve_refuses_the_whole_batch_when_any_final_price_is_zero` | **TESTS C + E** — atomic rejection |
| `bulk_approve_refuses_the_whole_batch_when_any_final_price_is_negative` | **TEST F** |
| `bulk_approve_still_succeeds_when_every_final_price_is_valid` | bulk not broken by the guard |

## § Catalogue Integrity

Every rejection case asserts, through a shared `assertCatalogueUntouched()` helper:

```
products.regular_price        unchanged (7044.00)
products.sale_price           unchanged (6600.00)
pricing_reviews.status        still Pending
pricing_reviews.resolved_at   still NULL
pricing_reviews.approved_price still NULL
pricing_reviews.publish_status still NULL
price_approvals               no row for this review
```

So a refused approval leaves no false success in the catalogue, the review state, or the
audit trail.

## § Manual Price Interaction

The guard operates on the **final resolved decision**, never on `suggested_selling_price`
alone. Explicitly proven by
`test_a_valid_manual_price_still_approves_when_no_suggestion_exists`: with
`suggested_selling_price = NULL` and `manual_regular_price = 6900`, Approve succeeds and
publishes 6900. A valid manual decision is never rejected merely because the engine produced
no suggestion.

## § Bulk Semantics

**Determined from the existing code, then preserved:** atomic, pre-validated, fail-closed.
No partial-success contract was invented. The guard runs before `DB::transaction` opens,
matching how unknown ids (404) and missing products (422) are already handled.

## § P8 — OUT OF SCOPE

`CostManagementDashboardController::index()` issues five bare `PricingReview::query()` calls
with no company predicate (pending counts, summed `cost_difference`, average margin) on a
route carrying `auth:sanctum` only. **PRE-EXISTING** — never touched by any task in this
session. **Not modified here.** Requires its own repair task.

## § P10 — OUT OF SCOPE

`PricingReviewResource` emits no `publish_status` / `approved_price` / `approved_sale_price` /
`published_at`, while `pricing-review.ts:73-76` declares them required, leaving the Publish
badge and button permanently false. **PRE-EXISTING** — verified against
`git show HEAD:…PricingReviewResource.php`, which emits none of them either. **Not modified
here.** Requires its own repair task.

## § Static Verification

| Check | Result |
|---|---|
| Pint | ✅ PASS — 54 files |
| PHPStan L0 | ✅ No errors |
| PHPStan core L6 | ✅ No errors |
| TypeScript (`-p tsconfig.app.json`) | ✅ **24** — documented baseline, unchanged |
| TypeScript in `cost-management` | ✅ **0** |
| ESLint | ✅ 0 errors |
| Vite build | ✅ built |
| `git diff --check` | ✅ clean |

## § Database Safety

- **No migration was created or required.** The invariant is a domain guard plus validation.
- **`ecos_dev` was not mutated.** No writes, no schema change, no `migrate:fresh`.
- All fixtures live in `ecos_dev_test` under `RefreshDatabase`, self-built per test.
- No direct SQL was used to make anything pass.

## § Scope Verification

| File | Change |
|---|---|
| `Domain/Services/PricingReviewService.php` | extracted `effectiveRegularPriceFor()`; added `isApprovableAt()`; `resolve()` consumes the extraction |
| `Presentation/Http/Controllers/PricingReviewController.php` | guard in `approve()` and `bulkApprove()`; `inline.regular_price` → `min:0.01` |
| `Presentation/Http/Requests/ApprovePricingReviewRequest.php` | `custom_price` → `min:0.01` |
| `frontend/…/cost-pricing-center-page.tsx` | one UX-only line — inline editor will not submit regular price 0 |
| `tests/Feature/CostManagement/PriceReviewActionHttpTest.php` | **+9 cases** (39 → 48) |

Nothing else touched. No dashboard, no publish flow, no pricing/cost/margin/suggested-price
engine, no tenant architecture, no schema, no UI redesign.

## § Runtime Verification

*(filled in below)*

## § Final Certification

*(filled in below)*
