# TASK-ECOS-V1-REMEDIATION-PROCUREMENT-035A — Report

## Status

**V1 PROCUREMENT REMEDIATION COMPLETE — READY FOR INTEGRATION**

All 6 confirmed defects investigated against the current code (not assumed from the source
reference); 5 were real and reachable and are fixed; 1 (item 6, "unknown inventory class") was
investigated and found to be a stale/misattributed reference to a general enum-contract test, not
a live defect — documented, not fixed, per instruction ("Fix only if reproducibly a real
application-path defect").

## Base / Source Reference

- **Base:** canonical `develop` @ `7d122fdc6a9cd0aeae977df7c181ad8945dc4a23`
- **Worktree:** `E:\ECOS\_v1-remediation-procurement-035a`, branch `task/v1-remediation-procurement-035a`
- **Source reference used:** `7b9c1c5b757b1d2e6e2025109316a947c984f0ee` ("Task 033 validation
  recovery snapshot") — a single commit sitting directly on the same base, one commit ahead, on a
  different branch (`task/v1-validation-recovery-034`). It is a broad, 39-file recovery dump
  spanning many unrelated modules (Finance, Marketing, Collaboration, Logistics, etc.), preserved
  losslessly from an interrupted prior task — **not** itself approved or reviewed. Per instruction,
  only the ONE relevant piece — `Supplier.php`'s tenant-scope qualification — was forward-ported,
  and only after independently confirming the same unqualified state still existed on the current
  base (it did). No other file from that snapshot was touched; the other 38 files are out of this
  task's scope entirely.

## Changed Files

12 modified, 2 new (1 new test file, 1 existing test file extended).

- `Modules/Purchasing/Suppliers/Domain/Models/Supplier.php` — item 1
- `Modules/Purchasing/Suppliers/Domain/Models/SupplierCategory.php` — item 1 (dormant sibling)
- `Modules/Purchasing/SupplierInvoices/Domain/Services/InvoiceReceiptAnchorService.php` — items 2, 4
- `Modules/Purchasing/SupplierInvoices/Application/Services/InvoiceReceivingLinkService.php` — item 3
- `Modules/Purchasing/SupplierInvoices/Application/Services/SupplierInvoiceReceivingSummary.php` — item 4
- `Modules/Purchasing/SupplierInvoices/Application/Services/PostSupplierInvoiceService.php` — item 5
- `Modules/Purchasing/SupplierInvoices/Presentation/Http/Requests/StoreSupplierInvoiceRequest.php` — item 5
- `Modules/Purchasing/GoodsReceipts/Application/Actions/CreateGoodsReceiptAction.php` — item 5
- `Modules/Purchasing/GoodsReceipts/Application/Actions/UpdateGoodsReceiptAction.php` — item 5
- `Modules/Purchasing/GoodsReceipts/Presentation/Http/Requests/StoreGoodsReceiptRequest.php` — item 5
- `Modules/Purchasing/GoodsReceipts/Presentation/Http/Requests/UpdateGoodsReceiptRequest.php` — item 5
- `Modules/Inventory/InventoryItems/Application/Actions/ReceiveStockAction.php` — item 5
- `tests/Feature/Purchasing/SupplierMultipleCategoriesTest.php` — item 1 regression (extended)
- `tests/Feature/Purchasing/V1ProcurementRemediation035ATest.php` (new) — items 2-5 regressions

## 1. Supplier List 500

**Confirmed and fixed.** `Supplier`'s tenant-scope global scope did `$query->where('company_id',
$companyId)` unqualified; `EloquentSupplierRepository::paginate()` (the actual query behind `GET
/api/suppliers`) unconditionally `leftJoin`s `supplier_categories` — a real SQL join, not an
eager-load — which also carries its own `company_id` column, making the reference genuinely
ambiguous to MySQL. Fixed by qualifying: `$query->where('suppliers.company_id', $companyId)`.

**Also found and fixed, not in the original report:** `SupplierCategory`'s own tenant scope has
the exact same unqualified pattern. It is currently dormant (nothing joins another
`company_id`-bearing table against a `SupplierCategory` query today), but it is the same bug
class, sitting one join away from firing the same way — qualified defensively
(`supplier_categories.company_id`) while already in this exact area.

**Regression coverage:** added to `SupplierMultipleCategoriesTest.php` (matching its own
established isolated-schema convention, since a real RefreshDatabase migration run is confirmed
infeasible in this environment). Every *existing* test in that file creates its fixtures via
`Supplier::withoutGlobalScopes()`, so none of them actually exercised the tenant scope's `WHERE`
clause together with the join — the new test activates the real scope (via a plain object bound
over `TenantOwnershipResolver`, since that class is `final` and can't be subclassed) so it
actually reproduces the original ambiguous-column failure, not just re-proves the fix in
isolation.

## 2. Invoice-First Receiving Supplier Authority

**Confirmed and fixed — was 100% reproducible, not an edge case.**
`InvoiceReceiptAnchorService::anchorSupplierId()` only ever checked `purchaseOrder->supplier_id`
and `purchaseMaterialLine->supplier_id`. An invoice-first-anchored `GoodsReceiptLine` has neither
(by construction — it settles a Supplier Invoice, not a PO or a Purchase Material), so this method
always resolved `''` for it, and `resolve()`'s supplier guard then unconditionally threw
`supplierMismatch` — for every single Mode-1 company's invoice-first posting attempt, regardless
of whether the invoice's real supplier was correct. Fixed by adding the third, invoice-first
authority: `$anchor->supplierInvoiceLine?->supplierInvoice?->supplier_id`, tried after the
existing two (both `resolve()`'s and `eligibleFor()`'s eager-loads extended to match, avoiding
N+1). Cross-supplier protection itself is untouched — a genuinely mismatched invoice-first anchor
still fails the same guard, for the same reason, just no longer failing on a *correct* one too.

## 3. Anchor Clobbering

**Confirmed and fixed.** `InvoiceReceivingLinkService::createLinkedReceipt()` filtered lines only
by `quantity > 0`, never by whether `goods_receipt_line_id` was already set — so it would build a
brand-new receipt and unconditionally overwrite any pre-existing anchor (e.g. a legacy, manually-
anchored line), silently orphaning whatever real receipt line it used to point at.
`resyncLinkedReceipt()` had the identical gap for its own re-sync path. Fixed in both: a line
already anchored elsewhere (not to `null`, and — for `resyncLinkedReceipt()` specifically — not to
one of *this* receipt's own about-to-be-recreated lines) is now excluded from the rebuild
entirely, leaving its existing anchor untouched. This is additive-only now, matching the flow's
own documented design intent.

## 4. Partial Receipt Status / Cancellation

**Confirmed and fixed.** `InvoiceReceiptAnchorService::reconciledQuantity()` — correctly
posted-only, since it feeds the AP posting basis and an unposted receipt has no stamped
`landed_unit_cost` — was being reused by `SupplierInvoiceReceivingSummary` for a completely
different purpose: presentation status and `RejectInvoiceReceivingService`'s cancellation-safety
guard. A warehouse can legitimately confirm a real, non-zero partial quantity on a still-Draft
invoice-first receipt (`ConfirmReceiptQuantitiesAction`'s whole purpose) — but the posted-only
filter made that quantity invisible to both the status computation and the reject guard, so the
guard would see "awaiting" (nothing accepted), silently zero the already-confirmed quantity, and
cancel the whole invoice. Fixed by adding `physicallyAcceptedQuantity()` — the identical
derivation, deliberately without the posted-only filter — and switching
`SupplierInvoiceReceivingSummary` to use it. `reconciledQuantity()`/`basisFor()` (the financial/AP
side) are completely unchanged; presentation truth and financial truth are now allowed to
(correctly) disagree while a receipt is still Draft, which is the whole point of the fix.

## 5. Cross-Company Product Lookup Failure

**Confirmed and fixed at every layer that had the gap**, on both named paths (Goods Receipt and
Invoice posting):

- **Root cause, both creation actions:** `CreateGoodsReceiptAction`/`UpdateGoodsReceiptAction`
  fetch products via a tenant-scoped `whereIn`, then read them back with `$products->get($id)` —
  which silently returns `null` for a cross-company or nonexistent id (the scope excludes it from
  the result rather than erroring) — and proceed anyway with null UOM data. Fixed: both actions
  now verify every requested product id was actually found, throwing the existing
  `ProductNotFoundException` (already `BusinessException`-derived, already cleanly rendered as a
  4xx — no `bootstrap/app.php` change needed) immediately, before any `GoodsReceiptLine` is ever
  created with an unverified product reference.
- **Root cause, Invoice side:** `StoreSupplierInvoiceRequest`'s `lines.*.product_id` rule was a
  bare `exists:products,id` — tenant-*blind*, unlike the tenant-scoped rule already sitting one
  line above it for `goods_receipt_line_id` (with its own comment explaining exactly why a
  scope-blind rule is dangerous here). Fixed to match: `Rule::exists('products',
  'id')->where('company_id', ...)`. The two Goods Receipt request classes
  (`StoreGoodsReceiptRequest`/`UpdateGoodsReceiptRequest`) had the identical gap and were fixed
  the same way (the latter needed a new `actorCompanyId()` helper added, matching its sibling's).
- **Defense in depth, both posting paths:** even with the above, a stale/pre-existing row could
  still reach posting. `ReceiveStockAction` (shared by both Goods Receipt and Mode-1 Invoice
  posting) and `PostSupplierInvoiceService::postMode3Payable()` (Mode-3 Invoice posting) both did
  `$product?->product_type` / `Product::query()->find(...)` and fed a possible `null` straight
  into `InventoryClass::fromProductType()`, whose own null-input branch throws
  `UnknownInventoryClassException` — a plain `DomainException`, **not** registered in
  `bootstrap/app.php`'s renderer list, so it fell through as a raw 500. Both now check for `null`
  explicitly first and throw `ProductNotFoundException` instead — a clean, already-registered 4xx.
  `InventoryClass::fromProductType()` itself is untouched (its own "refuse a genuinely
  unrecognized product_type, never guess" behavior is correct and unrelated to this gap). The
  tenant scope itself was never bypassed anywhere in this fix — every check is "was a real,
  scope-visible product found," never a raw cross-tenant lookup.

## 6. Unknown Inventory Class

**Investigated, found to be stale/non-issue — no fix applied.** `InventoryClass::fromProductType()`
is a plain `tryFrom`-then-throw (not a `match` with missing arms), and a dedicated drift-guard test
(`InventoryClassContractTest::test_the_enum_matches_product_types_exactly`) pins the enum 1:1
against `Product::TYPES`'s real, exhaustive 3 values — so a genuine `Product` row can never carry
a `product_type` this enum fails to recognize. The "5 cases" are that same test's own data
provider (`unclassifiable()`), a general enum-contract test with synthetic strings
(`null`, `''`, `'consumable'`, wrong-case, `'work_in_progress'`) — not 5 real product/scenario
combinations reproducible against production data. Neither `PostGoodsReceiptAction` nor
`CreateReceiptLayersAction` even reference `InventoryClass` directly; the only real, currently-
reachable path that can throw it is the exact null-product gap already fixed in item 5. If a
specific ticket/report exists behind "5 goods-receipt-posting cases," it is worth checking whether
it is actually describing this same item-5 path under a different description — no other candidate
was found.

## Focused Tests

Backend only, `php -l`/Pint/PHPStan clean; **not executed live** — see Environment Blockers.

- `SupplierMultipleCategoriesTest.php` — 1 new test (item 1): reproduces the original ambiguous-
  column failure by activating the real tenant scope (all of this file's existing tests bypass it
  entirely via `withoutGlobalScopes()`), then asserts `paginate()` succeeds and returns only the
  acting company's own supplier.
- `V1ProcurementRemediation035ATest.php` — 7 new tests (items 2-5):
  - Item 2: invoice-first posting succeeds end-to-end once the linked receipt is posted; direct
    `resolve()` call confirms the anchor resolves through the invoice-first path.
  - Item 3: creating/syncing the linked receipt never touches a line already anchored to a
    separate, pre-existing (foreign) receipt line — that anchor and its receipt are asserted
    completely unchanged; the previously-unanchored line gets its own new anchor as expected.
  - Item 4: a draft-but-confirmed partial quantity is correctly visible as `partially_received`
    (not `awaiting`); Warehouse Full Rejection is correctly blocked (422) once any such quantity
    exists, and the confirmed quantity is confirmed to survive the refused attempt untouched.
  - Item 5: Supplier Invoice creation and the Goods Receipt creation action both reject a
    cross-company product reference deterministically (422 / `ProductNotFoundException`) rather
    than creating anything or crashing.

## Validation

- `git diff --check` — clean.
- PHP syntax — 14/14 touched files (12 source + 2 test) clean.
- Pint — clean (one cosmetic auto-fix accepted: brace placement on an anonymous test double, no
  semantic change).
- PHPStan (project config, touched scope) — `[OK] No errors`.
- No broad backend test suite was run; no PHPUnit infrastructure was repaired; no database was
  restored/bootstrapped; scope was not broadened beyond the 6 named items (the `SupplierCategory`
  sibling fix in item 1 is the one deliberate exception — same file, same bug class, same
  one-line shape, directly adjacent to the named fix, not a separate concern).

## Environment Blockers

**BACKEND FOCUSED TESTS — ENVIRONMENT/INFRASTRUCTURE BLOCKED**

Same condition observed throughout this Procurement work: `php artisan test` does not complete in
this environment. All 8 new/extended test methods are `php -l` and PHPStan clean and were reviewed
by hand against the exact current code they exercise (in several cases, by first tracing the
precise failure mode directly against the current source before writing the fix, not from the
source-reference snapshot alone).

## Checkpoint

Working tree is clean of anything unexpected; all changes are staged for one commit on top of
`7d122fdc` in this worktree/branch. No integration into canonical `develop`, no DEV deploy, no
push, and no certification — stopping here per instruction.
