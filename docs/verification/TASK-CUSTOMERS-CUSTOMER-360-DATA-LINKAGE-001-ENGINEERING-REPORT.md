# TASK-CUSTOMERS-CUSTOMER-360-DATA-LINKAGE-001 — Engineering Report

**Date:** 2026-08-14 · No PHPUnit, no E2E, no runtime verification (per your instruction).
**Status:** **IMPLEMENTATION COMPLETE — RUNTIME VERIFICATION PENDING USER REVIEW**

---

## 1 — Root Cause (proven)

**The customer linkage was never broken. `customers.company_id` was simply never populated, so a
tenant-scoped Customers list could only ever show the one record that happened to have it.**

### The audit data

```
customers: 4 total
  019fd976…  أحمد محمد           phone 01099999999   company_id = NULL
  019fd977…  Test                phone 01012345678   company_id = NULL
  019fde71…  RENAMED TestRecord  phone NULL          company_id = 019f4e1c-2d1e…   ← the only one visible
  019ff80d…  OSAMA FAYEZ AHEMD   phone 01008200808   company_id = NULL
```

```
order        has customer_id   linked customer      phone         customer.company   order.company
ORD-00001    YES               أحمد محمد            01099999999   NULL               019f4e1c-2d1e…
ORD-00002    YES               Test                 01012345678   NULL               019f4e1c-2d1e…
ORD-00003    YES               OSAMA FAYEZ AHEMD    01008200808   NULL               019f4e1c-2d1e…
ORD-00004    YES               OSAMA FAYEZ AHEMD    01008200808   NULL               019f4e1c-2d1e…
ORD-00005    YES               OSAMA FAYEZ AHEMD    01008200808   NULL               019f4e1c-2d1e…
```

**Five of five orders have a customer, and every link resolves.** ORD-00003/4/5 correctly share a
single customer — so phone-based dedupe is already working.

The Customers page shows **one** customer because the tenant-scoped read filters on
`customers.company_id`, and only `RENAMED TestRecord` carries it. The three customers that actually own
orders are invisible — not missing.

### The defective code

`CreateManualOrderAction::resolveCustomer()` — the auto-creation path — had two faults:

```php
// 1. Phone lookup, UNSCOPED by company:
$existing = Customer::where('phone', $phone)->orWhere('mobile', $phone)->first();

// 2. Creation, with NO company_id:
$customer = Customer::create([
    'code' => $code,
    'name' => (string) $data['customer_name'],
    …                                   // company_id absent entirely
]);
```

Fault 2 is the reported symptom. **Fault 1 is more serious and was not in the brief: a cross-tenant
leak.** With no company predicate, Company A creating an order for a phone number that already exists
under Company B would silently attach **Company B's customer** to Company A's order.

---

## 2 — What Changed

Two edits, both inside `resolveCustomer()`:

```php
// Same tenant source the order itself uses (see the `company_id` assignment on the
// Order below) — resolved here because resolveCustomer() runs before that point.
$companyId = Auth::user()?->company_id;

// Scoped to the acting company, and the phone/mobile alternation is GROUPED.
// Without the closure the `orWhere` would escape the company predicate and a
// phone belonging to another tenant would be attached to this order.
$existing = Customer::query()
    ->where('company_id', $companyId)
    ->where(fn ($q) => $q->where('phone', $phone)->orWhere('mobile', $phone))
    ->first();

$customer = Customer::create([
    'company_id' => $companyId,
    …
]);
```

The grouping closure matters: adding `->where('company_id', …)` in front of a bare
`->where(...)->orWhere(...)` would have left the `orWhere` outside the company predicate and *kept* the
leak while appearing to fix it.

`$companyId` is resolved from `Auth::user()?->company_id` — the identical expression the same action
already uses for the Order (`:124`). No new tenant mechanism was introduced.

---

## 3 — Files Modified

**One file:** `backend/Modules/Commerce/Orders/Application/Actions/CreateManualOrderAction.php`

No migration, no schema change, no API contract change, no frontend change, no new service, no change to
order lifecycle, pricing, inventory or fulfilment.

---

## 4 — API / DB Changes

**None.** `customers.company_id` already exists on the table; it was simply never written by this path.
No endpoint signature or response shape changed.

---

## 5 — Handling of Existing Orders

**Not performed — needs your decision (see §9).**

Three customers currently hold `company_id = NULL`:

| Customer | Phone | Orders |
|---|---|---|
| أحمد محمد | 01099999999 | ORD-00001 |
| Test | 01012345678 | ORD-00002 |
| OSAMA FAYEZ AHEMD | 01008200808 | ORD-00003, 00004, 00005 |

Each is unambiguously attributable: every order that references them carries
`company_id = 019f4e1c-2d1e-719d-873c-75779ab67251`, and no customer is referenced by orders from two
different companies. So a backfill would be a safe, deterministic
`UPDATE customers SET company_id = <the company of its orders> WHERE company_id IS NULL`.

**I did not run it.** Your standing instruction has been not to modify `ecos_dev` data, and this task
did not lift that. Until it runs, those three customers stay invisible on the Customers page **even
with the code fixed**, because the fix only affects newly-created customers.

---

## 6 — Customer 360: what already exists

The audit found most of the requested capability already implemented, so I did not rebuild it:

| Requirement | State |
|---|---|
| Every order has a linked customer | **Already true** — 5/5 verified |
| Auto-create customer on order creation | **Already implemented** — `resolveCustomer()` |
| Phone as primary identity | **Already implemented** — matches on `phone` **or** `mobile` |
| No duplicate customer per phone | **Already working** — 3 orders → 1 customer, proven in data |
| Additional phones | **Already present** — `customers.mobile` + `orders.customer_secondary_phone` |
| Order snapshots preserved | **Untouched** — `orders.customer_name` etc. still written |
| Company/tenant scope | **Now correct** — was the actual defect |
| Customer 360 / history / KPIs / search | **Two modules already exist** — `Modules/Crm/Customers` and `Modules/Sales/Customers`, each with its own `CustomerController` |

Rebuilding any of this would have duplicated working code, which the task explicitly forbids.

---

## 7 — Data Gaps

1. **3 customers with NULL `company_id`** — §5, awaiting your decision.
2. **`RENAMED TestRecord` has `phone = NULL`** and owns no orders. It is the only customer currently
   visible. Phone is the identity key, so a phone-less customer cannot be matched or deduped —
   likely test residue.
3. **Two parallel customer modules** (`Crm\Customers` and `Sales\Customers`) with two controllers.
   I did not attempt to reconcile them; which one backs the Customers page, and whether both should
   exist, is an architectural question outside this fix.
4. **Customer code generation is global, not per-company** — `MAX(CAST(SUBSTRING_INDEX(code,'-',-1)))`
   scans all customers. Now that customers are company-scoped, two tenants will share one `CUS-#####`
   sequence. Cosmetic today; flagged, not changed.

---

## 8 — Static Verification

| Check | Result |
|---|---|
| `php -l` | **No syntax errors** |
| PHPStan L0 | **[OK] No errors** |
| PHPStan core L6 | **[OK] No errors** |
| Pint | **fail — PROVEN PRE-EXISTING** |
| PHPUnit / E2E | **not run**, per your instruction |

Pint reports `CreateManualOrderAction.php` with 5 fixers. Running Pint on the **HEAD version of the
same file**, without my edit, reports **6** — the same set plus `blank_line_before_statement`. The file
was already failing before this task and my change introduced **no new violation**. I did not repair
the pre-existing ones (they belong to the concurrent agent's changes in that file).

---

## 9 — Decision Required

**May I backfill `customers.company_id` for the three NULL records?**

It is a single deterministic statement — each customer's company is unambiguous from the orders that
reference it, and no customer spans two companies. **Without it, the Customers page will still show
only one record**, because the code fix applies to newly created customers only.

I have not touched `ecos_dev` data, in line with your standing instruction.

A second, smaller question: should the two customer modules (`Crm` vs `Sales`) be reconciled? That is
an architecture decision, not part of this fix.

---

## Final Status

> **IMPLEMENTATION COMPLETE — RUNTIME VERIFICATION PENDING USER REVIEW**

The code defect is fixed and statically clean. The reported symptom will only fully clear once the
backfill in §9 is authorised, since existing customers keep their NULL company until then.

**Manual browser check after the backfill:** open Customers and expect **4** records (or 3 excluding the
phone-less test record); open `OSAMA FAYEZ AHEMD` and expect **3 orders** — ORD-00003, ORD-00004,
ORD-00005 — proving the 360 view and phone-based dedupe were already working all along.
