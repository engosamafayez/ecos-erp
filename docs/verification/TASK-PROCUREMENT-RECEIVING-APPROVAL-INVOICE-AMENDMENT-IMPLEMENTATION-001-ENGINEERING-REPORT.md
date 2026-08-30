# TASK-PROCUREMENT-RECEIVING-APPROVAL-INVOICE-AMENDMENT-IMPLEMENTATION-001 — Engineering Report

**Date:** 2026-08-17 · **Branch:** `develop` · **Runtime:** MySQL 8.4.10 / PHP 8.4.24

**OUTCOME: STOP — PART 26 STOP CONDITIONS #1 AND #2**
**FINAL VERDICT: NOT CERTIFIED**
**Production changes made: NONE.** No code, migration, schema, API, UI or data was modified.

The blocker is **narrow and precisely located**: the *inventory + FIFO* half of this contract is
already achievable with existing certified architecture, and the review/edit/approval layer is
ordinary additive work. What cannot be built is the **supplier payable leg** — final posting cannot
atomically establish a payable because no configured, callable path exists from an approved
Purchasing invoice to the Finance AP subledger.

Per PART 26 I did not work around it and did not invent accounting architecture.

---

## 1. Existing architecture audit

Inspected before any design, per PART 1. Nothing was discarded and no certified behaviour was touched.

| Component | State | Verdict |
|---|---|---|
| `GoodsReceipt` model + `GoodsReceiptStatus` | States are **`Draft`, `Posted`** only | Extendable, but `Posted` currently means *inventory posted* — see §6 |
| `PostGoodsReceiptAction` | Posts inventory, ledger, FIFO, PO `received_qty`; receipt row `lockForUpdate` (D-INB-03) | Certified, reusable |
| `SupplierInvoice` + `PostSupplierInvoiceService` | Own status machine (`Validated` → `AutoProcessing` → `Posted`/`Failed`); posts inventory via canonical actions; shared inbound lock (C-1) | Certified, reusable |
| `GoodsInwardAuthority` + `companies.goods_inward_mode` | Decides which document posts inventory | Certified — **key enabler, see §3** |
| `ReceiveStockAction` / `CreateReceiptLayersAction` | Canonical inventory + ledger + FIFO | Certified, reusable |
| `app/Core/Documents/DocumentService` | Polymorphic (`subject_type`/`subject_id`), company-scoped, `attach()/getFor()/delete()/getDownloadUrl()` | **Ready** for line-level photo evidence |
| `config_audit_log` + `ConfigAuditService` | old→new, actor, reason, timestamp | **Ready** for edit audit |
| `AccountsPayableService` | `createDocument()` + `postDocument()` — creates `SupplierBill`, journal via `PostingCoordinator`, and `SupplierLedgerEntry` | **Exists but not reachable — see §12** |
| `SupplierLedgerService` | `balance()`, `history()`, `statement()` | Ready — but has no data to read |
| Finance Integration bridge | `BusinessEventType`, `AccountRoleResolver`, `PostingRuleRegistry` | **GL journals only — see §12** |
| Approval infrastructure | No generic engine; ECOS uses per-domain state machines | Convention, not a blocker |

## 2. Historical authority

This task supersedes `TASK-PROCUREMENT-RECEIVING-INSPECTION-INVOICE-AMENDMENT-001`, and in doing so
**resolves two of its three recorded gaps by design**:

| Prior gap | Status under this contract |
|---|---|
| **G-B** — no reversal path for a posted Goods Receipt | **DISSOLVED.** PART 2 removes rejection entirely; the reviewer edits and approves, so nothing ever needs reversing |
| **G-C** — no retroactive FIFO cost correction | **DISSOLVED.** PART 7/15 post inventory only after invoice approval, at the final approved cost, so no correction is ever required |
| **G-A** — no Purchasing → Supplier Account path | **STILL OPEN, and now the sole blocker** (§12) |

That is real progress: the redesign removed two architectural obstacles rather than papering over them.

## 3. Receiving workflow — the inventory timing already exists

The contract requires (rules 9, 18): **no inventory mutation when receiving is entered**, and none at
receiving approval — only at final posting.

**ADR-011 Mode 3 already produces exactly that timing.** Under
`companies.goods_inward_mode = supplier_invoice`, a Goods Receipt records physical receiving and posts
**zero** inventory, zero ledger rows and zero FIFO layers, while the Supplier Invoice performs the
inventory posting. This is not theory — it is proven by tests executed earlier today
(`InboundCrossDocumentConcurrencyTest::test_g/test_i`, `GoodsReceiptConcurrencyTest::test_g`).

So the required sequencing — *receipt records, invoice posts at final approved values* — is expressible
with certified architecture and needs **no** change to `GoodsInwardAuthority` (PART 31 respected).

**The one caveat, stated plainly:** under Mode 1 (`goods_receipt`, the platform default) the receipt
posts inventory immediately, which contradicts rules 9/18. Applying this contract company-wide would
therefore mean either operating those companies in Mode 3 or introducing a third mode — a Goods Inward
Authority decision, which PART 31 places out of bounds for me. **STOP condition #3 is not triggered**
(nothing here would duplicate inventory), but the mode dependency is a ruling the owner must make.

## 4. Receiving edit contract · 5. Photo evidence · 6. Receiving approval

**Not implemented** — these depend on the blocked chain terminating in a payable, so building them
would produce a workflow whose final step cannot complete. Design is settled and needs no invention:

- **Edit + audit (PART 3, 21):** `config_audit_log` already records old→new, actor, reason and
  timestamp, and is proven end-to-end (I recorded live entries through the browser earlier today).
  Mandatory-reason enforcement is request validation, not new architecture.
- **Photo evidence (PART 4, 22):** `DocumentService.attach()` with `subject_type = GoodsReceiptLine`,
  company-scoped, survives edits and approvals because nothing in the quantity path touches documents.
  `goods_receipt_lines.weight_photo_path` and `inventory_count_line_attachments` are existing
  line-level precedents. **No second document store is needed.**
- **Approval states (PART 5, 20):** `GoodsReceiptStatus` currently has only `Draft`/`Posted`, where
  `Posted` means *inventory posted*. The workflow needs an approval state that is explicitly **not**
  an inventory event. That separation is additive and safe **under Mode 3**, where `Posted` already
  implies no inventory. **STOP condition #5 is not triggered** — the model can be extended without
  contradictory states, provided §3's mode ruling lands first.

## 7. Invoice amendment · 8. Invoice approval

**Not implemented.** Quantity amendment, price amendment and both-together (PART 8 cases A/B/C) are
all straightforward against `SupplierInvoiceLine` with `config_audit_log` capturing original → new →
reason. PART 9 (no deferred remaining quantity) is a policy the amendment simply honours — the invoice
closes at the approved quantity and a later delivery starts a new cycle; no carry-over structure is
required, so nothing needs building to satisfy it.

These are blocked only because approving the invoice must trigger a final posting that includes a
payable, which cannot execute.

## 9. Final posting · 10. Inventory · 11. FIFO

Two of the three legs are ready:

| Leg | Mechanism | Status |
|---|---|---|
| Inventory quantity + stock ledger | `ReceiveStockAction` (locks the inventory row) | **Ready** |
| FIFO layer at final approved cost | `CreateReceiptLayersAction` | **Ready** — and because posting happens after approval, the layer is created once at the final cost, exactly as PART 15 requires |
| **Supplier payable** | `AccountsPayableService` | **BLOCKED — §12** |

Atomicity itself is not the problem: both ready legs already run inside one `DB::transaction` in
`PostSupplierInvoiceService`, with the C-1 shared canonical inbound lock in front of them, so adding a
third leg to that same transaction would be mechanically simple. **STOP condition #2 is triggered
solely because the third leg cannot execute at all.**

## 12. Finance integration — THE BLOCKER

Traced exactly as PART 12 requires, and the result is unambiguous.

**(a) The event bridge cannot produce a payable.** `Modules/Finance/Integration` contains **zero**
references to `SupplierBill`, `SupplierLedgerEntry` or `AccountsPayableService`. It maps business
events to **general-ledger journals only**. `BusinessEventType::PurchaseMaterials`
(`procurement.purchase_materials`) exists and is annotated *"supplier invoice (accrual clear + VAT)"*,
so a GL rule is defined — but firing it would never move the supplier balance.

**(b) Nothing emits those events anyway.** A repo-wide search for `BusinessEventType::` outside
`Modules/Finance` and the test suite returns **nothing**. `PostSupplierInvoiceService` emits no event
of any kind. Purchasing and Finance are entirely disconnected.

**(c) The AP subledger is unconfigured and currently non-functional.** `SupplierLedgerEntry` is written
by exactly two call sites, both inside `AccountsPayableService`. Its `postDocument()` resolves the AP
control account first — and on the live dev runtime that call **throws**:

```
Modules\Finance\Ledger\Domain\Exceptions\FinanceException —
"No AP control account is configured. Mark a chart-of-accounts node as the AP control
 before posting subledger documents."
```

State: **300** chart-of-accounts rows exist, but **0** carry `is_control = true` with
`control_subledger = 'ap'`; `finance_supplier_bills` = **0**; `finance_supplier_ledger_entries` = **0**.
The AP subledger has never been used.

**(d) The per-line account mapping has no canonical source.** `createDocument()` requires an
`expense_account_id` (int, GL account) on **every** line. Purchasing has no mapping from a purchased
product to a GL account, and under perpetual inventory the debit for stock purchases is an **inventory
asset**, not an expense — so even the field's name encodes an accounting decision that has not been made
for this flow.

**(e) Double-posting risk.** `postDocument()` itself requests a journal through `PostingCoordinator`.
If an approved invoice both created a `SupplierBill` *and* emitted `procurement.purchase_materials`,
the same invoice would post to the GL twice. Choosing between those routes is an accounting ruling.

### Why this is a STOP and not a small addition

PART 12 forbids writing `SupplierLedgerEntry` from Purchasing (also STOP #9), and PART 26 #1 triggers
when *"Finance has no canonical way to create/update the SupplierBill from the approved Purchasing
invoice."* Calling `AccountsPayableService` would be the correct shape, but it cannot run without
decisions that are the owner's to make, not mine to invent:

| # | Decision required | Nature |
|---|---|---|
| **F-1** | Which chart-of-accounts node is the **AP control account**? | Finance configuration |
| **F-2** | Which GL account does a purchased **inventory** line debit (inventory asset vs expense)? Is there a per-product/category mapping, or one default per company? | Accounting policy |
| **F-3** | Does the approved invoice reach Finance via **`AccountsPayableService`** (payable + journal), via the **event bridge** (journal only), or both with explicit de-duplication? | Integration contract |
| **F-4** | Does the existing `inventory.goods_receipt` GL rule also fire, and how does it reconcile with the invoice posting so inventory is not booked twice in the GL? | Accounting policy |
| **F-5** | Tax: `createDocument()` takes `tax_code_id` per line — which `TaxCode` maps to a Purchasing invoice's VAT? | Finance configuration |

**Minimum architectural change to unblock:** designate an AP control account (F-1); define the
purchase-line → GL account mapping (F-2), ideally reusing the existing `AccountRole` /
`AccountRoleResolver` mechanism rather than a new one; and add **one** integration point that calls
`AccountsPayableService::createDocument()` + `postDocument()` from the approved-invoice posting path
(F-3), with F-4/F-5 settled. Affected files would be a new Purchasing→Finance integration service plus
`PostSupplierInvoiceService`; **no change to `AccountsPayableService` itself** and no direct
`SupplierLedgerEntry` write.

## 13. Supplier payable · 14. Supplier balance

**Not implemented — blocked by §12.** `SupplierLedgerService::balance()` and `statement()` are ready
and correct; they simply have no entries to read, because nothing has ever created a `SupplierBill`.

## 15. Audit trail

**Not implemented**, design settled: `config_audit_log` (module/category/action/old_value/new_value/
reason/actor/timestamp) already satisfies PART 21 and is proven in live use. No second audit mechanism
is needed.

## 16. Idempotency · 17. Concurrency

**Not implemented**, but the guarantees this contract needs already exist and were verified today:
`PostGoodsReceiptAction` locks the receipt row and re-asserts its guards inside the transaction
(D-INB-03); `PostSupplierInvoiceService` locks the **same canonical inbound row** and re-reads its own
status under that lock (C-1); `InboundPostingGuard` provides ledger-reference idempotency. A final
posting added to that transaction would inherit all of it — **no new identity heuristic is required**,
exactly as PART 16 demands.

## 18. Tenant isolation

**Unchanged and certified.** `GoodsReceipt` and `SupplierInvoice` carry the `tenant` global scope;
`documents.company_id` scopes evidence; `SupplierBill` and `SupplierLedgerEntry` are company-scoped.
HTTP-level cross-tenant rejection with zero mutations was proven earlier today. **STOP condition #7 is
not triggered.**

## 19. UI · 20. API changes

**None.** No frontend file and no route was added or modified. Building the receiving-review and
invoice-review surfaces (edit + mandatory reason + approve, with **no Reject action**, per PARTS 19/20)
is ordinary work once the chain can terminate — but shipping screens whose final action cannot complete
would be the "UI exists therefore done" failure the task's FINAL RULE explicitly prohibits.

## 21. Test matrix · 22. Runtime proof

**Not executed.** No production change was made, so there is nothing to test. PART 28 cases A–F all
terminate in inventory/FIFO **and supplier payable**; cases A, B, C and E cannot be proven while the
payable leg cannot execute, and proving only the inventory half would misrepresent the contract as
satisfied.

## 23. Regression

**Nothing could regress — zero production changes.** The current verified state, all executed earlier
today against this same deployed code, stands:

| Suite | Result |
|---|---|
| `SupplierReturnValuationTest` (certified) | **OK — 20 tests, 56 assertions** |
| `InboundOwnershipContractTest` (certified) | **OK — 15 tests, 49 assertions** |
| `InboundCrossDocumentConcurrencyTest` | **OK — 11 tests, 62 assertions** |
| `GoodsReceiptConcurrencyTest` | **OK — 8 tests, 41 assertions** |
| `GoodsInwardModeConfigurationTest` | **OK — 12 tests, 69 assertions** |
| Frontend configuration card | **11 passed** |

**STOP condition #8 is not triggered** — Supplier Return is untouched and green.

## 24. Deployment parity

**Nothing deployed.** HOST == RUNNER == APP is unchanged from the previously verified state, and the
frontend bundle remains the one verified over HTTP during the Goods Inward certification.

## 25. Remaining gaps

| # | Gap | Blocks | Decision |
|---|---|---|---|
| **G-A** | No configured, callable path from an approved Purchasing invoice to the Finance AP subledger: bridge is GL-only, no emitters exist, **AP control account unconfigured (resolver throws)**, no purchase-line → GL account mapping, and a double-GL-posting risk between the two candidate routes | PARTS 11–15, 20; STOP #1, #2 | **F-1 … F-5** (§12) |
| **G-D** | Rules 9/18 (no inventory at receiving) hold under **Mode 3** but contradict **Mode 1**, the platform default | Company-wide rollout | Goods Inward Authority ruling — out of bounds under PART 31 |

STOP conditions **#3, #4, #5, #7, #8, #9 are NOT triggered**; **#6 is dissolved** by this contract's own
design (§2). Only **#1 and #2** are triggered, and both reduce to G-A.

## 26. Final certification

**NOT CERTIFIED.**

The task's FINAL RULE is explicit: the feature is certified only when the complete runtime chain works
through to Inventory + FIFO + Supplier Payable, and *"if any part of that chain is not backed by a
canonical existing architecture, STOP and report it."* The payable leg is not backed by a functioning
canonical path — `AccountsPayableService::postDocument()` throws before it can post anything in this
environment.

### What is genuinely ready

Two-thirds of the chain needs no invention: **inventory and FIFO timing already work via Mode 3**, and
the review/edit/approval layer, line-level photo evidence and the audit trail all map onto existing
certified infrastructure (`DocumentService`, `config_audit_log`, the receipt/invoice locks). Once
**F-1 and F-2** are ruled — designate the AP control account and define the purchase-line GL mapping —
the remaining work is a single integration point plus the workflow states and screens, with no change
to `AccountsPayableService` and no direct `SupplierLedgerEntry` write from Purchasing.

I have deliberately made no production changes pending those rulings. No certified contract was
reopened or modified, and no further Procurement work was started.
