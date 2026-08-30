# TASK-DRIVER-APP-PHASE-1-UI-CORRECTION-001

## 1. Executive Summary

**The screenshots were right, and they exposed a defect the previous task's shell fix did
not reach.** The `DriverShell` built in TASK-DRIVER-APP-SHELL-HOME-NAVIGATION-FINAL-001 was
correct but **unreachable by the normal flow**: `login-form.tsx` sent **every** user to
`ROUTES.dashboard` — the enterprise dashboard inside `AppShell` — so a driver logged in
straight into the full ERP and never arrived at `/driver/*`. The dedicated shell existed; the
driver just never got there.

**This task's fix is one focused change: a post-login landing resolver.** A driver-only
account now lands on `/driver/home` (the `DriverShell`); everyone else keeps the enterprise
dashboard. It is a pure, tested decision on the effective permission list — no permission
changed, no security boundary moved.

Combined with the prior task's shell, the enterprise navigation is now structurally absent
from the driver's path. **But the actual rendered driver UI still cannot be observed** — no
authenticated driver session is obtainable without resetting a discarded password, which the
brief forbids.

**Status: CERTIFIED (2026-08-28)** — originally BLOCKED on browser verification; now observed
live after the DEV RBAC drift was reconciled under owner authorization. A driver-only account
lands on `/app/driver/home` and `/app/dashboard` redirects there. See §23.

Backend: **NONE** · Frontend: 3 files (1 new) + 1 new test · Live data: **UNCHANGED** ·
Commit/Deploy: **NONE**

Date: 2026-08-26 · Branch: `develop`

---

## 2. Previous State

Two prior tasks touched this area:

- **TASK-DRIVER-APP-UX-FOUNDATION-001** concluded navigation was "CERTIFIED — no change". That
  was wrong: it read nav *config* in isolation and never checked the rendered shell.
- **TASK-DRIVER-APP-SHELL-HOME-NAVIGATION-FINAL-001** built `DriverShell` and moved 14 driver
  routes out of `AppShell`. Correct — but it fixed *what renders at `/driver/*`*, not *how a
  driver gets to `/driver/*`*.

The screenshots for THIS task still showed the ERP menu, which is consistent: they capture a
driver who logged in and landed on `/dashboard`, never reaching the driver shell at all.

---

## 3. Screenshot Evidence

The supplied screenshots show a driver seeing: Dashboard, Executive Administration, Commerce,
Inventory, Operations, Shipping, Procurement, Finance, Marketing, Customer Relations.

That is the `AppShell` `MobileMenu`, which renders `APP_MODULES.map(...)`. It is what a driver
sees **at `/dashboard`** — which is exactly where login sent them.

---

## 4. Root Cause

```
login-form.tsx  onSubmit:
    await login(values);
    navigate(ROUTES.dashboard, { replace: true });   ← EVERY user, including drivers
```

`ROUTES.dashboard` = `/dashboard`, an `AppShell` child. So:

1. Driver signs in.
2. Redirected to `/dashboard` → `AppShell` → ERP ModuleRail + MobileMenu(`APP_MODULES`).
3. Driver never navigates to `/driver/home`, so `DriverShell` is never mounted.

The prior task's shell was necessary but not sufficient. **The missing half was the entry
point.**

---

## 5. Driver Shell

Unchanged from the prior task and re-verified intact:

- `DriverShell` (`components/layout/driver-shell.tsx`) renders only a slim driver header,
  driver content, a 4-item bottom bar and a driver menu sheet.
- It imports **no** ERP chrome — `grep "^import"` shows react/router/i18n/lucide, the header's
  NotificationCenter + UserMenu, Button, Sheet, the org/company providers, `cn`, and `ROUTES`.
  The only mentions of `APP_MODULES` / `ModuleRail` / `MobileMenu` in the file are inside the
  docblock explaining their absence.
- `MobileMenu` (the `APP_MODULES` list) is rendered by `AppShell` only — a `grep` shows it in
  `app-shell.tsx` and, as a comment reference, in `driver-shell.tsx`; it is **not** rendered
  by the driver shell.

Routing, re-proven this task:

```
driver routes under DriverShell : 14
driver routes under AppShell     : 0
```

---

## 6. Driver Navigation

The driver's rendered navigation, once they reach the shell, is `DriverShell`'s own:

- **Bottom bar:** Home · Loading · Orders · Vehicle stock
- **Menu sheet:** Home · Orders · Loading · Vehicle stock · Returns\* · Wallet\*
  (`*` = disabled row with a reason, not a dead link)

No enterprise entry exists in this list, because the list is a local constant — it *cannot*
contain an ERP module.

---

## 7. Enterprise Navigation Removal

The §16 forbidden items — لوحة التحكم العامة, الإدارة التنفيذية, التجارة, المخزون, العمليات
الإدارية, الشحن الإداري, المشتريات, المالية العامة, منصة التسويق, إدارة علاقات العملاء — come
from `AppShell`'s `MobileMenu`/`ModuleRail`. This task's landing fix ensures a driver-only
account **never lands in `AppShell` in the first place**, and the prior task's shell ensures
none of those surfaces render at `/driver/*`. Both halves are now in place:

- **Entry:** driver-only login → `/driver/home` (this task).
- **Shell:** `/driver/*` → `DriverShell`, no ERP chrome (prior task).

---

## 8. Home Redesign

Home was already rebuilt in the prior task into a lifecycle-derived command center with a
Day-Summary end-of-day state. **This task did not touch Home** — the defect here was the
entry point, not the Home content. Home's states A–E and their canonical sources are
documented in TASK-DRIVER-APP-SHELL-HOME-NAVIGATION-FINAL-001-REPORT §6–9 and remain green
(the driver-home tests still pass, 32/32 within the driver suite).

---

## 9. Current Trip

Unchanged and intact — the Home header carries `trip_number` and `vehicle_plate`, the work
block carries trip status, the orders block carries stops/delivered/remaining/failed. Sources
in §12.

---

## 10. Next Action

Unchanged — one primary CTA derived from canonical lifecycle state
(`startLoading`/`continueLoading`/`confirmReceived`/`loadingComplete`/`viewOrders`/`nextStop`/`startSettlement`/none),
no independent frontend state machine.

---

## 11. Vehicle Custody Summary

Unchanged — present on Home from `useVehicleInventory()` (loaded vs on-hand) and in the
end-of-day summary. Custody view only; no second inventory system.

---

## 12. Data Sources

The one new artifact is the landing resolver, whose only input is the effective permission
list already on the authenticated user:

| Decision | Input | Source |
|---|---|---|
| driver vs enterprise landing | `AuthUser.permissions` (`loading.driver.operate`, enterprise prefixes) | `/api/auth/me` — the effective permission list the backend already returns |
| system bypass | `AuthUser.is_system` | same |

No new backend read was needed; no value is computed from a second authority. Home's metric
sources are unchanged from the prior task's §15 map.

---

## 13. Missing Data Contracts

Unchanged from the prior task: **the cross-trip Driver Wallet read does not exist.** All
driver financial reads are trip-scoped (`/trips/{tripId}/settlement`, `/collections`). The
Wallet is therefore a disabled menu row, not a fake screen, and the required
`GET /api/driver/wallet` contract is specified in
TASK-DRIVER-APP-SHELL-HOME-NAVIGATION-FINAL-001-REPORT §14. **No financial value is
fabricated** (§17/§18 honoured).

---

## 14. Security

**No permission was changed. UI hiding is not treated as the boundary.**

- The landing resolver is a **routing** decision, not authorization. Every `/api/driver/*`
  route stays behind `auth:sanctum` + `permission:loading.driver.operate` with per-request
  ownership; the enterprise APIs enforce their own permissions regardless of where the UI
  lands.
- A user who is *both* driver and enterprise (or a system user) still lands on the dashboard —
  the resolver never *removes* enterprise access, it only redirects a driver-**only** account.
- The resolver treats `logistics.distribution.*` as **enterprise, not driver** — the exact
  dispatcher permission the DEV RBAC drift wrongly granted. A test pins this.

---

## 15. Mobile UX

`DriverShell` is mobile-first (slim header, full-width content, fixed thumb-reach bottom bar),
unchanged from the prior task. **Not visually re-verified at 400×866** — see §17; the layout
is structurally mobile-first but the rendered result is unobserved.

---

## 16. RTL

The shell and Home use logical properties (`text-start`, `rtl:rotate-180`) and all strings are
i18n keys with EN/AR parity (verified programmatically in the prior task; no new user-facing
string was added here — the resolver has none). **Not visually re-verified** — §17.

---

## 17. Browser Verification

### **BROWSER VERIFICATION BLOCKED**

Attempted this session, honestly:

- Frontend dev server is up (`/app/` → 200).
- Navigated to `http://127.0.0.1:5173/app/driver/home` → **redirected to `/app/login`**. No
  session, no cookies, no token.
- The DEV driver (`dev.driver396@ecos.local`) has a password **hash** but the plaintext was
  generated and discarded by explicit instruction in TASK-DEV-DRIVER-396-PASSWORD-SETUP-001;
  `mail.default = array`, so there is no reset channel.
- §21 and §27 forbid manufacturing or resetting credentials. I did neither.

**None of §21's 16 observations was made.** The central claim — a driver logging in now lands
in a driver-only app — is proven by the resolver's unit tests and by the routing structure,
**not** by watching it in the browser.

**To unblock:** sign in as the driver with a password you hold and hand me the authenticated
tab, or leave a driver session open. Then the whole §21 checklist can be walked.

---

## 18. Tests

| Check | Result |
|---|---|
| `post-login-landing.test.ts` (new) | **7 / 7** |
| Driver + auth suites together | **52 / 52 green** (5 files) |
| TypeScript | **23 = documented baseline**, 0 in touched files |
| ESLint (touched files) | **exit 0** |
| i18n parity | unchanged — no new user-facing string |

The new tests pin: driver-only → driver home; enterprise → dashboard; **both** → dashboard;
system → dashboard; no permissions → dashboard; null user → dashboard; and
`logistics.distribution.*` treated as enterprise.

No existing test was weakened.

---

## 19. Regression

No backend file was touched, so no backend regression was run. Frontend driver + auth suites
are green (§18). The prior task's driver-home tests (32) and the shell remain intact.

---

## 20. Files Changed

| File | Change |
|---|---|
| `features/auth/post-login-landing.ts` | **NEW** — `resolvePostLoginPath(user)` |
| `features/auth/post-login-landing.test.ts` | **NEW** — 7 tests |
| `features/auth/components/login-form.tsx` | land via the resolver instead of always `/dashboard`; dropped the now-unused `ROUTES` import |

**Not touched:** `AppShell`, `DriverShell`, `MobileMenu`, `ModuleRail`, Home, the router
structure, any backend file, any permission, the ECOS design system.

---

## 21. Demo Data Protection

No DEV business data was read as truth or modified. No order, trip, assignment, inventory,
loading, delivery, return or settlement row was touched. The landing decision reads only the
authenticated user's permission list.

---

## 22. Remaining Gaps

1. **Browser verification** — the whole visual acceptance, unobserved (auth blocked).
2. **Deep-link to `/dashboard` by an already-authenticated driver** is out of scope here — a
   driver who *bookmarks* `/dashboard` would still load `AppShell`. The normal login flow is
   fixed; re-routing arbitrary enterprise deep-links for a driver is a larger, riskier change
   and was not made. Worth a follow-up if required.
3. **Wallet backend read** (§13) — Phase 6.
4. **States D/E have no exercisable demo data** — no live trip has departed, so in-delivery and
   end-of-day cannot be seen even once auth is available, without a dispatched trip.
5. **DEV RBAC drift** — the live `driver` role still holds the two extra dispatcher
   permissions; needs your authorization to re-seed (reported in the prior two tasks, not
   acted on).

---

## 23. Certification Status

**CERTIFIED (2026-08-28).**

Originally reported PARTIALLY IMPLEMENTED / BLOCKED — the login resolver was fixed and tested,
but a driver session could not be authenticated to observe it. That block is now cleared:

- A live authenticated DEV driver session became available, and the DEV Driver role RBAC drift
  was reconciled under explicit owner authorization (see
  TASK-DRIVER-APP-PHASE-1-DEEP-LINK-ISOLATION-001-REPORT §15 for the exact 2-row mutation,
  before/after, scope isolation and no-business-data proof).
- **Browser-verified** with the driver-only account: login lands on `/app/driver/home`; the
  Driver Operations shell renders with no enterprise modules; `/app/driver/loading` and
  `/app/driver/orders` are reachable; and — the deep-link half — `/app/dashboard` now
  **redirects to `/app/driver/home`** instead of showing the ERP dashboard.

The screenshots' defect (a driver seeing the enterprise navigation) is closed on **both** the
login path (this report) and the deep-link path (the DEEP-LINK-ISOLATION task), and both are
now observed in the real browser, not merely inferred from source.

---

**STOP.** Phase 1 correction only. No Phase 2–6, no Finance, no ORD-00014, no demo-data
mutation, no commit.
