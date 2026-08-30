# TASK-ECOS-ADR-042-BASELINE-RECONCILIATION-DESIGN-001 — Engineering Report

**ADR-042 Certification + `C:\ecos-develop` Safe Reconciliation Design**
Mode: **READ-ONLY ARCHITECTURE / AUDIT / DESIGN** · Date: 2026-08-30
Primary: `C:\ecos-develop` @ `72ecaddc` · Published baseline: `2b851c14` · Remediation clone: `C:\ecos-baseline-remediation`
**No source edit, no git mutation, no reconciliation executed.**

---

## 1. Executive Summary

The published baseline `2b851c14` (= `72ecaddc` + 2 approved Guardian-remediation commits) is on remote `develop` and Guardian-clean. `C:\ecos-develop`'s local `develop` sits at `72ecaddc` (2 behind the remote) with **497 dirty paths** (139 modified + 358 untracked) that were deliberately deferred — **the bulk of which is the ADR-042 implementation and everything transitively coupled to it.** ADR-042 (Order FSM V3 Canonical) is an **Approved** cross-cutting contract whose *document* is already committed in the baseline, but whose *runtime implementation* (the `OrderStatus` vocabulary change, `PaymentFulfillmentGate`, `payment_proofs`, the fulfillment workflows, the normalisation migration, and the three-module eligibility lists) remains in the deferred set.

The safe reconciliation is **not** a `git pull`. Adopting `2b851c14` overlaps the deferred set in exactly 3 files (2 are genuine collisions), and the deferred set contains 358 untracked files a naïve advance would strand. **Recommendation: a preservation-first strategy (Option B/D)** — capture the entire dirty set as a recoverable git artifact, fast-forward `develop` onto the baseline, and integrate the ADR-042 deferred work only **after ADR-042 is certified**, as a relocation-ready fresh primary. Distribution and Mobile remain **BLOCKED on ADR-042**; Finance is **READY**.

**FINAL STATUS: DESIGN COMPLETE — READY FOR CTO REVIEW.**

## 2. Execution Environment

Read-only inspection of `C:\ecos-develop` (source), `C:\ecos-baseline-remediation` (clone, has both `72ecaddc` and `2b851c14`), the shared canonical repo `C:\Projects\ECOS-ERP`, and the tracked ADR at `docs/adr/ADR-042-order-fsm-v3-canonical.md`. This audit builds on the prior consolidation's per-path classification (a 13-agent read-only workflow) and its backend/frontend build-closure analyses. (`docs/CLAUDE.md` is a stale planning template — actual stack is Laravel 11 / MySQL 8.4 / React+Vite, not the "Laravel 12 / Next.js / PostgreSQL / Planning Phase" it lists; it is not authoritative.)

## 3. Current Primary Tree State (`C:\ecos-develop`)

| Field | Value |
|---|---|
| root / branch | `C:/ecos-develop` / `develop` |
| HEAD (local develop) | `72ecaddc` ✅ (matches expected) |
| remote develop | `2b851c14` (published) |
| ahead / behind remote | 0 ahead / **2 behind** (the 2 remediation commits) |
| staged | 0 |
| modified (tracked) | **139** (unchanged invariant since consolidation) |
| untracked | 358 (494 deferred set + 3 report deliverables added by these baseline tasks) |
| total dirty (`-uall`) | **497** |

The previously reported state is still accurate; the only delta since the last report is +3 untracked task-report `.md` files (this task's own deliverables). No tracked file changed. `origin/develop`'s cached tracking ref in the source is still `f0d7822a` (the source has not fetched the published tip — read-only, intentional).

## 4. Published Baseline State

Remote `develop` = **`2b851c14ee71ac82d87ff7720d6d39ddf670318d`**. It descends from `72ecaddc` (ancestor confirmed) via 2 commits and passed the full Guardian pre-push suite (PHP-syntax, Laravel-bootstrap, Pint, PHPStan, ESLint, TypeScript, Vite build) with no bypass.

## 5. ADR-042 Authority

Authoritative source: **`docs/adr/ADR-042-order-fsm-v3-canonical.md`** — *"ADR-042: Order FSM — V3 Canonical"*, **Status: Approved**, v1.0 dated **2026-08-13**, author *Engineering Architecture Review*, amended 2026-08-21 (D1-A financial control) and 2026-08-23 (three owner amendments A/B/C). It **discharges** ADR-005 §5's deferred FSM, **supersedes** the status vocabulary of migration `2026_07_22_100000_simplify_order_lifecycle_v3` (the migration file itself is preserved, §9), and is **Related** to ADR-005/023/027. The doc is committed in the baseline; its implementation is deferred (this report's subject).

## 6. ADR-042 Full Contract (classified)

| # | Rule | Class |
|---|---|---|
| §2 | Canonical `OrderStatus` = 11 cases; **`new` removed from the runtime enum**; pre-V3 vocab (`pending/processing/preparing/completed/review/rescheduled`) accepted nowhere | MUST MIGRATE / MUST REMOVE |
| §2.1 | `confirmed` restored as a first-class state | MUST PRESERVE |
| §2.2 | `in_progress` becomes the **unlocked entry state**; structural lock begins at `confirmed`; unlocked = `{in_progress, scheduled, awaiting_payment}` | MUST PRESERVE |
| §3 | Pick-and-stay entry contract: create only in `in_progress`/`scheduled`/`awaiting_payment`; `confirmed` never an entry status | MUST PRESERVE |
| §3.1 | `payment_proof_policy: required` = mandatory financial control; fulfilment-eligible requires `deposit>=total` **and** an active `verified` `payment_proofs` row; proof-required method always created `awaiting_payment`; audited via `entry_status_overridden_by_payment_proof_policy` event | MUST PRESERVE |
| §4 | Payment method may **not** determine lifecycle status (`PAYMENT_CLEAR_STATUS_PREFERENCE` removed) | MUST REMOVE |
| §5 | Transitions: **Confirm** `in_progress→confirmed` at `POST /fulfillment/orders/{order}/confirm`; **Unlock** `confirmed→in_progress`; `awaiting_stock` only by reservation | MUST PRESERVE |
| §6 | **Reservation boundary UNCHANGED** — Confirm is NOT the reservation trigger; reservation stays in `ProcessOrderWorkflow` (`initiate_order`) at `in_progress`; governed by ADR-027 | MUST NOT DUPLICATE / MUST PRESERVE |
| §7 | Fulfilment eligibility = `['in_progress','confirmed']`; **Preparation, Distribution and Wave Engine each keep their OWN closed list** (Distribution deliberately does not import Preparation's) | MUST PRESERVE (three parallel lists) |
| §7.1 | Payment-fact trigger advances `awaiting_payment→in_progress` only (never `→confirmed`); method-change = re-evaluation only | MUST PRESERVE |
| §8 | `LEGACY_STATUS_MAP` read-time repair prohibited; normalise once by migration | MUST REMOVE (repair) / MUST MIGRATE |
| §9 | V3 migration `2026_07_22_100000` **not modified** (historical); July `confirmed→in_progress` merges not recoverable | MUST PRESERVE (do not touch) |
| §11 | Deployment ordering: normalisation migration is **raw-SQL, idempotent**, same deploy as enum change; deploy code → migrate → serve | DEFERRED (deploy-time constraint) |
| §12 | Enforcement: `OrderStatus` single source of truth; FormRequests derive from `OrderStatus::cases()`; statuses from `GET /orders/statuses`; writes via `FulfillmentEngine`; eligibility lists asserted by tests | MUST PRESERVE |

**Cross-cutting impact:** Order/Commerce (owner), **Preparation / Distribution / Wave Engine** (each a §7 eligibility consumer), Operations/Fulfillment (Confirm/reevaluate workflows), Finance (payment-proof verify), Procurement (unaffected), Manufacturing (`ManufacturingPolicy` references eligibility), API routes (`/fulfillment/orders/{order}/confirm`, `/orders/statuses`, `/orders/{order}/payment-proofs`), database schema (`payment_proofs` table, `orders.status` default, normalisation migration), runtime (`FulfillmentEngine` write-guard). **Navigation and shared FE components are NOT part of ADR-042** — that coupling is a separate deferred nav restructure.

## 7. ADR-042 Source Ownership Map

**64 dirty files reference ADR-042 runtime symbols** (`OrderStatus::Confirmed`, `fulfilmentEligible()`, `entryStatuses()`, `PaymentFulfillmentGate`, `PaymentProof`, `ReevaluateOrderFulfillmentAction`, …): Commerce 25, backend tests 19, Operations 10, FE orders 5, Logistics 3, `backend/routes/api.php` 1, `backend/config/distribution.php` 1. The broader ADR-042 changeset (incl. files that don't literally name a symbol but belong to the unit) spans **Commerce/Orders (52)**, **FE orders (18)**, **Operations/Fulfillment (7)**, plus coupled Logistics trip-execution and ~30 tests. All are **dirty and NOT present in `2b851c14`** (deliberately excluded); all carry an ADR-042 dependency; the eventual action is *integrate as approved commits once ADR-042 is certified* — never a blind adopt.

## 8. `72ecaddc → 2b851c14` Delta

Exactly **2 commits, 36 files, +96/−93** — the approved Guardian remediation and nothing else:
- `0ce8c357` fix(baseline): resolve committed static-analysis defects (RuntimeException import; `RulePostingStrategy::roleForInventoryClass()` forward-closure; removal of 4 stale `OperationResult` PHPStan baseline entries).
- `2b851c14` style(baseline): Pint formatting on the 33 flagged files.

**Content overlap with the dirty source tree = 3 files** (the §5 collision analysis):

| Delta file | Overlap nature | Reconciliation effect |
|---|---|---|
| `RulePostingStrategy.php` | The source's uncommitted change **is the same** `roleForInventoryClass()` addition the remediation committed | **Clean** — after adopting `2b851c14`, the source's change is already present; the file becomes non-dirty (no conflict, no loss) |
| `phpstan-baseline-platform.neon` | Source modified it for ADR-042; remediation removed 4 stale entries | **COLLISION** — both edited the same shared file differently; must be merged (union of the ADR-042 baseline needs minus the stale entries) |
| `V3TransitionResolutionTest.php` | Source holds an uncommitted ADR-042 version (uses `OrderStatus::Confirmed`); baseline holds the pint-styled committed version | **COLLISION** — the source's ADR-042 test must be re-applied onto the pint-styled base when ADR-042 lands |

## 9. Dirty Working Set Classification (497 paths)

| Group | Class | ~files | Representative paths | Evidence | In `2b851c14`? | Overwrite risk on adopt |
|---|---|---|---|---|---|---|
| Commerce/Orders | **A — ADR-042 REQUIRED** | 52 | `Domain/Enums/OrderStatus.php`, `Domain/Services/PaymentFulfillmentGate.php`, `Domain/Models/PaymentProof.php`, supersede migration | ADR-042 §2–§12; in-tree "No commit" reports | No | Preserved (untracked/modified) — protect |
| Operations/Fulfillment | **A — ADR-042 REQUIRED** | 7 | `Application/Workflows/{Confirm,Process,MoveToPreparation,…}Workflow.php` | use `PaymentFulfillmentGate`/`fulfilmentEligible()` | No | Protect |
| FE orders | **A — ADR-042 REQUIRED** | 18 | `features/orders/**` payment/proof/confirmed cells | call ADR-042 backend | No | Protect |
| Logistics (trip-exec) + coupled | **A — ADR-042 REQUIRED** | ~9 | `DriverDaySettlementReadService`, `PreparationEligibilityReader`, `OrderCityBinder` | use `PaymentProof`/`OrderGeographyChanged`/eligibility | No | Protect |
| backend tests (ADR-042) | **A — ADR-042 REQUIRED** | ~30 of 38 | `tests/Feature/{Commerce,Orders,Operations}/…` | reference `OrderStatus::Confirmed`/`PaymentProof` | No | Protect |
| Inventory | **B — APPROVED, DEFERRED** | 20 | `Products`/`InventoryItems` availability/unit convergence | approved (freeze); left for coherence | No | Protect |
| Manufacturing | **B/C — DEFERRED VALID** | 9 | BOM/recipe extras beyond the committed primitives | approved-adjacent | Partly (primitives in 2b851c14) | Protect |
| Logistics (non-coupled) | **B — APPROVED, DEFERRED** | balance of 9 | logistics services not committed | approved | No | Protect |
| FE other features | **D — WIP / NOT APPROVED** (subset) + **B** | 57 | `admin/configuration`, `hr`, `marketing`, `engineering`, `business-accounts`, `stock-ledger`, `orders/manual-order-form`, `logistics/dispatch` | **the 24 pre-existing tsc errors live here** | No | Protect (do not adopt into baseline) |
| FE nav/router (shared) | **C — DEFERRED VALID (separate restructure)** | 6 | `config/navigation.ts`, `config/module-navigation.ts`, `router.ts` | multi-workstream nav restructure; unverified; lazy-imports deferred pages | No | HIGH collision (see §14) |
| FE shared components | **C — DEFERRED VALID** | 9 | `components/layout/*`, `components/ui/*` | nav/RBAC shell coupling | No | MEDIUM collision |
| backend routes/config (shared) | **A/C — MIXED** | 3 | `routes/api.php`, `config/distribution.php`, `config/permissions.php` | multi-workstream drift incl. ADR-042 routes | No | CRITICAL collision (see §14) |
| docs/reports | **C — DEFERRED VALID (traceability)** | 234 | `TASK-*-REPORT.md`, some ADRs | task audit trail; some ADR-042-topic | No (most) | LOW — additive |
| misc / backend other | **G — UNKNOWN / review** | ~9 | assorted | no clear owner | No | Protect / review |

No **E — SUPERSEDED** and no **F — GENERATED/LOCAL** groups exist inside the source tree (superseded work lives in `agent-ad776`, not here; generated artifacts are gitignored, so none appear).

## 10. Approved Completed Work (deferred)

Beyond the baseline's 684 committed files, the dirty tree still holds **approved-but-deferred** work left uncommitted only because it was coupled to ADR-042 or to the nav restructure: parts of Inventory/Manufacturing extras, the non-coupled balance of Logistics, and the ADR-042 implementation itself (approved as a *design* via the committed ADR, pending *certification* of the code). These must be integrated, not discarded.

## 11. Deferred Valid Work

The nav restructure (`navigation.ts`/`module-navigation.ts`/`router.ts` + layout components), the 234 report docs (traceability), and the ADR-042 test suite. Valid, intentionally deferred, must be preserved.

## 12. WIP / Unknown Work

The **24 pre-existing tsc errors** (predating the baseline) live in uncommitted WIP features: `admin/configuration`, `hr`, `marketing`, `engineering`, `business-accounts`, `stock-ledger`, `orders/manual-order-form`, `logistics/dispatch`. Class **D** — not to be adopted into any baseline until fixed; class **G** for a handful of ownerless backend/misc paths.

## 13. Superseded Work

**None inside `C:\ecos-develop`.** The only superseded body is `agent-ad776`'s `Modules/Operations/Distribution` prototype, which lives in a separate linked worktree (§19), not in the source tree.

## 14. Shared-File Collision Matrix

| File / group | Dirty? | In `2b851c14`? | Risk | Why |
|---|---|---|---|---|
| `backend/routes/api.php` | Yes | Baseline has `72ecaddc` version (NOT committed in delta) | **CRITICAL** | Multi-workstream drift; references classes absent at HEAD incl. ADR-042 routes (`/fulfillment/…/confirm`, `/orders/…/payment-proofs`); must never be copied wholesale — reconcile as the union of approved route additions |
| `frontend/src/config/navigation.ts` / `module-navigation.ts` / `router.ts` | Yes | No | **HIGH** | Nav restructure spanning ~7 tasks incl. unverified/blocked pages; `router.ts` lazy-imports still-deferred pages; adopting must not import the restructure |
| `backend/phpstan-baseline-platform.neon` | Yes | **Yes (delta removed 4 entries)** | **HIGH** | Edited in BOTH the baseline (stale-entry removal) and the source (ADR-042 needs); reconcile as union, do not clobber either |
| migrations (`**/Migrations/*.php`) | Yes (50 uncommitted; ADR-042 supersede + payment_proofs among them) | Committed ones in baseline; ADR-042 ones not | **HIGH** | §11 deploy ordering + no-duplicate-authority; the ADR-042 normalisation migration is raw-SQL/idempotent and must deploy with the enum change |
| driver-mobile / shared i18n | Yes (some) | i18n union committed at `9a75b3a7` | **MEDIUM** | Baseline already carries the union of keys; source deltas must merge additively, never replace |
| `UniversalDataGrid` / `EntityTable` / shared `components/ui` | Mixed | Committed versions in baseline | **MEDIUM** | Additive props committed; source changes must merge, not overwrite |
| Pint-formatted files (33) | 1 overlaps (`V3TransitionResolutionTest`) | Yes | **LOW–MEDIUM** | Style already in baseline; source's ADR-042 edits re-apply onto the styled base |
| `RulePostingStrategy.php` | Yes | **Yes (identical change)** | **LOW** | Same content both sides → resolves clean |
| engineering context / `docs` / task-index / MEMORY | Yes (reports) | No | **LOW** | Additive; append, do not overwrite |
| composer/package files | Not dirty (verified: no composer.json/package.json in the 139 modified) | — | **LOW** | No dependency drift to reconcile |

## 15. Distribution ADR-042 Readiness

- **Contracts depending on ADR-042:** Distribution's §7 fulfilment-eligibility list must be exactly `['in_progress','confirmed']` (its own closed list). Its trip-execution/driver-runtime layer also uses `PaymentProof`/`PaymentState`/`OrderGeographyChanged` (ADR-042/order-coupled).
- **In `2b851c14`?** The **adopted architecture** (`Modules/Logistics/Distribution` group/template/window/fleet + engine + migrations) IS committed. The **trip-execution/driver-runtime/settlement-read** layer is **not** (deferred, ADR-042-coupled).
- **Only in dirty tree?** Yes — that coupled layer + the `['in_progress','confirmed']` eligibility wiring.
- **Unimplemented vs uncertified?** Largely **uncertified, not missing** — the code exists in the dirty tree; it is coupled to the un-certified ADR-042 unit.

**DISTRIBUTION ADR-042 STATUS: BLOCKED** — blocker: the trip-execution/driver-runtime layer and the §7 eligibility list depend on the un-certified ADR-042 implementation (Commerce `OrderStatus::Confirmed`/`PaymentProof`).

## 16. Mobile ADR-042 Readiness

- **ADR-042 relevance:** LOW/indirect. Mobile depends chiefly on the **navigation restructure** and shared responsive components, plus the driver-mobile FE (committed) and DriverShell isolation.
- **Blocker is NOT ADR-042** — it is the deferred **nav restructure** (`navigation.ts`/`router.ts`/module-navigation + layout), which is a separate deferred unit, and the approved-but-not-implemented Mobile Responsive Foundation (Mobile UX is DESIGN-APPROVED only).

**MOBILE ADR-042 STATUS: BLOCKED (by nav restructure, not by ADR-042 directly)** — blockers: uncommitted shared nav restructure + Mobile Responsive Foundation not yet implemented.

## 17. Finance ADR-042 Readiness

Finance is **independent of ADR-042**. The baseline `2b851c14` contains the required current contracts: Procurement (PO-driven receiving, Purchase Material), **Supplier Invoice commercial + AP payment READ model** (`SupplierInvoicePaymentSummary`), Supplier-Ledger/AP subledger config + supplier opening balances, the **canonical `App\Core\Responses\OperationResult`** namespace (the stale `Modules\Shared\Application\OperationResult` PHPStan entries were cleaned in `2b851c14`), and the **`RulePostingStrategy::roleForInventoryClass()` closure** (committed in `2b851c14`). Payment *verify* touches ADR-042's proof lifecycle, but the AP read/posting contracts do not.

**FINANCE ADR-042 STATUS: READY** — no ADR-042 dependency; contracts are in the published baseline.

## 18. Baseline Remediation Clone Status

`C:\ecos-baseline-remediation`: branch `task/completed-baseline-guardian-remediation`, HEAD **`2b851c14`** (byte-identical to the published tip), **clean**. It exactly represents `2b851c14` and can serve as trustworthy temporary baseline evidence and as a Guardian-verified seed for a relocation clone. **Recommendation: KEEP UNTIL RELOCATION.**

## 19. agent-ad776 Disposition

`agent-ad776` (worktree, `e14b17a6`, base **2026-07-11 — predates ADR-042 of 2026-08-13**) holds the old `Modules/Operations/Distribution` prototype, which is **absent (0 files) from the published baseline** — superseded by `Modules/Logistics/Distribution`+`Fleet`. ADR-042 does **not** change this: ad776 both predates ADR-042 and uses the abandoned architecture. **Disposition: KEEP ARCHIVED** (do not merge/delete/reassess).

## 20. Reconciliation Options

- **Option A — reset-and-reapply:** snapshot dirty → `reset --hard`/recreate tree to `2b851c14` → reapply preserved sets.
- **Option B — preservation-branch + advance + selective restore:** commit the entire dirty set to a `wip/adr-042-deferred` branch (recoverable) → fast-forward `develop` to `2b851c14` → integrate deferred sets from the wip branch when ADR-042 is certified.
- **Option C — fresh clean clone as new primary:** clone `2b851c14` → port only validated deferred sets → promote as primary (relocation-native).
- **Option D — hybrid B+C:** preservation-branch/bundle for safety, then realise the advance as a fresh relocation clone; integrate ADR-042 deferred work into it post-certification.

## 21. Recommended Reconciliation Strategy

**Option D (hybrid), preservation-first.** Rationale: it preserves *everything* in git before touching anything (zero data-loss window), advances the baseline cleanly, keeps a linear auditable history, and produces a relocation-ready primary — while keeping the un-certified ADR-042 work isolated on a branch until it is certified. It avoids blind pull, destructive reset of the only copy, anonymous stash, commit-all, and wholesale route/nav copies (all of which the mandate forbids).

## 22. Exact Future Execution Sequence (for a later, CTO-approved task — NOT executed here)

1. **Freeze writers** on `C:\ecos-develop` (single exclusive writer; confirm no peer session).
2. **Create a preservation artifact BEFORE any mutation:** `git bundle create` of the full repo **and** a `wip/adr-042-deferred-<date>` branch from `72ecaddc` capturing the entire dirty set — tracked modifications **and** all 358 untracked files — via explicit, classified `git add` of groups A/B/C/D/G (never a blind `add -A`; class-labelled commits so the set is auditable). Verify `git diff wip <worktree>` is empty (nothing left behind, especially untracked).
3. **Snapshot inventory:** record the 497-path manifest + this report's Table A as the reconciliation ledger.
4. **Separate approved vs deferred sets** on the wip branch into labelled commits: `A ADR-042`, `B approved-deferred`, `C deferred-valid (nav/docs)`, `D WIP`, `G unknown`.
5. **Advance the baseline:** with the working tree now clean (all captured on wip), fast-forward `develop` to `origin/develop` (`2b851c14`) — a true fast-forward (2 commits). No merge, no reset of un-captured work.
6. **Reapply specific sets:** integrate only class **B** (approved-deferred, non-ADR-042) onto `develop` as reviewed commits; leave classes **A/C/D/G** on the wip branch.
7. **Reconcile the shared collisions** deliberately (§14): `phpstan-baseline` = union (ADR-042 needs minus the 4 removed stale entries); `V3TransitionResolutionTest` = re-apply source's ADR-042 edits onto the pint-styled base; `routes/api.php`/nav = reconcile the *approved* additions only, never wholesale copy.
8. **Targeted static checks** (no broad regression): Guardian gates (Pint/PHPStan/ESLint/tsc/build) on the candidate, isolated as in the remediation task.
9. **Produce a candidate HEAD** (descendant of `2b851c14`).
10. **Guardian** must pass genuinely (no `--no-verify`).
11. **Publish after CTO approval** only.
> The **ADR-042 class-A integration is a separate step gated on ADR-042 *certification*** — it is not part of the baseline advance. Distribution/Mobile unblock only after that.

## 23. Rollback Strategy

Every step is reversible: the `git bundle` + `wip` branch are the master recovery point (the full dirty state is restorable byte-for-byte). The baseline advance is a fast-forward, revertible by resetting `develop` back to `72ecaddc` (both SHAs retained). No step deletes the only copy of any file; untracked files are captured before any tree change. The remediation clone (`2b851c14`) is an independent third copy of the baseline.

## 24. Relocation Considerations

The project will move off `C:`. **Before relocation:** create the preservation artifact (bundle + wip branch) and confirm the published baseline is authoritative on `origin`. **The relocation vehicle should be Option C** — clone `2b851c14` (or the verified remediation clone) onto the new drive as the new primary, then carry the `git bundle`/`wip` branch across and integrate class-B (and later class-A after ADR-042 certification) there. **After relocation:** ADR-042 certification + class-A integration + Distribution/Mobile lane creation. Do **not** create `C:\ecos-distribution` / `C:\ecos-mobile` / `C:\ecos-finance` yet.

## 25. Parallel Lane Readiness

| Lane | ADR-042 dependency | Baseline dependency | Status | Blocker |
|---|---|---|---|---|
| **Finance** | None | Contracts in `2b851c14` | **READY** | none (lane creation deferred to post-relocation) |
| **Distribution + Loading** | High (trip-exec + §7 eligibility) | `Logistics/Distribution`+`Fleet` in `2b851c14`; trip-exec deferred | **BLOCKED** | un-certified ADR-042 implementation |
| **Mobile UX** | Low/indirect | driver-mobile FE + i18n + routes in `2b851c14`; nav deferred | **BLOCKED** | nav restructure + Responsive Foundation (not ADR-042) |

## 26. STOP Conditions

- **ADR-042 certification is a prerequisite** for class-A integration and for Distribution readiness — do not integrate the ADR-042 code until certified.
- Reconciliation must not begin until the preservation artifact exists and is verified (untracked included).
- `routes/api.php` and the nav restructure must never be copied wholesale.
- The shared-file collisions (§14) require deliberate merge, never clobber.
- No lane clones, no relocation, no execution until CTO approves this design.

## 27. CTO Decisions Required

1. **Approve the recommended reconciliation strategy (Option D, preservation-first)** and authorise a separate execution task with the §22 sequence.
2. **ADR-042 certification** — schedule the certification pass that unblocks class-A integration and Distribution.
3. **Relocation ordering** — confirm Option C (fresh `2b851c14` clone as new primary on the new drive) as the relocation vehicle, and what completes before vs after the move (§24).
4. **WIP tsc-error cleanup** (class D) — authorise a separate fix of the 24 pre-existing FE errors so those features can eventually be adopted.
5. **`agent-ad776` / remediation clone** — confirm KEEP ARCHIVED / KEEP UNTIL RELOCATION.

---

## Table A — Dirty path/group classification

*(See §9 for the full table.)* Columns: PATH/GROUP · WORKSTREAM · CLASSIFICATION (A–G) · IN 2b851c14? · DIRTY? · ADR-042 RELEVANT? · FUTURE ACTION. Summary: A (ADR-042 required) ≈ 116 files across Commerce/Ops-Fulfillment/FE-orders/coupled-Logistics/tests; B (approved-deferred) ≈ Inventory/Mfg/Logistics balance; C (deferred-valid) = nav restructure + 234 docs; D (WIP) = the 24-tsc-error features; G (unknown) ≈ 9. All are DIRTY, none in `2b851c14`, future action = preserve → integrate per class (A gated on ADR-042 certification).

## Table B — Lane readiness

*(See §25.)*

## Table C — Reconciliation options

| Option | Data-loss risk | Conflict risk | Auditability | Relocation-friendly? | Recommended? |
|---|---|---|---|---|---|
| A reset-and-reapply | **High** (reset before full capture is fatal; untracked easily missed) | High (reapply onto changed base) | Medium | Medium | No |
| B preservation-branch + advance | Low (git-captured first) | Medium (shared-file merges) | **High** (labelled commits) | Medium | Partial |
| C fresh clone as new primary | Low (source untouched) | Low–Medium (deliberate port) | High | **High** (native to the move) | Partial |
| **D hybrid B+C (preservation-first)** | **Lowest** | Medium (managed §14 merges) | **High** | **High** | **YES** |

---

FINAL STATUS: **DESIGN COMPLETE — READY FOR CTO REVIEW**
DISTRIBUTION: BLOCKED (ADR-042) · MOBILE: BLOCKED (nav restructure) · FINANCE: READY
`C:\ecos-develop`: **UNTOUCHED** (read-only audit; HEAD `72ecaddc`, 139 modified invariant intact)
NEXT: **ADR-042 CERTIFICATION → then preservation-first reconciliation (§22) → relocation (Option C)**
