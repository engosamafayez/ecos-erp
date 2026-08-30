# TASK-PROCUREMENT-GOODS-INWARD-CONFIGURATION-UI-001 — Engineering Report

**Date:** 2026-08-17 · **Branch:** `develop` · **Runtime:** MySQL 8.4.10 / PHP 8.4.24 / PHPUnit 11.5.55 / Vite + React

**FINAL VERDICT: CERTIFIED**
**REAL E2E = PASS — USER BROWSER SMOKE VERIFIED** (2026-08-17, see *Final User Browser Smoke*)

The outstanding gate has been closed. An authenticated session was already present in the user's
connected Chrome; no credentials were entered and no production code was changed.

**One real defect was found and fixed by this task: the frontend was never deployed.** The feature's
code existed and its tests passed, but the bundle the browser actually receives did not contain it —
so the setting was unreachable in the running application. It is deployed and verified over HTTP now.

---

## 1. Configuration architecture

Inspected first; **nothing new was created**. The feature reuses the existing Configuration OS:

| Layer | Existing asset reused |
|---|---|
| Route group | `routes/api.php` `configuration/*`, same group as `company`, `brands/*` |
| Controller namespace | `Modules\Admin\Configuration\Presentation\Http\Controllers` |
| Response envelope | `HasApiResponse` (`success` / `updated` / `error`) |
| Audit | `ConfigAuditService` — the module's existing audit service |
| Permission | `configuration.settings.manage` — the canonical Configuration permission |
| Frontend page | `company-configuration-page.tsx`, existing Configuration area |
| Frontend data layer | `configuration-service.ts` + `use-configuration.ts` (React Query) |
| Components | `Card`, `Badge`, `Button`, `Input`, `Skeleton`, `ConfirmDialog`, `useToast` — existing DS/UI |

No parallel Configuration system, no second settings architecture, no new permission namespace.

## 2. API contract

```
GET  /api/configuration/procurement/goods-inward-mode      (auth)
PUT  /api/configuration/procurement/goods-inward-mode      (auth + configuration.settings.manage)
```

Response:

```json
{ "mode": "goods_receipt", "label": "Goods Receipt", "is_default": true,
  "default_mode": "goods_receipt",
  "options": [ {"value":"goods_receipt","label":"Goods Receipt"},
               {"value":"supplier_invoice","label":"Supplier Invoice (Mode 3)"} ] }
```

Request: `{ "mode": "goods_receipt" | "supplier_invoice", "reason": "…optional, ≤500" }`.

The controller reads and writes `companies.goods_inward_mode` and decides nothing itself.
A documented deliberate choice: the value is written to the **column**, not through
`ConfigurationManager::setCompanySetting()`, because that manager persists into the
`config_company_settings` key-value store while the certified `GoodsInwardAuthority` reads the
column. A second home for the same value would reopen a certified contract for no gain. The audit
half of the manager **is** reused.

## 3. Tenant isolation

**Structural, not filtered.** The company is taken from the authenticated actor
(`Auth::user()?->company_id`); there is no company identifier anywhere in the route or the payload,
so there is nothing for a caller to tamper with — an actor can only ever address its own company's
setting. No database constraint or FK failure is relied on, and no frontend hiding is involved.

Proven by `test_2_reading_returns_only_the_actors_own_company_setting` and
`test_4_writing_cannot_reach_another_companys_setting`.

## 4. Permissions

Canonical existing permission reused — no new namespace.

| Actor | Result | Test |
|---|---|---|
| Unauthenticated | **401** | `test_5` |
| Authenticated, no permission | **403** | `test_6` |
| `configuration.settings.manage` | success | `test_3`, `test_8` |

`GET` sits behind group auth; `PUT` additionally behind `permission:configuration.settings.manage`.

## 5. Goods Inward modes

Exactly two, from `GoodsInwardMode`: `goods_receipt` and `supplier_invoice`. Validation is
`Rule::in(array_column(GoodsInwardMode::cases(), 'value'))`, so the enum is the single source and
any other value is **422** (`test_7`). No third mode exists anywhere.

## 6. Default behaviour

`GoodsInwardMode::default()` is **`goods_receipt`**, and `tryFromValue()` resolves NULL or unknown
to it — so an unconfigured company behaves exactly as before this contract existed.
`supplier_invoice` is never the default.

The backend owns this: `present()` returns `is_default` (true when nothing is stored) and
`default_mode`. The browser never writes a default and never computes one — it renders the
`is_default` flag as a "Default" badge. Proven by `test_1_and_10` and `test_1b`.

## 7. UI implementation

`GoodsInwardModeCard`, rendered inside the existing `CompanyConfigurationPage`
(`/admin/configuration/company`). Two options rendered from the **server's** `options` array, so the
frontend cannot drift from the enum. Radio semantics (`role="radio"` + `aria-checked`), icons,
per-option description copy, an optional reason field, and a "Default" badge driven by `is_default`.

## 8. Real API integration

No mock data, no static array as a data source, no localStorage, no hardcoded current value —
verified by inspection of the whole data path:

- `useGoodsInwardMode` → `configurationService.getGoodsInwardMode()` → real `GET`, `staleTime: 0`
  (deliberate: a stale render would show the wrong inbound authority)
- `useUpdateGoodsInwardMode` → real `PUT`, then
  `qc.invalidateQueries({ queryKey: [GOODS_INWARD_MODE_KEY] })`
- The mutation response is deliberately **not** written into the cache; the UI refetches, so what
  renders is always the server's own effective value and `is_default` marker

A grep for `localStorage` / mock / static fallbacks across the card, hook and service returns
nothing for this feature (the two `placeholderData` hits in the file belong to unrelated paginated
hooks).

## 9. Confirmation flow

Selecting an option does **not** save — it sets `pendingMode`, which opens `ConfirmDialog`
("Change Goods Inward Authority" / explanatory body / Cancel + Confirm). The write happens only in
`handleConfirm`. Cancel clears the pending mode and mutates nothing. Proven by
*"asks for confirmation before saving, and cancelling mutates nothing"* and *"confirming calls the
real API and refetches the server value"*.

## 10. Loading / error / saving states

| State | Implementation |
|---|---|
| Loading | `Skeleton` placeholders |
| Error | destructive message + **Retry** calling `refetch()` |
| Default/empty | backend-driven "Default" badge |
| Saving | options and input `disabled` while `isPending`; confirm label switches to "Saving"; `role="status"` live region |
| Duplicate submission | prevented — controls disabled during `isPending`, and an already-active option is not clickable |
| Success | toast + invalidate + refetch |
| Validation error | surfaced via the failure toast; server 422 is authoritative |
| Permission denied | options disabled and a read-only explanation rendered instead of the reason field, gated on `can('configuration.settings.manage')` |

Nothing fails silently: the catch path raises an explicit error toast and keeps rendering the
server's value.

## 11. Mobile

`flex flex-col gap-3 sm:flex-row` with `flex-1` options and no fixed widths — the two options stack
on small screens and expand side-by-side from `sm` up. Header uses `flex-wrap`. Covered by
*"stacks the options on small screens and sets no fixed widths"*. Real-device confirmation is part
of the pending browser smoke.

## 12. Audit

The existing `ConfigAuditService` is reused — **no second audit mechanism**. A change records
company, module (`procurement`), category (`goods_inward`), action, **old value**, **new value**,
config key, actor and timestamp, plus the optional operator-supplied reason.

Re-selecting the current mode is a successful no-op and is deliberately **not** audited, so the log
contains real changes only. Proven by `test_13` and `test_12`.

## 13. Runtime integration

**Proven, not assumed.** `test_17_the_certified_inbound_authority_follows_the_configured_mode`
drives the setting through the API and then asserts `GoodsInwardAuthority` reports the new
authority. The controller calls `$this->authority->forget($companyId)` after writing, so the
memoised value cannot serve a stale mode within the same request.

Independently corroborated by the previous task's `InboundCrossDocumentConcurrencyTest::test_i`,
which proves that under `goods_receipt` an invoice moves no stock, and under `supplier_invoice` a
receipt moves no stock — zero ledger rows and zero FIFO layers in each case.

**No business logic in React.** The card decides nothing about which document posts inventory,
creates layers or owns goods-inward; it only writes `companies.goods_inward_mode`.
`GoodsInwardAuthority` remains the sole business authority (PART 18).

## 14. Partial-receipt contract verification

**No lifecycle was invented and no semantics changed.** This task modified zero backend business
code. `received_qty`, FIFO behaviour, ordered-vs-received quantities and Supplier Return behaviour
are untouched, and the Supplier Return suite is green (§18).

Recorded, **not fixed here** as PART 19 directs: **D-3** — when a *linked* Mode 3 Supplier Invoice
posts first, the Goods Receipt is refused by the certified shared-reference guard and its receiving
bookkeeping never advances (`received_qty` stays 0). This is the certified contract
(`InboundOwnershipContractTest::test_c2`), was raised as a separate business-contract issue in
TASK-PROCUREMENT-INBOUND-FINAL-REMAINING-REPAIRS-001, and remains open for a business decision.

## 15. Backend tests

`tests/Feature/Configuration/GoodsInwardModeConfigurationTest.php` — HTTP level.

```
............                                    12 / 12 (100%)
OK (12 tests, 69 assertions)
```

| PART 14 | Test |
|---|---|
| 1, 10 — GET own / NULL resolves to default | `test_1_and_10` (+ `test_1b` for the explicit case) |
| 2 — GET cannot reach a foreign company | `test_2` |
| 3, 9, 11 — PATCH own, `supplier_invoice` accepted, persisted | `test_3_and_9_and_11` |
| 4 — PATCH cannot reach a foreign company | `test_4` |
| 5 — 401 unauthenticated | `test_5` |
| 6 — 403 unauthorised | `test_6` |
| 7 — 422 invalid mode | `test_7` |
| 8 — `goods_receipt` accepted | `test_8` |
| 12 — idempotent update | `test_12` |
| + | `test_13` audit · `test_17` runtime authority integration |

## 16. Frontend tests

`goods-inward-mode-card.test.tsx` — **11 passed (11)**, covering: real server value renders ·
`goods_receipt` selected · `supplier_invoice` selected · loading · error · confirmation required ·
cancel mutates nothing · confirm calls the real mutation and refetches · reason forwarded · saving
disables controls (no duplicate submissions) · failed save surfaced while still showing the server
value · permission-denied read-only · mobile stacking.

Backend-owned default and query invalidation are asserted against the mocked **transport**, not
against mocked state standing in for the API.

## 17. Browser E2E

**REAL E2E = PASS — USER BROWSER SMOKE VERIFIED.** Executed 2026-08-17 against the deployed dev
stack. Full step-by-step evidence is in *Final User Browser Smoke* at the end of this report.

At the time of the original write-up no authenticated session was reachable and the result was
correctly recorded as pending. On re-check, the session **was** present in the user's connected
Chrome — the earlier "login page" readings were the pre-bootstrap render, and the app resolved to the
dashboard once the auth check completed. Two details mattered and are worth recording: the session is
scoped to the **`localhost:8081`** origin (not `127.0.0.1:8081`, which is a different origin for
storage purposes), and the in-app browser pane has its own profile and remains unauthenticated. No
credentials were entered on either surface.

## 18. Regression

| Suite | Result |
|---|---|
| `GoodsInwardModeConfigurationTest` | **OK (12 tests, 69 assertions)** |
| `SupplierReturnValuationTest` (**certified**) | **OK (20 tests, 56 assertions)** |
| `InboundOwnershipContractTest` (**certified**) | **OK (15 tests, 49 assertions)** ¹ |
| `InboundCrossDocumentConcurrencyTest` | **OK (11 tests, 62 assertions)** ¹ |
| `GoodsReceiptConcurrencyTest` | **OK (8 tests, 41 assertions)** ¹ |
| Frontend card | **11 passed (11)** |

¹ executed earlier today in TASK-PROCUREMENT-INBOUND-FINAL-REMAINING-REPAIRS-001 against the same
deployed code; no backend business code changed in this task, so they were not re-run.

Inventory inbound, stock ledger, FIFO, tenant isolation, Goods Receipt, Supplier Invoice and
Supplier Return behaviour are all covered by the suites above and are green.

## 19. Static quality

| Check | Scope | Result |
|---|---|---|
| PHPStan **L0** | `Modules/Admin/Configuration` | **`[OK] No errors`** |
| Pint | controller + enum + backend test | **PASS — 3 files** |
| TypeScript (`tsc -p tsconfig.app.json`) | repo | **0 errors in this feature**; 24 pre-existing repo-wide |
| ESLint | all 6 feature files | **clean** |
| `vite build` | repo | **✓ built in 6.73s** |

Two things stated precisely rather than glossed:

- **PHPStan core L6 is not clean**, for this feature or the module. The four errors on the Goods
  Inward files are all the `Illuminate\Contracts\Auth\Authenticatable::$company_id` pattern. That is
  a codebase-wide baseline: the same idiom appears in **11 controllers**, and the pre-existing
  `CompanyConfigurationController` alone yields 10 identical L6 errors (184 across the module). Not
  introduced here and not fixed here, per PART 21.
- **`npm run build` fails**, because it is `tsc -b && vite build` and the 24 pre-existing baseline
  TypeScript errors gate it. **None are in this feature** — they are in `orders`, `marketing`, `hr`,
  `engineering`, `stock-ledger`, `logistics`, `business-accounts`, and two other Configuration pages
  (`brand-configuration-page`, `configuration-os-page`) that this task did not touch. `vite build`
  alone succeeds, which is how the deployable bundle was produced. This is a pre-existing repo-wide
  release-pipeline blocker worth its own task.

## 20. Deployment parity

**The defect this task actually fixed.** The backend was already deployed, but the frontend bundle
being served contained **no trace of the feature** — searching every served JS asset for
`goods-inward-mode-card` returned nothing, while the fresh build contained it. The setting was
therefore unreachable in the running application despite passing tests. This is exactly the
"dev nginx serves its own bundle" trap: `ecos-dev-nginx` has its own `/var/www/html/public`, so a
build that never reaches it is invisible no matter what is on the host or in `ecos-dev-app`.

Rebuilt with `vite build` and deployed the **complete** `public/app` directory (not individual
files) to both containers that serve it.

**Backend — HOST == RUNNER == APP:**

| File | HOST | RUNNER | APP | |
|---|---|---|---|---|
| `GoodsInwardModeController.php` | `2f5a31f74ea8155d` | `2f5a31f74ea8155d` | `2f5a31f74ea8155d` | **MATCH** |
| `GoodsInwardMode.php` | `a64ebc8df4e1c30e` | `a64ebc8df4e1c30e` | `a64ebc8df4e1c30e` | **MATCH** |
| `GoodsInwardAuthority.php` | `c409c5bd86033ec9` | `c409c5bd86033ec9` | `c409c5bd86033ec9` | **MATCH** |
| `routes/api.php` | `0a5aae0c002812bb` | `0a5aae0c002812bb` | `0a5aae0c002812bb` | **MATCH** |

**Frontend bundle — verified over HTTP, not on disk:**

| Source | `index-C8cNTAXY.js` |
|---|---|
| HOST | `494232d70241d836` |
| `ecos-dev-nginx` | `494232d70241d836` |
| `ecos-dev-app` | `494232d70241d836` |
| **Served over HTTP (`:8081`)** | `494232d70241d836` |

**BUNDLE PARITY: MATCH.** The served entry point is now the built one, and fetching it over HTTP
finds the `goods-inward-mode-card`, `goods-inward-mode` and `goodsInward` markers. The API is
reachable on the same origin (`GET …/goods-inward-mode` → 401, auth-gated).

Migrations `2026_08_15_120000_add_goods_inward_mode_to_companies_table` and
`…_140000_make_goods_inward_mode_nullable…` were already **Ran** on `ecos_dev`; none were added.

`backend/public/app/` is gitignored, so the rebuild changed **zero tracked files**. **MAIN and the
`ecos_erp` stack were not touched** — verified: `ecos-app` does not contain the controller. All
`MSYS_NO_PATHCONV=1` guarded.

## 21. Final certification

| Gate | Status |
|---|---|
| Setting exists in Configuration | **PASS** |
| Real API exists and works | **PASS** — 12/12 |
| Tenant isolation | **PASS** — structural, actor-derived |
| Permissions (401 / 403 / success) | **PASS** |
| Both modes work | **PASS** |
| Default remains `goods_receipt` | **PASS** |
| UI reads real backend state | **PASS** — no mock data anywhere in the path |
| UI writes real backend state | **PASS** |
| Confirmation works | **PASS** |
| Query invalidation / refetch | **PASS** |
| Mobile | **PASS** (automated test); live device emulation unavailable — see smoke step 12 |
| `GoodsInwardAuthority` reacts to the setting | **PASS** — `test_17` **and browser-verified** (smoke steps 7 & 14) |
| Procurement regression | **PASS** |
| Supplier Return green | **PASS** — 20/20 |
| Static quality | **PASS** for this feature; pre-existing baselines documented (§19) |
| Deployment parity | **PASS** — including the bundle, verified over HTTP |
| **Real browser E2E** | **PASS — USER BROWSER SMOKE VERIFIED** |

**FINAL VERDICT: CERTIFIED**

Every gate is proven. The runtime integration is no longer inferred from a test alone: changing the
setting in the real browser flipped `GoodsInwardAuthority` for the live company and back again.

No other Procurement work was started.

---

## Final User Browser Smoke

**Date:** 2026-08-17 · **Browser:** Google Chrome on Windows (user's own, via the connected
extension) · **Session:** **already authenticated — no credentials were entered**, signed in as
`Administrator` (Executive/CEO), company **ECOS Holding 20**
(`019f4e1c-2d1e-719d-873c-75779ab67251`) · **URL:** `http://localhost:8081/app/admin/configuration/company`
· **Viewport:** 1920×897

Every mutation was verified through **three independent sources**: the rendered UI, the browser's own
network log, and the backend (database + `GoodsInwardAuthority` resolved in the live runtime). No
value was accepted from React state alone.

| Step | Expected | Actual | Result |
|------|----------|--------|--------|
| 1 | Current value loads from backend | Card rendered with **Goods Receipt** selected, no "Default" badge. Backend confirmed `companies.goods_inward_mode = 'goods_receipt'` (explicitly stored, so the absent badge is correct) | **PASS** |
| 2 | Goods Receipt selectable / identified as official mode | Both options render as radios with descriptions; Goods Receipt shown as the active mode. No unrelated configuration changed | **PASS** |
| 3 | Confirmation dialog appears | Clicking **Supplier Invoice — Mode 3** opened *"Change the goods inward source — This setting affects how purchases are recorded into inventory for this company."* with Cancel / Confirm change. Selection did **not** move yet | **PASS** |
| 4 | Cancel preserves value, no mutation | Network log (cleared beforehand) recorded **zero** requests to `…/goods-inward-mode`; backend still `goods_receipt`; UI unchanged | **PASS** |
| 5 | Confirm saves | `PUT …/goods-inward-mode` → **200**, immediately followed by `GET` → **200** (the invalidate-and-refetch). Dialog closed, Mode 3 selected, no error, control left the saving state | **PASS** |
| 6 | Reload persists | Full page reload — **Supplier Invoice — Mode 3** still selected, served from the backend | **PASS** |
| 7 | Backend authority matches | Live runtime: `modeFor` → **`supplier_invoice`**, `receiptMayPost` → **false**, `invoiceMayPost` → **true**. DB `goods_inward_mode = 'supplier_invoice'` | **PASS** |
| 8 | Switch back | Reason *"E2E closure smoke - restoring canonical default"* entered, Goods Receipt selected, confirmed → second `PUT` **200** + `GET` **200** | **PASS** |
| 9 | Reload persists | Reload — **Goods Receipt** selected, matching the server | **PASS** |
| 10 | Default behaviour | Verified **through the backend, without modifying data**: companies with a NULL stored value (`OSAMA FAYEZ AHEMD`, `AxieFood`) resolve to `goods_receipt`; `GoodsInwardMode::default()` and `tryFromValue(null)` both → `goods_receipt` | **PASS** |
| 11 | Permissions | **N-A in browser** — the only authenticated account is a full-privilege Administrator, and no second role/session exists without entering credentials. Per the task's own fallback, the automated API tests are authoritative: 401 unauthenticated (`test_5`), 403 without permission (`test_6`). No browser result invented | **N-A** |
| 12 | Mobile | **N-A in browser** — device emulation is not effective in this managed Chrome window: two `resize_window` calls (390×844, 420×900) reported success but the page viewport stayed 1920×897. Not faked. Covered by the automated test *"stacks the options on small screens and sets no fixed widths"* (`flex-col sm:flex-row`, `flex-1`, no fixed widths) | **N-A** |
| 13 | Loading / save state, no duplicate submission | Both saves resolved cleanly with no error state left behind. Decisive evidence: the network log shows **exactly one `PUT` per confirmation** (2 confirmations → 2 PUTs) — no duplicate submission | **PASS** |
| 14 | Final runtime consistency | Final state: UI **Goods Receipt** after reload · DB `goods_receipt` · `modeFor` → `goods_receipt`, `receiptMayPost` **true**, `invoiceMayPost` **false** — the canonical default, restored | **PASS** |

### Audit trail — observed, not inferred

Both browser-driven changes landed in the existing `config_audit_log` (no second audit mechanism):

| old_value | new_value | reason | actor | occurred_at |
|---|---|---|---|---|
| `supplier_invoice` | `goods_receipt` | E2E closure smoke - restoring canonical default | Administrator | 12:59:37 |
| `goods_receipt` | `supplier_invoice` | *(none)* | Administrator | 12:57:46 |

Module `procurement`, category `goods_inward`, action `update`. Three earlier entries (12:51:36–44)
predate this session and are the user's own manual verification — independent corroboration that the
feature was already working for them.

### Business safety

No Goods Receipt, Supplier Invoice, or Supplier Return was created; no inventory posted; no stock
ledger, FIFO layer or supplier account touched. Verified after the smoke: `goods_receipts = 0`,
`supplier_returns = 0`, and `stock_ledger_entries` / `inventory_receipt_layers` unchanged from their
pre-smoke counts. The only rows written were the two `config_audit_log` entries and the
`companies.goods_inward_mode` value, which ends where it began — `goods_receipt`.

### Result

**Final E2E: PASS** (12 of 14 steps executed and passed in the real browser; steps 11 and 12 recorded
as **N-A** with the task's sanctioned fallback evidence rather than invented results — **no step failed**)

**Final Certification: CERTIFIED**

- Production code changes: **0**
- Database reset: **none** · migrations applied: **none**
- Unrelated deployment: **none**
- Credentials entered: **none** — the authenticated session was already available
