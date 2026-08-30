# TASK-PRICE-REVIEW-ACTION-REPAIR-001 — ENGINEERING REPORT

**Title:** Pricing Decision Center — Row Actions Full Repair
**Date:** 2026-08-13
**Branch:** `develop` @ `6149875b`
**Prerequisite:** TASK-PRICE-REVIEW-ACTION-REGRESSION-DIAGNOSTIC-001 (root causes proven; not re-derived)
**Verdict:** see §27

---

## 1. Executive Summary

Four of the five row actions in the Pricing Decision Center — **Approve, Price Kept, Custom Price, Reject** — plus all bulk variants returned HTTP 500 before any mutation ran, and the UI showed nothing at all. This task repairs the execution path end to end.

Five things changed, in six files:

| # | Change | Why |
|---|---|---|
| 1 | **Approver identity contract** — `resolve(..., ?int $approverId)` + `price_approvals.approved_by` migrated `char(36)` → `unsignedBigInteger` with an FK to `users` | The `TypeError` root cause. Fixed as one coherent contract across controller → service → model → schema → tests, not by loosening a type hint |
| 2 | **Approve = Apply** — the `publishing_strategy` fork was removed from `resolve()` | Approve now writes the decided price to the product in every case. No hidden second Publish step |
| 3 | **Tenant isolation** — all 11 controller methods, plus summary counters and the nav badge, now resolve records through the existing `TenantOwnershipResolver` | Every read and write was unscoped; a review from another company was both readable and mutable |
| 4 | **Error visibility** — `onError` added to all 14 frontend mutation call sites; client-side row removal deleted | A fatal 500 was indistinguishable from a dead button |
| 5 | **HTTP test coverage** — 18 cases against the real routes | The old suite called the model directly and stayed green while every row action was dead |

**No pricing formula was changed.** Margin, markup, discount, suggested-price and sale-price derivation are byte-for-byte identical. Only the *destination* of an already-decided price moved, and only for the `approval_only` branch that Part 7 supersedes.

**No second pricing engine was introduced.** `PricingReviewService::resolve()` remains the single authoritative mutation path; bulk actions call the same method in a loop inside one transaction.

---

## 2. Business Contract

Implemented exactly as Part 0 specifies:

```
Approve
  → approve the pricing decision
  → apply the approved Regular Price to the product
  → apply the approved Sale Price to the product
  → persist through the canonical pricing path (PricingReviewService::resolve)
  → record the approval/audit row (price_approvals)
  → close/resolve the review (status + resolved_at)
  → review leaves the Pending queue (index filters on status)
  → frontend invalidates and refetches; the row disappears because the API stopped returning it
```

Per-action semantics are preserved and deliberately **not** made identical:

| Action | Regular price | Sale price | Terminal status | Audit row |
|---|---|---|---|---|
| `approve_suggested` | ← `suggested_selling_price` | ← `suggested_sale_price` (as shown to the operator) | `approved` | ✅ |
| `keep_current` | held at `selling_price` | recomputed from the live discount | `kept` | ✅ |
| `custom_price` | ← operator value | operator value × (1 − discount%) | `custom_price` | ✅ |
| `reject` | **untouched** | **untouched** | `rejected` | ✅ |
| `snooze` | untouched | untouched | `snoozed` (non-terminal) | ❌ by design |

---

## 3. Root Cause

Carried from the diagnostic, not re-investigated.

`PricingReviewController::approve()` passed `$request->user()?->id` — an `int`, because `users.id` is `bigint unsigned` (ADR-040) — into `PricingReviewService::resolve()`, whose 7th parameter was `?string $approverId` in a file declaring `strict_types=1`. PHP raises a `TypeError` at the call boundary, so the service body never executed.

Proven by seven identical log entries carrying `"userId":1`, by `price_approvals` holding **0 rows in every database**, and by the affected review's `updated_at` (2026-07-29) predating every approve attempt (2026-08-12).

The `TypeError` is gone because the contract is now coherent, not because it was suppressed. Nothing is caught, silenced, or cast away at the call site.

---

## 4. Approver Identity Contract

Part 2 required determining the **intended canonical identity relationship already used elsewhere**, not inventing one.

### Evidence

`users.id` is `bigint unsigned auto_increment`. Every column in the database that carries a real foreign key to `users` is `bigint unsigned`:

```
supplier_returns.approved_by         bigint unsigned   FK → users.id   ← same name, same purpose
finance_journal_entries.approved_by  bigint unsigned
finance_supplier_bills.approved_by   bigint unsigned
finance_customer_invoices.approved_by bigint unsigned
hr_payroll_runs.approved_by          bigint unsigned
fleet_inspections.approved_by        bigint unsigned
```

Verified live against the running schema (read-only):

```
Schema::getColumnType('users','id')                    → bigint
Schema::getColumnType('price_approvals','approved_by') → char
Schema::getForeignKeys('supplier_returns')             → [approved_by], [company_id], …
Schema::getForeignKeys('price_approvals')              → [pricing_review_id], [product_id]   (no approved_by)
```

`supplier_returns.approved_by` is the direct precedent: identical column name, identical approval-audit purpose, already carrying the FK. The `char(36)` variants elsewhere (`preparation_sessions`, `inventory_count_sessions`, `config_audit_log`) hold no FK to `users` and are legacy.

### Contract implemented

**`approved_by` holds `users.id` as a bigint, with a foreign key to `users` and `nullOnDelete`.** Applied coherently across all six layers:

| Layer | Change |
|---|---|
| Controller | `approverId: $request->user()?->id` — unchanged, now type-correct |
| Service | `?string $approverId` → **`?int $approverId`** |
| Domain event | `PriceReviewApproved`/`Rejected` take `string $approverId`; the service casts explicitly `(string) $approverId` rather than relying on coercion |
| Model | `'approved_by' => 'integer'` cast + `approver(): BelongsTo<User>` relation |
| Schema | migration `2026_08_13_120000` — `char(36)` → `unsignedBigInteger` nullable + FK |
| Tests | asserts `assertSame($user->id, $approval->approved_by)`, `assertIsInt(...)`, and that `$approval->approver` resolves |

No new identity mechanism. No other identity table touched. MySQL implicit `1 → "1"` conversion is explicitly **not** relied on — that was the trap Part 2 called out, and it is why the type hint alone would have been insufficient.

---

## 5. Approval Schema

`price_approvals` — write-once audit (`$timestamps = false`). The only change is the `approved_by` column type plus its FK. No field was invented, renamed, or removed.

Migration `2026_08_13_120000_align_price_approvals_approved_by_with_users.php`:

1. Skips entirely if the table/column is absent, and is idempotent (re-running only ensures the FK).
2. **Data-safety pre-step** — nulls only values that cannot represent a bigint key (driver-aware: MySQL `NOT REGEXP`, Postgres `!~`, SQLite cast). Numeric values are preserved. **Nothing is deleted and no row is rewritten.**
3. Changes the column via the Schema builder (Laravel 12, no doctrine/dbal).
4. Adds the FK to `users` with `nullOnDelete`, guarded against duplication.
5. `down()` reverses cleanly — drops the FK, restores `uuid`.

See §22 for the provenance investigation Part 19 required.

---

## 6. Tenant Isolation

The diagnostic proved every method used unscoped `findOrFail()` / `whereIn()`. Fixed by reusing the mechanism the surrounding Inventory domain already uses — **`App\Core\Company\TenantOwnershipResolver`** (RC-6 / GD-1), the same resolver and the same fail-closed predicate as `ProductController::patch()`, `ProductController::stats()` and `EloquentProductRepository::paginate()`.

No new global scope was created. `pricing_reviews` carries `company_id` directly, so ownership needs no relation hop:

```php
private function scopedQuery(): Builder
{
    $query = PricingReview::query();

    if ($this->tenant->appliesTo() && ! $this->tenant->isUnrestricted()) {
        $companyId = $this->tenant->companyId();

        if ($companyId === null) {
            $query->whereRaw('1 = 0');   // no company ≠ every company
        } else {
            $query->where('company_id', $companyId);
        }
    }

    return $query;
}
```

Applied to **every** entry point, not just Approve:

| # | Method | Scoped |
|---|---|---|
| 1 | `index` (list) | ✅ |
| 2 | `index` summary/statistics — all 7 counters | ✅ |
| 3 | `detail` | ✅ |
| 4 | `approve` — `approve_suggested` | ✅ |
| 5 | `approve` — `keep_current` | ✅ |
| 6 | `approve` — `custom_price` | ✅ |
| 7 | `approve` — `reject` | ✅ |
| 8 | `snooze` | ✅ |
| 9 | `assign` | ✅ |
| 10 | `inline` (PATCH — writes `products`) | ✅ |
| 11 | `publish` (writes `products`) | ✅ |
| 12 | `bulkApprove` | ✅ + fail-closed |
| 13 | `bulkPolicy` | ✅ + fail-closed |
| 14 | `badge` | ✅ |

The `badge` endpoint previously filtered by a **client-supplied** `company_id` only — that parameter now narrows *within* the authoritative scope instead of being the scope.

Cross-company access still flows only through the documented `is_system` path. An actor with no company is closed out, never widened to the global pool.

---

## 7. Approve

`POST /cost-management/pricing-reviews/{id}/approve` · `permission:inventory.price_review.approve`

Path: route → `auth:sanctum` → permission middleware → tenant-scoped `findOrFail` → resolved-state guard (422) → product-exists guard (422) → `PricingReviewService::resolve()` inside `DB::transaction` → product price write → `ProductMapping` sync flag → review `approved_price`/`approved_sale_price`/`publish_status='published'`/`published_at` → `price_approvals` row → `PricingReview::resolve(Approved)` → `PriceReviewApproved` event.

Approve does not bypass the pricing domain, does not duplicate any calculation, and introduces no frontend-specific mutation.

---

## 8. Price Kept

`keep_current` through the same endpoint and the same service. Regular price is **held**; the sale price is recomputed from the live discount (pre-existing contract, unchanged). Terminal status `kept`. Audit row written with `action = 'keep_current'`.

## 9. Custom Price

`custom_price` through the same endpoint and service. Regular price ← operator value; sale ← value × (1 − discount%). `price_approvals.custom_price` records the raw operator input (populated for this action only). Terminal status `custom_price`. The custom path uses the identical discount source, publishing path, audit shape and margin formula as the others — it does not bypass the approved pricing contract.

## 10. Reject

`reject` through the same endpoint and service. **Writes no price** — the one action that never touches the product, exactly as before. `publish_status` stays `NULL`. Terminal status `rejected`. Audit row still written. `PriceReviewRejected` fires instead of `PriceReviewApproved`.

---

## 11. Snooze

Per Part 10, Snooze was **audited, not modified for symmetry**.

- It does not pass `approverId`, so it never carried the `TypeError`. Its mutation contract (`status = snoozed`, `snooze_until = <date>`) is untouched.
- The one defect it *did* share is tenant isolation — `snooze()` used the same unscoped `findOrFail`. That is in scope (Part 3 lists snooze explicitly) and is fixed by `scopedQuery()`. Its business semantics are unchanged.
- It also gained an `onError` handler on the frontend, since a failed snooze was equally invisible.

No other change. Snooze remains non-terminal: it writes no price and no audit row.

---

## 12. Bulk Actions

Bulk uses the **same canonical service** as single-row actions — `bulkApprove` loops `PricingReviewService::resolve()`. There is no frontend loop issuing N requests, and no parallel bulk implementation.

**Fail-closed on mixed-company input** (Part 11):

```php
$ids     = array_values(array_unique(array_map('strval', (array) $request->input('ids', []))));
$reviews = $this->scopedQuery()->with('product')->whereIn('id', $ids)->get();

if ($reviews->count() !== count($ids)) {
    return response()->json(['message' => 'One or more pricing reviews were not found.'], 404);
}
```

If **any** requested id is not resolvable inside the caller's tenant, **nothing** is mutated — not even the rows the caller legitimately owns. The message is identical for "belongs to another company" and "does not exist", so existence is not disclosed. The whole loop runs inside `DB::transaction`, so a mid-loop failure cannot leave a partial bulk.

`bulkPolicy` received the same treatment.

---

## 13. Pricing Mutation

**No pricing formula was modified.** Unchanged: cost source, markup, target margin, brand margin, discount, suggested regular, suggested sale, margin/gross-profit derivation, and `Product::effectiveTargetMargin()` / `effectiveDiscountPct()`.

What changed is solely the **destination** of the already-decided price. Before, under `publishing_strategy = 'approval_only'`:

```php
if ($strategy === 'approval_only') {
    $review->update([...'publish_status' => 'pending_publish']);   // product untouched
} else {
    $product->update(['regular_price' => $newPrice, ...]);
}
```

Now, unconditionally:

```php
$product->update(['regular_price' => $newPrice, 'sale_price' => $effectiveSalePrice]);
ProductMapping::query()->where('product_id', $product->id)->update(['sync_status' => Pending]);
$review->update([
    'approved_price'      => $newPrice,          // still recorded — audit of what was decided
    'approved_sale_price' => $effectiveSalePrice,
    'publish_status'      => 'published',
    'published_at'        => now(),
]);
```

`$newPrice` and `$newSalePrice` are computed by the identical expressions as before. Part 18 is satisfied: this **executes** the decision, it does not recalculate it.

### Relationship to `publishing_strategy` (Part 7)

The affected brand carries `publishing_strategy: "approval_only"`. Under the previous code that meant a decided price was staged on the review and the product kept its old price until a second, separate Publish call — the "approved but not published" state Part 27 forbids.

The reading now implemented: **the Price Review Center IS the approval gate.** Resolving a review is the approval, so `approval_only` was imposing a second gate on an already-gated decision. Approve therefore applies in every case.

Consequences, stated plainly:

- `publishing_strategy` no longer influences `resolve()`. It remains stored brand configuration and is read by nothing in this path. The `ConfigurationManager` dependency was removed from `PricingReviewService` because it had no other use.
- `POST /{id}/publish` is retained but is now **legacy-only** — no new review can reach `pending_publish`. It exists to drain rows staged by the previous behaviour, and is tenant-scoped and transactional like everything else.
- If the business later wants a genuine two-step publish, it needs a separate explicit operator-visible state, per Part 27 — not the silent staging that existed before.

---

## 14. Review State

Unchanged state machine. `pricing_reviews.status` cast to `PricingReviewStatus`; terminal set (`isResolved()`) = `approved`, `kept`, `custom_price`, `rejected`; `pending` and `snoozed` remain open. `PricingReview::resolve()` sets status + `resolved_at`.

After a successful action the **backend state changes first**; the list endpoint filters `where('status', $status)`, so the review is genuinely absent from `?status=pending` on the next fetch. The frontend removes nothing.

---

## 15. Approval Audit

Every successful resolution writes one `price_approvals` row. Verified fields:

| Required (Part 12) | Column | Status |
|---|---|---|
| review id | `pricing_review_id` | ✅ |
| product/review reference | `product_id` | ✅ |
| approved_by | `approved_by` (bigint FK → `users.id`) | ✅ **repaired** |
| approved values | `new_selling_price`, `new_sale_price`, `custom_price` | ✅ |
| previous values | `old_product_cost`, `old_selling_price`, `old_sale_price` | ✅ |
| decision | `action` | ✅ |
| timestamp | `approved_at`, `created_at` | ✅ |
| tenant/company context | via `pricing_review_id` → `pricing_reviews.company_id` | ✅ (existing shape; no column invented) |

No audit field was invented. `margin_pct` and `discount_pct` continue to be captured.

---

## 16. Error Handling

The 500 is gone. Business failures use the controller's existing JSON-response convention — the global error system was not redesigned.

| Condition | Before | Now |
|---|---|---|
| Type mismatch on approver | **500 TypeError** | *(cannot occur — contract aligned)* |
| Unauthorized action | 403 | 403 (unchanged) |
| Unauthenticated | 401 | 401 (unchanged) |
| Cross-tenant record | **200 + mutation** | **404**, fails closed, no existence leak |
| Already-resolved review | 422 | 422 (unchanged) |
| Invalid payload | 422 | 422 (unchanged) |
| Product no longer exists | **500** (null dereference) | **422** with a business message |
| Bulk selection containing an unreachable id | silent partial processing | **404**, nothing mutated |

`resolve()` is now wrapped in `DB::transaction`, so a failure part-way through cannot leave a published price with no audit row.

---

## 17. Frontend Mutation

The diagnostic had already ruled out query-key mismatch as the cause, so the hooks were left alone — they were correct. Two changes only, both required to complete the certified workflow:

**Client-side hiding removed.** `removeFromCurrentList()` wrote to `['pricing-reviews', query]`, a key that never existed (the real key is `['company', <id>, 'pricing-reviews', params]`), so it was dead code. It was deleted rather than repaired: correcting the key would have made rows vanish on click regardless of backend state — exactly the shortcut Part 28 forbids. Rows now disappear only because the refetched list no longer contains them.

**`onError` added to all 14 mutation call sites** — approve, keep, custom, reject, snooze, assign, publish, inline, bulk approve/keep/reject, bulk brand-policy/margin/markup/snooze. Each shows the API's own message via a local `apiErrorMessage()` helper matching the existing project idiom (`driver-drawer.tsx`, `shipping-company-drawer.tsx`).

On failure: nothing is removed, no dialog closes, no selection clears, and the error is neither swallowed nor expanded into a stack trace (Laravel returns only `{message}` in production).

14 new i18n keys added to **both** `en` and `ar` `cost-management.json` — the i18n keys are typed, so a missing key is a compile error, not a runtime blank.

---

## 18. Cache Invalidation

Unchanged and already correct. Every mutation hook invalidates `['company', companyId, 'pricing-reviews']`, the exact prefix the list query uses (`['company', companyId, 'pricing-reviews', params]`), with `companyId` derived identically in both. Table, summary counters and Pending KPI all come from that one response, so all three refresh together.

Known residual (unchanged, out of scope): the nav badge uses key root `price-review-badge` and is not invalidated by mutations; it self-corrects on its 120 s `refetchInterval`.

---

## 19. HTTP Tests

*(filled in below — see §19.1)*

## 20. Regression Tests

*(filled in below — see §20.1)*

---

## 21. Static Verification

| Check | Scope | Result |
|---|---|---|
| **Pint** | `Modules/CostManagement`, `tests/Feature/CostManagement` | ✅ **PASS** — 50 files |
| **PHPStan L0** | `phpstan.neon.dist` (whole platform) | ✅ **No errors** |
| **PHPStan core L6** | `phpstan-core.neon.dist` | ✅ **No errors** |
| **TypeScript** | `npx tsc --noEmit -p tsconfig.app.json` | ✅ **24 errors before, 24 after** — 0 in `cost-management` |
| **ESLint** | changed frontend files | ✅ **0 errors** |
| **Vite build** | production build | ✅ **built in 8.87s** |
| **Route table** | `route:list --path=pricing-reviews` | ✅ same 10 routes, same methods, no collision |

The 24 TypeScript errors are the pre-existing EPIC-L10N-001 baseline (marketing, orders, stock-ledger). Measured by reverting only my three frontend files, re-running, and restoring — **identical count**, so this change is neutral on the ratchet.

### Scope of change

Exactly six files:

```
M backend/Modules/CostManagement/Domain/Models/PriceApproval.php
M backend/Modules/CostManagement/Domain/Services/PricingReviewService.php
M backend/Modules/CostManagement/Presentation/Http/Controllers/PricingReviewController.php
M backend/Modules/CostManagement/Providers/CostManagementServiceProvider.php
? backend/Modules/CostManagement/Infrastructure/Database/Migrations/2026_08_13_120000_align_price_approvals_approved_by_with_users.php
? backend/tests/Feature/CostManagement/PriceReviewActionHttpTest.php
M frontend/src/features/cost-management/pages/cost-pricing-center-page.tsx
M frontend/src/i18n/locales/{en,ar}/cost-management.json
```

The working tree also carries **unrelated uncommitted Orders/Operations/Distribution work from another session** (`manual-order-form.tsx`, `tests/TestCase.php`, `vite.config.ts`, `router.ts`, `routes.ts`, `tests/Feature/Operations/*`, and the Commerce/Logistics modules). **None of it was touched by this task.**

---

## 22. Database Safety

**Provenance investigation (Part 19) — completed before any schema change.**

`price_approvals` row counts, read-only:

| Database | Rows |
|---|---|
| `ecos_dev` | **0** |
| `ecos_erp` (main stack) | **0** |
| `ecos_dev_test` | table did not exist |

**There are no historical `approved_by` values anywhere** — UUID-like or otherwise. That is a direct consequence of the root cause: the `TypeError` meant a row was never successfully written. So there is no history to preserve, and no risk of rewriting one.

The migration is nonetheless written defensively for any environment not inspected here: numeric values survive the type change, only non-numeric values become `NULL`, nothing is deleted, and `down()` restores the previous column type. No unrelated identity table was touched.

---

## 23. Tenant Tests

*(filled in below — see §19.1)*

## 24. Final E2E Flow

*(filled in below)*

## 25. Certification Matrix

*(filled in below)*

## 26. Remaining Risks

*(superseded — see §28 and the certification-closure report)*

## 27. Final Verdict

*(superseded — see §28)*

---

# 28. Final Decision Price vs Suggested Price

**Added 2026-08-13 after an URGENT CONTRACT CORRECTION.** Approve was applying the
suggested price *even when the operator had already priced the row by hand*. That
is not the approved behaviour and it silently discarded an explicit human decision.

## 28.1 The contract

Approve applies the **FINAL DECISION PRICE**, which is only the suggestion when no
manual decision was made.

| Path | Condition | Approve applies |
|---|---|---|
| **A** | Operator has not edited the price | canonical **suggested** price |
| **B** | Operator edited the price via the inline editor | the operator's **edited** price |

The suggestion must never overwrite an explicit operator decision. `custom_price`
remains a separate action with its own meaning — Approve after an edit still
resolves to `approved`, not `custom_price`.

## 28.2 What the trace found

The "edit old price" control is the inline pencil on the Regular Price / Sale Price
columns → `PATCH /cost-management/pricing-reviews/{id}/inline`.

**The frontend was never the problem.** The edited value reaches the backend and is
persisted immediately:

```
edit regular_price 6900 → products.regular_price = 6900 , pricing_reviews.selling_price = 6900
edit sale_price    6500 → products.sale_price    = 6500
```

The defect was entirely in resolution — Approve sends `{action:'approve_suggested'}`
with no price, and `resolve()` read `suggested_selling_price` / `suggested_sale_price`
unconditionally, which the inline edit never updates.

### Data model as found

| Concept | Where it lived |
|---|---|
| Current / old regular price | `pricing_reviews.selling_price` — **mutated in place** by the inline edit |
| Current / old sale price | `products.sale_price` only — the review has **no** sale-price column |
| Suggested regular / sale | `pricing_reviews.suggested_selling_price` / `suggested_sale_price` |
| Manual edited price | **no dedicated field** |
| Manual / custom-price flag | `products.pricing_mode = 'custom'` — set **only** by margin/markup edits, never by a direct price edit |
| Approved decision | `pricing_reviews.approved_price` / `approved_sale_price` (written at resolution) |
| Final decision price | **did not exist as a concept** |
| Canonical resolver | **none** — `Product::effectiveTargetMargin/Markup/DiscountPct` exist; there is no effective-price resolver. `ProductPricingGateway` is POS-side and unrelated |

Because the inline edit overwrites `selling_price` in place, the pre-edit value is
gone — so an edited row and an untouched row were **indistinguishable**: both simply
had a current price differing from the suggestion.

One existing behaviour is worth noting, because it is the domain's own pattern:
editing **target margin or markup already recomputes `suggested_selling_price`**, so
`approve_suggested` has always applied the operator's intent on that path. Only
direct price edits bypassed it.

## 28.3 Resolution

Two boolean flags on `pricing_reviews` — **deliberately not new price columns**, since
the operator's figures already live in `selling_price` and `products.sale_price` and
duplicating them would create a second source of truth for the same number:

```
pricing_reviews.selling_price_overridden   boolean default false
pricing_reviews.sale_price_overridden      boolean default false
```

A canonical resolver was added to the domain model, so the decision order lives in
one place rather than as a conditional inside the service:

```php
PricingReview::finalSellingPrice(): float
    → selling_price_overridden ? selling_price : suggested_selling_price

PricingReview::finalSalePrice(float $finalSellingPrice, float $discountPct, ?float $manualSalePrice): ?float
    1. sale_price_overridden      → the operator's sale price, verbatim
    2. selling_price_overridden   → derived from the DECIDED regular × live discount
                                    (the stored suggestion came from a price no
                                     longer chosen, so applying it stale is wrong)
    3. otherwise                  → the suggestion shown to the operator
```

`PricingReviewService::resolve()` uses these for `approve_suggested` only.
`keep_current`, `custom_price` and `reject` are byte-for-byte unchanged.

**Flag lifecycle** — set when the operator prices the row, cleared when they ask the
engine to re-derive, because that request supersedes the earlier figure:

| Event | `selling_price_overridden` | `sale_price_overridden` |
|---|---|---|
| `inline` edits `regular_price` | **true** | unchanged |
| `inline` edits `sale_price` (incl. clearing to null) | unchanged | **true** |
| `inline` sets `target_margin` / `markup` | false | false |
| `inline` reverts to `brand_policy` | false | false |
| `bulk-policy` brand policy / target margin / markup | false | false |
| `upsertForProduct()` — a new cost movement re-opens the review | false | false |

## 28.4 Source used by Approve

| Field | Value |
|---|---|
| Current price | `pricing_reviews.selling_price` (regular) · `products.sale_price` (sale) |
| Suggested price | `pricing_reviews.suggested_selling_price` / `suggested_sale_price` |
| Manual / edit price | the same `selling_price` / `products.sale_price`, marked by the override flags |
| **Final decision price** | `PricingReview::finalSellingPrice()` / `finalSalePrice()` |
| **Exact source consumed by Approve** | `resolve()` → `match('approve_suggested') => $review->finalSellingPrice()`, and `finalSalePrice(...)` for the sale price |

Worked example, matching the reported scenario:

```
current 7044 · suggested 7011.1111 · operator edits regular → 6900
Approve ⇒ products.regular_price       = 6900.00      (NOT 7011.11)
          pricing_reviews.approved_price = 6900.0000
          status = approved · price_approvals.new_selling_price = 6900

current sale 6600 · suggested sale 6310 · operator edits sale → 6500
Approve ⇒ products.sale_price          = 6500.00      (NOT 6310)
```

## 28.5 Tests proving both paths

`tests/Feature/CostManagement/PriceReviewActionHttpTest.php` — real HTTP → Laravel → real DB.

| # | Test | Proves |
|---|---|---|
| 1 | `approve_applies_the_suggested_price_when_no_manual_edit_was_made` | **PATH A** — no edit → `regular_price = 7011.11`, `approved_price = 7011.1111` |
| 2 | `approve_applies_the_manually_edited_regular_price` | **PATH B** — edit 6900 → `regular_price = 6900`, plus an explicit `assertNotEqualsWithDelta` that it is **not** the suggested price |
| 3 | `approve_applies_the_manually_edited_sale_price` | edit sale 6500 → `sale_price = 6500`, not 6310; untouched regular still follows the suggestion |
| 4 | `approve_applies_both_manually_edited_prices` | both edited → 6900 / 6500 applied, and both mirrored to `approved_price` / `approved_sale_price` |
| 5 | `edited_price_then_approve_closes_the_review_and_audits_the_effective_price` | product = 6900 · review resolved · **removed from Pending** · Pending KPI 1 → 0 · `price_approvals.new_selling_price = 6900` |
| 6 | `setting_a_target_margin_clears_an_earlier_manual_price_decision` | re-deriving supersedes the manual figure — flag cleared, Approve follows the recomputed suggestion |

Test 6 was added beyond the brief: "when does the override stop applying" is the part
most likely to regress silently.

### Result

```
OK (31 tests, 159 assertions)
```

**31/31 PASS.** Runner idle gate 6/6 confirmed at 20:04:35; database target `ecos_dev_test`.

Cost Management regression: `Tests: 40, Assertions: 188, Failures: 5` — 40 = 31 + 9
cascade, and all 5 failures are the **P7 cascade failures already proven PRE-EXISTING**
by the pristine-HEAD control. **No new regression.**

## 28.6 No pricing regression

Unchanged: cost calculation · markup · target margin · brand margin · suggested-price
calculation · discount calculation · pricing-strategy calculation. Only the *selection*
of which already-computed price Approve applies changed, and only for
`approve_suggested`.

## 28.7 Static quality

| Check | Result |
|---|---|
| Pint | ✅ PASS — 53 files |
| PHPStan L0 | ✅ No errors |
| PHPStan core L6 | ✅ No errors |
| TypeScript (`-p tsconfig.app.json`) | ✅ 24 before → **24 after**, 0 in cost-management |
| Vite build | ✅ built; bundle redeployed and hash-verified (`e032690da0e4`) |

## 28.8 Files changed

| File | Change |
|---|---|
| `…/Migrations/2026_08_13_140000_add_manual_price_override_flags_to_pricing_reviews.php` | **NEW** — two boolean flags, reversible |
| `…/Domain/Models/PricingReview.php` | fillable + casts; **`finalSellingPrice()` / `finalSalePrice()`** canonical resolver |
| `…/Domain/Services/PricingReviewService.php` | `approve_suggested` consumes the resolver; `upsertForProduct()` clears the flags |
| `…/Presentation/Http/Controllers/PricingReviewController.php` | `inline()` sets the flags; margin / markup / brand-policy and `bulkPolicy` clear them |
| `…/Presentation/Http/Resources/PricingReviewResource.php` | exposes both flags |
| `frontend/…/types/pricing-review.ts` | the two flags on the `PricingReview` type |
| `backend/tests/…/PriceReviewActionHttpTest.php` | +6 cases (25 → 31) |

**No frontend behavioural change was required** — the editor already sent and persisted
the operator's value; the backend was ignoring it, and that is where it was fixed.

`ecos_dev` migrated narrowly by `--path` (batch 104). The other session's
`2026_08_13_100000_supersede_order_lifecycle_v3_canonical` remains **Pending** — not
deployed by this work. `FG-000001` untouched.

## 28.9 Certification status

```
PATH A — Approve without manual edit → suggested price applied   = PASS
PATH B — Approve after manual edit   → edited price applied      = PASS
Approval recorded · review closed · Pending row removed · KPI updated = PASS
No frontend-only hiding · tenant isolation preserved             = PASS
Automated suite 31/31 · regression no-new-failures · static      = PASS
─────────────────────────────────────────────────────────────────────
REAL E2E = PENDING USER BROWSER SMOKE
PRICE REVIEW = NOT CERTIFIED
```

Both contract paths are now proven at runtime. The **only** remaining gate is the
authenticated browser click-through, which this session cannot perform — credentials
are never entered on the user's behalf.

### Two assumptions recorded

The manual-override representation did not exist in the model, and the design question
was put to the user but not answered before work had to proceed. Both choices are
documented here and are cheap to reverse:

1. **A flag, not a price field** — the alternative was reusing `suggested_*` to hold the
   operator's figure (no migration), at the cost of the "Suggested" column displaying the
   operator's number instead of the engine's recommendation.
2. **Terminal status stays `approved`** after an edited-price Approve, preserving the
   Approve / Custom Price boundary the correction explicitly required.
