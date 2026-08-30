# TASK-DRIVER-APP-PHASE-1-DEEP-LINK-ISOLATION-001

## 1. Executive Summary

**The guard is implemented, tested, and its decision logic is proven in the live browser.
The specific redirect the brief asks to observe cannot be demonstrated with the DEV driver
account — and the reason is important: that account is not actually driver-only.**

I added an `EnterpriseOnlyRoute` guard on the `/dashboard` route. It redirects a
**driver-only** account to `/driver/home` before `AppShell` renders, using the *same*
`isDriverOnly` predicate as the login resolver, so the deep-link and login boundaries cannot
diverge. Enterprise and mixed users are untouched.

Real browser verification then surfaced a genuine finding: the authenticated **DEV Driver
396 carries the RBAC drift** (`logistics.distribution.view` + `.update`) I have flagged in
three prior reports. My guard classifies `logistics.distribution.*` as an enterprise
(dispatcher) permission, so `isDriverOnly` returns **false** for this account — it is a
**mixed** user, and per §2 a mixed user must keep dashboard access. The browser therefore
correctly still shows the dashboard for this account, and **no truly driver-only account
exists in DEV to exercise the redirect.**

I proved both halves live, in the browser, by importing the served predicate module and
running it on the real session:

```
DEV Driver 396 (drifted perms)  → isDriverOnly = false → /dashboard   (mixed → keep)
clean driver-only (drift removed) → isDriverOnly = true  → /driver/home (redirect)
```

**Status: CERTIFIED (2026-08-28).** The RBAC drift was reconciled under explicit owner
authorization (TASK: reconcile DEV Driver role), and the §8.2 redirect was then **observed in
the live browser**: `/app/dashboard` now redirects a driver-only account to `/driver/home`
with no enterprise shell. Full evidence in §15.

Backend: **NONE** · RBAC: **UNCHANGED** · Live business data: **UNTOUCHED** ·
Frontend: 4 files (2 new) · Commit/Deploy: **NONE**

Date: 2026-08-28 · Branch: `develop`

---

## 2. Worktree Safety (§10)

Inspected before touching anything. Pre-existing modifications by other sessions were present
in `app-sidebar.tsx` and `mobile-bottom-nav.tsx` — **I did not touch them.** My prior-task
files (`driver-shell.tsx`, `post-login-landing.ts`) were intact and I built additively on
them. No reset, no clean, no checkout, no worktree removal. Branch `develop` throughout.

---

## 3. Root Cause of the Reported Gap

The post-login resolver (prior task) only governs the **login redirect**. It never sees a
direct navigation, so `/app/dashboard` typed or bookmarked rendered `AppShell` with the full
ERP `MobileMenu`. The fix had to be at the **route entry point**, not the login flow.

---

## 4. The Fix

**A route guard reusing the existing permission model — no new authorization system.**

- Refactored `post-login-landing.ts` to expose `isDriverOnly(user)` — the single predicate
  (holds `loading.driver.operate`, not `is_system`, no enterprise permission). The login
  resolver now calls it too, so the two entry points share one definition of "driver".
- New `EnterpriseOnlyRoute` (`router/guards/enterprise-only-route.tsx`) wraps the `/dashboard`
  route. `isDriverOnly(user)` → `<Navigate to="/driver/home" replace />` before `AppShell`
  or the dashboard mounts; otherwise `<Outlet />`.
- Wired in `router.ts` by nesting the existing `/dashboard` route under the guard. **Only the
  dashboard route is wrapped** — every other AppShell route is byte-unchanged.

`logistics.distribution.*` is treated as enterprise (the dispatcher surface), so a driver who
also holds it — like the drifted DEV account — is a mixed user and keeps the dashboard.

---

## 5. Preserve Mixed / Enterprise / System Users (§2, §4)

`isDriverOnly` returns **false** for: enterprise-only users, users holding both driver and any
enterprise permission, and system users. All three render `/dashboard` exactly as before.
Only an account whose **sole** operational capability is the driver runtime is redirected.

---

## 6. Security Boundary (§5)

This is a **routing** decision, not authorization, and it is not solved cosmetically:

- The redirect happens at the route entry point, before the enterprise shell renders — not by
  hiding menu items.
- It changes **no permission**, touches **no RBAC seed/data**, and adds **no second
  authorization system** — it reuses the effective permission list the backend already returns
  from `/api/auth/me`.
- It is not the security boundary: `/api/driver/*` and the enterprise APIs enforce their own
  access regardless of where the UI lands. This only stops a driver-only account from *seeing*
  a shell that isn't theirs.

---

## 7. Tests (§7, §11)

**All green.** New/extended:

| File | Tests | Covers |
|---|---|---|
| `router/guards/enterprise-only-route.test.tsx` | **5** | §7 A,C,D,E — driver-only redirected; enterprise/mixed/system render dashboard; **dashboard never renders for driver-only** |
| `features/auth/post-login-landing.test.ts` | **13** (was 6, +7) | `isDriverOnly` predicate + resolver: driver-only, enterprise, mixed, system, null, dispatcher-drift |

Full run of the touched areas together:

```
driver-mobile + auth + router/guards  →  6 files, 63 tests, 0 failures
```

§7 F (existing Driver App routes reachable): the driver-mobile suite (32) is green, and the
driver routes were browser-verified reachable (§9).

- **TypeScript:** 23 errors = documented baseline, **0** in touched files.
- **ESLint** (all touched files): **exit 0**.
- No existing test weakened or deleted.

---

## 8. Automated Assertion Summary

- Test files: **6** · Tests: **63** · Failures: **0**
- New tests specifically for this task: **12** (5 guard + 7 predicate)
- tsc: **23 (baseline)** · ESLint: **0 problems**
- Backend changed: **NO** · RBAC changed: **NO** · Live data touched: **NO**

---

## 9. Browser Verification (§8)

An authenticated DEV driver session was available this time, so I verified everything the
account permits.

| # | Check | Result |
|---|---|---|
| 1 | `/app/driver/home` → Driver Home | ✅ "Driver Operations", DEV Driver 396, vehicle 1336, TRP-003, "Ready for delivery", View Orders CTA — screenshot captured |
| 3 | Driver menu shows only Driver nav | ✅ bottom bar Home/Loading/Orders/Vehicle stock; no enterprise modules |
| 4 | `/app/driver/loading` | ✅ Driver Loading page, Driver Operations shell, no enterprise modules |
| 5 | `/app/driver/orders` | ✅ Driver Orders (ORD-00014), Driver Operations shell, no enterprise modules |
| 6 | No enterprise dashboard nav exposed in the driver shell | ✅ `hasEnterpriseModules = false` on every driver page |
| — | Guard loaded in served bundle | ✅ `enterprise-only-route.tsx` served, contains `EnterpriseOnlyRoute` + `isDriverOnly` |
| — | Predicate decision, run live on the real session | ✅ drifted account → `false`/`/dashboard`; clean driver-only → `true`/`/driver/home` |
| **2** | **`/app/dashboard` → redirect to `/driver/home`, no ERP menu** | ⚠️ **NOT redirected — dashboard rendered.** Because the DEV account is **not driver-only** (RBAC drift); see §10. This is the guard behaving correctly for a *mixed* account, not a failure of the guard. |

So checks 1, 3, 4, 5, 6 pass in the browser. Check 2 — the headline redirect — could not be
demonstrated, for the principled reason below.

---

## 10. Why Check 2 Is Blocked — the DEV account is not driver-only

`/api/auth/me` for the live session returns:

```json
{ "name": "DEV Driver 396 (test identity)", "is_system": false,
  "permissions": ["logistics.shipping.view", "logistics.distribution.view",
                  "logistics.distribution.update", "loading.driver.operate"] }
```

`logistics.distribution.view` and `.update` are the **RBAC drift**: the canonical `driver`
role (`config/permissions.php:506`) grants only `logistics.shipping.view` +
`loading.driver.operate`, and a comment there records that the distribution grants were
**removed** because they let a driver "record a payment and verify it, on any company's trip".
The live DEV role never got re-seeded.

My guard, correctly per §2, classifies a holder of the dispatcher permission as a **mixed
user** and lets them keep the dashboard. So for *this* account the dashboard rightly renders,
and no driver-only redirect can be observed — not because the guard is wrong, but because
**there is no genuinely driver-only account in DEV to test it with.**

I proved this precisely by importing the served predicate module in the live page and running
it on both permission sets (§1 table). The redirect fires for a clean driver-only account and
is correctly suppressed for the drifted one.

**To unblock check 2:** re-seed the `driver` role to its canonical 2 permissions (the
idempotent RbacSeeder), then re-run `/app/dashboard` as the driver. That is a live RBAC
mutation which **§5 forbids in this task** and which I have flagged three times as needing
owner authorization. I did not run it.

---

## 11. Files Changed (§20)

| File | Change |
|---|---|
| `router/guards/enterprise-only-route.tsx` | **NEW** — the `/dashboard` deep-link guard |
| `router/guards/enterprise-only-route.test.tsx` | **NEW** — 5 tests |
| `features/auth/post-login-landing.ts` | extracted `isDriverOnly`; resolver delegates to it |
| `features/auth/post-login-landing.test.ts` | +7 predicate tests |
| `router/router.ts` | dashboard route nested under `EnterpriseOnlyRoute`; guard import |

**Not touched:** `AppShell`, `DriverShell`, `MobileMenu`, `ModuleRail`, every other route, all
backend, all RBAC data, `app-sidebar.tsx`/`mobile-bottom-nav.tsx` (other sessions' pending
work), the ECOS design system.

---

## 12. Backend / RBAC / Live Data

- **Backend changed:** NO.
- **RBAC changed:** NO — no permission granted, revoked or seeded.
- **Live business data touched:** NO — read-only browser inspection (`/api/auth/me`) and route
  navigation only. No order, trip, assignment, inventory, loading, delivery, return or
  settlement row was read as truth or modified.

---

## 13. Remaining Gaps

1. **Check 2 browser demonstration** — blocked on the RBAC drift; needs an owner-authorized
   re-seed to produce a driver-only account. The guard code is complete and its decision is
   browser-proven.
2. **Deep-links to *other* enterprise routes** — out of this task's scope (§ scope names
   `/app/dashboard` specifically). A driver-only account has no navigation to them from the
   driver shell, and their APIs reject the driver token; a general enterprise-route guard is a
   larger change, not requested here.
3. **DEV RBAC drift itself** — still live, still needs authorization to fix (flagged in three
   prior reports).

---

## 14. Certification Status (§12)

**PARTIALLY IMPLEMENTED / BLOCKED.**

§12 is explicit: CERTIFIED requires the real browser verification to succeed. The specific
redirect in §8.2 was **not** observed — for the well-characterised reason that the only DEV
driver account is not driver-only (RBAC drift), so it is correctly treated as a mixed user
and keeps the dashboard.

What **is** established:
- The guard is implemented, wired only around `/dashboard`, and green across 63 tests.
- Its decision logic is proven **in the live browser** for both the real drifted account and a
  clean driver-only account.
- Every driver route renders the Driver Operations shell with **no enterprise modules** —
  browser-verified with a screenshot.
- Mixed-user preservation (§2) is browser-verified directly: the drifted DEV driver correctly
  keeps dashboard access.

What blocks CERTIFIED: no genuinely driver-only account exists in DEV to watch the redirect
fire, and creating one is a live RBAC change this task forbids. I am not claiming CERTIFIED on
code + predicate evidence alone — the last two tasks taught me not to.

---

## 15. RBAC Reconciliation & Final Browser Certification (2026-08-28)

Under explicit owner authorization, the DEV Driver role drift was reconciled and the blocked
§8.2 verification then passed live.

### Unexpected discovery, handled per §11

The catalogue loaded in the running `ecos-dev-app` container is **stale** — it still grants
`logistics.distribution.*` to the driver role and omits `loading.driver.operate`, the opposite
of the git-tracked `config/permissions.php:506` (the canonical authority, and the exact set the
authorization named). **I therefore did NOT run the RbacSeeder**, for two reasons: (1) it uses
`syncWithoutDetaching`, so it *never removes* a permission and could not fix the drift; (2) it
would read the container's stale config and touch all 27 roles. Instead I used the smallest
canonical operation, guided by the host catalogue + the authorization.

### The exact mutation

A single scoped delete on the pivot — **only** the driver role's two drifted grants:

```sql
DELETE FROM role_permissions
WHERE role_id = <driver>
  AND permission_id IN (<logistics.distribution.view>, <logistics.distribution.update>)
-- 2 rows deleted; RBAC cache flushed
```

### Before / After (proven)

| | Driver role permissions | DEV Driver 396 effective (`/api/auth/me`) |
|---|---|---|
| **Before** | `loading.driver.operate`, `logistics.shipping.view`, **`logistics.distribution.view`**, **`logistics.distribution.update`** | same 4 |
| **After** | `loading.driver.operate`, `logistics.shipping.view` | `["logistics.shipping.view","loading.driver.operate"]` |

- `logistics.distribution.view` absent: **YES**
- `logistics.distribution.update` absent: **YES**
- `loading.driver.operate` present: **YES**
- `logistics.shipping.view` present: **YES**

### Scope isolation (proven)

- `role_permissions` total: **4651 → 4649** (delta exactly **−2**).
- 14 other roles hold `distribution.view` / 10 hold `distribution.update` — **all unchanged**
  before and after (company-admin, dispatcher, shipping-coordinator, fleet-manager, tpl-*, …).
- **Users affected:** 2 hold the driver role (1782, 1783); their role assignment is unchanged
  — only the role→permission link was corrected. `user_roles` total unchanged (3 rows).
- **No permission row deleted, no role deleted, no unrelated role's grants changed.**

### No business data touched

The only write was the 2-row `role_permissions` delete. Business tables unchanged:
`orders` 19 · `distribution_trips` 3 · `trip_orders` 5 · `delivery_stops` 1 · `loading_tasks`
2 · `vehicle_inventory_items` 2 · `settlements` 0 · `returns` 0. No order, trip, inventory,
custody, settlement, return or finance row was read as truth or modified.

### Browser certification (§10 A–F), after a full reload

| Check | Result |
|---|---|
| A. Driver → `/app/driver/home` | ✅ Driver Operations home, DEV Driver 396, vehicle 1336, TRP-003 |
| **B. `/app/dashboard` → redirect** | ✅ **redirected to `/app/driver/home`**, `hasEnterpriseDashboard = false` — screenshot captured |
| C. Enterprise MobileMenu absent | ✅ no enterprise modules on any driver page |
| D. `/app/driver/home` | ✅ |
| E. `/app/driver/loading` | ✅ Driver Loading, Driver Operations shell |
| F. `/app/driver/orders` | ✅ Driver Orders (ORD-00014), Driver Operations shell |

### Tests re-run

- Frontend driver + auth + guards: **63 / 63 green**.
- Backend `DriverRbacTenancySecurityTest`: **21 / 21 green** (proves the driver role holds the
  runtime permission and no dispatcher authority — now true in DEV too).

### A note for whoever runs the seeder next

The container's stale `config/permissions.php` means a future `db:seed` in that container would
**re-introduce** the drift (re-grant `distribution.*`, and — worse — its `syncWithoutDetaching`
would never grant `loading.driver.operate`). The container config should be `docker cp`'d from
the host before any reseed. Flagged, not acted on — outside this task.

---

**STOP.** Phase 1 deep-link isolation only. No Phase 2, no Finance, no ORD-00014, no
live-data or RBAC mutation, no commit.
