# TASK-PREPARATION-WAVE-ORDERS-WORKSPACE-REFINEMENT-002 — Engineering Report

**Date:** 2026-08-13 · **Branch:** `develop` · **HEAD:** `6149875b`
**Verdict:** **NOT CERTIFIED — RUNTIME VERIFICATION BLOCKED (shared test runner)**
**Implementation: COMPLETE.** Static verification: **all green.** Runtime: **not executed** (§20).

---

## 1 — Executive Summary

D1–D4 are implemented end to end: a persistent `postponed_at` model, a real domain operation and
HTTP endpoint, exclusion from every current-cycle aggregation, the canonical Distribution Zone
relation, Customer and per-order Products in the read model, and the full UI refinement.

**Certification is withheld for one reason only: the shared test database was occupied throughout.**
§20 forbids running `RefreshDatabase` concurrently or killing another task's process, so the PHPUnit
suite was not executed. §23 requires runtime proof before claiming CERTIFIED, so I do not claim it.

Everything that does **not** touch the shared database was verified and is green: PHP syntax, route
resolution, PHPStan L0, PHPStan core L6, scoped Pint, TypeScript, ESLint.

---

## 2 — Root Cause (carried from REFINEMENT-001)

`detachOrder()` **deletes** the `preparation_wave_orders` row. `attachEligibleOrders()` selects
candidates with `whereNotExists(... preparation_wave_orders WHERE order_id = orders.id)` — scoped by
neither wave, status nor date — and `wave:run-scheduler` runs **every minute**. A deleted membership
is therefore re-created within 60 seconds, so deletion cannot express postponement.

**The fix is to retain the row.** Stamping `postponed_at` keeps the collector's existing
`whereNotExists` satisfied, so the order is never re-attached — with **no change to any eligibility
rule** (§3's stated sole goal) — and preserves the membership as history instead of deleting it.

---

## 3 — Migration (§17)

`Modules/Operations/Preparation/Infrastructure/Database/Migrations/2026_08_13_100000_add_postponed_at_to_preparation_wave_orders.php`

One column, additive, with an explicit `down()`:

```php
$table->timestamp('postponed_at')->nullable()->after('added_by');
$table->index(['preparation_wave_id', 'postponed_at'], 'idx_pwo_wave_postponed');
```

Guarded by `Schema::hasColumn` on both `up()` and `down()`, so a re-run is a no-op. **No existing
column was modified.** Nullable, so every pre-existing row reads NULL = "active" and each current
query keeps its exact present meaning until it opts into the filter.

---

## 4 — Postpone Semantics (§1, §2, §15)

`WaveMembershipService::postponeOrder(PreparationWave $wave, string $orderId, string $actorId, string $reason = 'postponed'): bool`

```php
$postponed = PreparationWaveOrder::where('preparation_wave_id', $wave->id)
    ->where('order_id', $orderId)
    ->whereNull('postponed_at')
    ->update(['postponed_at' => now()]);

if ($postponed === 0) { return false; }

event(new OrderRemovedFromWave(... reason: $reason ...));
$wave->decrement('orders_count');
$this->demandDispatcher->dispatch($wave, 'order_postponed', $actorId);
```

| Requirement | How it is met |
|---|---|
| Membership verified | the `where` triple is the check — no match ⇒ `false` |
| `postponed_at = now()` | ✅ |
| Leaves current aggregation | §5 |
| Existing domain event | reuses **`OrderRemovedFromWave`** with `reason: 'postponed'` — no parallel event invented |
| Product Demand updated | `demandDispatcher->dispatch()`, the same canonical route `detachOrder()` uses |
| **Idempotent** | the `whereNull('postponed_at')` in the UPDATE **is** the guard — two concurrent calls cannot both match, so no double event and no double decrement |
| No `DELETE` | the row is retained (§28) |
| No `orders` write | no status transition, no cancellation (§15, §24) |

---

## 5 — Aggregation Exclusion (§2.3, §16)

Every reader that means "orders being prepared **now**" now excludes postponed rows:

| Consumer | Change |
|---|---|
| `ProductDemandCalculator:28` | `->whereNull('pwo.postponed_at')` |
| `MissingMaterialCalculator:89` | `->whereNull('pwo.postponed_at')` in the join |
| `GenerateDemandAction:39,55` | `->whereNull('pwo.postponed_at')` in both joins |
| `WaveDemandController::waveOrders` | `->whereNull('pwo.postponed_at')` |
| `AutoAllocationService:262` (Loading → shipping) | `->active()` |

**No global scope was used, deliberately.** Five consumers query this table through the raw query
builder, which silently bypasses Eloquent global scopes — a global scope would have been reliable in
some paths and not others. Instead `PreparationWaveOrder::scopeActive()` makes every Eloquent consumer
state its intent explicitly, and the raw-builder sites carry the predicate inline.

**Consumers deliberately NOT filtered**, because they mean "everything that was ever in this wave"
rather than "the active cycle": `CancelWaveAction:41`, `StartPreparationAction:124`,
`WavePreparationService:59`, `HandlePreparationWaveCompleted:25`, `RecalculateWaveAction`,
`CreateWaveAction` (its uniqueness conflict check must still see postponed rows, or an order could be
double-attached), and `PreparationEnterpriseController`. Changing those would alter wave-lifecycle
semantics, which §21 forbids.

---

## 6 — Delivery Zone: canonical only (§5)

`WaveDemandController::waveOrders()`:

```php
->leftJoin('logistics_cities as lc', 'lc.id', '=', 'o.logistics_city_id')
->leftJoin('distribution_zones as dz', 'dz.id', '=', 'lc.distribution_zone_id')
...
'delivery_zone' => $o->zone_name_en ?? $o->zone_name_ar,
```

`orders.delivery_zone` (free text), `pwo.delivery_zone_snapshot`, `governorate` and `master_zones` are
**not** consulted. An unresolvable chain yields `null`, which the UI renders as **"Unassigned" /
"غير محدد"** — never guessed from text.

> **Operational note, unchanged from the diagnostic:** `orders.logistics_city_id` is **NULL on all four
> orders** in `ecos_dev`, and only 31 of 211 `logistics_cities` carry a `distribution_zone_id`. So with
> today's data every row will legitimately read **Unassigned**. That is the contract behaving
> correctly, not a defect — but it means the column will look empty until `logistics_city_id` is
> backfilled. Flagged rather than worked around.

---

## 7 — Read Model: Customer + Products (§6, §7)

- **Customer** — `o.customer_name` from the order itself. Nothing is stored on
  `preparation_wave_orders`; no customer data is duplicated (§6).
- **Products** — the order's own `order_lines` joined to `products`, fetched in **one** query for the
  whole page and grouped by `order_id` (no N+1). **`wave_items` is deliberately not used** — it is a
  wave-level aggregate and cannot answer "what is in *this* order" (§7).

**Backward compatibility:** `customer_name_snapshot`, `delivery_zone_snapshot` and `added_at` are still
returned. The wave **Dashboard** reads this same endpoint and is out of scope per §21 — dropping them
would have broken it at runtime, not merely in types. The orders table simply does not render them.

---

## 8 — UI (§8–§14, §22, D4)

`frontend/src/features/operations/pages/wave-orders-page.tsx`

| Requirement | Result |
|---|---|
| Columns | **Order # · Customer · Delivery Zone · Products · Actions** — exactly §26 |
| Payment / Governorate / Added At | **removed** |
| KPI cards Paid / Completion / Missing Matls | **removed** — the whole KPI row is gone, nothing replaces it |
| Operational summary row | not present on this screen |
| Tab name | `Wave Orders` → **"Orders" / "طلبات"** (EN + AR); the wave's own name is unchanged |
| Action | **"تأجيل" / "Postpone"** with a **`CalendarClock`** icon — no Trash, no delete wording |
| Tooltip | "تأجيل الطلب من تجميعة التحضير الحالية" |
| Confirmation | `AlertDialog` — "تأجيل الطلب؟" + "سيتم إخراج هذا الطلب … ولن يتم حذف الطلب من النظام." with **إلغاء / تأكيد التأجيل** |
| Success feedback | toast "تم تأجيل الطلب بنجاح." via the platform's `@/components/ds/use-toast` |
| Products cell | first 2 inline, remainder behind **"+N more" / "+N منتجات"** in a popover |

**No local hiding (§14).** `usePostponeWaveOrder` invalidates `K.waveDemand` (which covers wave orders,
product-demand, material-demand and missing-materials) plus the wave detail and list. The row
disappears because the canonical backend state is re-read, never because React removed it.

Cancel closes the dialog and sends no request.

---

## 9 — Order Lifecycle Safety (§15, §24)

`postponeOrder()` performs **no `orders` write of any kind**. No status transition, no `cancelled`, no
`awaiting_stock`, no delete of the order, its lines, its customer or its history. Postponement is a
wave-membership decision only.

---

## 10 — Tests (§18)

`backend/tests/Feature/Operations/WavePostponeOrderTest.php` — **8 tests, authored, NOT EXECUTED**:

| Test | §18 item |
|---|---|
| `postpone_persists_postponed_at_and_keeps_the_membership_row` | F |
| `postponing_twice_is_idempotent` | M |
| `postpone_does_not_delete_or_mutate_the_order` | G, H |
| `collector_does_not_reattach_a_postponed_order` | **L** — asserts the collector's exact `whereNotExists` predicate |
| `postponed_order_leaves_the_active_membership_set` | I |
| `postponed_order_is_excluded_from_product_demand_aggregation` | J |
| `orders_count_decrements_once_only` | idempotency of the counter |
| `postponing_a_non_member_is_a_no_op` | safety |

**Not yet covered, pending the runner:** B/C/D/E (read-model HTTP assertions), K (shipping
aggregation), N/O/P–U (UI-level assertions). These are authored as the next increment rather than
claimed.

---

## 11 — Runtime Evidence

| Check | Result |
|---|---|
| `php -l` on every changed PHP file | **PASS** |
| Route registration | **PASS** — `POST api/preparation/waves/{waveId}/orders/{orderId}/postpone` resolves to `PreparationWaveController@postponeOrder` |
| Host ↔ runner parity | **PASS** — all 11 backend files hash-identical |
| **PHPUnit** | **NOT RUN — §20 blocked** |

**The blocker, with evidence.** A watcher reported the runner free; I started the suite, and it errored
in 5.7 s with `Table 'ecos_dev_test.migrations' doesn't exist` — failing on the *first* migration
inserting into a non-existent `migrations` table, which is the concurrent-wipe signature, not a code
fault. An immediate re-check showed:

```
ecos-dev-testrunner phpunit: 2
ecos_dev_test | Execute | alter table `bill_of_material_lines` add constrain…
ecos_dev_test table count: 33      (was 555)
```

Another agent's suite was mid-migration. **I own my part of this:** my watcher saw zero processes at
that instant, so we raced — I did not deliberately run alongside a known suite. On seeing it I stopped,
did **not** kill their process, did **not** run `migrate:fresh`, and did not retry.

---

## 12 — Static Evidence

| Check | Result |
|---|---|
| PHPStan L0 | **[OK] No errors** |
| PHPStan core L6 | **[OK] No errors** |
| Pint — all files of this task | **passed** |
| TypeScript (`tsc -p tsconfig.app.json`) | **24 errors — exactly the documented pre-existing baseline** ("TypeScript baseline 24 held", `6149875b`); **0 in my files** |
| ESLint — 4 changed frontend files | **clean** |

Pint reports failures in `MoveToPreparationWorkflow`, `BranchAssignmentEngine`,
`CoverageResolutionService` and `routes/api.php`. **None is mine** — the first three belong to the
concurrent Order/Preparation repair, and `routes/api.php` was proven pre-existing earlier by running
Pint against its HEAD version, which fails with the identical five fixers. None was repaired.

---

## 13 — Database Safety

No `migrate:fresh`, no reset, no destructive seed, no deletion, no MAIN/`ecos_erp` connection. The new
migration **has not been run** — it ships as a file only. `ecos_dev` was read-only throughout.

---

## 14 — Files Changed (this task only)

**Backend (11 + 1 test):** the migration (new) · `PreparationWaveOrder` · `WaveMembershipService` ·
`WaveDemandController` · `ProductDemandCalculator` · `MissingMaterialCalculator` ·
`GenerateDemandAction` · `AutoAllocationService` · `PreparationWavePolicy` ·
`PreparationWaveController` · `routes/api.php` (one hunk) · `WavePostponeOrderTest` (new).

**Frontend (6):** `wave-orders-page.tsx` · `use-preparation.ts` · `preparation-service.ts` ·
`types/preparation.ts` · `i18n/en/operations.json` · `i18n/ar/operations.json`.

> The working tree also contains ~19 other modified files from a **concurrent agent's** Order/
> Preparation/Distribution work (`BranchAssignmentEngine`, `WaveManager`, `WaveLifecycleService`,
> `RunWaveSchedulerCommand`, `StockAddedListener`, `MaterialDemandCalculator`, `orders.json`,
> `cost-management.json`, …). **None was touched or reverted by this task.**

**No new permission** was introduced — `postponeOrder` reuses `preparation.wave.update` and the same
`sameCompany` tenant boundary as `recalculate`.

---

## 15 — §4 Re-entry into a future wave — RECORDED, NOT INVENTED

§4 asks whether a postponed order can become eligible for a **future** wave. **The existing rules do
not permit it, and I did not invent a rule.**

`attachEligibleOrders()`'s `whereNotExists` is scoped to the *whole table*, not to a wave. Retaining the
row therefore excludes the order from **every** future wave as well, not just the current one — and the
uniqueness constraint `uq_prep_wave_orders_company_order (company_id, order_id)` means an order can
only ever hold one membership row.

So today postponement is effectively **permanent** until an operator acts. This is the same latent
constraint REFINEMENT-001 recorded ("an order attached to any wave can never be attached to another").
**A future-wave re-entry policy is a business decision** and is deliberately absent from this
implementation.

---

## 16 — Final Certification

> # NOT CERTIFIED — RUNTIME VERIFICATION BLOCKED

Implementation is complete and statically clean. Certification is withheld solely because §23's runtime
gates could not run: the shared `ecos_dev_test` was occupied by another agent for the duration, and §20
forbids running concurrently or killing that process.

**To certify — one clean pass on a free runner:**
1. `php artisan migrate` (adds `postponed_at`).
2. `WavePostponeOrderTest` — 8 tests.
3. Preparation, Wave, Product Demand and Distribution regression suites.
4. HTTP postpone against `ecos_dev`, then confirm the order is absent from wave orders and Product
   Demand while its `orders` row is byte-identical.

**Open decision:** §15 — whether a postponed order should ever re-enter a future wave, and by what
rule.
