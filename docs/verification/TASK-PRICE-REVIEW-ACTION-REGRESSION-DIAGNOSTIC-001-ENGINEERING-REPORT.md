# TASK-PRICE-REVIEW-ACTION-REGRESSION-DIAGNOSTIC-001 — ENGINEERING REPORT

**Type:** Diagnostic only — no production code, no data, no schema changed
**Date:** 2026-08-13
**Branch:** `develop` @ `6149875b`
**Surface:** Inventory → Price Review → Pricing Decision Center
**Environment inspected:** `ecos-dev` stack (`ecos-dev-app`, `ecos-dev-mysql`, DB `ecos_dev`) — **read-only** (SELECT, `route:list`, log reads). No PATCH/PUT/POST/DELETE issued. No rows written.

**CERTIFICATION STATUS: `ROOT CAUSE PROVEN — FIX READY`**
with one **CONTRACT DECISION REQUIRED** (see §19.1 and §23) before implementation begins.

---

## 1. Executive Summary

Clicking **Approve** (and **Price Kept**, **Custom Price**, and **Reject**) on a Price Review row sends a correct request to a correct route with correct authorization — and the backend then throws a **PHP `TypeError` and returns HTTP 500 before any mutation runs**. The frontend registers no `onError` handler on any of these mutations, and the shared axios instance has no global error interceptor for non-401 responses, so the 500 is **completely invisible**. The row stays because nothing ever changed.

**The proven failure point is one line:**

`PricingReviewController::approve()` ([PricingReviewController.php:139](backend/Modules/CostManagement/Presentation/Http/Controllers/PricingReviewController.php:139)) passes `$request->user()?->id` into
`PricingReviewService::resolve(..., ?string $approverId)` ([PricingReviewService.php:222](backend/Modules/CostManagement/Domain/Services/PricingReviewService.php:222)).

`users.id` is **`bigint unsigned`** (the ECOS User PK was deliberately kept as bigint — ADR-040). The service file declares `strict_types=1`, so `int` → `?string` is a hard `TypeError`, not a coercion.

### Runtime proof (from `ecos_dev`)

```
storage/logs/laravel-2026-08-12.log — 7 occurrences, userId 1:

production.ERROR: Modules\CostManagement\Domain\Services\PricingReviewService::resolve():
Argument #7 ($approverId) must be of type ?string, int given, called in
.../PricingReviewController.php on line 130
#0 ...PricingReviewController.php(130): ...->resolve(Object(PricingReview),
   'approve_suggest...', NULL, NULL, NULL, Array, 1)
```

Corroborating state:

| Evidence | Value | Meaning |
|---|---|---|
| `SELECT COUNT(*) FROM price_approvals` | **0** | No approval has **ever** succeeded on this database |
| `pricing_reviews` rows | 1 | `status = pending`, `resolved_at = NULL`, `publish_status = NULL` |
| Approve attempts in log | 7 | All 7 died with the same `TypeError` |

The failure is **100% backend**. It is **not** a cache/refetch problem, **not** a route collision, **not** a permission problem, and **not** a stale-query problem. Those were each tested and cleared (§7–§9, §13, §14).

### Classification

| Item | Verdict |
|---|---|
| Primary | **D — Backend mutation defect** (fatal `TypeError` before mutation) |
| Secondary | **A — Regression**, introduced by commit `26341937` |
| Contributing | Silent-failure UI: no `onError` on any Price Review mutation |
| Contributing | Dead optimistic-removal cache write (wrong query key) |
| Also found (pre-existing, not the cause) | **P0 tenant isolation gap** — no company scoping anywhere in this controller (§10) |
| Also found | `price_approvals.approved_by` is `char(36)` vs `users.id` `bigint` — the fix must resolve this too (§15) |

### Which actions are broken

| Row action | Endpoint | Broken? |
|---|---|---|
| **Approve** | `POST .../{id}/approve` (`approve_suggested`) | ❌ **500 TypeError** |
| **Price Kept** | `POST .../{id}/approve` (`keep_current`) | ❌ **500 TypeError** |
| **Custom Price** | `POST .../{id}/approve` (`custom_price`) | ❌ **500 TypeError** |
| **Reject** | `POST .../{id}/approve` (`reject`) | ❌ **500 TypeError** |
| Bulk Approve / Keep / Reject | `POST .../bulk-approve` | ❌ **500 TypeError** (same call, [line 320](backend/Modules/CostManagement/Presentation/Http/Controllers/PricingReviewController.php:320)) |
| **Snooze** | `POST .../{id}/snooze` | ✅ Backend correct — does not pass `approverId` |
| Assign Reviewer | `POST .../{id}/assign` | ✅ Backend correct |
| Inline edits, policy toggle | `PATCH .../{id}/inline` | ✅ Backend correct |
| Publish | `POST .../{id}/publish` | ✅ Backend correct |

The user reported "the same issue occurs with the other row action buttons as well". That is **accurate for Approve / Price Kept / Custom Price / Reject** — four of the five visible row actions share the single broken call path. **Snooze is a different endpoint and is not affected by this defect**; if Snooze also appears to fail in practice it is a separate observation and needs its own capture (§5.1).

---

## 2. Previous Contract

Recovered from source, ADR, and commit history. Nothing was invented.

**Origin:** `30655b91` — `feat(cost-management): TASK-ARCH-PRICE-001 — Unified Cost & Pricing Architecture` (2026-07-02). Memory index: `task_arch_price_001.md` — *unified cost & pricing architecture — ACTIVE*.

Contract as encoded in [PricingReviewService::resolve()](backend/Modules/CostManagement/Domain/Services/PricingReviewService.php:215):

1. **Selling price is never changed automatically.** A cost change only ever creates a `pricing_reviews` row. Management resolves each one explicitly. (Stated verbatim in the [PricingReview model docblock](backend/Modules/CostManagement/Domain/Models/PricingReview.php:15).)
2. **Four canonical resolution actions**, validated by [ApprovePricingReviewRequest](backend/Modules/CostManagement/Presentation/Http/Requests/ApprovePricingReviewRequest.php:15):
   `approve_suggested` · `keep_current` · `custom_price` · `reject`
3. **Price application** (`$action !== 'reject'`) is governed by the brand's `publishing_strategy` pricing policy:
   - `automatic` → write `products.regular_price` + `products.sale_price` immediately, flag every `ProductMapping` as `sync_status = pending`, set `publish_status = 'published'`.
   - `approval_only` → **stage** into `pricing_reviews.approved_price` / `.approved_sale_price`, set `publish_status = 'pending_publish'`, and require a **separate explicit Publish** call.
4. **Audit is mandatory and immutable** — one `price_approvals` row per resolution, with before/after cost, before/after regular price, before/after sale price, margin %, discount %, action, custom price, reason, manager name, approver id, approved channels, and timestamp. (`PriceApproval` has `$timestamps = false` and is written once.)
5. **Review closes** — `PricingReview::resolve(status)` sets the terminal status and stamps `resolved_at`.
6. **Terminal statuses leave the Pending queue** — `PricingReviewStatus::isResolved()` is true for `approved`, `kept`, `custom_price`, `rejected`; false for `pending` and `snoozed`.
7. **Domain events fire** — `PriceReviewApproved` for the three non-reject actions, `PriceReviewRejected` for reject.
8. **Idempotency guard** — a second resolution attempt on an already-resolved review returns `422 "This review has already been resolved."`

The user's stated requirement — *approved product disappears from Pending immediately after successful approval* — is satisfied by (5)+(6)+ the Pending query (§13), because the list endpoint filters on `status`. **The design does this by changing canonical backend state, not by hiding the row.** No client-side hiding exists in the intended path.

---

## 3. Current Symptom

Screen: **Pricing Decision Center**, `Pending = 1`, one product at `Status = Pending` with row actions on the right.
Route: `/inventory/cost-management/price-review` → `CostPricingCenterPage` ([router.ts:455](frontend/src/router/router.ts:455), [routes.ts:51](frontend/src/router/routes.ts:51)).

Observed: clicking **Approve** does not remove the product. No toast, no error, no visible change.

Confirmed in data — the exact row on screen:

```
id:                      019faefb-befa-735b-9fa6-0bd5dc10f0b7
product_id:              019faef5-af41-7321-9f6b-546045947ace  (SKU FG-000001, not soft-deleted)
company_id:              019faeca-76d0-7366-8f2b-4db815cfa691
status:                  pending          resolved_at:    NULL
publish_status:          NULL             approved_price: NULL
selling_price:           7044.0000
suggested_selling_price: 7011.1111
suggested_sale_price:    6310.0000
created_at: 2026-07-29 20:46:01   updated_at: 2026-07-29 20:48:35
```

`updated_at` is 2026-07-29 while approve attempts occurred 2026-08-12 — **the row has not been touched by any approve attempt.** This alone rules out "backend mutated but the frontend is stale".

---

## 4. Approve Trace

Complete path, end to end, answering all 18 questions from Part 4.

| # | Step | Location | Result |
|---|---|---|---|
| 1 | **Does the button fire?** | [cost-pricing-center-page.tsx:1520-1528](frontend/src/features/cost-management/pages/cost-pricing-center-page.tsx:1520) | ✅ Yes. Icon `Button`, `onClick={() => handleApprove(review)}`, rendered when `status === 'pending' \|\| status === 'snoozed'`, disabled only while `approveReview.isPending`. |
| 2 | **What handler is attached?** | [`handleApprove`, line 945](frontend/src/features/cost-management/pages/cost-pricing-center-page.tsx:945) | ✅ Calls `approveReview.mutate({ id, payload })`. |
| 3 | **What payload is sent?** | line 947 | ✅ `{ action: 'approve_suggested' }`. No `custom_price`, `reason`, `manager_name`, or `channels` — all optional under validation. |
| 4 | **Which endpoint?** | [pricing-review-service.ts:71](frontend/src/features/cost-management/services/pricing-review-service.ts:71) | ✅ `POST /cost-management/pricing-reviews/{id}/approve`. |
| 5 | **Does the route exist?** | `php artisan route:list --path=pricing-reviews` in `ecos-dev-app` | ✅ Yes, live and uncached (§8). |
| 6 | **Route collision?** | §8 | ✅ None. `/{id}/approve` is 3 segments, `/bulk-approve` is 2 — cannot shadow. No `apiResource` registered on this prefix. |
| 7 | **Does authorization pass?** | §9 | ✅ Yes. `permission:inventory.price_review.approve`; the acting user (id 1, `admin@ecos.local`) holds `super-admin` with `is_system = 1` → unconditional gateway allow. **Log line carries `"userId":1`, which proves the middleware was passed and the controller was entered.** |
| 8 | **Does validation pass?** | [ApprovePricingReviewRequest:15](backend/Modules/CostManagement/Presentation/Http/Requests/ApprovePricingReviewRequest.php:15) | ✅ Yes. The stack trace shows `ApprovePricingReviewRequest` fully resolved and injected — a validation failure would have short-circuited to 422 before the controller body. |
| 9 | **Does the controller execute?** | [PricingReviewController:122](backend/Modules/CostManagement/Presentation/Http/Controllers/PricingReviewController.php:122) | ✅ Partially. `findOrFail($id)` succeeded, `isResolved()` returned false, then `resolve(...)` was invoked. |
| 10 | **Which service performs approval?** | [PricingReviewService::resolve()](backend/Modules/CostManagement/Domain/Services/PricingReviewService.php:215) | ❌ **Never entered.** The `TypeError` is raised at the call boundary — argument type checking happens *before* the function body runs. |
| 11 | **Does it update product price?** | — | ❌ No. Unreachable. |
| 12 | **Regular Price updated?** | — | ❌ No. |
| 13 | **Sale Price updated?** | — | ❌ No. |
| 14 | **Does it close the review item?** | — | ❌ No. `resolved_at` still `NULL`, `status` still `pending`. |
| 15 | **Does the pending query exclude it after?** | — | ❌ No — correctly so. The state never changed, so the query correctly still returns it. |
| 16 | **Does React invalidate the right key?** | §14 | ⚠️ **Two mechanisms; one works, one is dead.** `useApproveReview.onSuccess` invalidates the **correct** key. `removeFromCurrentList` writes to a **wrong, non-existent** key. Neither ever runs here — `onSuccess` does not fire on a 500. |
| 17 | **Still present because backend did not mutate?** | — | ✅ **YES. This is the answer.** |
| 18 | **Or mutated but frontend cache stale?** | — | ❌ **No.** Disproven by `price_approvals` = 0 rows and by `pricing_reviews.updated_at` predating every attempt. |

### The exact failing line

```php
// backend/Modules/CostManagement/Presentation/Http/Controllers/PricingReviewController.php:130-140
$approval = $this->service->resolve(
    review:       $review,
    action:       $request->validated('action'),
    customPrice:  ...,
    reason:       $request->validated('reason'),
    managerName:  $request->validated('manager_name'),
    channels:     (array) ($request->validated('channels') ?? []),
    approverId:   $request->user()?->id,   // ← int (bigint PK), NOT string
);
```

```php
// backend/Modules/CostManagement/Domain/Services/PricingReviewService.php:215-223
declare(strict_types=1);          // ← file header; makes this fatal, not coercive
...
public function resolve(
    PricingReview $review,
    string $action,
    ?float $customPrice,
    ?string $reason,
    ?string $managerName,
    array $channels,
    ?string $approverId = null,   // ← declared ?string
): PriceApproval {
```

```sql
mysql> SHOW COLUMNS FROM users LIKE 'id';
Field  Type              Null  Key  Extra
id     bigint unsigned   NO    PRI  auto_increment
```

Under `strict_types=1`, PHP performs **no** int→string widening for a `?string` parameter. `TypeError` → uncaught → Laravel exception handler → **HTTP 500**.

### Why the UI shows nothing

```tsx
// cost-pricing-center-page.tsx:945-955 — no onError branch exists
approveReview.mutate(
  { id: review.id, payload: { action: 'approve_suggested' } },
  { onSuccess: () => { toast.success(...); removeFromCurrentList(review.id); } },
  //  ↑ only onSuccess. A 500 rejects the promise, onSuccess never runs, nothing is shown.
);
```

And [lib/axios.ts:35-44](frontend/src/lib/axios.ts:35) only intercepts **401** (clear token + logout). Every other status is re-thrown untouched with no global toast. Net effect: **a fatal server error is indistinguishable from a dead button.**

---

## 5. Other Actions Trace

Every visible row action, traced independently. They do **not** all use the same endpoint.

| Action (UI label) | Canonical source name | Endpoint | Method | Permission | Mutation | Expected final state | Leaves Pending? | FE invalidation | Verdict |
|---|---|---|---|---|---|---|---|---|---|
| **Approve** (✔ icon) | `approve_suggested` | `/pricing-reviews/{id}/approve` | POST | `inventory.price_review.approve` | price ← `suggested_selling_price`; sale ← `suggested_sale_price`; `+price_approvals` | `status = approved`, `resolved_at` set | ✅ yes | ✅ correct key | ❌ **500 TypeError** |
| **Price Kept** (− icon) | `keep_current` | same | POST | same | price unchanged; sale recomputed from live discount; `+price_approvals` | `status = kept` | ✅ yes | ✅ correct key | ❌ **500 TypeError** |
| **Custom Price** (⋯ menu) | `custom_price` | same | POST | same | price ← operator value; sale ← price × (1 − discount%); `+price_approvals` | `status = custom_price` | ✅ yes | ✅ correct key | ❌ **500 TypeError** |
| **Reject** (✕ icon) | `reject` | same | POST | same | **no price change** (contract); `+price_approvals` only | `status = rejected` | ✅ yes | ✅ correct key | ❌ **500 TypeError** |
| **Snooze** (🕐 icon) | `snooze` | `/pricing-reviews/{id}/snooze` | POST | `inventory.price_review.update` | `status = snoozed`, `snooze_until = <date>` | `status = snoozed` | ✅ yes (not `pending`) | ✅ correct key | ✅ **backend sound** |
| Assign Reviewer (⋯) | `assign` | `/pricing-reviews/{id}/assign` | POST | `inventory.price_review.update` | `reviewer_name` | unchanged status | ➖ stays (by design) | ✅ correct key | ✅ sound |
| Inline price/margin/markup edits | `inline` | `/pricing-reviews/{id}/inline` | **PATCH** | `inventory.price_review.update` | writes `products` **and** `pricing_reviews` | unchanged status | ➖ stays (by design) | ✅ correct key | ✅ sound |
| Brand-policy ↔ Custom toggle (⋯) | `inline` (`pricing_mode`) | same | PATCH | same | `products.pricing_mode` | unchanged status | ➖ stays | ✅ correct key | ✅ sound |
| Publish (⬆, only when `pending_publish`) | `publish` | `/pricing-reviews/{id}/publish` | POST | `inventory.price_review.publish` | product prices ← staged `approved_*`; mappings → `sync_status=pending`; `publish_status = published` | already resolved | ➖ already gone | ✅ correct key | ✅ sound |
| Bulk Approve / Keep / Reject | `bulkApprove` | `/pricing-reviews/bulk-approve` | POST | `inventory.price_review.approve` | loops `resolve()` per id | per-action | ✅ yes | ✅ correct key | ❌ **500 TypeError** ([line 320](backend/Modules/CostManagement/Presentation/Http/Controllers/PricingReviewController.php:320)) |
| Bulk brand policy / margin / markup / snooze | `bulkPolicy` | `/pricing-reviews/bulk-policy` | POST | `inventory.price_review.approve` | policy + suggestion recompute | unchanged (except snooze) | ➖ mostly stays | ✅ correct key | ✅ sound |

### 5.1 Note on Snooze

Snooze does **not** pass `approverId` and therefore does **not** hit the `TypeError`. Its backend path ([snooze():256](backend/Modules/CostManagement/Presentation/Http/Controllers/PricingReviewController.php:256) → [PricingReviewService::snooze():341](backend/Modules/CostManagement/Domain/Services/PricingReviewService.php:341)) is a clean two-column update, and the hook invalidates the correct key, so the row should leave the `status = pending` list.

However, `handleSnoozeConfirm` ([line 983](frontend/src/features/cost-management/pages/cost-pricing-center-page.tsx:983)) **also has no `onError` handler**, so if it were to fail for any other reason (e.g. the `after:today` validation rejecting a same-day date → 422) the user would see the identical "nothing happened" symptom. Two Snooze-specific traps worth knowing:

- `'until' => ['required','date','after:today']` — a **same-day** date is a silent 422.
- Row actions render for `status === 'pending' || status === 'snoozed'`, so a snoozed row still shows the full action set if the status filter is widened.

No snooze request appears in the dev logs at all, so **no runtime evidence exists that Snooze was actually exercised and failed.** Treat the user's "other buttons too" as confirmed for Approve/Kept/Custom/Reject and **unverified for Snooze** — see §23.

---

## 6. Frontend Mutation

Hooks: [use-pricing-reviews.ts](frontend/src/features/cost-management/hooks/use-pricing-reviews.ts). Service: [pricing-review-service.ts](frontend/src/features/cost-management/services/pricing-review-service.ts).

Structurally sound. Three defects, none of which is the primary cause:

**6.1 — No `onError` on any mutation call site (the reason the bug is invisible).**
`handleApprove`, `handleCustomPriceConfirm`, `handleKeepCurrentConfirm`, `handleReject`, `handleSnoozeConfirm`, `handleAssignConfirm`, `handleBulkApprove`, `handleBulkKeep`, `handleBulkReject`, `handleBulkApplyBrandPolicy`, `handleBulkMarginConfirm`, `handleBulkMarkupConfirm`, `handleBulkSnoozeConfirm`, and the inline `publishReview.mutate` all pass **only** `onSuccess`.
The single exception is `handleInlineSave` ([line 928](frontend/src/features/cost-management/pages/cost-pricing-center-page.tsx:928)), which does have `onError`. That inconsistency is itself the tell: the inline path would have surfaced a failure; the approve path could not.

**6.2 — `removeFromCurrentList` writes to a query key that does not exist.**

```tsx
// cost-pricing-center-page.tsx:889-895
queryClient.setQueryData<PricingReviewsResult>(
  ['pricing-reviews', query],   // ← this cache entry never exists
  (old) => old ? { ...old, data: old.data.filter(r => !idSet.has(r.id)) } : old,
);
```

The list actually lives under an org-scoped key ([use-pricing-reviews.ts:23](frontend/src/features/cost-management/hooks/use-pricing-reviews.ts:23)):

```ts
queryKey: ['company', activeCompanyId ?? 'global', 'pricing-reviews', params]
```

The updater is called with `old === undefined` and returns `undefined` — a no-op that also silently seeds an orphan cache entry. **This is dead code today.** It matters because it is the mechanism the page *appears* to rely on for the "row disappears immediately" behaviour; a fix that repairs only the backend will still work (via invalidation) but the intent expressed here is broken and should be either corrected or deleted.

Note also that `removeFromCurrentList` is exactly the **client-side row hiding the contract forbids**. Correcting it to the right key would technically make rows vanish — but it must not be used as the *fix*, because it would hide rows on failure too. See §20.

**6.3 — Invalidation itself is correct.**
Every mutation hook calls `qc.invalidateQueries({ queryKey: ['company', companyId, 'pricing-reviews'] })`, a prefix that correctly matches `['company', companyId, 'pricing-reviews', params]`. `companyId` is derived identically in the list hook and every mutation hook (`useOrganizationContext().activeCompanyId ?? 'global'`), so there is no scope drift. **Once the backend returns 2xx, the list will refetch and the row will leave Pending with no further frontend change.**

One gap: the nav badge uses a different key root (`['company', scopeId, 'price-review-badge', companyId]`) and is **never invalidated** by any mutation. It self-corrects within its `refetchInterval: 120_000`, so the badge can lag the table by up to two minutes.

---

## 7. API

Base: `/cost-management` — [api.php:791](backend/routes/api.php:791), `Route::middleware('auth:sanctum')->prefix('cost-management')`.

| Method | Path | Controller action | Middleware |
|---|---|---|---|
| GET | `pricing-reviews` | `index` | `auth:sanctum` only |
| GET | `pricing-reviews/badge` | `badge` | `auth:sanctum` only |
| GET | `pricing-reviews/{id}/detail` | `detail` | `auth:sanctum` only |
| POST | `pricing-reviews/{id}/approve` | `approve` | `permission:inventory.price_review.approve` |
| POST | `pricing-reviews/{id}/snooze` | `snooze` | `permission:inventory.price_review.update` |
| POST | `pricing-reviews/{id}/assign` | `assign` | `permission:inventory.price_review.update` |
| POST | `pricing-reviews/{id}/publish` | `publish` | `permission:inventory.price_review.publish` |
| POST | `pricing-reviews/bulk-approve` | `bulkApprove` | `permission:inventory.price_review.approve` |
| PATCH | `pricing-reviews/{id}/inline` | `inline` | `permission:inventory.price_review.update` |
| POST | `pricing-reviews/bulk-policy` | `bulkPolicy` | `permission:inventory.price_review.approve` |

Frontend↔backend method and path agreement verified line-by-line against `pricing-review-service.ts` — **all ten match exactly**, including the `PATCH` on `/inline` (the one non-POST mutation).

Note: the three **GET** endpoints carry **no permission middleware** at all — `inventory.price_review.view` is defined and granted but never enforced. Any authenticated user can read the full pricing queue, including costs and margins. Out of scope for this defect; recorded for the security backlog.

---

## 8. Route Table

Live table from the running dev container — not from source:

```
$ docker exec ecos-dev-app php artisan route:list --path=pricing-reviews

GET|HEAD   api/cost-management/pricing-reviews
GET|HEAD   api/cost-management/pricing-reviews/badge
POST       api/cost-management/pricing-reviews/bulk-approve
POST       api/cost-management/pricing-reviews/bulk-policy
POST       api/cost-management/pricing-reviews/{id}/approve
POST       api/cost-management/pricing-reviews/{id}/assign
GET|HEAD   api/cost-management/pricing-reviews/{id}/detail
PATCH      api/cost-management/pricing-reviews/{id}/inline
POST       api/cost-management/pricing-reviews/{id}/publish
POST       api/cost-management/pricing-reviews/{id}/snooze

Showing [10] routes
```

**No route collision. Proven, not assumed.** Part 9 explicitly asked whether the previous Inventory-toggle failure mode (a generic resource route registered before a dedicated PATCH route) recurs here. It does not:

- There is **no** `Route::apiResource` / `Route::resource` on this prefix — every route is individually declared.
- `pricing-reviews/{id}/approve` (3 segments) and `pricing-reviews/bulk-approve` (2 segments) can never match the same URI, so their registration order is irrelevant.
- `pricing-reviews/badge` (GET, 2 segments) has no `pricing-reviews/{id}` GET sibling to be shadowed by.
- The `PATCH .../inline` route is the sole PATCH on the prefix; nothing precedes it that could absorb the method.

**Route cache is not stale** — `bootstrap/cache/` in `ecos-dev-app` contains `config.php`, `events.php`, `packages.php`, `services.php` and **no `routes-v7.php`**, so routes are resolved live from `api.php` on every request. (This clears the known ECOS stale-route-cache failure mode.)

---

## 9. Authorization

Middleware alias `permission` → [RequirePermissionMiddleware](backend/Modules/IAM/Infrastructure/Middleware/RequirePermissionMiddleware.php), registered at [IamServiceProvider.php:86](backend/Modules/IAM/Infrastructure/Providers/IamServiceProvider.php:86). It delegates to `AuthorizationGateway::decision()` (ADR-038), which applies the **system-role bypass** before any permission lookup: any role with `is_system = true` is allowed unconditionally.

**Authorization passes. Three independent proofs:**

1. **The permission exists in the database:**
   ```
   inventory.price_review.approve
   inventory.price_review.publish
   inventory.price_review.update
   inventory.price_review.view
   ```
2. **The acting user bypasses it anyway.** `admin@ecos.local` (id 1) → role `super-admin`, `is_system = 1`.
3. **The stack trace is the definitive proof.** A 403 aborts *inside* the middleware; the logged trace shows execution reaching `PricingReviewController->approve()` and then `PricingReviewService->resolve()`. The middleware was passed.

Grants are otherwise well-formed — `inventory.price_review.approve` is held by `company-admin`, `inventory-controller`, `tpl-inventory-controller`, `tpl-operations-director`, `tpl-production-director`, `tpl-warehouse-director`, `tpl-warehouse-manager`, `tpl-ceo`, `tpl-cfo`, `tpl-coo`, `tpl-cto`.

**One catalog inconsistency worth recording (not a cause):** two parallel permission namespaces exist for the same capability.
- Routes and `config/permissions.php` use **`inventory.price_review.*`** with four actions (`view`, `update`, `approve`, `publish`).
- The IAM enterprise matrix migration ([2026_12_20_000000_seed_enterprise_permission_matrix.php:134](backend/Modules/IAM/Infrastructure/Database/Migrations/2026_12_20_000000_seed_enterprise_permission_matrix.php:134)) seeds **`cost.price_review.*`** with only two (`view`, `update`).

Both sets exist in the DB. `cost.price_review.*` is enforced **nowhere** — it is dead surface granted to `tpl-ceo/cfo/coo/cto`, `company-admin`, and `viewer`. Executives whose grants came *only* from the `cost.*` namespace would hold no approve right; in practice they also hold `inventory.price_review.approve`, so nothing is currently broken. Record for the IAM backlog.

---

## 10. Tenant Isolation — **P0 FINDING**

**Tenant isolation cannot be demonstrated, because there is none.** This is a source-level fact, proven without executing any cross-tenant mutation.

`PricingReview` ([model](backend/Modules/CostManagement/Domain/Models/PricingReview.php)) has a `company_id` column and a `company()` relation, but **no global scope, no `OwnsCompany` implementation, and no company trait**. Every controller method locates records with an unscoped query:

| Method | Lookup | Company filter |
|---|---|---|
| `index` | `PricingReview::query()->latest()` + status/product/brand/search filters | ❌ **none** |
| `index` summary counts | `PricingReview::query()->where('status', …)->count()` × 7 | ❌ **none — counts are global across all tenants** |
| `detail` | `findOrFail($id)` | ❌ none |
| `approve` | `findOrFail($id)` | ❌ none |
| `snooze` | `findOrFail($id)` | ❌ none |
| `assign` | `findOrFail($id)` | ❌ none |
| `inline` | `findOrFail($id)` → then writes `products.*` | ❌ none |
| `publish` | `findOrFail($id)` → then writes `products.*` | ❌ none |
| `bulkApprove` | `whereIn('id', $ids)` | ❌ none |
| `bulkPolicy` | `whereIn('id', $ids)` | ❌ none |
| `badge` | `where('status','pending')` | ⚠️ filters **only** if the caller volunteers `?company_id=` — client-controlled, not enforced |

**Answer to "Could a user approve a product belonging to another company?" — Yes, and it is happening right now on the read path.**

Proof, from read-only SELECTs, without crossing any tenant boundary myself:

```
pricing_reviews.company_id  = 019faeca-76d0-7366-8f2b-4db815cfa691
users.company_id (admin, id 1) = 019f4e1c-2d1e-719d-873c-75779ab67251
```

The review currently rendered on the operator's screen **belongs to a different company than the operator**. The row is visible purely because `index()` applies no company predicate. The identical absence of a predicate in `approve()` means the mutation would have been accepted cross-tenant had it not died on the `TypeError` first.

The frontend keys its cache by `activeCompanyId`, which creates a **false impression of scoping**: the cache is partitioned per company while the data behind it is not, so switching companies yields a fresh fetch of the same global list.

This is consistent with the two P0 multi-company scoping defects already recorded in `uat_campaign1_platform_init.md` and is invisible on a single-company demo.

**No cross-tenant mutation was executed. This finding is source- and read-only-data-derived.** It is **not** the cause of the reported symptom and must be scoped as its own security task (§22).

---

## 11. Pricing Mutation

Exactly what **Approve** persists, per [resolve()](backend/Modules/CostManagement/Domain/Services/PricingReviewService.php:215). No formula was changed, invented, or re-derived.

### 11.1 Price selection

```php
$newPrice = match ($action) {
    'approve_suggested' => $review->suggested_selling_price,
    'keep_current'      => $review->selling_price,
    'custom_price'      => $customPrice ?? $review->selling_price,
    'reject'            => $review->selling_price,
};
```

### 11.2 Sale price derivation

```php
$discountPct = $product->effectiveDiscountPct();   // product override, else brand default

$newSalePrice = $action === 'approve_suggested'
    ? ($review->suggested_sale_price ?? round($newPrice * (1 - $discountPct/100), 4))  // honour what the operator saw
    : round($newPrice * (1 - $discountPct/100), 4);                                     // recompute live
```

`approve_suggested` deliberately reuses the **stored** `suggested_sale_price` — the number displayed in the "Suggested Sale" column — so the operator gets what was on screen. `keep_current` and `custom_price` recompute from the live discount. This asymmetry is intentional and must be preserved.

### 11.3 Which values are persisted

| Value | Where written on Approve | Under `automatic` | Under `approval_only` |
|---|---|---|---|
| Regular Price | `products.regular_price` | ✅ immediately | ⛔ staged in `pricing_reviews.approved_price` |
| Sale Price | `products.sale_price` (`NULL` if ≤ 0) | ✅ immediately | ⛔ staged in `pricing_reviews.approved_sale_price` |
| Suggested Regular | read-only source | — | — |
| Suggested Sale | read-only source | — | — |
| Cost | **never written here** — read-only input | — | — |
| Final Margin | **not persisted** — derived in `PricingReviewResource` | — | — |
| Brand Margin | **not written** — read via `effectiveTargetMargin()` | — | — |
| Discount | **not written** — read via `effectiveDiscountPct()` | — | — |
| Markup | **not persisted** — derived in the resource: `margin / (100 − margin) × 100` | — | — |
| `margin_pct` (audit) | `price_approvals.margin_pct` = `(newPrice − product_cost) / newPrice × 100` | ✅ | ✅ |
| `publish_status` | `pricing_reviews.publish_status` | `'published'` | `'pending_publish'` |
| Channel sync | `product_mappings.sync_status = pending` | ✅ | ⛔ deferred to Publish |

`reject` writes **no** price and **no** `publish_status` — audit row only. This is the existing contract.

### 11.4 Custom price uses the approved contract — verified

`custom_price` flows through the identical `resolve()` body: same discount source, same publishing-strategy gate, same audit row, same margin formula. It additionally records the raw operator input in `price_approvals.custom_price` (populated only for this action). **It does not bypass the pricing contract.** Its only extra requirement is client-side: the Custom Price dialog requires a non-empty reason before enabling Confirm ([line 453](frontend/src/features/cost-management/pages/cost-pricing-center-page.tsx:453)) — a UI-only rule; `ApprovePricingReviewRequest` leaves `reason` nullable.

### 11.5 ⚠️ Publishing strategy for the affected row — decision required

```
config_brand_policies — brand 019faecb-8420-72ce-ac40-cf4d9e1b9ee6, group 'pricing':
{
  "publishing_strategy": "approval_only",      ← ★
  "discount_type": "percentage", "discount_value": 10,
  "minimum_margin_pct": 55, "auto_price_review": true,
  "required_approval_above": 0, "pending_review_threshold": 5,
  "price_expiration_days": null
}
```

**This brand is `approval_only`.** Therefore, once the `TypeError` is fixed, clicking **Approve** on this exact row will:

- ✅ create the `price_approvals` audit row
- ✅ set `status = approved`, stamp `resolved_at`
- ✅ **remove the row from the Pending queue** (the user's stated requirement)
- ⛔ **NOT** write `products.regular_price` or `products.sale_price`
- ⛔ **NOT** flag channel mappings for sync
- ➡️ instead stage `approved_price = 7011.1111`, `approved_sale_price = 6310.0000`, `publish_status = 'pending_publish'`, requiring a **second, separate Publish action**

Sanity-checked against the live data: cost 3155 → suggested 7011.1111 = 3155 / (1 − 0.55), i.e. exactly the brand's `minimum_margin_pct` of 55%; 7011.1111 × (1 − 0.10) = 6310.0000 = the stored `suggested_sale_price`. The suggestion engine is internally consistent.

**This is a genuine divergence from the contract as stated in the task brief** ("APPROVE must … Apply the approved Regular Price … Apply the approved Sale Price … Publish/store the resulting product prices"). Under `approval_only` that is a **two-step** operation by existing design. This triggers **Stop Condition 4** and is carried to §23 as a decision, not resolved unilaterally.

There is also a **workflow trap** in the two-step flow: once approved, the row leaves the `status = pending` list, but the ⬆ Publish button only renders on a visible row. The operator must widen the status filter to find their own staged approval and publish it. Worth addressing in the implementation task.

---

## 12. Price Review State

- **State column:** `pricing_reviews.status`, cast to `PricingReviewStatus` ([model:91](backend/Modules/CostManagement/Domain/Models/PricingReview.php:91)).
- **Values:** `pending` · `approved` · `kept` · `custom_price` · `snoozed` · `rejected`.
- **Terminal set:** `isResolved()` → `approved`, `kept`, `custom_price`, `rejected`. `pending` and `snoozed` are **open**.
- **Closure mutation:** `PricingReview::resolve(status)` ([model:118](backend/Modules/CostManagement/Domain/Models/PricingReview.php:118)) sets `status` + `resolved_at = now()` and saves. Called once, at [PricingReviewService.php:311](backend/Modules/CostManagement/Domain/Services/PricingReviewService.php:311).
- **Independent axis:** `publish_status` (`NULL` | `pending_publish` | `published`) tracks catalog propagation. It is **orthogonal** to `status` — a review can be `approved` + `pending_publish`.
- **Re-open semantics:** `upsertForProduct()` ([line 82](backend/Modules/CostManagement/Domain/Services/PricingReviewService.php:82)) reuses an existing `pending`/`snoozed` row for the same `product_id + company_id + channel_id IS NULL` under `lockForUpdate()`, forcing it back to `pending` and clearing `snooze_until`. A resolved review is never reopened — a new cost change creates a new row.

**Current DB state: `status = pending`, `resolved_at = NULL`, `publish_status = NULL`, `approved_price = NULL`.** No approve attempt ever advanced this state machine.

---

## 13. Pending Query

**Which endpoint supplies the row:** `GET /cost-management/pricing-reviews` → [`index()`](backend/Modules/CostManagement/Presentation/Http/Controllers/PricingReviewController.php:27).

**What defines Pending:**

```php
$status = $request->query('status', 'pending');       // default
if ($status && $status !== 'all') {
    $query->where('status', $status);                 // ← the whole filter
}
```

The page opens with `useState<PricingReviewsQuery>({ status: 'pending', page: 1, per_page: 25 })` ([line 866](frontend/src/features/cost-management/pages/cost-pricing-center-page.tsx:866)), so the default request is `?status=pending&page=1&per_page=25`.

**Which field represents review state:** `pricing_reviews.status` (§12).

**What mutation should change it:** `PricingReview::resolve(PricingReviewStatus)` via `PricingReviewService::resolve()`.

**Does the list actually filter on the changed field?** ✅ **Yes — same column, no indirection.** `resolve()` writes `status`; `index()` filters `status`. Once `status` becomes `approved`, the row is excluded from `?status=pending` on the very next fetch.

**Could it remain because the list query is stale?** ❌ No. The mutation hook invalidates the exact prefix the list query uses (§14), and the list is an active observer, so invalidation triggers an immediate refetch.

**Could it remain because the mutation changes a different field?** ❌ No. Verified by reading `resolve()` — it changes `status` and nothing else state-wise. And in this incident, **no field changed at all** (`updated_at` unmoved since 2026-07-29).

**Conclusion: pending-queue semantics are unambiguous and correct.** `successful approval → status leaves 'pending' → row leaves the list`. Stop Condition 9 does not apply.

Two side notes on `index()`:
- The `summary` counts (which drive the `Pending = 1` KPI card) are **separate unfiltered `count()` queries**, not derived from the paginated set — and are **not company-scoped** (§10). The KPI and the table can therefore disagree in a multi-company database.
- `below_brand_margin` is hardcoded `0` and recomputed client-side from the current page only, so it reflects the visible page rather than the queue.

---

## 14. Cache / Refetch

No Zustand, no local list state — the table renders straight off React Query (`data?.data ?? []`), then applies a purely client-side `impactFilter`.

**Query keys (exact):**

| Purpose | Key | Where |
|---|---|---|
| List (table + KPI summary) | `['company', activeCompanyId ?? 'global', 'pricing-reviews', params]` | [use-pricing-reviews.ts:23](frontend/src/features/cost-management/hooks/use-pricing-reviews.ts:23) |
| Detail (drawer) | `['company', …, 'pricing-reviews', 'detail', id]` | line 33 |
| Nav badge | `['company', …, 'price-review-badge', companyId]` | line 120 |

**Invalidation on every mutation** (`useApproveReview`, `useSnoozeReview`, `useAssignReview`, `useBulkApprove`, `useInlineUpdateReview`, `useBulkPolicyUpdate`, `usePublishReview`):

```ts
onSuccess: () => qc.invalidateQueries({ queryKey: ['company', companyId, 'pricing-reviews'] })
```

**Verdict: ✅ the mutation invalidates the same key the table reads.** Prefix matching covers the `params` suffix, and `companyId` is derived identically in both hooks. **No fix is required here.**

**Three real but secondary observations:**

1. **`removeFromCurrentList` targets `['pricing-reviews', query]` — a key that never exists** (§6.2). Dead code. It is *not* masking the bug; it simply does nothing.
2. **The badge is never invalidated.** No mutation touches `'price-review-badge'`. It corrects itself on its 120 s `refetchInterval`, so the sidebar count can lag the table.
3. **`placeholderData: keepPreviousData`** on the list means a refetch keeps showing the previous page's rows until the new data lands. Harmless here (sub-second), but it means "the row lingered briefly" is not by itself evidence of a cache bug.

**Nothing in the cache layer contributes to the reported symptom.** `onSuccess` — and therefore every invalidation *and* the dead removal — never fires, because the request returns 500.

---

## 15. Approval History

**Where stored:** `price_approvals` — [PriceApproval model](backend/Modules/CostManagement/Domain/Models/PriceApproval.php), `HasUuids`, `$timestamps = false` (write-once, immutable audit).

**What a successful Approve is expected to create** ([resolve():282-301](backend/Modules/CostManagement/Domain/Services/PricingReviewService.php:282)):

| Field | Value |
|---|---|
| `pricing_review_id`, `product_id` | linkage |
| `old_product_cost` / `new_product_cost` | cost snapshot (before/after) |
| `old_selling_price` / `new_selling_price` | **price snapshot** |
| `old_sale_price` / `new_sale_price` | sale price snapshot |
| `margin_pct`, `discount_pct` | economics at decision time |
| `action` | `approve_suggested` \| `keep_current` \| `custom_price` \| `reject` — the **decision state** |
| `custom_price` | operator input (non-null only for `custom_price`) |
| `reason`, `manager_name` | justification |
| `approved_by` | **actor** |
| `approved_channels` | channel scope |
| `approved_at`, `created_at` | **timestamp** |

All six elements Part 12 asks about — approval record, decision state, timestamp, actor, price snapshot — are designed in.

**Current state: `SELECT COUNT(*) FROM price_approvals` → 0.** Zero audit records have ever been written on `ecos_dev`. No history was lost; none was ever created.

### 15.1 Second defect in the same actor chain — the fix must handle it

```sql
mysql> SHOW COLUMNS FROM price_approvals LIKE 'approved_by';
Field        Type      Null  Default
approved_by  char(36)  YES   NULL

mysql> SHOW COLUMNS FROM users LIKE 'id';
Field  Type             Null  Key  Extra
id     bigint unsigned  NO    PRI  auto_increment
```

`approved_by` was created as `$table->uuid('approved_by')` ([2026_07_06_140002_add_audit_fields_to_price_approvals.php:27](backend/Modules/CostManagement/Infrastructure/Database/Migrations/2026_07_06_140002_add_audit_fields_to_price_approvals.php:27)) — written on the assumption of a UUID user key. ECOS deliberately kept the **bigint** User PK (ADR-040 / `iam_user_management.md`).

**Consequence for the fix:** simply relaxing the `?string` type hint is *not* sufficient. Writing integer `1` into `char(36)` will be silently coerced by MySQL to the string `"1"` — no error, but the audit column holds a value that is neither a valid UUID nor a usable foreign key, and it will not join to `users.id` without a cast. **The type hint and the column must be reconciled in the same change** (see §20). This is precisely the class of silent-coercion defect that makes an audit trail untrustworthy, and it is why the fix scope is two lines *plus* a migration rather than one line.

---

## 16. Existing Tests

Sole test file: [backend/tests/Feature/CostManagement/PricingReviewCascadeTest.php](backend/tests/Feature/CostManagement/PricingReviewCascadeTest.php) — 10 tests:

```
test_pricing_review_created_after_manual_cost_update
test_no_review_created_when_product_cost_unchanged
test_no_review_created_when_material_has_no_recipe
test_existing_pending_review_is_updated_not_duplicated
test_new_review_created_after_previous_one_was_resolved
test_company_id_falls_back_to_first_company_when_not_in_meta
test_review_is_linked_to_triggering_cost_history_record
test_cost_decreased_impact_flag_when_cost_goes_down
test_margin_below_target_flag_set_correctly
```

**Every one of these tests review *creation* (the cost cascade). Not one exercises resolution.**

**This is the gap Part 14 asks about, and it is exactly the shape described:** the one test that touches resolution does so by calling the **model method directly**, bypassing the controller and the service entirely —

```php
// line 211
PricingReview::query()->first()->resolve(PricingReviewStatus::Approved);
```

`PricingReview::resolve()` is a two-line model method that has never been broken. The defect lives in `PricingReviewService::resolve()`, one layer above, reachable only through HTTP.

**Coverage of the real HTTP surface: zero.** No `postJson`/`patchJson` call exists anywhere in the suite for:

`POST /{id}/approve` · `POST /{id}/snooze` · `POST /{id}/assign` · `POST /{id}/publish` · `POST /bulk-approve` · `POST /bulk-policy` · `PATCH /{id}/inline` · `GET /pricing-reviews` · `GET /badge` · `GET /{id}/detail`

**A single feature test issuing `postJson('.../approve', ['action' => 'approve_suggested'])` as an authenticated user would have failed loudly at the moment `26341937` landed.** This is why a fatal 500 shipped and survived through four subsequent commits.

No tests were added in this diagnostic, per Part 14.

---

## 17. Previous Certification

| Commit | Date | Relevance |
|---|---|---|
| `30655b91` | 2026-07-02 | **TASK-ARCH-PRICE-001** — original build. Created `PricingReviewService`, `PricingReview`, `PriceApproval`, the controller (`index/detail/approve/snooze/assign/bulk-approve`), requests, resources, routes. `resolve()` had **no `approverId` parameter**. **The approve path was sound at this commit.** |
| `c474af4e` | — | Recovery snapshot. `approverId` still absent. |
| **`26341937`** | — | **`feat(orders): Enterprise Orders — manual order creation, financial snapshots, cost pricing`. Introduces BOTH the `?string $approverId` parameter AND the `$request->user()?->id` call site, in the same commit. ← DEFECT INTRODUCED HERE.** |
| `96ba2aba`, `d2e7c2f6` | — | Carried forward unchanged. |
| `8ef069f7` | 2026-08-03 | TASK-HR-V1-ENHANCEMENTS-001. `git log -L 128,132` shows this commit touched the call site for **named-argument whitespace alignment only** — it did not introduce the defect. |

Presence of `approverId` in the controller, verified per commit:

```
30655b91  0      c474af4e  0      26341937  2   ← introduced
96ba2aba  2      d2e7c2f6  2      8ef069f7  2      HEAD  2
```

**No certification report exists for the Price Review Center.** `docs/verification/` contains UAT campaigns 001–011 and several task reports; **none covers Price Review, Pricing Decision Center, or the approve flow.** The memory index records `task_arch_price_001.md` as **ACTIVE**, not certified.

**Therefore Stop Condition 10 does not apply** — there is no prior certification to contradict current source. The flow was **built and shipped without ever being certified end-to-end**, which is consistent with §16.

---

## 18. Root Cause

> **`PricingReviewController::approve()` passes the authenticated user's integer primary key into `PricingReviewService::resolve()`, whose seventh parameter is typed `?string` in a file declaring `strict_types=1`. PHP raises an uncatchable `TypeError` at the call boundary, the request returns HTTP 500, and no mutation — price, review status, or audit record — is ever attempted. The React page registers no `onError` handler on this mutation, and the shared axios instance intercepts only 401, so the fatal error produces no toast, no console surface, and no visual change. The row remains in Pending because the canonical backend state is genuinely unchanged.**

Single root cause. Fully proven by four independent, mutually corroborating pieces of evidence:

1. **Stack trace** naming file, line, argument position, declared type, actual type, and the literal argument value `1` — with `"userId":1` confirming middleware was passed.
2. **`price_approvals` = 0 rows** — no resolution has ever completed on this database.
3. **`pricing_reviews.updated_at = 2026-07-29`**, seven approve attempts on **2026-08-12** — the row was never written.
4. **Source inspection** confirming `strict_types=1`, the `?string` declaration, and `users.id bigint unsigned`.

Everything else was tested and cleared: route exists and is uncached (§8), no collision (§8), authorization passes (§9), validation passes (§4), product exists and is not soft-deleted (§3), cache invalidation targets the correct key (§14), pending-queue semantics are unambiguous (§13).

---

## 19. Regression Classification

**Primary: A — Regression.** Introduced by `26341937`. The approve flow was structurally sound at `30655b91`, where `resolve()` took no `approverId`. The audit-actor feature was added with a type hint incompatible with the ECOS bigint User PK, and it was added on **both** sides in the same commit, so no intermediate state ever worked.

**Also and simultaneously D — Backend mutation defect.** The regression manifests as a fatal error in the mutation path.

Secondary classifications, in order of contribution:

| Code | Applies | Notes |
|---|---|---|
| **A** Regression | ✅ **primary** | `26341937` |
| **D** Backend mutation defect | ✅ **primary** | fatal `TypeError` pre-mutation |
| **B** Previously incomplete | ✅ contributing | Zero HTTP coverage of any Price Review endpoint (§16) let it ship and survive four commits |
| **I** Contract drift | ⚠️ **partial — decision required** | The brand is `approval_only`, so Approve stages rather than publishes (§11.5) — a divergence from the contract as stated in the brief |
| **C** Frontend mutation defect | ⚠️ contributing to *invisibility*, not to failure | No `onError` anywhere; dead `removeFromCurrentList` key |
| **E** Query/cache invalidation defect | ❌ **ruled out** | Invalidation keys are correct (§14) |
| **F** Route collision | ❌ **ruled out** | Proven from the live route table (§8) |
| **G** Permission/authorization | ❌ **ruled out** | Three independent proofs (§9) |
| **H** Data/state issue | ❌ **ruled out** | Review and product rows are well-formed (§3) |

### 19.1 Contract decision required

Per Stop Condition 4, this is raised rather than assumed: **under `publishing_strategy = 'approval_only'`, Approve does not apply prices to the product.** It closes the review and removes it from Pending (satisfying the user's stated primary requirement) but stages the prices for a separate Publish step. The brief's contract reads as a single-step apply-and-publish. Resolution is a business decision, carried to §23.

---

## 20. Fix Scope

Not implemented. Scoped and bounded. **No pricing formula is touched — Stop Condition 8 is not triggered.**

### Required — the root cause (2 files, ~3 lines + 1 migration)

**F1. Reconcile the actor type.** `PricingReviewService::resolve()` parameter `?string $approverId` vs an `int` user PK. Two viable options:
- **(a) Recommended —** widen the service signature to `int|string|null $approverId` and cast on write. Preserves the bigint PK (ADR-040) and works if a future tenant ever carries UUID users.
- **(b)** Cast at the call site: `approverId: $request->user()?->id === null ? null : (string) $request->user()->id`. Smaller, but leaves a service contract that misrepresents the domain.

**F2. Same fix at the second call site** — `bulkApprove()` ([line 320](backend/Modules/CostManagement/Presentation/Http/Controllers/PricingReviewController.php:320)) makes the identical call and fails identically.

**F3. Reconcile `price_approvals.approved_by`** — `char(36)` vs `users.id bigint unsigned` (§15.1). Without this, F1 "succeeds" while silently writing `"1"` into a UUID column, producing an audit trail that cannot join to `users`. Migration must follow the ECOS MySQL-compat rule (`DB::statement` + `Schema::table`; no `Blueprint::check()`, no `CONCURRENTLY`, no partial indexes).

### Required — make failure visible (1 file)

**F4. Add `onError` to every Price Review mutation call site** in `cost-pricing-center-page.tsx` (13 sites listed in §6.1). Without this, the next backend failure is equally invisible. `handleInlineSave` already demonstrates the correct pattern in this same file.

### Required — resolve the dead cache write (1 file)

**F5. `removeFromCurrentList`** — either correct the key to `['company', activeCompanyId ?? 'global', 'pricing-reviews', query]` **or delete it**.
**Recommendation: delete it.** Invalidation already produces the correct behaviour from canonical state (§14), whereas an optimistic client-side removal is exactly the "client-side hiding" the contract forbids — and, once the key is correct, it would hide rows on failure too, reproducing this same class of bug by design.

### Conditional — pending the §23 decision

**F6.** If Approve must publish immediately for `approval_only` brands, that is a **change to the publishing contract** and requires its own ADR — not a bug fix. If the two-step flow stands, add a UI affordance so an operator can reach their staged approval (the Publish button currently only renders on a row the Pending filter has already removed).

### Out of scope for the fix task — separate tasks required

**F7. P0 tenant isolation** (§10) — company scoping across all 11 controller methods plus the summary counts. Security task, own review.
**F8.** Dual permission namespace `cost.price_review.*` vs `inventory.price_review.*` (§9) — IAM backlog.
**F9.** Missing `permission:inventory.price_review.view` on the three GET routes (§7).
**F10.** Badge query key never invalidated (§14).

### Explicitly NOT in scope

- ❌ Any pricing formula — margin, markup, discount, suggested price, sale price
- ❌ `PricingReviewStatus` values or `isResolved()` semantics
- ❌ The publishing-strategy mechanism itself
- ❌ The `index()` pending filter — it is correct

---

## 21. Risks

| # | Risk | Severity | Mitigation |
|---|---|---|---|
| R1 | **Fixing only the type hint (F1) without the column (F3)** writes `"1"` into a `char(36)` UUID column. MySQL coerces silently — the fix *appears* to work while the audit trail is quietly unusable. | **High** | F1 and F3 must land together. Assert on `approved_by` in the acceptance test, not just on row count. |
| R2 | **Fixing the backend without F4** means the *next* failure is equally invisible. | High | Treat F4 as mandatory, not cosmetic. |
| R3 | **"Fixing" the symptom via F5 alone** (correcting the removal key) makes rows vanish on click without any state change — a cosmetic fix that violates the contract and hides real failures. **This is the most dangerous available wrong fix.** | **High** | Explicitly forbidden. Row disappearance must be a consequence of refetch after a 2xx. |
| R4 | **`approval_only` surprise** — once fixed, Approve on this brand does not change catalog prices. If unanticipated, this reads as "the fix didn't work". | Medium | Resolve §23 decision **before** implementation; state the expected outcome in the acceptance criteria. |
| R5 | **Tenant isolation (F7) remains open.** Once Approve works, an unscoped mutation becomes genuinely reachable cross-company. Today only the read path leaks; after the fix the write path is live. | **High** | Sequence F7 alongside or immediately after the fix. Do not ship the working mutation into a multi-company tenant with no scoping. |
| R6 | `resolve()` is **not wrapped in a transaction** — it writes the product, updates mappings, creates the audit row, and calls `$review->resolve()` as separate statements. A mid-sequence failure leaves a published price with no audit record. | Medium | Wrap in `DB::transaction()` while in the file. Low cost, materially safer. |
| R7 | `resolve()` dereferences `$review->product` without a null guard ([line 224](backend/Modules/CostManagement/Domain/Services/PricingReviewService.php:224)). `Product` uses `SoftDeletes`; a soft-deleted product yields `null` → fatal 500, a second silent failure of the same shape. Not currently triggered (product exists). | Medium | Add a null guard returning 422. |
| R8 | The `PriceReviewApproved` event fires after resolution; downstream listeners have never executed on this database (0 approvals ever). Their behaviour is **entirely unverified in practice**. | Medium | Enumerate listeners before certification; watch the queue on the first successful approve. |
| R9 | `docker cp` requirement — the volume is not hot-mounted; every backend source edit needs an explicit copy into the container, or the fix appears not to apply. | Low | Standard ECOS deploy step. |
| R10 | `ecos_dev` currently holds exactly **one** pending review. Once resolved it leaves Pending and there is no second row to retest with; a new review requires a fresh cost change. | Low | Certify on `ecos_dev_test` fixtures (§23), not on this single production-like row. |

---

## 22. Required Implementation Task

**`TASK-PRICE-REVIEW-ACTION-REPAIR-001`** — repair the Price Review resolution path.

**Scope:** F1 · F2 · F3 · F4 · F5 (+ R6, R7 as low-cost hardening in the same files).
**Blocked on:** the §23 contract decision (F6 depends on it; F1–F5 do not).
**Out of scope:** F7–F10, each its own task.

Suggested split:

| Task | Scope | Priority |
|---|---|---|
| `TASK-PRICE-REVIEW-ACTION-REPAIR-001` | F1–F5, R6, R7 + HTTP feature tests | **P0** — four of five row actions are dead |
| `TASK-PRICE-REVIEW-TENANT-ISOLATION-001` | F7 — company scoping across all 11 methods + summary counts | **P0 security** |
| `TASK-PRICE-REVIEW-PERMISSION-CLEANUP-001` | F8, F9 | P2 |
| `TASK-PRICE-REVIEW-PUBLISH-UX-001` | F6 (if the two-step flow stands) | P2, gated on §23 |

**Mandatory test additions** (§16 — the gap that let this ship):

- `POST /{id}/approve` with each of the four actions, as an authenticated user with the real permission, asserting **200**, `price_approvals` row created, `approved_by` correct **and correctly typed**, `status` terminal, `resolved_at` stamped.
- `POST /bulk-approve` — the second broken call site.
- The pending-queue contract end-to-end: `GET ?status=pending` → approve → `GET ?status=pending` returns one fewer row.
- Both publishing strategies: `automatic` writes product prices; `approval_only` stages and sets `pending_publish`.
- The 422 idempotency guard on re-resolving.
- `POST /{id}/snooze`, `POST /{id}/assign`, `PATCH /{id}/inline`, `POST /{id}/publish` — no HTTP coverage exists for any of them.

ECOS test-runner constraints apply: `route:clear` before any API feature test; dual-loader bootstrap with `DB_DATABASE=ecos_erp_test migrate --force` first; never use `tinker` against the dev `.env` (it points at the test DB and pollutes it).

---

## 23. Certification Requirements

### 23.1 Status

**`ROOT CAUSE PROVEN — FIX READY`** — for the reported symptom. One root cause, four independent proofs, bounded fix scope, no formula changes.

Two qualifications, neither of which blocks the fix:

**(a) One contract decision is required before implementation** (Stop Condition 4 — §11.5, §19.1).

The brand governing the affected product is `publishing_strategy = "approval_only"`. After the fix, **Approve will close the review and remove it from Pending — the stated primary requirement is met — but will NOT write `products.regular_price` / `products.sale_price`.** Prices stage into `approved_price` / `approved_sale_price` with `publish_status = 'pending_publish'`, awaiting a separate Publish.

The task brief states Approve must "apply the approved Regular Price … apply the approved Sale Price … publish/store the resulting product prices". Under the existing certified-by-code contract that is a two-step operation for this brand. Three options:

1. **Keep the two-step flow** (existing design, no ADR needed) and add a UI affordance so operators can reach staged approvals (F6). *Recommended — preserves the deliberate approval gate.*
2. **Change the brand's `publishing_strategy` to `automatic`** — configuration change, no code change; Approve then applies prices immediately.
3. **Change the contract so Approve always publishes** — requires a new ADR; would remove the approval gate for every `approval_only` brand.

**I did not choose.** The brief forbids inventing semantics, and this is a pricing-governance decision.

**(b) Snooze is unverified at runtime** (§5.1). Its backend path does not contain the defect. No snooze request appears in the logs, so there is no evidence it was exercised. If Snooze genuinely misbehaves, capture the browser Network response for `POST /{id}/snooze` — it is a different endpoint and would be a different defect.

### 23.2 Environment for certification

**`ENVIRONMENT BLOCKED` for runtime certification — read-only diagnosis is complete.**

Proving the *fix* requires executing real mutations (POST `/approve`, verifying price writes and audit rows). Per Part 13, I did not and will not do that against `ecos_dev`. **Certification requires `ecos_dev_test` fixtures.**

Additional constraint: `ecos_dev` holds exactly **one** pending review (R10). Certifying against it would consume the only available row and leave nothing to retest.

### 23.3 Acceptance criteria for the fix

| # | Criterion |
|---|---|
| C1 | `POST /{id}/approve` with `approve_suggested` returns **200** (not 500) |
| C2 | A `price_approvals` row is created with a **correctly typed, joinable** `approved_by` |
| C3 | `pricing_reviews.status = 'approved'` and `resolved_at` is stamped |
| C4 | `GET ?status=pending` no longer returns the row, and `summary.pending` decrements |
| C5 | The row disappears from the UI **via refetch after a 2xx**, with no client-side hiding |
| C6 | Same for `keep_current`, `custom_price`, `reject`, and all bulk variants |
| C7 | Under `automatic`, `products.regular_price` / `sale_price` are written and mappings flagged `sync_status = pending` |
| C8 | Under `approval_only`, prices stage to `approved_price` / `approved_sale_price` with `publish_status = 'pending_publish'` and the product is untouched |
| C9 | A backend failure now produces a **visible error toast** (F4) — verify by forcing a 500 |
| C10 | Re-resolving a resolved review still returns **422** |
| C11 | `PriceReviewApproved` fires and its listeners complete without error (R8) |
| C12 | No pricing formula changed — diff contains no arithmetic edits to margin, markup, discount, or price derivation |

### 23.4 Stop conditions — status

| # | Condition | Status |
|---|---|---|
| 1 | Mutation against `ecos_dev` required to prove the issue | ❌ Not required — proven from logs + read-only SELECTs + source |
| 2 | Another agent owns `ecos_dev_test` | ➖ Not encountered |
| 3 | Route collision with unclear canonical endpoint | ❌ No collision (§8) |
| 4 | **Pricing contract differs from previously certified contract** | ⚠️ **TRIGGERED — §11.5 / §19.1 / §23.1(a). Decision required.** |
| 5 | Approval semantics unrecoverable | ❌ Fully recovered from source + history (§2, §17) |
| 6 | **Tenant isolation cannot be proven** | ⚠️ **TRIGGERED — §10. There is none. Reported, not fixed; no cross-tenant mutation executed.** |
| 7 | Mutation goes through an unknown pricing path | ❌ Path fully traced (§11) |
| 8 | Fix would require changing pricing formulas | ❌ It would not (§20) |
| 9 | Pending-queue semantics ambiguous | ❌ Unambiguous (§13) |
| 10 | Existing certification contradicts source | ❌ No prior certification exists (§17) |

---

## Appendix — Read-only commands used

No mutation was executed. Every command below is read-only.

```bash
# Source: Read / Grep over backend/Modules/CostManagement, frontend/src/features/cost-management,
#         backend/routes/api.php, backend/config/permissions.php, backend/tests

# Live route table (read-only artisan command)
docker exec ecos-dev-app php artisan route:list --path=pricing-reviews

# Route cache presence
docker exec ecos-dev-app ls -la bootstrap/cache/

# Application logs
docker exec ecos-dev-app grep -n "PricingReview" storage/logs/laravel-2026-08-12.log

# Read-only SELECT / SHOW only — no INSERT, UPDATE, DELETE, or DDL
docker exec ecos-dev-mysql mysql -uecos -p… ecos_dev -e "
  SELECT … FROM pricing_reviews ORDER BY created_at DESC LIMIT 10;
  SELECT COUNT(*) FROM price_approvals;
  SELECT id,sku,name,brand_id,regular_price,sale_price,product_cost,deleted_at
    FROM products WHERE id='019faef5-af41-7321-9f6b-546045947ace';
  SELECT name FROM permissions WHERE name LIKE '%price_review%';
  SELECT r.slug,p.name FROM role_permissions rp
    JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id
    WHERE p.name LIKE '%price_review%';
  SELECT u.id,u.email,r.slug,r.is_system FROM users u
    JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id;
  SELECT brand_id,policy_group,settings FROM config_brand_policies
    WHERE brand_id='019faecb-8420-72ce-ac40-cf4d9e1b9ee6' AND policy_group='pricing';
  SHOW COLUMNS FROM price_approvals LIKE 'approved_by';
  SHOW COLUMNS FROM users LIKE 'id';
"

# Git history
git log --oneline -S "approverId" -- backend/Modules/CostManagement/
git log -1 -L 128,132:backend/Modules/CostManagement/Presentation/Http/Controllers/PricingReviewController.php
```

**No production code modified. No database data modified. No schema modified. No mutating HTTP request issued.**
