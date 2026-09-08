import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, Outlet, useLocation } from 'react-router-dom';
import {
  AlertTriangle,
  BarChart3,
  ClipboardList,
  FileText,
  Home,
  ListChecks,
  Map as MapIcon,
  Menu,
  Package,
  Receipt,
  RotateCcw,
  Truck,
  Wallet,
  type LucideIcon,
} from 'lucide-react';

import { Sheet, SheetContent, SheetTitle } from '@/components/ui/sheet';
import { cn } from '@/lib/utils';
import { ROUTES } from '@/router/routes';
import { useDriverTrips } from '@/features/operations/driver-mobile/hooks/use-driver-mobile';
import { selectCurrentTrip } from '@/features/operations/driver-mobile/lib/trip-lifecycle';
import { NotificationCenter } from '@/components/layout/header/notifications/notification-center';
import type enDriverMobile from '@/i18n/locales/en/driver-mobile.json';

/**
 * DriverShell — the Driver application's shared layout/navigation boundary for `/driver/*`.
 *
 * It is a SIBLING of the enterprise `AppShell`, not a child: `/driver/*` routes resolve
 * through this shell instead, so a driver never receives the ERP chrome. By construction this
 * file imports NO enterprise navigation — no `APP_MODULES`, `ModuleRail`, `AppSidebar`,
 * `MobileMenu`, `AppTopbar`, company/warehouse switchers or global search — so it *cannot*
 * render an enterprise module. The security boundary is unchanged and lives on the API
 * (`permission:loading.driver.operate` + per-request ownership); this is the UX boundary
 * that keeps the two shells apart (post-login routing sends driver-only users here;
 * `EnterpriseOnlyRoute` keeps them out of the ERP shell).
 *
 * TASK-ECOS-NOTIFICATIONS-FINAL-USER-REVIEW-REMEDIATION-010 §7 — the one deliberate
 * exception: `NotificationCenter` (the same self-contained bell+Sheet the enterprise
 * `AppTopbar` uses, never `AppTopbar` itself) as a small fixed top-right button, so a
 * driver can actually see notifications addressed to their own user id — confirmed this
 * shell rendered zero notification affordance before this task, which is exactly why
 * drivers reported "I don't see notifications" despite the backend already answering
 * their feed correctly.
 *
 * Navigation is a FIXED driver nav, not a permission-filtered module list: within the driver
 * app every destination belongs to the single `loading.driver.operate` capability, so there is
 * no per-item RBAC to apply and no second navigation authority is introduced. Labels come from
 * the existing `driver-mobile` › `shell` i18n contract. The four thumb-reach destinations sit
 * on the bottom bar; the remaining canonical destinations live in the "More" sheet. Trip-scoped
 * execution screens (stop detail, returns, settlement, custody, timeline) are reached by
 * navigating INTO a trip from these pages, not from the shell, so they are intentionally not
 * listed here.
 */

type DriverNavLabel = ($: typeof enDriverMobile) => string;

type DriverNavItem = {
  key: string;
  label: DriverNavLabel;
  icon: LucideIcon;
  path: string;
};

/** Bottom bar — the four thumb-reach destinations. */
const PRIMARY_NAV: DriverNavItem[] = [
  { key: 'home', label: ($) => $.shell.nav.home, icon: Home, path: ROUTES.driverHome },
  { key: 'loading', label: ($) => $.shell.nav.loading, icon: Package, path: ROUTES.driverLoading },
  {
    key: 'orders',
    label: ($) => $.shell.nav.orders,
    icon: ClipboardList,
    path: ROUTES.driverOrders,
  },
  {
    key: 'vehicle',
    label: ($) => $.shell.nav.vehicle,
    icon: Truck,
    path: ROUTES.driverVehicleInventory,
  },
];

/** "More" sheet, general section — the canonical flat driver destinations. */
const SECONDARY_NAV: DriverNavItem[] = [
  { key: 'map', label: ($) => $.shell.nav.map, icon: MapIcon, path: ROUTES.driverMap },
  { key: 'wallet', label: ($) => $.shell.nav.wallet, icon: Wallet, path: ROUTES.driverWallet },
  { key: 'reports', label: ($) => $.shell.nav.reports, icon: BarChart3, path: ROUTES.driverReports },
  { key: 'tripExpenses', label: ($) => $.shell.nav.tripExpenses, icon: Receipt, path: ROUTES.driverTripExpenses },
  { key: 'statement', label: ($) => $.shell.nav.statement, icon: FileText, path: ROUTES.driverStatement },
  // Internal Collaboration & Tasks (TASK-ECOS-COLLABORATION-WORKSPACE-DRIVER-EXPOSURE-
  // CLOSURE-005) — a nav ENTRY only, added the same way every other flat destination
  // here is: DriverShell itself, its four primary thumb-reach slots, and its ownership
  // are all untouched. The page it points to lives in Operations\DriverMobile, reusing
  // Collaboration's existing TaskDetailDrawer — no parallel driver task authority.
  { key: 'tasks', label: ($) => $.shell.nav.tasks, icon: ListChecks, path: ROUTES.driverTasks },
];

/**
 * "More" sheet, trip section (§1) — execution screens that belong to the driver's CURRENT trip.
 * They carry a `:tripId`, so they appear only when an active trip exists and the id is substituted
 * at render. This gives Returns/Exceptions a real navigation entry without exposing raw
 * parameterised routes as top-level destinations.
 */
const TRIP_NAV: DriverNavItem[] = [
  { key: 'returns', label: ($) => $.shell.nav.returns, icon: RotateCcw, path: ROUTES.driverTripReturns },
  { key: 'exceptions', label: ($) => $.shell.nav.exceptions, icon: AlertTriangle, path: ROUTES.driverTripExceptions },
];

function isActivePath(pathname: string, path: string): boolean {
  return pathname === path || pathname.startsWith(`${path}/`);
}

export function DriverShell() {
  const { t } = useTranslation('driver-mobile');
  const { pathname } = useLocation();
  const [menuOpen, setMenuOpen] = useState(false);

  // Resolve the driver's current trip so the "From current trip" section can offer its execution
  // screens (Returns / Exceptions) without exposing raw :tripId routes. The ONE shared
  // "which trip is current" rule (trip-lifecycle.ts); empty when the driver has no active trip.
  const { data: trips } = useDriverTrips();
  const currentTrip = selectCurrentTrip(trips);
  const tripLinks = currentTrip
    ? TRIP_NAV.map((item) => ({ ...item, to: item.path.replace(':tripId', String(currentTrip.id)) }))
    : [];

  const moreActive =
    SECONDARY_NAV.some((item) => isActivePath(pathname, item.path)) ||
    tripLinks.some((item) => isActivePath(pathname, item.to));

  return (
    <div className="flex min-h-svh flex-col bg-background">
      {/* §7 — the shell's only fixed top-of-screen chrome, deliberately just the bell
          (not a full enterprise-style top bar). Driver pages still own their own
          in-content header entirely.
          D5 (TASK-ECOS-COMMERCE-IAM-NOTIFICATIONS-FINAL-USER-REVIEW-REMEDIATION-005) —
          every driver page's own sticky header (e.g. driver-home-page.tsx) puts its
          Refresh action flush at this exact same end/top corner, so the bell used to sit
          directly on top of it. Fixed with real flex sizing, not a screen-width hack:
          `size-9` + `p-1` gives this chip the SAME footprint `<main>`'s `pt-14` below
          reserves, so at rest (the reported, common case) a page's own header starts
          below the bell rather than under it; the opaque, bounded chip (bg + border +
          shadow, not a bare transparent icon) also keeps the bell itself an
          unambiguous, distinct tappable target even in a mid-scroll frame where a
          page's own `sticky top-0` header can still momentarily reach this same band. */}
      <div className="fixed end-3 top-3 z-40 flex items-center gap-2 rounded-full border bg-background/95 p-1 shadow-sm">
        <NotificationCenter />
      </div>

      {/* Driver page content owns its own header; the shell adds only the bottom nav.
          `pb-16` clears the fixed bottom bar (h-16) so no page content hides beneath it.
          `pt-14` (D5) clears the fixed bell chip above so a page's own header/actions
          never render underneath it. */}
      <main className="flex-1 pb-16 pt-14">
        <Outlet />
      </main>

      {/* Fixed driver bottom bar — the ONLY persistent shell chrome. */}
      <nav
        aria-label={t(($) => $.shell.primaryNav)}
        className="fixed inset-x-0 bottom-0 z-40 flex h-16 items-stretch border-t bg-background"
      >
        {PRIMARY_NAV.map(({ key, label, icon: Icon, path }) => {
          const active = isActivePath(pathname, path);
          const text = t(label);
          return (
            <Link
              key={key}
              to={path}
              aria-label={text}
              aria-current={active ? 'page' : undefined}
              className={cn(
                'flex flex-1 flex-col items-center justify-center gap-0.5 text-[10px] font-medium transition-colors',
                active ? 'text-primary' : 'text-muted-foreground hover:text-foreground',
              )}
            >
              <Icon className="size-5" aria-hidden />
              <span>{text}</span>
            </Link>
          );
        })}

        <button
          type="button"
          onClick={() => setMenuOpen(true)}
          aria-label={t(($) => $.shell.openMenu)}
          aria-current={moreActive ? 'page' : undefined}
          className={cn(
            'flex flex-1 flex-col items-center justify-center gap-0.5 text-[10px] font-medium transition-colors',
            moreActive ? 'text-primary' : 'text-muted-foreground hover:text-foreground',
          )}
        >
          <Menu className="size-5" aria-hidden />
          <span>{t(($) => $.nav.more)}</span>
        </button>
      </nav>

      {/* "More" sheet — the remaining canonical destinations. */}
      <Sheet open={menuOpen} onOpenChange={setMenuOpen}>
        <SheetContent side="bottom" className="rounded-t-2xl pb-8">
          <SheetTitle>{t(($) => $.shell.menuTitle)}</SheetTitle>
          <div className="mt-4 grid grid-cols-2 gap-2">
            {SECONDARY_NAV.map(({ key, label, icon: Icon, path }) => {
              const active = isActivePath(pathname, path);
              const text = t(label);
              return (
                <Link
                  key={key}
                  to={path}
                  onClick={() => setMenuOpen(false)}
                  aria-current={active ? 'page' : undefined}
                  className={cn(
                    'flex items-center gap-3 rounded-xl border p-4 text-sm font-medium transition-colors',
                    active
                      ? 'border-primary/40 bg-primary/5 text-primary'
                      : 'bg-card text-foreground hover:bg-accent/40',
                  )}
                >
                  <Icon className="size-5 shrink-0" aria-hidden />
                  <span>{text}</span>
                </Link>
              );
            })}
          </div>

          {/* §1 — trip-scoped execution screens for the driver's current trip (Returns / Exceptions),
              shown only when an active trip exists. */}
          {tripLinks.length > 0 && (
            <>
              <p className="mt-5 mb-2 text-xs font-semibold text-muted-foreground">
                {t(($) => $.shell.fromCurrentTrip)}
              </p>
              <div className="grid grid-cols-2 gap-2">
                {tripLinks.map(({ key, label, icon: Icon, to }) => {
                  const active = isActivePath(pathname, to);
                  const text = t(label);
                  return (
                    <Link
                      key={key}
                      to={to}
                      onClick={() => setMenuOpen(false)}
                      aria-current={active ? 'page' : undefined}
                      className={cn(
                        'flex items-center gap-3 rounded-xl border p-4 text-sm font-medium transition-colors',
                        active
                          ? 'border-primary/40 bg-primary/5 text-primary'
                          : 'bg-card text-foreground hover:bg-accent/40',
                      )}
                    >
                      <Icon className="size-5 shrink-0" aria-hidden />
                      <span>{text}</span>
                    </Link>
                  );
                })}
              </div>
            </>
          )}
        </SheetContent>
      </Sheet>
    </div>
  );
}
