# TASK-MOBILE-ENTERPRISE-MODULE-SUBNAV-001

> **UX correction (CTO decision).** An earlier iteration exposed the module sub-navigation via a
> mobile **top-header toggle** opening the canonical `AppSidebar` drawer. Per the CTO UX decision,
> the approved pattern is **inline nested (accordion) navigation inside the primary MobileMenu**.
> This report describes the final, approved implementation; the header-toggle iteration was
> **reverted** (see §11) so no two competing mobile navigation systems remain.

## 1. Root Cause

Inside an Enterprise module on mobile, only the module `defaultPath` was reachable — the primary
mobile menu listed modules but exposed no path to a module's other authorized pages
(Commerce → Products/Customers). Desktop shows intra-module nav (persistent `AppSidebar`); mobile
had no equivalent.

## 2. Approach

The primary `MobileMenu` (fullscreen, `md:hidden`) now renders each authorized module as an
**expandable accordion row**: tapping a module reveals its child pages **inline**, sourced from the
same canonical navigation metadata desktop uses. Single-open accordion; the active module is
auto-expanded on open; selecting a child navigates and closes the menu.

## 3. Mobile Navigation Architecture Trace

`useNavigation()` (module-level RBAC) → authorized modules → `MobileMenu` renders each module row →
children = `moduleNavLinks(mod.items)` from `config/module-navigation.ts` → child `NavLink`
navigates to its own canonical `path` and calls `onClose`. The active module comes from
`useActiveModule()` → `findModuleByPath(pathname)`. This is the same metadata + module-level RBAC
the desktop `ModuleRail`/`AppSidebar` consume — no mobile page registry, no hardcoded child arrays.

## 4. Canonical Authority

Modules from `useNavigation().modules`; children from `moduleNavLinks(mod.items)`; labels from
`useNavLabel` (`common.nav.groups`/`items`); active module from `useActiveModule`. Commerce's
Orders/Products/Customers, Inventory's pages, etc. all come straight from the config (§2). No
hardcoded Commerce/Inventory-specific arrays; works for every module generically (§9).

## 5. Accordion Behavior

- A module with **≥2** authorized children is an expandable row (chevron; tap toggles expansion).
- A module with **≤1** navigable destination navigates **directly** to its `defaultPath` — no
  meaningless one-item accordion (§ "single destination navigates directly").
- **Single-open accordion:** expanding one module collapses the others (keeps the menu short).
- **Active module auto-expanded** when the menu opens.
- Selecting a child **navigates to its canonical route and closes** the menu.

## 6. Active Module / No Stale State (§5)

`expandedId` is re-synced to the active module whenever the menu (re)opens or the route's module
changes, using React's **"adjust state during render"** pattern (not an effect — no extra commit,
and ESLint-clean). Within one open session the user's manual expand/collapse is preserved; on
reopen or module switch it resets to the active module. Test-pinned: an Inventory-active menu does
not show Commerce's children until Commerce is tapped, and tapping it collapses Inventory.

## 7. RBAC (§6)

Same authority as desktop: module-level visibility via `useNavigation` + per-page **route guards**
(unchanged/not weakened). `MobileMenu` renders exactly `moduleNavLinks(mod.items)` for each module
the authority returns — so if the authority omits a child, the menu omits it (test-pinned:
authority returns Commerce without Products → Products absent). There is no per-item permission
field in the canonical metadata and desktop applies none; introducing one would be a separate
permissions model (§2 forbids), so mobile matches desktop and relies on route guards per page.

## 8. Default Path (§7)

Preserved: a module's default destination remains reachable — for multi-child modules it is the
first child (e.g. Commerce's Orders = `defaultPath`), and single-destination modules navigate
straight to `defaultPath`. `defaultPath` values are unchanged.

## 9. Driver Isolation (§8)

Untouched. `MobileMenu` is Enterprise-only (`AppShell`); `DriverShell` has its own nav and never
renders it. No Driver code changed.

## 10. Generic Implementation (§9)

`MobileMenu` renders whatever modules/items the canonical config + authority provide — verified
generically over Commerce, Inventory and (in the shared AppSidebar test) Purchasing. No
module-specific code.

## 11. Removal of the Superseded Header Trigger

The earlier iteration's mobile change to `AppTopbar` (making the sidebar toggle mobile-visible) was
**reverted**: the toggle is back to tablet-only (`hidden md:flex lg:hidden`), and `AppShell` no
longer passes `hasSidebarItems` to it. The only mobile module navigation is now the MobileMenu
accordion — no competing systems. Tablet (topbar toggle → `AppSidebar` Sheet) and desktop
(persistent `AppSidebar`) are unchanged (§11). Test-pinned: `app-topbar.test.tsx` asserts the
toggle is tablet-only (mobile-hidden).

## 12. Files Changed

| File | Change |
|---|---|
| `components/layout/mobile-menu.tsx` | Inline accordion sub-nav (canonical children, single-open, auto-expand active, direct-nav for single-destination modules) |
| `components/layout/app-topbar.tsx` | Reverted mobile toggle change → tablet-only again |
| `components/layout/app-shell.tsx` | Reverted (no `hasSidebarItems` passed to topbar) |
| `components/layout/mobile-menu.test.tsx` | **Rewritten** — accordion behavior (CTO test list) |
| `components/layout/app-topbar.test.tsx` | **NEW** — proves the obsolete mobile trigger is gone |
| `components/layout/app-sidebar.test.tsx` | Reworded to "desktop/tablet" (the AppSidebar tiers); still proves canonical child navigation |

`AppSidebar` logic, desktop layout, routes, backend and DriverShell are unchanged.

## 13. Focused Verification (§12)

`mobile-menu.test.tsx` (real `MemoryRouter`; controlled authority + active module) — **7/7**:
- Commerce auto-expands; Orders/Products/Customers appear beneath it.
- Products navigates to **/products** (not the Commerce default /orders) and closes the menu.
- Current child (`/products`) is highlighted (`aria-current="page"`).
- Single-open: expanding Inventory collapses Commerce.
- No stale/cross-module state (Inventory-active menu hides Commerce children until tapped).
- Unauthorized child absent (authority returns Commerce without Products → Products absent).
- Single-destination module (Dashboard) navigates directly (no accordion button) and closes.

`app-topbar.test.tsx` (1) — obsolete mobile sub-nav trigger absent (toggle tablet-only).
`app-sidebar.test.tsx` (6) — desktop/tablet AppSidebar child navigation (Commerce/Inventory/
Purchasing), no cross-module bleed, item-less module renders nothing.

Static: **ESLint 0** on all touched files; **0 tsc errors in touched files** (the standard). Note:
the repo-wide tsc count read 30 (was 23) because a concurrent, unrelated uncommitted change in
`features/operations/components/wave-workspace-layout.tsx` (7 errors) appeared in this shared
worktree — **not** from this task; my touched files contribute zero.

## 14. Deferred Browser Verification

The accordion behavior and navigation are unit-proven in jsdom. A real-device pass (mobile
viewport: open the menu → Commerce is expanded → tap Products/Customers; expand Inventory and see
Commerce collapse; confirm no header sub-nav trigger) is **deferred to final system review** — the
`md:hidden`/breakpoint visibility is browser-only.

## 15. Remaining Risks

- Per-child nav RBAC is module-level (matches desktop) + route guards; tightening to per-item would
  be a new permission model (out of scope, §2).
- Repo-wide tsc baseline is currently inflated by concurrent unrelated work (`wave-workspace-layout.tsx`);
  independent of this change.
- Implemented and verified; **not deployed** (no deploy authorized). Vite `:5173` reflects the
  source via HMR; the nginx `:8081` bundle would need a `vite build` + `docker cp` refresh if wanted.

## 16. Implementation Status

**IMPLEMENTATION STATUS: COMPLETE** — mobile users reach every authorized page within an Enterprise
module via inline single-open accordion navigation in the primary MobileMenu, from the same
canonical metadata + module-level RBAC as desktop; the active module auto-expands, children
navigate to their own routes and close the menu, single-destination modules navigate directly, the
superseded header trigger is removed, and desktop/tablet/Driver navigation are unchanged.

**FINAL CERTIFICATION: DEFERRED TO FINAL SYSTEM REVIEW.**

---

**STOP.** No commit. No push. No deploy. No DEV business-data mutation.
