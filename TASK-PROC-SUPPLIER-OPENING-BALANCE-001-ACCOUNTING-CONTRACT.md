# TASK-PROC-SUPPLIER-OPENING-BALANCE-001 — CLEAN FINANCE ACCOUNTING CONTRACT (for approval)

**Date:** 2026-08-20
**Status:** DESIGN / CONTRACT — awaiting explicit approval. **No code written. No migration run. No commits.**
**Decisions honored:** Type A → **A2 (clean accounting — no fake bill, no contra-3300)**; Type B → **B1 (additive Advance extension)**.
**Scope boundary:** a Supplier/Finance onboarding contract, **completely separate from Preparation OS**. Creates **no** Purchase, Purchase Bill (invoice), Goods Receipt, Inventory, Stock Movement, or Purchase history.

> Per your directive, this document defines the contract *before* implementation. I will not write code until you approve §9.

---

## 1. Principles
1. The **Finance ledger is the single source of truth** after posting. `suppliers.opening_balance_amount/opening_balance_type` is **write-once onboarding input only** — consumed once by posting, then never displayed as a balance (else double-counting).
2. **Two independent business types**, never conflated or netted in display:
   - **Supplier Payable Opening** — money the company already owes the supplier from before ECOS (an opening **liability**).
   - **Supplier Advance Opening** — money already paid to the supplier for undelivered future supply (an opening **prepaid asset / available credit**).
3. **No fake Purchase Bill and no contra-3300 hack** (you rejected A1). Both types post as first-class **`JournalType::Opening`** entries.
4. **Reuse the proven opening-balance pattern** — the platform already posts opening balances into control accounts via `PostingCoordinator` with `source='posting'` (precedent: `YearEndClosingService::postCarryForward`, `YearEndClosingService.php:199-254`). This is exactly how an opening AP credit is booked *cleanly* without the manual-journal control guard (`JournalEngine.php:272-277`) and without a bill.

---

## 2. Accounting treatment (exact journals)

**Universal counter-account:** a new **`3600 Opening Balance Equity`** (equity, credit-normal, postable, non-control) — the standard ERP cut-over offset. Added to `ChartOfAccountsSeeder` + `CompanyFinanceProvisioner`; both opening types post against it. (No existing equity leaf is repurposed.)

| Type | Journal (`JournalType::Opening`, `source='posting'`) | Supplier subledger entry |
|---|---|---|
| **A — Payable** | **DR 3600 Opening Balance Equity** / **CR 2110 AP Control** = amount | `SupplierLedgerEntry`: `entry_type='opening_payable'`, `amount = +amount` |
| **B — Advance** | **DR 1520 Supplier Advances** / **CR 3600 Opening Balance Equity** = amount | `SupplierLedgerEntry`: `entry_type='advance'`, `amount = −amount` |

- **2110 Trade Payables** (`is_control`, `control_subledger='ap'`, `ChartOfAccountsSeeder.php:109`) — resolved via `ControlAccountResolver::payable()`. Credited for Type A exactly as a payable requires.
- **1520 Supplier Advances** (asset, debit-normal, postable, non-control, `ChartOfAccountsSeeder.php:94`) — the correct GL home for a prepaid advance. Type B books here, **never** AP-as-debt.
- Type B carries the supplier dimension on the subledger entry, so it is **per-supplier tracked** (unlike a bare manual journal) and **settleable** (§8).

---

## 3. Document / type identity
- **Reuse `JournalType::Opening`** (`JournalType.php:19`) — so neither type ever appears in the purchase journal or purchase analytics.
- **New `SupplierLedgerEntryType` cases** (additive): `OpeningPayable = 'opening_payable'` (sign +1), `Advance = 'advance'` (sign −1). `entry_type` is a bare `string(20)` with **no DB CHECK** (`…create_finance_supplier_ledger_entries_table.php:30`) → **PHP enum change only, NO migration.**
- **No SupplierBill / SupplierDocumentType is used** for either type — they are not invoice-shaped documents.
- Posting via a new thin `SupplierOpeningBalanceService` that calls the existing `PostingCoordinator::post(sourceModule, sourceEventId, request, null, actorId)` — glue code, not a new posting engine or rule.

---

## 4. Tenant / company scope
Every read/write is company-scoped (`Supplier`/`SupplierLedgerEntry` already carry `company_id` and the certified tenant scope, `SupplierInvoice.php:141-168` pattern). A supplier outside the actor's company fails closed (404). Cross-tenant opening-balance posting is refused.

## 5. Authorization
Posting an opening balance is a Finance posting act → reuse an existing Finance permission. **Recommended:** `finance.ap.bill.post` (already seeded/granted, maker/checker SoD) **or** a dedicated `finance.ap.opening.post` if you prefer separation. No permission bypass; entering a draft ≠ posting.

## 6. Idempotency
Deterministic `sourceEventId` per supplier+type: `supplier_opening_payable:{supplierId}` / `supplier_opening_advance:{supplierId}`. `PostingCoordinator` records a receipt keyed on `(source_module, source_event_id)`, so a second post is a **no-op** — no double count. "Posted" state is **derived** from the existence of the opening `SupplierLedgerEntry` (no new column, no migration).

## 7. Draft → Review → Post + audit trail
- **Draft/input:** the `opening_balance_amount` + `opening_balance_type` (+ date, reference, notes) captured on the supplier. **No accounting effect.**
- **Post:** the `SupplierOpeningBalanceService` posts the journal + subledger entry (idempotent). After posting, the balance is live in the ledger; the number is **not** directly editable — corrections use the **existing** Finance reversal/adjustment mechanism (no new adjustment contract).
- **Audit:** the `SupplierLedgerEntry` + `JournalEntry` carry supplier, type, amount, date, actor, posted timestamp, and journal reference — the existing audit surface. No new audit system.

## 8. Display (Supplier 360) + settlement
- **`SupplierLedgerService`** buckets the balance into two distinct figures (never one mixed label): **Outstanding Payable** (`bill` + `opening_payable` + `debit_note` − `credit_note` − `payment` − allocated advances) and **Supplier Advance (available)** (unallocated `advance`).
- **Outstanding re-point:** the supplier grid + 360 "outstanding" widget read `SupplierLedgerService::balance()` (the ledger SSOT), replacing the hand-entered `goods_receipts.paid_amount` source. The scalar disappears from all balance surfaces.
- **Settlement:**
  - *Payable* is settled by **Pay Supplier** (existing `AccountsPayableService::createPayment→post` + `AllocationEngine`) — the opening_payable entry is allocatable exactly like a bill.
  - *Advance* is settled by extending **`AllocationEngine`** to treat `advance` entries as available credits applied FIFO to future posted supplier bills (the same shape as an unallocated payment, but sourced from 1520). No new allocation/reconciliation engine.

---

## 9. ⚠️ APPROVAL REQUESTED — the exact additive changes

| # | Change | Migration? | Notes |
|---|---|---|---|
| 1 | Add **`3600 Opening Balance Equity`** to `ChartOfAccountsSeeder` + `CompanyFinanceProvisioner` | seeder/provisioner change; **existing companies need a one-time backfill** (a data migration **or** an idempotent provisioner command) | This is the ONE item that touches the CoA + likely needs a migration/command. **A2 authorizes the account; I need your explicit OK on the backfill mechanism.** |
| 2 | Add `SupplierLedgerEntryType::OpeningPayable` + `Advance` | **No** (PHP enum; `entry_type` unconstrained `string(20)`) | Additive enum only |
| 3 | New `SupplierOpeningBalanceService` (`postOpeningPayable`, `postAdvance`) | No | Thin glue over `PostingCoordinator` |
| 4 | Extend `AllocationEngine` to settle `advance` entries | No | Additive method/branch |
| 5 | `SupplierLedgerService` bucketing (Payable vs Advance) | No | Read-model split |
| 6 | Supplier 360: "Post Opening Balance" (Draft→Post) + separate Payable/Advance display + outstanding re-point | No | Frontend |
| 7 | Permission choice: reuse `finance.ap.bill.post` **or** dedicated `finance.ap.opening.post` | No | Your call |

**Precondition (operational, not code):** an **OPEN `FiscalPeriod`** covering the opening date must exist (`JournalEngine.php:295-304`; via `FiscalCalendarService`). There is no system-wide go-live date config — the opening date must land in an open period.

---

## 10. Test matrix (owner A–H + B1) — to be delivered on implementation
A) Payable opening → CR AP control 2110, appears in Outstanding Payable · B) in statement + GL with journal ref · C) Draft→Post, idempotent (re-post = no double count) · D) no inventory/GR/PO · E) Advance → DR 1520 asset, shown as Advance not debt · F) Advance settleable later via AllocationEngine · G) outstanding ledger-derived, Payable vs Advance distinct · H) scalar not double-counted · + tenant isolation · + authorization · + Advance not shown as a Purchase Bill · + no inventory impact on Advance creation.

---

## 11. What I will NOT do (hard "no")
A fake Purchase Bill or contra-3300 for either type; `journalType=Purchase` for an opening entry; a stored/mutated `supplier_balance`; persisting `opening_balance_amount` as a live displayed balance; a second `SupplierLedgerEntry` writer that hardcodes AP control for advances; auto-creating GL accounts at runtime; any inventory/GR/PO/stock effect; touching Order/Payment/Preparation; any ADR change; any commit.

---

### Decision needed from you
**Approve §9 (especially item 1 — the `3600 Opening Balance Equity` account + its backfill mechanism for existing companies) and the permission choice (item 7).** On approval I implement the additive extension exactly as specified above and run the §10 tests through the serialized gate. If you want item 1 handled without a migration (idempotent provisioner command only), say so and I'll scope it that way.
