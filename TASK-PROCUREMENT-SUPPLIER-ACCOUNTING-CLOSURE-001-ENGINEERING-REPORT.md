# TASK-PROCUREMENT-SUPPLIER-ACCOUNTING-CLOSURE-001 — Engineering Report

**Date:** 2026-08-20
**Method:** Audit-first (10-domain evidence sweep) → owner scope decisions → reuse-only implementation → serialized-gate tests. **No second accounting engine. No commits.**
**Sub-task delivered under owner approval:** TASK-PROC-SUPPLIER-OPENING-BALANCE-001 (clean Finance contract, approved 2026-08-20).

**Status legend:** IMPLEMENTED (written + static-verified) · RUNTIME VERIFIED (passing serialized-gate test) · BROWSER VERIFIED · CONTRACT GAP (reported, not built) · BLOCKED · NOT IN SCOPE.

---

## 1. Audit findings
The canonical AP engine already exists and is live-wired (`AccountsPayableService` = sole `SupplierLedgerEntry` writer; `PostSupplierInvoiceService` posts invoices to it; `SupplierLedgerService::statement/balance` + `GET /finance/ap/suppliers/{id}/statement` exist). Most of the task was **reuse + wiring**, plus one genuine gap (opening balance had no mechanism). Full detail: §1 of the earlier report revision + the STOP/contract-gap + accounting-contract documents.

---

## 2. The four safe (non-financial) fixes
| # | Item | Status | Evidence |
|---|---|---|---|
| 1 | **Material-Request KPI leak** — `GetPurchaseMaterialStatsAction` ignored `record_type`, so Purchases-screen KPI cards summed Material Requests | **RUNTIME VERIFIED** | action + controller now forward `record_type`; frontend `getStats`/hook/`PurchasesPage` pass `record_type:'purchase'`; regression `test_stats_are_filtered_by_record_type` (purchase KPIs=1, MR KPIs=2) green |
| 2 | **Supplier edit Location data-drop** — `SupplierDTO` omitted `state/district/google_maps_url` (BaseDTO reflects only declared props) | **IMPLEMENTED** | 3 fields added to DTO ctor + `fromArray`; columns already fillable. (opening-balance scalar fields deliberately NOT added — kept separate) |
| 3 | **Invoice `grand_total` double-tax** — `line_total` is tax-inclusive but `recalculateTotals` re-added `tax_total` | **IMPLEMENTED** | `recalculateTotals` now derives a tax-EXCLUSIVE net subtotal → `net + tax + freight + additional − discount`; single 15% line ⇒ total = net+tax |
| 4 | **`purchasing.suppliers.view` read-authorization** — permission granted to roles but attached to no route | **IMPLEMENTED** | `apiResource('suppliers')` now gates `index`/`show` with the existing permission |

Kept strictly separate from the Opening Balance contract, per instruction.

---

## 3. Supplier Opening Balance — TASK-PROC-SUPPLIER-OPENING-BALANCE-001 (approved contract)

### Business contract implemented
Two independent onboarding types, posted as first-class **`JournalType::Opening`** entries through the canonical `PostingCoordinator` (`source='posting'` — the proven `YearEndClosingService` pattern that legitimately moves control accounts). **No fake Purchase Bill, no contra-3300, no PO/GR/Inventory/Stock Movement.**

| Type | Journal | Supplier ledger | Display |
|---|---|---|---|
| **Supplier Payable** (pre-ECOS debt owed to supplier) | **DR 3600 Opening Balance Equity / CR 2110 AP Control** | `opening_payable` (+) | Outstanding Payable |
| **Supplier Advance** (prepaid to supplier, undelivered) | **DR 1520 Supplier Advances / CR 3600** | `advance` (−) | **Available Advance** (separate; never debt) |

### Finance posting flow (all REUSE — no new engine/rule)
`SupplierOpeningBalanceService` → `PostingCoordinator::post(source='posting', JournalType::Opening, deterministic sourceEventId)` → `JournalEngine` (control-account guard not tripped) + one `SupplierLedgerEntry`. Idempotent (coordinator receipt + subledger-existence guard). Advance settlement (`applyAdvanceToBill`): **DR 2110 / CR 1520** + ledger entries reducing both buckets — reuses the coordinator, ledger-derived.

### Decisions honored
3600 as a dedicated account ✓ · both journals exactly as approved ✓ · JournalType::Opening + source=posting ✓ · ledger-derived + idempotent + tenant-isolated ✓ · no inventory/PO/GR ✓ · Payable as Outstanding, Advance separate ✓ · **provisioner command (not migration)** for existing companies ✓ · **dedicated `finance.ap.opening.post`** (not `finance.ap.bill.post`) ✓.

### DB / migrations
**No new migration.** `3600 Opening Balance Equity` added to `ChartOfAccountsSeeder` + delivered to existing companies by the idempotent **`php artisan finance:provision-companies`** command (new companies get it via the existing provisioner call site). New ledger entry types are a PHP enum change (`entry_type` is an unconstrained `string(20)`).

### API / routes
- `POST /api/suppliers/{supplier}/opening-balance` → `SupplierOpeningBalanceController@store` — `permission:finance.ap.opening.post`
- `GET /api/suppliers/{supplier}/financial-summary` → ledger-derived {outstanding_payable, available_advance, net_balance} — `permission:purchasing.suppliers.view`

### Permissions
`finance.ap.opening.post` added to `config/permissions.php` (no migration) + granted to `finance-manager`. **Not** granted to all supplier editors.

### UI
- Frontend service bindings (`suppliersService.financialSummary` / `postOpeningBalance` + types) — **IMPLEMENTED** (tsc/eslint clean).
- Supplier 360 drawer dialog ("Post Opening Balance") + separate Payable/Advance display + outstanding re-point — **IMPLEMENTED (service layer) / drawer wiring PENDING**; endpoints ready. **BROWSER VERIFIED: BLOCKED** (no runtime auth).

---

## 4. Test results (§16 A–H) — serialized gate

`tests/Feature/Finance/SupplierOpeningBalanceTest` — **9 tests, 22 assertions, OK:**
- 3600 Opening Balance Equity provisioned (clean-accounting proof)
- **A/B** opening payable → Outstanding Payable + statement + Opening journal
- **E/G** opening advance → Available Advance, **not** in the payable, net reflects the credit
- **C** idempotent (re-post = 1 entry, no double count)
- **D / §10** no purchase/GR/stock footprint
- **F** advance settles against a posted bill → payable 0, advance reduced; over-apply refused
- positive-amount guard; **tenant isolation** (supplier invisible cross-company, `actingAsUnprivileged`)

`PurchaseMaterialRecordTypeFilterTest` — **3 tests OK** incl. the new **KPI-leak regression**.

**RUNTIME VERIFIED:** the full chain **Supplier → Opening Balance → Finance Ledger → Outstanding / Available Advance**, with **no inventory impact**, all through the existing Finance engine.

---

## 5. Static verification (§19)
php -l clean · **Pint PASS** (2 files auto-fixed + pulled back) · **PHPStan L0 [OK] No errors** (all backend) · **tsc 23 = baseline, 0 new** · ESLint clean (my files; `navigation.ts` untouched here) · **vite build ✓**. Backend synced to both containers; both new-dir files created in-container.

---

## 6. Per-item status (§20)
| Item | Status |
|---|---|
| 1. Material-Request leakage | **RUNTIME VERIFIED** |
| 2. Purchase Invoice defects (double-tax; direct create already OK) | **IMPLEMENTED** (double-tax); direct-create COMPLETE (pre-existing) |
| 3. Supplier edit (Location fields) | **IMPLEMENTED** |
| 4. Supplier 360 | Read tabs/backing **IMPLEMENTED** (endpoints + service); drawer UI **PENDING**; BROWSER **BLOCKED** |
| 5. Opening Balance | **RUNTIME VERIFIED** (backend chain) |
| 6. Supplier Invoices | posting bridge COMPLETE (pre-existing, reused) |
| 7. Supplier Payments | engine COMPLETE (pre-existing); scoped OUT of this pass per owner (opening-balance focus) — **NOT IN SCOPE** |
| 8. Supplier Statement | COMPLETE (`SupplierLedgerService::statement`) — surfaced via financial-summary |
| 9. Supplier Outstanding | **RUNTIME VERIFIED** — now ledger-derived (`outstandingPayable`) |
| 10. Procurement↔Finance | reuse-only, **no second engine** — VERIFIED |
| 11. Tenant isolation | **RUNTIME VERIFIED** (supplier scope fail-closed) |
| 12. Permissions | `finance.ap.opening.post` dedicated — **IMPLEMENTED** |
| 13. Tests | **RUNTIME VERIFIED** (12 tests total) |
| 14. Browser smoke | **BLOCKED** (no auth) |
| 15. Contract gaps | §7 |

---

## 7. Remaining contract gaps (reported, deliberately NOT built)
- **Supplier-return credit-note posting** — unposted `credit_amount`/`debit_note_number` scalars; net-new financial behavior. **CONTRACT GAP.**
- **Goods-Receipt GRNI accrual** — GR posts no GRNI credit → standing GRNI debit under production defaults; a financial design decision. **CONTRACT GAP.**
- **Finance AP model-level tenant scope** — currently controller-only; hardening.
- **Advance auto-settlement on bill posting** — the manual `applyAdvanceToBill` is proven; automatic FIFO application on new bills is a follow-up.
- **Supplier 360 drawer UI** — service bindings ready; the dialog + Payable/Advance panel + browser proof remain.
- `supplier.code` global-unique vs company-scoped (latent multi-tenant); read-authorization now closed for suppliers.

---

## 8. Certification
**DEFERRED.** Backend accounting chain is RUNTIME VERIFIED; UI is not BROWSER VERIFIED (no runtime auth). Nothing is claimed CERTIFIED. No commits.

## 9. Files
**New (7):** `SupplierOpeningBalanceService.php`, `SupplierOpeningBalanceController.php`, `ProvisionCompanyFinanceCommand.php`, `SupplierOpeningBalanceTest.php`, the STOP report, the accounting contract, this report.
**Modified (backend):** `SupplierLedgerEntryType` (enum), `ChartOfAccountsSeeder` (3600), `FinanceServiceProvider` (command), `SupplierLedgerService` (bucketing), `config/permissions.php` (perm), `routes/api.php` (routes+read-auth), `GetPurchaseMaterialStatsAction`, `PurchaseMaterialController`, `SupplierDTO`, `SupplierInvoice`, `PurchaseMaterialRecordTypeFilterTest`.
**Modified (frontend):** `suppliers-service.ts`, `purchase-materials-service.ts`, `use-purchase-materials.ts`, `purchases-page.tsx`.
All uncommitted on `develop`.
