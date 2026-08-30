# TASK-ORDER-CREATE-STATUS-INVALID-DIAGNOSTIC-001 — Engineering Report

**Date:** 2026-08-12 · **Branch:** `develop` · **DIAGNOSTIC ONLY — nothing was modified**

> # ROOT CAUSE = **Stale V2 status whitelist in `StoreManualOrderRequest`**
>
> `backend/Modules/Commerce/Orders/Presentation/Http/Requests/StoreManualOrderRequest.php:71`
>
> ```php
> 'status' => 'nullable|string|in:pending,scheduled,processing,awaiting_payment,completed,cancelled',
> ```
>
> The frontend correctly sends the canonical V3 value **`new`**. That value is **not in this list**, so Laravel's `in` rule rejects it with its standard message: *"The selected status is invalid."*
>
> **Classification: E — Stale V2 contract.** Proven from source, end to end. The frontend is correct, the V3 enum is correct, and the sibling request class does it correctly — only this one path drifted.

---

## 1. Executive Summary

Creating a manual order fails because a single validation rule still carries the **pre-V3** status vocabulary. Three of its six values (`pending`, `processing`, `completed`) do not exist anywhere in the V3 `OrderStatus` enum, and the V3 initial status (`new`) is absent from it.

The Order lifecycle architecture is **not** at fault, and this is **not** evidence against it. The identical sibling request derives its list from the enum and would have accepted `new` without issue.

## 2. Screenshot Evidence

The reported symptom — *"The selected status is invalid."* on `/app/orders/new` with **Entry Status = New** — is reproduced exactly by the chain below. That string is Laravel's built-in `validation.in` message (`:attribute is invalid` → *"The selected status is invalid."*), which confirms the failure is an `in:` rule and not a custom guard, an enum cast, or a domain exception.

## 3. Frontend Trace

| Step | Evidence |
|---|---|
| Form | `frontend/src/features/orders/components/manual-order-form.tsx` |
| **Default value** | **`:852` → `status: 'new'`** |
| Label shown | `order-inventory-status-card.tsx:26-28` — `formatStatusLabel()` renders `'new'` → **"New"** (underscores → spaces, title-cased) |
| Entry Status control | `manual-order-form.tsx:1682-1684` — a Select "loaded from brand order policy"; options come from `resolveEntryStatuses(policy)` = `policy.source_entry_policies.manual` (`order-inventory-status-card.tsx:21-24`) |
| Submit | `:1421-1422` → `createManual.mutate(payload)` |
| Service | `orders-service.ts:36-38` → `api.post('/orders/manual', payload)` |
| Transformation | **None.** The value is passed through unchanged — no mapping, no case change, no label→value lookup |

**The label "New" and the value `'new'` are consistent.** The UI is not sending a display string; it sends the canonical enum value.

## 4. Request Payload

```
POST /api/orders/manual
{ ..., "status": "new", "lines": [...] }
```

Confirmed by the form's own dev-mode log at `:1421`, which prints `payload.status` immediately before the request.

## 5. Backend Route

```
routes/api.php:536
Route::post('orders/manual', [OrderController::class, 'storeManual'])
    ->middleware('permission:sales.orders.create');
```

`OrderController::storeManual(StoreManualOrderRequest $request, CreateManualOrderAction $action)` — `OrderController.php:121`.

Validation therefore runs in **`StoreManualOrderRequest`**, before the action is ever entered.

## 6. Validation Rule

```php
// StoreManualOrderRequest.php:71
'status' => 'nullable|string|in:pending,scheduled,processing,awaiting_payment,completed,cancelled',
```

**Accepted values (6):** `pending`, `scheduled`, `processing`, `awaiting_payment`, `completed`, `cancelled`.

## 7. Canonical V3 Status Contract

`Modules/Commerce/Orders/Domain/Enums/OrderStatus.php` — the authority, **11 cases**:

```
new · in_progress · ready_for_dispatch · out_for_delivery · delivered
awaiting_payment · awaiting_stock · scheduled · on_hold
cancelled · returned
```

The initial status for a new order is **`new`** (case `NewOrder = 'new'`). It is a real enum value, not a UI label. The correct literal is lowercase `new` — not `New`, not `NEW`, not `draft`.

## 8. Frontend vs Backend Comparison

| | Value |
|---|---|
| Frontend sends | **`new`** ✅ canonical |
| `StoreManualOrderRequest` accepts | `pending`, `scheduled`, `processing`, `awaiting_payment`, `completed`, `cancelled` |
| **Overlap with V3 enum** | only `scheduled`, `awaiting_payment`, `cancelled` — **3 of 6** |
| **`new` accepted?** | ❌ **No** |

**Mismatch point: `StoreManualOrderRequest.php:71`.** The frontend, the enum and the domain all agree; the whitelist alone disagrees.

## 9. V2 / V3 Drift Check

Three values in the whitelist exist in **no** V3 enum case:

| Stale value | V3 equivalent |
|---|---|
| `pending` | renamed to **`new`** |
| `processing` | folded into **`in_progress`** |
| `completed` | **`delivered`** |

This matches the documented V3 rename (TASK-ORDERS-LIFECYCLE-ARCH-002; `PreparationSessionPolicy:86` records *"in_progress — subsumes the former confirm/confirmed"*). The whitelist predates that rename and was never migrated.

**The correct pattern already exists in the same directory.** Every sibling request derives from the enum:

| Request | Rule |
|---|---|
| `StoreOrderRequest:23,30` | `$statuses = array_column(OrderStatus::cases(), 'value');` … `Rule::in($statuses)` ✅ |
| `UpdateOrderRequest:31` | `Rule::in($statuses)` ✅ |
| `PatchOrderRequest:26` | `Rule::in($statuses)` ✅ |
| **`StoreManualOrderRequest:71`** | **hardcoded V2 string** ❌ |

One file out of four drifted. This is isolated drift, not an architectural problem.

## 10. Existing Tests

`POST /orders/manual` validation is evidently **not covered** by a test that submits `status: 'new'` — otherwise this would have failed in CI rather than in the UI.

The plausible reason (**not fully verified — see §11**) is that order-creation tests either exercise `POST /orders` (`StoreOrderRequest`, which derives from the enum and accepts `new`) or call `CreateManualOrderAction` directly, bypassing the FormRequest entirely. A service-level test cannot see a FormRequest rule.

This is the same shape of gap found twice before in this codebase: **service tests green, HTTP surface unexercised.**

## 11. Runtime Evidence

**No runtime POST was executed** and **no database was written** — the task forbids it and the source chain is unambiguous without it.

Every link is proven from source with file:line: the frontend default (`:852`), the endpoint (`orders-service.ts:37`), the route (`api.php:536`), the request class (`OrderController.php:121`), and the rule (`StoreManualOrderRequest.php:71`). The error string is Laravel's stock `in` message, which independently confirms the failing rule type.

**Uncertainty 1 — RESOLVED by a follow-up trace.** It was initially unclear what the brand order policy supplies, since the Entry Status options are fetched at runtime rather than hardcoded. A full frontend trace closed it:

- Options come from `orderPolicy.source_entry_policies.manual` (`GET /configuration/brands/{brandId}/policies/order`), and each `<SelectItem value={s}>` binds the **raw policy string** as the value.
- The visible text is `t($ => $.status[s], { defaultValue: STATUS_LABELS[s] ?? s })` — a **one-way, display-only** value→label mapping. There is no label→value lookup anywhere.
- **Therefore the label "New" in the screenshot proves the underlying value was `new`**, because "New" is only ever produced by rendering `new` (`orders.json` → `"new": "New"`; local fallback `STATUS_LABELS` → `new: 'New'`).
- Two further guards independently converge on the same literal: the policy-load effect (`:1070-1082`) falls back to `validChoices[0] ?? 'new'`, and the payload builder (`order-form-schema.ts:156`) applies `values.status || 'new'`.
- `STATUS_LABELS` (`:95-107`) contains **exactly the 11 V3 enum values** — the frontend is fully V3-aligned.

**Uncertainty 2 — still open.** §10's explanation of why tests stayed green was not run to ground; the absence of HTTP coverage is inferred from the failure reaching production, not from an executed test inventory. This does not affect the root cause.

The root cause stands either way: `new` cannot pass `in:pending,scheduled,processing,awaiting_payment,completed,cancelled`.

## 12. Root Cause

**ROOT CAUSE = `StoreManualOrderRequest.php:71` carries a hardcoded pre-V3 status whitelist that omits the canonical initial status `new`.**

```
manual-order-form.tsx:852   status: 'new'          ← correct, canonical
        ↓ POST /orders/manual
api.php:536 → OrderController::storeManual
        ↓
StoreManualOrderRequest:71  in:pending,scheduled,processing,awaiting_payment,completed,cancelled
        ↓ 'new' ∉ list
422  "The selected status is invalid."
```

Classification: **E — Stale V2 contract.** Not A (frontend is correct), not C (label/value mapping is consistent), not D (no transformation exists).

## 13. Impact

- **Manual order creation is blocked entirely** for the default Entry Status. Any status the operator picks that is V3-canonical but absent from the six-value list fails the same way — that includes `new`, `in_progress`, `awaiting_stock`, `on_hold`, `ready_for_dispatch`, `out_for_delivery`, `delivered`, `returned`.
- Only `scheduled`, `awaiting_payment` and `cancelled` would pass.
- Worse latent risk: the list *accepts* `pending`, `processing` and `completed` — values `OrderStatus::from()` cannot construct. If any client sent one, it would pass validation and then fail deeper, or persist a status no enum recognises.
- **`POST /orders` (the non-manual path) is unaffected.**

## 14. Recommended Fix

**Not applied.** The minimal change that preserves the certified lifecycle is to make this request derive its list the same way its three siblings already do:

```php
// StoreManualOrderRequest — mirror StoreOrderRequest:23,30
$statuses = array_column(OrderStatus::cases(), 'value');
...
'status' => ['nullable', 'string', Rule::in($statuses)],
```

This changes **no** enum value, **no** lifecycle rule, **no** API contract semantics, and **no** frontend code. It makes one file consistent with the authority it already had.

Deliberately **not** recommended: adding `new` to the hardcoded list (leaves the other seven missing statuses broken and the three phantom values accepted), or changing the frontend to send `pending` (would send a value the enum cannot construct).

**A regression test should accompany the fix** — `POST /orders/manual` with `status: 'new'` asserting 201, which is exactly the coverage whose absence let this reach the UI.

## 15. Files That Would Need Modification

| File | Change |
|---|---|
| `backend/Modules/Commerce/Orders/Presentation/Http/Requests/StoreManualOrderRequest.php` | one rule + the `$statuses`/`Rule` import, mirroring `StoreOrderRequest` |
| `backend/tests/Feature/...` (new or existing orders HTTP test) | regression test for `POST /orders/manual` with `status: 'new'` |

**No frontend change. No migration. No enum change. No data change.**

## 16. Certification Impact

- **Order lifecycle (V3) architecture:** unaffected — the enum is correct and this proves the frontend agrees with it.
- **Orders component certification:** the *manual creation HTTP path* is uncertified in practice; the drift survived because that path lacks HTTP-level coverage.
- **No other module is implicated.** Distribution, Preparation, Warehouse Assignment and the Reservation contract do not read this rule.

## 17. STOP / GO Recommendation

**GO — with a narrow scope.**

The root cause is proven from source with no ambiguity, the fix is one rule in one file, and it aligns a drifted path with a pattern already established three times in the same directory. There is no business decision to make: the canonical status list is the enum, and that is already settled.

**Recommended authorization:** a small task limited to (a) deriving the status rule from `OrderStatus` in `StoreManualOrderRequest`, and (b) adding the missing HTTP regression test. Nothing else in the Orders module should be touched.

**Nothing was modified by this diagnostic** — no backend, no frontend, no database, no migration, no validation, no enum, no test, no order data.
