# TASK-ECOS-PROCUREMENT-SUPPLIER-INVOICE-FINAL-LIFECYCLE-020 — Report

## Status

**PROCUREMENT FINAL SOURCE CLOSURE COMPLETE — READY FOR CANONICAL INTEGRATION**

All 30 sections addressed. The core fix is small and precise: commercial approval
(`validate()`) was calling the exact same receipt-anchor gate as final posting (`post()`),
including a guard that requires the physical Goods Receipt to already be **Posted** — which by
definition can't be true yet the moment an invoice-first invoice is approved. That premature
check is removed from `validate()`; it remains fully intact at `post()`, unchanged. On top of
that: the manual Goods Receipt Line picker is removed from the create/edit UX, a presentation-
only `display_status`/`available_actions` pair replaces hardcoded status-string checks
throughout the frontend, and a new, narrowly-scoped Warehouse Full Rejection action was built.
One additional, more severe bug was found and fixed while reconciling this lifecycle — see
**Legacy Compatibility** below.

## Base / Head

- **Base:** `565ca928` (Task 019 checkpoint, same branch/worktree)
- **Worktree:** `E:\ECOS\_procurement-supplier-master-returns-018` (not yet re-committed — see Final Checkpoint)
- **Branch:** `task/procurement-supplier-master-returns-018`

## Changed Files

17 modified, 4 new.

Backend (`backend/Modules/Purchasing/SupplierInvoices/`):
- `Domain/Enums/SupplierInvoiceStatus.php` — `canEdit()`/`canValidate()`/`canDelete()` (extracted
  from inline controller checks), `displayBucket(?string $receivingStatus)`
- `Presentation/Http/Controllers/SupplierInvoiceController.php` — `validate()` simplified (the
  fix), `available_actions`/`display_status` wiring in `index()`/`show()`, new `rejectReceiving()`
  action, the `syncLines()`/receipt-FK ordering fix (see Legacy Compatibility)
- `Presentation/Http/Resources/SupplierInvoiceResource.php` — `available_actions`
- `Application/Services/InvoiceReceivingLinkService.php` — new `canRewriteLines()` /
  `unlinkBeforeLineRewrite()` (the ordering fix)
- `Application/Services/RejectInvoiceReceivingService.php` (new) — Warehouse Full Rejection
- `Presentation/Http/Requests/RejectInvoiceReceivingRequest.php` (new)
- `backend/routes/api.php` — `POST /supplier-invoices/{id}/reject-receiving`
- `backend/tests/Feature/Purchasing/SupplierInvoiceLifecycleReconciliationTest.php` (new, 16 tests)

Frontend (`frontend/src/features/supplier-invoices/`):
- `components/invoice-line-calc.ts` — `EMPTY_LINE` defaults (§1/§2)
- `components/invoice-line-editor.tsx` — Goods Receipt Line selector removed, inline blank/zero-
  qty warning added (both desktop and mobile layouts)
- `components/supplier-invoice-editor.tsx` — `validateForm()` no longer silently drops a blank-
  qty line among otherwise-valid ones; `InvoiceLineEditor` call site slimmed
- `components/supplier-invoice-status-badge.tsx` (new) — `display_status` + "Next: …" hint
- `pages/supplier-invoices-page.tsx` — badge/next-action wired in, row action menu and drawer
  buttons driven by `available_actions` instead of hardcoded status strings, Reject Receiving
  dialog added
- `types/supplier-invoice.ts`, `services/supplier-invoices-service.ts`,
  `hooks/use-supplier-invoices.ts` — new types/fields, `rejectReceiving` service call + hook
- `i18n/locales/{en,ar}/supplier-invoices.json` — 278 keys each, exact EN/AR parity
- 3 test files updated (`invoice-line-editor.test.tsx`, `supplier-invoice-editor.test.tsx` — the
  latter also had a **pre-existing, unrelated mock gap fixed**, see Focused Tests —
  `supplier-invoices-page.test.tsx`)

## Invoice Default / Qty (§1–2)

`EMPTY_LINE` (`invoice-line-calc.ts`) now defaults `entity_type: 'raw_material'` (was
`'product'`) and `quantity: ''` (was `'1'`). `emptyLine()` — the factory both "Add Product" and
"Add Raw Material" call — only ever overrides `entity_type`, so the blank-quantity default is
shared by both buttons and the initial line. A blank/zero-quantity row is tolerated (not flagged)
only while it has no product selected yet — the instant a product is chosen, the Qty input gets a
destructive-red border/ring and an inline message, and `validateForm()` blocks submit with a
specific toast naming the problem, instead of the line silently vanishing from the payload as it
did before (that silent-drop behavior for a genuinely untouched, no-product row is preserved —
only the "half-filled" case is now caught).

## Goods Receipt Line Removal (§3)

`GoodsReceiptLineSelect` is no longer rendered anywhere in `InvoiceLineEditor` (removed from both
the desktop grid row and the mobile stacked-card layout). The component file and its
`useEligibleReceiptLines` hook are left in place, unreferenced, rather than deleted — a deliberate,
low-risk choice (same reasoning as Task 019's Channel/Buyer removals: UX removal, not deletion of
working code). The backend's `goods_receipt_line_id` field stays fully accepted (nullable) on the
create/update request for legacy compatibility; nothing about its storage or validation changed.

## Commercial Approval (§4/§7)

**The core fix.** `SupplierInvoiceController::validate()` no longer calls
`InvoiceReceiptAnchorService::resolve()` — the per-line loop that required the linked Goods
Receipt to already be posted before an invoice could leave Draft. Approval is now exactly what
§7 specifies: a document-level state change (status → `Validated`) with **no** Finance/AP touch,
no inventory/costing touch, and no assumption that anything was physically received — confirmed
unchanged by this task's own test plus the pre-existing
`SupplierInvoiceCommercialContractTest::test_...approval_is_a_commercial_state_change_only`.
The dead `lineLabel()` helper (only used by the removed loop) was removed alongside it.

## Invoice → Warehouse Receiving Handoff (§8–9)

Unchanged and already correct: `InvoiceReceivingLinkService::sync()` (Task 014) auto-creates the
linked Goods Receipt at Draft-save time (`store()`/`update()`), before commercial approval even
happens — which was already true before this task and is not a functional gap, since a Draft
invoice's pre-staged receipt is inert (Draft, unposted, zero accepted) until someone actually acts
on it. Company/supplier/product/expected-quantity are all derived directly from the invoice at
creation, so cross-tenant/cross-supplier linkage cannot occur by construction; `resolve()`'s full
integrity check (company → supplier → product → receipt-posted → quantity) still runs, unchanged,
at the one place it now correctly belongs: final Post.

## Partial / Full Receipt (§10–11)

No backend change — `SupplierInvoiceReceivingSummary` (Task 014) already derives
`awaiting`/`partially_received`/`reconciled` correctly from the linked receipt's own state. What
this task adds is *surfacing* that progress properly: `display_status` folds it into the 6-word
vocabulary (`partial_received`/`fully_received`) everywhere status is shown — list, drawer badge,
and the "Next: …" hint — instead of only being visible inside `ReceivingSummaryCard`'s own detail
view as before.

## Full Rejection (§12–13)

New: `POST /supplier-invoices/{id}/reject-receiving` (`RejectInvoiceReceivingService`), gated to
`Validated` status with the receiving read-model at exactly `awaiting` (nothing accepted yet) —
the instant any quantity is accepted, this returns 422 (§13: partial acceptance is never
cancellation). On success: every line of the linked receipt is confirmed at `accepted_qty = 0`
by calling the **existing** `ConfirmReceiptQuantitiesAction` directly (no second receiving
engine), the invoice moves to `Cancelled` (`GoodsReceiptStatus` has only `Draft`/`Posted` — no new
case was added; a receipt that's confirmed-all-zero and never posted simply stays Draft forever,
which is what makes "no inventory is received" true by construction), and the reason is appended
to the invoice's pre-existing `internal_notes` column (no migration — that field already existed,
nullable, free-text). A dedicated test confirms no `SupplierBill` and no `Product.last_purchase_cost`
change results from a rejection.

## Status / Next Action (§5–6)

`SupplierInvoiceStatus::displayBucket(?string $receivingStatus)` combines the real 6-case status
enum with the receiving read-model's status into 8 presentation buckets: `draft`,
`commercially_approved`, `partial_received`, `fully_received`, `processing` (the transient
`auto_processing` state — see note below), `posted`, `failed`, `cancelled`. This is computed
server-side and merged into both `index()` and `show()` (the list needed the same receiving-aware
computation as the drawer, so `index()`'s query now eager-loads `autoReceipt`/
`lines.goodsReceiptLine.goodsReceipt` — one batched query for the whole page, not per-row N+1 —
rather than building a cheaper, second approximation that could drift from the drawer's own
number for the same invoice). `SupplierInvoiceStatusBadge` (new frontend component) renders the
resulting word via local i18n (never the raw `status`/`status_label` text) plus an optional
"Next: …" hint derived from `available_actions` by fixed priority (`validate` → `post` → `edit`;
`cancel`/`delete` are never framed as "next"). `available_actions` itself
(`SupplierInvoiceResource`) is computed purely from the status enum's own `canEdit()`/
`canValidate()`/`canPost()`/`canCancel()`/`canDelete()` — the same authority the controller's own
guards use — and now drives both the list row's `ActionMenu` and the drawer's action buttons,
replacing five separate hardcoded `status === '...'` checks that existed before.

*Note on `processing`:* `AutoProcessing` is set and cleared inside one single
`DB::transaction()` in `PostSupplierInvoiceService::execute()`, so it is, in practice, never
durably observable by a concurrent read — the bucket exists for correctness/honesty (never
mis-bucket a real value), not because it is expected to actually appear.

## ready_to_post (§15)

**Already correct, unchanged.** `SupplierInvoiceReceivingSummary`'s `ready_to_post` already meant
exactly "the linked receipt has posted, so this invoice's receiving is settled" (`status ===
RECONCILED`) — it was never used to gate commercial approval, only the frontend's Post button
(`receivingBlocksPost` in the drawer, Task 014, untouched). The bug this task fixes was a
*separate*, redundant backend check inside `validate()` calling the same underlying anchor
service `ready_to_post` mirrors — not a problem with `ready_to_post` itself.

## Final Confirmation (§14) / Finance / AP (§16) / Cost / Pricing Review (§17–18)

**Deliberately untouched, verified by direct source reading (not just the test suite).**
`PostSupplierInvoiceService::execute()` — the sole Finalize/Post authority — still runs the full
`InvoiceReceiptAnchorService::resolve()`/`basisFor()` check inside its transaction, still requires
`canPost()` (Validated or Failed), still has all four independent idempotency layers (bill natural
key `SI-{id}`, `AccountsPayableService::assertPostable()`, the pre-transaction + locked re-check on
the invoice itself, and the shared `InboundPostingGuard` reference for the physical leg), and still
delegates the actual GL/journal write to `PostingCoordinator` and the supplier-balance write to
`SupplierLedgerEntry` via `AccountsPayableService`. Cost propagation
(`CreateReceiptLayersAction` → `Product.last_purchase_cost`/`average_cost` → `MaterialCostService`
→ `PricingReviewService::upsertForProduct`) is likewise unchanged; Mode-1 invoice posting still
correctly skips it when the linked receipt already owns the physical inbound. No code in this
task's diff touches any file in this paragraph.

## Legacy Compatibility (§25) — plus one newly-found-and-fixed bug

Historical/legacy-anchored invoices remain fully readable: `receipt_links` (the pre-014
manual-anchor read-model) is untouched, and any existing `goods_receipt_line_id` value on a line
is preserved and still displayed — only the *frontend setter* is gone.

**A second, more severe bug was found and fixed while reconciling this lifecycle** (not in the
original 30-section spec, but directly in its subject matter): `syncLines()` (called by both
`store()` and `update()`) hard-deletes every existing invoice line and recreates them fresh. A
recent, separate migration (`2026_09_08_...add_supplier_invoice_anchor_to_goods_receipt_lines`,
Task 014's own follow-on work) added `goods_receipt_lines.supplier_invoice_line_id` with **`ON
DELETE RESTRICT`** — deliberately, so a posted receipt line's history can never be silently
orphaned. Since every Mode-1 invoice gets its lines linked to fresh receipt lines the moment it is
first saved (`InvoiceReceivingLinkService::createLinkedReceipt()`), **editing any Mode-1 Draft
invoice that already has its (automatic, normal) linked receipt would throw a raw database
error** — `syncLines()`'s delete ran into the RESTRICT constraint every time, before
`syncLinkedReceipt()` ever got a chance to clear the old receipt lines out of the way.
Fixed with two new, narrowly-scoped methods on `InvoiceReceivingLinkService` —
`canRewriteLines()` (reuses the exact same "nothing physical has happened yet" rule
`isSafeToRewrite()` already enforced for the receipt's own side) and `unlinkBeforeLineRewrite()`
(clears the old receipt lines *before* `syncLines()` runs, so the delete no longer conflicts) —
called from `update()` right before `syncLines()`. When it's *not* safe (something has already
been physically accepted against the receipt), the edit is now cleanly refused with a 422 instead
of crashing or silently corrupting state. Two regression tests cover both the fixed success path
and the clean-refusal path.

## Focused Tests (§27)

Backend — `SupplierInvoiceLifecycleReconciliationTest.php`, 16 methods, `php -l`/PHPStan(0
errors)/Pint clean; **not executed live** — see Environment Blockers. Covers: commercial approval
succeeding before any receiving exists (the core fix); final Post still requiring the receipt
posted (proving the real gate wasn't weakened); approval still requiring ≥1 line;
`display_status` progressing draft→commercially_approved→partial_received→fully_received;
`available_actions` matching the real status only; the real status engine untouched by the
display projection; all 4 reject-receiving scenarios (reason required, succeeds before
acceptance, blocked after any acceptance, blocked for Draft) plus its no-AP/no-cost-update proof;
and the 2 new regression tests for the line-edit/RESTRICT-FK fix. Does not duplicate the existing
`SupplierInvoiceCommercialContractTest`/`SupplierInvoiceAutoReceivingTest` coverage of the
underlying receiving/posting/AP/costing machinery itself.

Frontend — all executed and green this session: **33 tests across 7 files** (`invoice-line-editor.test.tsx`
gained 4 new/updated tests for the §1–3 defaults and the no-anchor-selector/blank-qty-warning
behavior; `supplier-invoice-editor.test.tsx` had a genuine, **pre-existing, unrelated** bug fixed
in passing — its `vi.mock()` was missing a `usePostSupplierInvoice` entry the component had
already been calling before this task touched anything, so all 3 of its tests were failing before
this session started; `supplier-invoices-page.test.tsx` fixtures updated for the two new required
fields). ESLint, `tsc -b --noEmit` (0 errors in any touched file; 36 pre-existing, unrelated
errors elsewhere, byte-identical file list to Task 019's own baseline), and EN/AR parity (278/278
keys, exact match) all clean.

A light browser check was also done: the dev server starts, the app and login page render with
zero console errors (confirms no build-breaking regression), but a real authenticated walkthrough
was not possible — the backend/DB stack this frontend proxies to (`127.0.0.1:8081`) is the same
one that has been environment-blocked for this entire session (see Environment Blockers), matching
the accepted limitation already noted in Tasks 014/018/019.

## Environment Blockers

**BACKEND FOCUSED TESTS — ENVIRONMENT/INFRASTRUCTURE BLOCKED**

Same condition observed and reported in Tasks 018 and 019 in this lane: `php artisan test` does
not complete in this environment (PHPUnit's repo-wide discovery scan never finishes before
timing out, well before reaching this task's filtered tests or a database connection attempt). No
attempt was made to repair test infrastructure, bootstrap/restore a database, or run
`migrate:fresh`. All 16 new backend test methods are `php -l` and PHPStan clean and were reviewed
by hand against the actual code they exercise.

## Migration Inventory

**None.** Every requirement in this task was satisfiable with existing schema:
`SupplierInvoice.internal_notes` (already existed, nullable, free-text) holds the rejection
reason; `GoodsReceiptStatus` stays its existing 2-case enum (Draft/Posted) — Full Rejection is
represented entirely by the invoice's own pre-existing `Cancelled` status plus a permanently-Draft
receipt, never a new receipt-side case; `SupplierInvoiceStatus` gained no new case, only new
enum *methods*. `display_status`/`available_actions` are computed, never stored.

## Final Checkpoint

Working tree is clean of anything unexpected; all Task 020 changes are staged for one commit on
top of `565ca928` in this same worktree/branch. No push, no deploy, no integration into canonical
`develop`, no certification, and no further Procurement task has been started.
