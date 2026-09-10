# TASK-ECOS-PROCUREMENT-PURCHASE-REQUESTS-FINAL-019 — Report

## Status

**PROCUREMENT PURCHASE REQUESTS FINAL SOURCE COMPLETE**

All 24 sections of the spec are addressed. Most of the purchasing-commitment/fulfillment/ownership
*engine* (SelectLineSupplierAction, PurchaseMaterialReceivingService, AdvancePurchaseMaterialWorkflowAction,
PurchaseMaterialOwnershipService, the table's action-menu) already existed and was correct — built and
proven in Task 011. This task's real work was: (a) a presentation-only status-vocabulary projection so the
UI shows the 6 approved words without altering the 10-status engine, (b) fixing Estimated Value to use the
actual "latest purchase price" field instead of a weighted average, (c) the Create-flow scroll/item-picker
rework, (d) removing Buyer/Channel/Review from the UX while preserving their underlying data and audit
trail, and (e) a Procurement Hub reconciliation pass (real KPIs only, drill-downs, a Requests-by-Status
strip). No integration, deployment, push, or certification was performed, per instruction.

## Base / Head

- **Base:** `49b82f7b` (Task 018 checkpoint, `task/procurement-supplier-master-returns-018`)
- **Worktree:** `E:\ECOS\_procurement-supplier-master-returns-018` (same lane, not yet re-committed — see Checkpoint)
- **Branch:** `task/procurement-supplier-master-returns-018`

## Changed Files

21 modified, 3 new — 661 insertions / 321 deletions.

Backend (`backend/Modules/Purchasing/PurchaseMaterials/`):
- `Domain/Enums/PurchaseMaterialStatus.php` — `displayBucket()` projection
- `Domain/Models/PurchaseMaterial.php` — `displayStatus()` (resolves OnHold through `held_from_status`)
- `Presentation/Http/Resources/PurchaseMaterialResource.php` — `display_status`, `is_on_hold`,
  `estimated_value_has_gaps`; `derivedEstimatedValue()` switched to `last_purchase_cost`
- `Presentation/Http/Resources/PurchaseMaterialLineResource.php` — `estimated_unit_price`,
  `estimated_line_value`, `product.last_purchase_cost`
- `Application/Actions/GetPurchaseMaterialStatsAction.php` — `by_display_status`,
  `estimated_value_open` switched to `last_purchase_cost`
- `Infrastructure/Repositories/EloquentPurchaseMaterialRepository.php` — status filter accepts
  comma-separated buckets (`whereIn`), single value still works unchanged
- `tests/Feature/Purchasing/PurchaseMaterialDisplayStatusAndValuationTest.php` (new, 10 methods)

Frontend (`frontend/src/features/`):
- `purchase-materials/components/create-purchase-material-wizard.tsx` — full rewrite (scroll + item picker)
- `purchase-materials/components/purchase-material-status-badge.tsx` — full rewrite (display vocabulary)
- `purchase-materials/components/purchase-material-ordering-popover.tsx` — hover + click support added
- `purchase-materials/components/purchase-material-action-menu.tsx` — badge props wired
- `purchase-materials/components/purchase-material-drawer.tsx` — Review tab removed, Ordered indicator added
- `purchase-materials/pages/purchases-page.tsx` — Buyer column removed, Estimated Value display, status filter
- `purchase-materials/types/purchase-material.ts` — new fields/types
- `procurement/pages/procurement-hub-page.tsx` — Requests-by-Status, Open Purchase Value KPI, drill-downs
- `purchase-materials/components/create-purchase-material-wizard.test.tsx` (new, 5 tests)
- `purchase-materials/components/purchase-material-status-badge.test.tsx` (new, 3 tests)
- `purchase-materials/pages/purchases-page.test.tsx`, `purchase-material-receiving-tab.test.tsx` — fixtures
  updated for the new required fields
- `i18n/locales/{en,ar}/{purchase-materials,procurement}.json` — 509 + 127 keys each, exact EN/AR parity
- `eslint-suppressions.json` — pruned 2 entries the wizard/badge rewrites made stale (see Focused Tests)

## Create UX (§1–2)

**Scroll:** the wizard dialog now scrolls as one region (`flex-1 min-h-0 overflow-y-auto`) instead of nested
per-step scroll containers; the dialog itself is full-height on mobile (`h-full sm:h-auto`). The Selected
section is always reachable by scrolling, on desktop and mobile.

**Item picking:** the single mixed catalogue browser is replaced by two explicit actions, "Add Product" and
"Add Raw Material," each opening its own search scoped server-side (`product_types=finished_good,packaging_material`
vs `product_type=raw_material`) — a raw-material search can never surface a product and vice versa. Default
browse is capped at 3 results (`per_page: 3`); typing a search term lifts the cap to 20. The picker stays
open after adding a row, so multiple items can be added in one pass.

## Estimated Value (§3)

Per-line: `Product.last_purchase_cost × requested_qty` — `last_purchase_cost` is written on every posted
Goods Receipt regardless of PO- or PurchaseMaterial-anchor, so it is the correct "latest purchase price"
authority (as opposed to `average_cost`, a weighted average, which was wrongly in use before). A line with
no purchase history reports `estimated_unit_price: null` / `estimated_line_value: null` — never a fabricated
0. The request total sums only the known lines and carries `estimated_value_has_gaps: true` when any line is
missing a price; the frontend renders that as a `~` prefix on the total plus an explicit "Estimated value
unavailable" line-level state (Arabic: "غير متاح") — never a silent zero.

## Ownership / Auto Claim (§4)

Buyer is removed from Create/Edit and the main table (no manual picker anywhere in normal UX).
`PurchaseMaterialOwnershipService::claimIfUnowned()` (Task 011) is untouched — every purchasing mutation
still atomically claims ownership on the first valid action by an authorized user, and ownership stays
auditable on the model; this task only removed the *manual* buyer UI, not the auto-claim mechanism or its
audit trail.

## Status Lifecycle (§5)

Added `PurchaseMaterialStatus::displayBucket()` and `PurchaseMaterial::displayStatus()` as a presentation-only
projection over the existing 10-case enum — Draft; UnderReview/WaitingSupplierSelection/Approved →
Awaiting Supplier; Purchasing; Receiving; Completed; Rejected/Cancelled → Rejected. OnHold resolves through
`held_from_status` (falls back to Awaiting Supplier if unset). This is explicitly **not** a second status
engine: `status`, `availableActions()`, and every transition guard are byte-for-byte unchanged — a dedicated
backend test (`test_the_real_status_and_available_actions_are_never_touched_by_the_display_projection`)
asserts the real engine survives the projection untouched. `PurchaseMaterialStatusBadge` shows the 6-word
vocabulary everywhere `display_status` is supplied, with a pause icon for on-hold; it falls back to the raw
status for any caller not yet updated (there are none left in this task's scope).

## Table Status Actions (§6)

Already satisfied by Task 011's `PurchaseMaterialActionMenu`: a table-row dropdown built *only* from the
server's own `available_actions` array (the same list the backend computes via `availableActions()`), so the
table can never offer a transition the server would reject. All 6 action mutations already invalidate both
the list and detail query keys on success, so the table's status reflects the change immediately. This task's
only change here was wiring the new `display_status`/`is_on_hold` props into the badge the menu already
renders.

## Ordered Action (§9)

Already a real, functioning commitment action (Task 011's `SelectLineSupplierAction`, reached from the
drawer's Supplier Selection tab): a form (supplier, price, quantity, lead time) submits through
`useSelectLineSupplier`, which is capped server-side at `requested_qty` and is monotonic non-decreasing —
never a frontend-only or decorative quantity. This task added the three-way Ordered / Partially Ordered /
Not Ordered indicator on each line (`SupplierSelectionLineRow`), reusing `is_fully_ordered`/`agreed_qty` the
backend already returns, so the visible state can never disagree with the real commitment record.

## Partial Ordering (§10)

Supported at the quantity level by the same `agreed_qty` field — e.g. Requested 100, an initial commitment
of 60 shows "Ordered 60 / 100" (amber, Partially Ordered), a later commitment can raise it toward 100
(emerald, Ordered). Never collapsed to a boolean; enforced server-side, not just displayed that way.

## Ordering Progress (§11)

The main table shows exactly one percentage: `Fully Ordered Item Lines / Total Requested Item Lines`
(`executionPercent()` in `PurchaseMaterialResource`, already matching this exact formula — verified, not
changed). A partially-ordered line does not count as fully ordered. No inventory/GR/financial/quantity-weighted
percentage competes with it on the table.

## Ordered / Not Yet Ordered (§12)

`PurchaseMaterialOrderingPopover` exposes the concrete line list (not just a count) on both hover (desktop,
150ms hover-intent close delay) and click/tap (works identically on any device — the trigger's click handler
force-opens rather than relying on Radix's default toggle, which would otherwise close it again immediately
after a click-triggered hover already opened it; see the component's doc comment). Not-yet-ordered entries
show concrete remaining context, e.g. "Cement — 20 remaining," sourced directly from `remaining_to_order`.

## Physical Fulfillment (§13–14)

Requested / Ordered / Physically Received / Remaining Outstanding are preserved per line
(`PurchaseMaterialReceivingService::requiredQty()`/`receivedGross()`/`remaining()`), with Goods
Receipt/Inventory remaining the authoritative source for what was physically received — the PR never
duplicates that ledger. A partial receipt only closes the physically-received quantity; verified via
`PostGoodsReceiptAction`, which advances workflow state but never marks the whole request Completed on a
partial receipt.

## Completion Rule (§15)

Confirmed unchanged and correctly wired: `AdvancePurchaseMaterialWorkflowAction` is called from
`PostGoodsReceiptAction` immediately *after* the receipt's own status flips to Posted, and only advances to
Completed when `PurchaseMaterialReceivingService::isRequestFulfilled()` — i.e. `received >= requested_qty`
for the full request — is true. Ordering everything, creating a PO, or a partial receipt each leave the
request short of Completed, as required. (One exploration pass initially reported this wiring as missing;
I re-verified directly against `PostGoodsReceiptAction.php`'s source and confirmed the call is present and
correctly positioned — the initial report was an incomplete search, not a real gap.)

## Procurement Hub (§17–19)

- Added a "Requests by Status" strip: 6 clickable cards reading the new `by_display_status` stats block,
  each navigating to the purchases table pre-filtered to its bucket (comma-joined internal statuses via the
  new repository filter, e.g. `?status=under_review,waiting_supplier_selection,approved,on_hold`).
- Added an "Open Purchase Value" KPI (`estimated_value_open`, now correctly sourced from `last_purchase_cost`),
  clickable through to the purchases table.
- Wrapped the 4 Performance-snapshot rows in real drill-down links (Total Purchases, Approved, Invoices
  Posted, Returns Completed) instead of static, non-actionable text.
- Rewrote a stale code comment that claimed PM financial KPIs were impossible; they are real and derived,
  just previously computed from the wrong cost field.
- No vanity/decorative/sourceless cards were found on this page to remove beyond what Task 018's own pass
  already covered; this task's audit found the remaining gaps were "real KPI, wrong field" (fixed) and
  "not clickable" (fixed), not fabricated data.

## Focused Tests (§22)

Backend — `PurchaseMaterialDisplayStatusAndValuationTest.php`, 10 test methods (one data-provider driven
across 9 statuses): display-status bucketing for all statuses, both OnHold resolution paths, proof the real
engine is untouched, Estimated Value using `last_purchase_cost` (priced / fully-missing / partially-missing
lines), the Hub's `by_display_status` bucket math, and both the comma-separated and single-value status
filter. `php -l` clean on all 7 touched/added backend files; **not executed live** — see Environment Blockers.

Frontend — all executed and green this session:
- `create-purchase-material-wizard.test.tsx` (new, 5 tests): two explicit picking actions with no combined
  browser; raw-material search scoped to `product_type=raw_material` with the 3-item browse cap; product
  search scoped to `product_types=finished_good,packaging_material` (never raw materials) with the same cap;
  typing a search term lifts the cap to 20; a selected result is added to the Selected list.
- `purchase-material-status-badge.test.tsx` (new, 3 tests): shows the 6-word vocabulary when `displayStatus`
  is supplied (and asserts the raw internal label is absent); falls back to the raw status label when
  `displayStatus` is omitted; shows the on-hold pause icon only when `isOnHold` is true.
- `purchases-page.test.tsx`, `purchase-material-action-menu.test.tsx`, `purchase-material-receiving-tab.test.tsx`:
  fixtures updated for the new required `PurchaseMaterial`/`PurchaseMaterialLine` fields; all pre-existing
  assertions (including the Task 011 Ordered/Not-Yet-Ordered popover test) still pass.
- Full `purchase-materials` + `procurement` suite: **5 files / 23 tests, all passing** (re-run twice, most
  recently after the last fixture edit).
- A real regression was caught and fixed during this task: adding hover support to
  `PurchaseMaterialOrderingPopover` broke its own pre-existing click test via a Radix `asChild` composition
  interaction (a synthetic `mouseenter`-before-`click` opened the popover via hover, then Radix's own click
  handler toggled it back closed). Root-caused via an isolated debug render rather than guessing; fixed by
  having the trigger's `onClick` call `preventDefault()` so it takes exclusive control instead of composing
  with Radix's default toggle. Documented at length in the component's own doc comment.

Static validation, all scoped to touched files, all clean:
- `php -l` — 7/7 backend files clean
- Pint — `{"tool":"pint","result":"passed"}`
- PHPStan (project config, touched backend files) — `[OK] No errors`
- ESLint (touched frontend files) — clean; pruned 2 now-stale suppression entries
  (`create-purchase-material-wizard.tsx`'s `no-unused-expressions`, `purchase-material-status-badge.tsx`'s
  `no-hardcoded-ui-strings`) that the rewrites of those two files genuinely eliminated — verified the pruned
  diff touches only those two entries, nothing else
- `tsc -b --noEmit` — 0 errors in any touched file (1 real error was caught and fixed: a test fixture in
  `purchase-material-receiving-tab.test.tsx` needed the newly-required `last_purchase_cost`/
  `estimated_unit_price`/`estimated_line_value` fields). 36 pre-existing errors remain in files this task
  (and Task 018) never touched — confirmed via `git diff 49b82f7b` showing zero changes to any of those
  paths (admin/configuration, business-accounts, engineering, hr, iam-admin, logistics, marketing,
  stock-ledger, suppliers) — out of scope, not introduced or worsened by this task.
- EN/AR parity — exact key-set match: `purchase-materials` 509/509, `procurement` 127/127, zero missing
  either direction.
- `git diff --check` — clean (no whitespace errors).

## Environment Blockers

**BACKEND FOCUSED TESTS — ENVIRONMENT/INFRASTRUCTURE BLOCKED**

`php artisan test --filter=PurchaseMaterialDisplayStatusAndValuationTest` does not complete in this
environment — it spends its full runtime on PHPUnit's repo-wide test-discovery/metadata scan across
thousands of unrelated test methods and times out before reaching the filtered test or a database
connection attempt. This matches the same environment condition observed in prior Procurement tasks in
this lane. No attempt was made to repair test infrastructure, bootstrap or restore a database, or run
`migrate:fresh`, per instruction. All 10 new backend test methods are `php -l` clean and were reviewed
by hand against the actual resource/action/repository code they exercise.

## Checkpoint

Working tree is clean of anything unexpected; all Task 019 changes are staged for one commit on top of
`49b82f7b` in this same worktree/branch. No push, no deploy, no integration into canonical `develop`, no
certification, and Task 020 has not been started.

## Remaining Task 020 Dependencies

- **Not a Task 019 gap, flagged for awareness:** `EloquentPurchaseMaterialRepository::update()` hard-deletes
  and recreates all lines on every edit, which would silently drop `agreed_qty`/`agreed_price`/`supplier_id`
  if a request were ever edited after a supplier commitment exists (reachable in principle via
  Approved → Hold → edit-while-on-hold, since `isEditable()` permits editing while OnHold). Deliberately
  **not fixed** in this task: `useUpdatePurchaseMaterial` has zero call sites anywhere in the frontend — no
  Edit UI exists, so the landmine is currently unreachable. Worth a guard or an append-only line-update
  strategy if/when an Edit UI is ever built.
- Task 020 (Supplier Invoice Final Lifecycle) can proceed independently — it does not depend on any
  Purchase Request internals changed here beyond what's already documented above (display-status vocabulary,
  Estimated Value source, comma-separated status filter).
