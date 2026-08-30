# TASK-PAYMENT-PROOF-LIFECYCLE-001 — Engineering Report

**Date:** 2026-08-20
**Author:** Osama Fayez (eng_osamafayez@hotmail.com)
**Final status:** **IMPLEMENTATION COMPLETE / RUNTIME VERIFIED**
**Certification:** DEFERRED (per task directive — this report does not claim certification)

---

## 1. Objective

Implement a first-class **Payment Proof lifecycle** for orders:

- Payment proof is a **first-class record** (`payment_proofs`), not a filesystem path treated as authoritative state.
- Proof states are exactly **UPLOADED / VERIFIED / REJECTED** — these are proof states, **not** Order statuses.
- Operations: **upload / verify / reject / replace**, with **full history retained** (nothing hard-deleted).
- **Three dedicated permissions** — upload ≠ verify ≠ reject.
- **Tenant isolation** — a foreign company gets 404, no data leak.
- **Payment policy preserved** — COD needs no proof, card optional, instapay/bank required.
- A proof must **not** bypass `ConfirmOrderWorkflow` and must **never** mark payment PAID.
- **Durable deployment** — image rebuilt (docker cp is ephemeral).

---

## 2. Final Status Summary

| Gate | Result |
|------|--------|
| ESLint (changed/new frontend files) | ✅ PASS (exit 0) |
| Vite production build | ✅ PASS (10.34s) |
| DEV DB identity verified = `ecos_dev` | ✅ PASS |
| `payment_proofs` migration applied to `ecos_dev` | ✅ PASS (business data intact) |
| DEV backend image rebuilt from working tree | ✅ PASS (`ac1d8fe5566f`) |
| `ecos-dev-app` recreated (durable, not docker cp) | ✅ PASS (healthy, 5 routes) |
| Production untouched | ✅ PASS (no prod container/DB command issued) |
| Backend tests | ✅ PASS (23 tests / 55 assertions) |
| Browser smoke scenarios 1–6 | ✅ PASS (6/6) |

**Overall: IMPLEMENTATION COMPLETE / RUNTIME VERIFIED.**

---

## 3. Data Model

New table `payment_proofs` (migration `2026_08_19_140000_create_payment_proofs_table.php`):

| Column | Type | Notes |
|--------|------|-------|
| `id` | uuid (pk) | |
| `company_id` | foreignUuid → companies (cascade) | tenant anchor |
| `order_id` | foreignUuid → orders (cascade) | |
| `state` | string(20), default `uploaded` | UPLOADED / VERIFIED / REJECTED |
| `storage_disk`, `storage_path` | string | private `local` disk |
| `original_filename`, `mime_type`, `size_bytes` | | mime is **content-sniffed**, not client-trusted |
| `uploaded_by` | foreignId → users (nullOnDelete) | BIGINT users PK |
| `uploaded_at` | timestamp | |
| `verified_by` / `verified_at` | | attribution |
| `rejected_by` / `rejected_at` / `rejection_reason` | | evidence retained |
| `superseded_at` | timestamp nullable | **null = active proof** |
| `replaces_proof_id` | uuid nullable | links a replacement to its predecessor |

Indexes: `idx_pp_order_active(order_id, superseded_at)`, `idx_pp_company_order(company_id, order_id)`.

**State authority:** the `state` column + `superseded_at` are the source of truth. The old `orders.payment_proof_path` single-path model is superseded (the detail page no longer renders it).

---

## 4. Backend Components

| File | Role |
|------|------|
| `Domain/Enums/PaymentProofState.php` | Uploaded / Verified / Rejected |
| `Domain/Models/PaymentProof.php` | casts state→enum; `isActive()` = `superseded_at === null` |
| `Application/Actions/UploadPaymentProofAction.php` | stores on `local`, path `payment-proofs/{company_id}/{ulid}.{ext}`, supersedes prior active proof, creates new UPLOADED, logs `OrderEvent 'payment_proof_uploaded'`, **no status/payment side-effects** |
| `Application/Actions/VerifyPaymentProofAction.php` | uploaded→verified only (else 422); **no payment/status change** |
| `Application/Actions/RejectPaymentProofAction.php` | uploaded→rejected; reason required (422 if empty); evidence retained |
| `Presentation/Http/Controllers/PaymentProofController.php` | index/upload/verify/reject/download; `resolveOrder`/`resolveProof` scoped `WHERE company_id = currentCompany` → `firstOrFail` (cross-tenant → 404); download streams via `Storage::disk->download` |
| `Http/Requests/UploadPaymentProofRequest.php` | `file, max:10240, mimes:jpeg,jpg,png,webp,gif,pdf` |
| `Http/Requests/RejectPaymentProofRequest.php` | `reason: required,string,max:1000` |
| `Http/Resources/PaymentProofResource.php` | exposes `state`, `is_active`, `download_url`, attribution fields |

**Permissions** (`config/permissions.php`, orders resource): `proof_upload`, `proof_verify`, `proof_reject` — three separate permissions.

**Routes** (`routes/api.php`, append-only) — all registered in the running container:

```
GET   api/orders/{order}/payment-proofs
POST  api/orders/{order}/payment-proofs                    (permission: sales.orders.proof_upload)
GET   api/orders/{order}/payment-proofs/{proof}/download
POST  api/payment-proofs/{proof}/verify                    (permission: sales.orders.proof_verify)
POST  api/payment-proofs/{proof}/reject                    (permission: sales.orders.proof_reject)
```

---

## 5. Frontend Components

| File | Role |
|------|------|
| `features/orders/services/payment-proof-service.ts` | list / upload(FormData) / verify / reject / view(blob via tenant-scoped endpoint) |
| `features/orders/hooks/use-payment-proofs.ts` | `usePaymentProofs` query + `usePaymentProofActions` mutations (invalidate on success) |
| `features/orders/components/payment-proof-section.tsx` | state display, Upload/View/Verify/Reject/Replace, inline reject-reason input, history list; `PROOF_REQUIRED_METHODS = ['instapay','bank_transfer']` |
| `features/orders/pages/order-detail-page.tsx` | mounts `<PaymentProofSection>` in PaymentCard; removed old `getMediaUrl(order.payment_proof_path)` block |
| `i18n/locales/en|ar/orders.json` | proof keys (title, required, none, state labels, actions, reason, history, error toasts) |

---

## 6. Recovery Plan Execution (steps 1–13)

| Step | Action | Result |
|------|--------|--------|
| 1 | ESLint on changed/new frontend files | ✅ exit 0 |
| 2 | `vite build` | ✅ 10.34s, no errors |
| 3 | Verify DEV DB = exactly `ecos_dev` | ✅ confirmed before any DB command |
| 4 | Apply **only** the `payment_proofs` migration to `ecos_dev` | ✅ table created, business data intact |
| 5 | Rebuild DEV backend image from working tree | ✅ `ecos-dev/app:latest` → `ac1d8fe5566f` |
| 6 | Recreate **only** `ecos-dev-app` | ✅ healthy; proof code baked; 5 routes present |
| 7 | Do NOT touch production | ✅ no prod container/DB command issued |
| 8 | No reset/truncate/seed/delete of business data | ✅ honored |
| 9 | No unrelated source edits | ✅ honored |
| 10 | No checkout/reset/clean/stash | ✅ honored |
| 11 | Run browser smoke 1–6 | ✅ 6/6 PASS (§7) |
| 12 | Record exact PASS/FAIL evidence | ✅ this report |
| 13 | Write engineering report | ✅ this file |

---

## 7. Browser Smoke Scenarios — Exact Evidence

All scenarios exercised against the **durable rebuilt image** at `http://127.0.0.1:5173` (UI) → `/api` (running `ecos-dev-app`). Test orders: ORD-00003/04/05, total 10000 each.

### Scenario 1 — Unpaid + Proof UPLOADED — ✅ PASS
Proof uploaded to an unpaid order via the real `POST /orders/{id}/payment-proofs` endpoint (200, state `uploaded`). UI proof section renders **"Uploaded — Awaiting Verification"** with View/Verify/Reject/Replace. Order payment unaffected.

### Scenario 2 — Full payment → PAID, then Verify → VERIFIED — ✅ PASS
Recorded full payment on ORD-00003 (deposit 10000/10000 → `paid`). Clicked **Verify** in the real UI → proof state **VERIFIED**; Verify/Reject buttons disappear (only View + Replace remain). Final DB: `ORD-00003 | verified | active | receipt1.png`.

### Scenario 3 — Reject with reason → REJECTED, reason visible, evidence retained — ✅ PASS
On ORD-00004, clicked **Reject** (real UI), entered reason **"Invalid transaction reference"**, submitted → proof **REJECTED**. UI shows `Reason: Invalid transaction reference`; **View** remains (evidence retained). DB: `ORD-00004 | rejected | receiptB.png | "Invalid transaction reference"`.

### Scenario 4 — Replace → new UPLOADED active, old retained in history — ✅ PASS
Replaced the rejected proof on ORD-00004 (`POST` new proof → 200, state `uploaded`, `is_active=true`, `replaces_proof_id` = the rejected proof id). API returns 2 proofs. UI renders:
- Active: **#2 Uploaded — Awaiting Verification**
- **PAYMENT PROOF HISTORY:** `#2 Uploaded (View)` · `#1 Rejected · Invalid transaction reference (View)`

Old rejected proof retained and viewable. DB: two rows for ORD-00004 (rejected inactive + uploaded active).

### Scenario 5 — Partial (3000/10000) → PARTIALLY_PAID with proof — ✅ PASS
ORD-00005 deposit 3000/10000. Before upload: `payment_state = partially_paid, status = awaiting_payment`. Uploaded proof (200, `uploaded`). **After upload: `payment_state = partially_paid` unchanged, `status = awaiting_payment` unchanged** — the proof did **not** flip payment to PAID and did **not** change order status. UI renders "Partially Paid" + "Uploaded — Awaiting Verification".

### Scenario 6 — Tenant isolation → 404 / no leak — ✅ PASS
DEV is single-company, so verified two ways:

**(a) Live 404 probes** (authenticated real requests):
- Real proof id requested under the **wrong order id** (download) → **404** (proof↔order binding enforced)
- Verify a **bogus proof id** → **404**
- List proofs for a **bogus order id** → **404**

**(b) Backend two-company tests** (fresh run via the test gate):
```
tests/Feature/Commerce/PaymentProofLifecycleTest.php
OK (23 tests, 55 assertions)
```
Includes cross-tenant cases: Company B cannot list/download/verify/reject Company A's proofs (404), no leak.

---

## 8. Payment Contract Preservation (proof ≠ payment)

Verified at runtime that a proof never mutates payment/order state:

- Upload/verify/reject actions perform **no** `RecordOrderPaymentAction`, **no** `ConfirmOrderWorkflow`, **no** `Order.status` write (P9 guard untouched).
- Scenario 5 proves an UPLOADED proof on a partially-paid order leaves `payment_state = partially_paid` and `status = awaiting_payment`.
- Verifying a proof (Scenario 2) records `verified_by/verified_at` only; the PAID state came from the **separate** recorded payment, not the proof.

Payment-method policy (`PROOF_REQUIRED_METHODS = ['instapay','bank_transfer']`) drives only the UI "Required" hint; upload is not hard-blocked by method (COD/card remain optional-to-none), matching the approved brand policy.

---

## 9. Backend Test Evidence

```
docker exec -e GATE_WAIT=2400 ecos-dev-testrunner sh scripts/test-gate.sh \
  tests/Feature/Commerce/PaymentProofLifecycleTest.php

PHPUnit 11.5.55 — PHP 8.4.24
....................... 23 / 23 (100%)
OK (23 tests, 55 assertions)
```

Coverage: upload, verify (uploaded→verified only), reject (reason-required), replace (supersede + history), tenant isolation (Company B → 404), payment-contract (no PAID / no status change), permission separation (upload ≠ verify ≠ reject).

---

## 10. Deployment Evidence (durable, not ephemeral)

- Image rebuilt from the working tree: `ecos-dev/app:latest` manifest `sha256:ac1d8fe5566f…` (build exit 0).
- `ecos-dev-app` recreated from the new image (not `docker cp`) → **healthy**.
- `php artisan route:list` inside the running container shows all 5 payment-proof routes.
- Migration `2026_08_19_140000_create_payment_proofs_table` applied to `ecos_dev` **and** `ecos_dev_test`.
- **Production:** no production container or DB command was issued at any point.

---

## 11. Final DB State (evidence)

```
ORD-00003 | verified | active   | receipt1.png       | —
ORD-00004 | rejected | inactive | receiptB.png       | Invalid transaction reference
ORD-00004 | uploaded | active   | receiptB2.png      | —
ORD-00005 | uploaded | active   | partial-receipt.png| —
```

Exactly one **active** proof per order; superseded/rejected rows retained (history intact).

---

## 12. Known Limitations / Notes (not patched, per directive)

1. **Browser file-picker glue not automatable.** Setting `input.files` via `DataTransfer` does not fire React's `onChange` in the in-app browser, so proof uploads in smoke were driven through the **exact same** `POST /orders/{id}/payment-proofs` FormData request the UI service issues, then the UI was reloaded to verify rendering. This validates the real endpoint + real UI display + verify/reject/replace **through the actual UI buttons**; only the OS file-picker→onChange step is a browser-automation limitation, not a feature gap.
2. **`payment_method` is NULL on the ORD-00003/04/05 test orders.** This is a property of how these particular test orders were created, unrelated to the proof lifecycle. With a null method the "Required" badge simply does not show (proof treated as optional), which is correct. **Not patched** (out of scope per the "do not patch unrelated problems" directive) — flagged here for visibility.

---

## 13. Files Changed

**New (backend):**
- `Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_08_19_140000_create_payment_proofs_table.php`
- `Modules/Commerce/Orders/Domain/Enums/PaymentProofState.php`
- `Modules/Commerce/Orders/Domain/Models/PaymentProof.php`
- `Modules/Commerce/Orders/Application/Actions/{Upload,Verify,Reject}PaymentProofAction.php`
- `Modules/Commerce/Orders/Presentation/Http/Controllers/PaymentProofController.php`
- `Modules/Commerce/Orders/Presentation/Http/Requests/{UploadPaymentProofRequest,RejectPaymentProofRequest}.php`
- `Modules/Commerce/Orders/Presentation/Http/Resources/PaymentProofResource.php`
- `tests/Feature/Commerce/PaymentProofLifecycleTest.php`

**New (frontend):**
- `features/orders/services/payment-proof-service.ts`
- `features/orders/hooks/use-payment-proofs.ts`
- `features/orders/components/payment-proof-section.tsx`

**Edited:**
- `backend/config/permissions.php` (orders: +proof_upload/proof_verify/proof_reject)
- `backend/routes/api.php` (append-only: import + 5 routes)
- `frontend/src/features/orders/pages/order-detail-page.tsx`
- `frontend/src/i18n/locales/en/orders.json`, `ar/orders.json`

---

## 14. Certification

**DEFERRED.** This report attests **IMPLEMENTATION COMPLETE / RUNTIME VERIFIED** on the DEV stack only. Formal certification (independent review, full regression gate, security sign-off) is out of scope for this task and remains pending.
