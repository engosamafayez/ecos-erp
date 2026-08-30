# TASK-CUSTOMER-360-ENHANCEMENTS-001 — Frontend completion

**Date:** 2026-08-14 · No PHPUnit, no E2E, no runtime verification (per instruction). Static gates only.
**Status:** **IMPLEMENTATION COMPLETE — RUNTIME VERIFICATION PENDING USER REVIEW**

No `CERTIFIED` claim is made.

---

## 1 — Delivered

| # | Item | Status |
|---|---|---|
| 1 | Customers list — Orders Count, Total Order Value, Receiving Rate, Last Order | **Done** |
| 2 | Columns Manager integration | **N/A — none exists on this page** (see §4) |
| 3 | Full Address column | **Done** — required a backend field (see §3) |
| 4 | Location column | **Done** — required a backend field (see §3) |
| 5 | Customer 360 KPIs | **Done** — 6 KPIs on the Overview tab |
| 6 | Products Purchased | **Done** — new drawer tab |
| 11 | No N+1 | **Preserved** (see §5) |
| 13 | Static verification | **All green** (see §6) |

**Zero client-side arithmetic.** Every figure is rendered exactly as the server returns it.
No React/JS re-computation of any metric, and nothing derived by combining fields in the client.

---

## 2 — The surface I worked on

`src/features/crm/` — **not** `src/features/customers/`. The latter is the legacy Sales feature
(`brands`, `lifetime_value`); it does not call `crm/customers` and was left untouched.

The Customer 360 is not a page — it is `crm-customer-drawer.tsx`, opened from the list.
KPIs went on the **Overview** tab; Products Purchased is a **new tab** beside it.

`receiving_rate` renders as an em-dash when `null` — never `0%`, which would read as "never
receives" for a customer who has simply never ordered. Same for `average_order_value` and
`last_order_at`.

---

## 3 — One decision I had to make, with evidence

Items 3 and 4 could not be done as pure frontend work: **`Customer360Service::identity()` — the
shape the list returns per row — carried no address fields at all.** Two sources exist, and they
**disagree in live data**:

```
ecos_dev, read-only:  4 customers · 2 with a master address · 2 rows in customer_addresses

CUS-00029  customers.city        = Maadi
           customer_addresses    = Shubra     ← same customer, same governorate, same street
```

That is a 1-in-2 disagreement across the populated set, so picking silently would have been
guessing. **I chose the structured `customer_addresses` default row**, because that is already
what `profile()` exposes as `addresses[]` — so the list and the 360° panel now answer the same
address instead of two quietly different ones. The master columns remain a fallback when no
default address row exists.

**This precedence is an engineering choice, not a ratified business rule.** If the business holds
`customers.address` authoritative, only `fullAddress()` and `location()` in `Customer360Service`
change — nothing else depends on the order. **This is the one item awaiting your decision.**

I did not touch `crm_customer_purchase_facts`, `lifetime_value`, CLV, churn, or the order-event
intelligence pipeline.

---

## 4 — Item 2: the condition does not apply

There is **no Columns Manager** on `crm-customers-workspace-page.tsx` — no `useColumnVisibility`,
no `ColumnVisibilityMenu`. Your instruction was conditional ("إذا كان موجودًا"), so nothing was
integrated and **no new column-management primitive was invented**. Six columns were added to a
table that had five; Full Address truncates with a `title` tooltip so the row height stays flat.

---

## 5 — Item 11: no N+1

| Data | Cost |
|---|---|
| Order metrics for the page | **1 aggregate query** — `CustomerOrderMetricsService::forCustomers()`, already built that way |
| Default address for the page | **1 eager-load** — `with(['addresses' => fn ($a) => $a->where('is_default', true)])` |

Neither scales with row count. The address eager-load is constrained to the default row, so a
customer with twenty addresses still costs nothing extra.

---

## 6 — Static verification (item 13)

| Check | Result |
|---|---|
| PHPStan — level 0, entire platform (`phpstan.neon.dist`) | **[OK] No errors** |
| PHPStan — core level 6 (`phpstan-core.neon.dist`) | **[OK] No errors** |
| Pint — my 3 backend files | **passed** |
| TypeScript — my files | **0 errors** |
| TypeScript — total | **24 = documented baseline** |
| ESLint — my 3 frontend files | **clean** |
| Vite build | **✓ built in 5.21s** |
| `git diff --check` | **clean** |
| PHPUnit / E2E | **not run**, per instruction |

Two corrections made during the pass, both mine:

- I first ran **level 6 against `Modules/Crm`** and got 145 errors. That is not the core gate —
  `phpstan-core.neon.dist` scopes level 6 to `app/Core + Contracts + Traits`, and its own header
  warns that level 2+ over the Modules manufactures thousands of false positives without Larastan.
  Re-run correctly, both gates are clean. **The 145 was my bad invocation, not a regression.**
- Pint reports two failures in `CustomerService.php` / `CustomerMergeService.php`. `git status`
  shows both **unmodified** — I never touched them; it is a pre-existing working-tree CRLF
  condition (`line_ending` fixer). My three files pass.

---

## 7 — A build break I caused and repaired

While I was working, the concurrent agent deduplicated `use-preparation.ts` and
`preparation-service.ts` — both of us had independently implemented the same four
Prepared/related-orders methods last round. My scripted slice-edit then ran against the **already
deduplicated** file and deleted the surviving service block, leaving four hooks calling methods
that no longer existed.

**Repaired.** The four methods are restored, one definition each, every consumer compiles, and the
frontend builds. Verified: no duplicate exports, no duplicate service methods, build green.

Two facts worth your attention, both pre-existing and **not** introduced by me:

1. The duplicate methods on the service object were **silently last-wins** — legal JS, no error.
   Only the duplicated *hook exports* broke the build and exposed it.
2. `related-orders-drawer.tsx` exists and compiles but **is rendered by nothing** — the Missing
   Material → Related Orders drawer (last round's §5 item 4) is still not wired to a page.

---

## 8 — For your manual review

1. **Customers list** → six new columns. Confirm Receiving Rate shows `—`, not `0%`, for a
   customer with no orders.
2. **Full Address / Location** → confirm CUS-00029 reads **Shubra**, not Maadi. If Maadi is the
   answer the business wants, that is the §3 decision and it is a two-method change.
3. **Open a customer → Overview** → six KPIs. Confirm they match the list row for the same customer.
4. **Products Purchased tab** → one row per distinct product, quantity summed across orders.
   Confirm a product ordered in several orders appears **once**.

---

> **IMPLEMENTATION COMPLETE — RUNTIME VERIFICATION PENDING USER REVIEW**
