# TASK-MOBILE-ENTERPRISE-NAVIGATION-FIX-001

## 1. Root Cause

The Enterprise mobile menu (`components/layout/mobile-menu.tsx`) rendered the **raw static module
registry** (`APP_MODULES`) instead of the **canonical, RBAC-filtered navigation authority**
(`useNavigation().modules`) that the desktop Module Rail uses. So the mobile menu listed modules
the authenticated user was **not authorized** for. Tapping such a module did fire a real
navigation, but the destination route is permission-guarded, so it **redirected/bounced back** —
which presents to the user as "the item doesn't open." Only modules the user is actually
authorized for — and the always-visible `dashboard`, which happens to be the **first** card —
reliably opened. Hence "navigation appears to work only for the first item."

The navigation **mechanics** (each `NavLink` has a distinct `to`, a stable key, and
`onClick={onClose}`) were **not** the defect — proven by test (see §9). The single defect was the
missing authorization filter.

**Fix:** `MobileMenu` now consumes `useNavigation()` — the exact same authority as the desktop
`ModuleRail` — so every card shown is one the user may open.

## 2. Mobile Navigation Architecture Trace

`MobileBottomNav` "More" button (`onOpenMenu`) → `AppShell` `mobileMenuOpen=true` →
`MobileMenu` (`fixed inset-0 z-50`) renders the workspace cards → tap a card
(`<NavLink to={mod.defaultPath} onClick={onClose}>`) → `onClose` closes the menu **and**
react-router navigates to `mod.defaultPath` → route guard evaluates permission → destination (or,
for an unauthorized module, a redirect).

Overlay/z-index checked and **cleared**: `MobileMenu` is `z-50`; `MobileBottomNav` is `z-40` and
rendered beneath it — no element overlays the module list, no `pointer-events`/backdrop trap. Keys
are stable (`mod.id`). Each `defaultPath` in `APP_MODULES` is **distinct** (dashboard→/dashboard,
commerce→/orders, inventory→/inventory dashboard, operations→/wave workspace, shipping→/shipping
companies, finance→/accounting, etc.) — so "all cards resolve to the same route" and "only the
first has a valid href" were **ruled out** by reading the config.

## 3. Why the First Item Worked

`dashboard` is in `ALWAYS_VISIBLE` (`use-navigation.ts`) — every authenticated user can open it,
and it is the first card. Its navigation was never blocked by a guard, so it always appeared to
"work."

## 4. Why the Other Items Failed

For a user without a given module's permission/feature/navigation-whitelist, that module should
not be in their navigation at all — but the mobile menu showed it anyway (raw registry). Tapping
it navigated into a guarded route that bounced back, so the module "did not open." The desktop
rail never exhibited this because it renders only `useNavigation().modules`.

## 5. Route Authority Used

The **canonical** authority, unchanged and reused: `useNavigation()` /
`isModuleVisible()` (`features/authorization/use-navigation.ts`) over the single `APP_MODULES`
registry (`config/module-navigation.ts`). No second mobile route map, no duplicated workspace
definitions, no hardcoded module URLs, no separate permissions model (§2). Desktop and mobile now
consume the same navigation authority.

## 6. RBAC Preservation

RBAC is **strengthened, not weakened** (§3): the mobile menu now shows — and can only navigate to —
modules `isModuleVisible()` authorizes (feature flags → system-user → always-visible → Role
Template navigation whitelist → domain-permission fallback). A hidden/unauthorized module is no
longer even rendered on mobile, so it cannot be reached from the menu. Test-pinned: unauthorized
modules (`administration`, `engineering`) are absent from the rendered menu.

## 7. Driver Isolation Impact

**None** (§4). `MobileMenu` is rendered only by `AppShell` (the Enterprise shell) — confirmed by
grep; the only occurrence in `driver-shell.tsx` is a docblock comment. A true driver-only user is
routed to `DriverShell` (its own nav), never to `AppShell`/`MobileMenu`. No Driver App code was
touched. The `driver` module remains governed by the same `useNavigation` authority (a mixed-role
user with `loading.driver.operate` sees it — correct), which is unchanged.

## 8. Files Changed

| File | Change |
|---|---|
| `components/layout/mobile-menu.tsx` | Consume `useNavigation().modules` instead of raw `APP_MODULES` (3 lines: import, hook call, map source) |
| `components/layout/mobile-menu.test.tsx` | **NEW** — focused navigation + RBAC tests |

No desktop file changed (§8). No backend, no routes, no business logic, no DEV data (§10).

## 9. Focused Verification

`mobile-menu.test.tsx` (real `MemoryRouter`; a harness that closes/unmounts the menu on tap, so
the real click→navigate+unmount race is reproduced, not a synthetic always-open menu) — **3/3**:
- **Each** authorized module (first `dashboard`, second `commerce`, middle `inventory`, last
  `finance`) navigates to its **own** `defaultPath` — not to the first route — and the menu closes.
  This proves the `onClick={onClose}` unmount does **not** drop navigation for any item.
- A middle tap (`inventory`) lands on `/inventory`, not `/dashboard` (items do not all resolve to
  the first route).
- Only authority-authorized modules render; `administration`/`engineering` (filtered out) are
  absent and therefore untappable (RBAC preserved).

Static: **tsc 23 = baseline (0 in touched files)**, **ESLint 0**. No full suite, no browser cert (§9).

## 10. Deferred Browser Verification

The component test proves the navigation mechanics and the RBAC filter in jsdom. A real-device
touch pass (authenticated Enterprise user on a mobile viewport: open the menu, tap Commerce /
Inventory / Operations / Shipping / Procurement / Finance / Marketing / Executive in turn, confirm
each closes the menu and opens its own destination) is **deferred to final system review** (§9) —
jsdom cannot exercise real pointer-events/touch. No CSS defect is evident in code (z-index and
overlay were verified correct), so this is confirmation, not an open fix.

## 11. Remaining Risks

- **Admin/system users** already saw every module (the filter returns all for `isSystem`), so for
  them the RBAC fix changes nothing. If a full-permission user still reports "first-only" on a real
  device, the cause would be a CSS/touch-layer issue not reproducible in jsdom — covered by the
  deferred browser pass (§10). The code-level audit found no such issue (menu z-50 > bottom-nav
  z-40, no overlay, distinct routes, correct keys).
- The mobile menu still shows no per-item sub-links (only module cards) — unchanged, out of scope.

## 12. Implementation Status

**IMPLEMENTATION STATUS: COMPLETE** — the confirmed root cause (mobile menu bypassing the canonical
RBAC navigation authority) is fixed by consuming `useNavigation()`, aligning mobile with desktop;
per-item navigation and RBAC filtering are proven by focused tests; desktop and Driver isolation
are untouched.

**FINAL CERTIFICATION: DEFERRED TO FINAL SYSTEM REVIEW.**

---

**STOP.** No commit. No push. No deploy. No DEV business-data mutation.
