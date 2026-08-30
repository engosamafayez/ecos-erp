# TASK-PROC-SUPPLIER-OPENING-BALANCE-001 — STOP / CONTRACT-GAP REPORT

**Date:** 2026-08-20
**Discipline:** Audit-only, per your directive ("ابدأ بالـAUDIT فقط… إذا ظهر Contract Gap، توقف عنده ولا تخترع حلًا"). No code written. No migration. No CoA change. No commits.
**Verdict:** Type A (Payable) is reachable by reuse **only via two accounting-policy compromises**; Type B (Advance) is a genuine **contract gap**. Both require your decision before implementation.

---

## 1. WHAT EXISTS (reusable, no change)

| Capability | Artifact |
|---|---|
| AP control account (credit target for a payable) | `2110 Trade Payables`, `is_control`, `control_subledger='ap'` — `ChartOfAccountsSeeder.php:109`; resolved by `ControlAccountResolver::payable()` |
| AP subledger posting (the ONLY sanctioned way to move AP control) | `AccountsPayableService::createDocument`→`postDocument` — `AccountsPayableService.php:47,114-156` (posts `source='posting'`, `journalType=Purchase`, so it is **not** a manual journal and legitimately credits the control account) |
| Supplier ledger + ledger-derived outstanding (the SSOT) | `SupplierLedgerService::balance()` = Σ `SupplierLedgerEntry.amount`; `statement()` — `SupplierLedgerService.php:18-24,76-87` |
| Supplier-advance asset account (correct GL home for an advance) | `1520 Supplier Advances` — asset, debit-normal, postable, non-control — `ChartOfAccountsSeeder.php:94` |
| On-account credit + settlement engine | unallocated `SupplierPayment` + `AllocationEngine::autoAllocatePayment` — `AllocationEngine.php:163` |
| Generic manual journal (maker/checker) | `POST /journals` → `PATCH /journals/{uuid}/approve` — `JournalController.php:49-79` |
| Draft→Post lifecycle + idempotency | `DocumentStatus` (posted docs frozen) + `PostingCoordinator` receipt keyed on `source_event_id` + per-company unique bill number |
| Permissions (seeded, granted, maker/checker SoD) | `finance.ap.bill.create` / `finance.ap.bill.post` — `config/permissions.php` |
| Fiscal-period gate | `JournalEngine.php:295-304` (an OPEN period covering the cutover date is required) |

---

## 2. WHAT IS MISSING

1. **No dedicated Opening-Balance-Equity / Opening-Balance-Control / Suspense account.** `ControlAccountResolver` resolves only `ap`/`ar`; `AccountRoleResolver`'s ~27 roles include **no** `opening`/`equity`/`suspense` role; no equity account (3100–3500) carries a control_subledger or role. → An opening entry's **contra leg has no purpose-built home**; it must repurpose an existing equity leaf (e.g. `3300 Retained Earnings`).
2. **No "Opening Balance" document type or ledger-entry type.** `SupplierDocumentType` = invoice/credit_note/…; `SupplierLedgerEntryType` = Bill/CreditNote/DebitNote/**Payment** — **no `Advance`, no `Opening`** (`SupplierLedgerEntryType.php:15-18`). → A Type-A opening AP can only ride an ordinary **Bill** (`journalType=Purchase`); a Type-B advance has **no ledger representation at all**.
3. **No posting path debits `1520` against a supplier.** `AccountsPayableService::postPayment` **hardcodes** the debit leg to AP control 2110 (`:225,232`); there is no parameter to redirect it to 1520. `1520` is effectively orphaned for supplier-attributed advances.

---

## 3. WHY IT CANNOT BE DONE SAFELY WITHOUT YOUR APPROVAL

**Type A (Payable):** reuse is *possible* but not *clean*:
- The contra leg would be **3300 Retained Earnings** (a policy choice — you said "don't make the user pick GL accounts"; someone must decide the opening offset, and there is no dedicated opening-equity account).
- The opening AP would be represented as a **Purchase bill** (`journalType=Purchase`), so it would appear in the **purchase journal** and could inflate purchase analytics. Adding a distinct `Opening` document type to avoid this is a **forbidden contract change** (enum + controller allow-list).
- Both hit your explicit STOP condition: *"لا يوجد Opening Balance control/equity contract مناسب"*.

**Type B (Advance):** the three required clauses cannot co-exist under reuse:

| Path | 1520 asset (not debt)? | Ledger-derived? | Settleable? |
|---|---|---|---|
| Manual journal `DR 1520 / CR 3300` | ✅ | ❌ (writes no `SupplierLedgerEntry`) | ❌ |
| Unallocated `SupplierPayment` | ❌ (debits AP control 2110 = AP-as-debt) | ✅ | ✅ |

Delivering all three needs: add `SupplierLedgerEntryType::Advance` (enum — migration-free but a contract change) **+** edit `AccountsPayableService::postPayment/postDocument` to accept a non-control counter account **+** extend `AllocationEngine` to consume Advance entries. These **alter existing Finance posting contracts** — outside the reuse-only mandate and unsafe to do unilaterally (they change how every supplier ledger row and allocation is interpreted).

---

## 4. THE DECISION REQUIRED FROM YOU

### Decision 1 — Type A (Supplier Payable opening balance)
- **A1 (reuse now):** post the opening AP as a supplier **Bill** (number prefix `OB-{supplierId}`, note-tagged), contra = **3300 Retained Earnings**, ledger-derived outstanding. Ships immediately, zero forbidden change. Accepts the purchase-journal representation caveat.
- **A2 (clean accounting, needs your approval to deviate):** first add a dedicated **Opening-Balance-Equity account** (CoA change) **and** an `Opening` document type (enum change) so opening balances never touch the purchase journal. Cleaner, but requires the CoA/contract changes your rules currently forbid.
- **A3 (hold):** report only; build nothing for Type A.

### Decision 2 — Type B (Supplier Advance)
- **B1 (recommended — additive, migration-free contract extension):** authorize `AccountsPayableService::postAdvance` debiting **1520** + a new `SupplierLedgerEntryType::Advance` + `AllocationEngine` support. No migration, no CoA change, no new posting rule. Satisfies all three clauses (asset + ledger-derived + settleable) and the Payable-vs-Advance distinction.
- **B2 (pure reuse, breaks a clause):** book the advance as an AP on-account credit (unallocated payment) — settleable + ledger-visible, but shown as **reduced payable / AP-as-debt**, not a 1520 asset.
- **B3 (pure reuse, breaks a clause):** GL-only `DR 1520 / CR 3300` manual journal — clean prepaid asset, but **not** on `SupplierLedgerService` and **not** settleable.
- **B4 (defer):** report only; build nothing for Type B.

### Independent of the above (safe, reuse-only — I will proceed on these once you confirm)
- **Re-point Supplier Outstanding** on the grid + Supplier 360 to `SupplierLedgerService::balance()` (the ledger SSOT), removing the hand-entered `goods_receipts.paid_amount` as a balance source. **GO** — no forbidden change. (Note: for the Payable half this is clean; the Payable-vs-Advance *split* depends on Decision 2.)
- Keep `suppliers.opening_balance_amount` as **write-once onboarding input only** — never summed or displayed alongside the ledger balance (that would double-count every supplier). It is consumed once by the posting, then the ledger is the only truth.

**My recommendation:** **A1 + B1** — ship Type A now via reuse (accepting the OB-bill representation) and authorize the small additive `Advance` extension for Type B (it is migration-free and is the only way to honor your "asset, not debt, ledger-derived, settleable" contract). But this is your accounting call — I will not pick it for you.
