import { useEffect, useMemo, useRef, useState } from 'react';
import { ChevronDown, ChevronRight, Search } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { NavLink } from 'react-router-dom';

import { cn } from '@/lib/utils';
import { useAuthorization, useNavigation, usePermission } from '@/features/authorization';
import { usePriceReviewBadge } from '@/features/cost-management/hooks/use-pricing-reviews';
import {
  moduleNavLinks,
  visibleModuleItems,
  type AppModule,
  type ModuleId,
  type NavItemKey,
} from '@/config/module-navigation';
import { useRecentNav } from '@/hooks/use-recent-nav';
import { FAMILY_ORDER, familyOf, type ModuleFamily } from './mobile-module-families';
import { useNavLabel } from './use-nav-label';

type MobileModulesLauncherProps = {
  activeModuleId: ModuleId | null;
  onNavigate: (path: string) => void;
  /**
   * TASK-ECOS-MOBILE-MENU-NAVIGATION-FINAL-REMEDIATION-001 — owned by the
   * parent Drawer's compact Search icon, not by this component: the search
   * row below renders only while this is true, and only ever becomes true
   * from that icon's own click handler, never on mount/open — search must
   * require an explicit tap, and the fix belongs where the toggle lives.
   */
  searchOpen: boolean;
};

type PageHit = { module: AppModule; path: string; key: NavItemKey; icon: AppModule['icon'] };

/** Requirement C: the Recent row shows exactly the latest 3 — not the up-to-5
 * `useRecentNav` may still hold in storage. Applied at this read/view-model
 * boundary (the fully resolved, display-ready list), not by rendering more
 * rows and hiding them, and not by changing `useRecentNav`'s own storage cap
 * or ordering — that authority is untouched. */
const RECENT_DISPLAY_LIMIT = 3;

/**
 * The Drawer's grouped navigation list (TASK-ECOS-MOBILE-NAVIGATION-DRAWER-
 * BOTTOM-TRIGGER-001) — replaces the previous 3-column tile grid + full-screen
 * drill-in with a single scrollable list: modules with one destination are
 * direct links, modules with several expand IN PLACE (an accordion), so the
 * whole navigation experience stays one Drawer, never a second screen.
 *
 * Same canonical data source as before — `useNavigation()` / `moduleNavLinks()`
 * — no new visibility rule, no new module, no new route. Search's own
 * matching logic and result rendering, and Recent's own ordering/storage
 * (`useRecentNav`), are unchanged (TASK-...-FINAL-REMEDIATION-001 only wraps
 * search's VISIBILITY behind the parent's `searchOpen` prop and caps Recent's
 * DISPLAY at `RECENT_DISPLAY_LIMIT` — neither touches how a result is found
 * or how an entry becomes "recent").
 *
 * The one badge in the whole nav system (`usePriceReviewBadge`, already shown
 * on the desktop rail — see `app-sidebar.tsx`) is surfaced here too, on the
 * same `price-review` item, reusing the same query — not a new capability.
 *
 * TASK-ECOS-MOBILE-NAVIGATION-WORLD-CLASS-DESIGN-CLOSURE-002 — the previous
 * per-family "card" treatment (`rounded-xl border bg-card ... shadow-sm`
 * wrapping every family group, every Recent row, and every search result)
 * read as a stack of dashboard panels rather than one calm navigation list —
 * exactly the "oversized section containers"/"dashboard-like cards inside
 * navigation" the User's fresh review named. Every one of those surfaces is
 * now a flat, borderless, lightly-tinted region (`rounded-2xl bg-muted/40`,
 * no border, no shadow) instead, and Recent is a compact horizontal chip row
 * rather than 3 full-width bordered rows — deliberately lighter than the
 * module list so it reads as secondary. None of this touched the data these
 * surfaces render: `useNavigation()`, `moduleNavLinks()`, `useRecentNav()`,
 * and the search matching logic are exactly as they were.
 */
export function MobileModulesLauncher({
  activeModuleId,
  onNavigate,
  searchOpen,
}: MobileModulesLauncherProps) {
  const { t } = useTranslation('common');
  const navLabel = useNavLabel();
  const { modules } = useNavigation();
  const { recent, recordVisit } = useRecentNav();
  const [query, setQuery] = useState('');
  const searchInputRef = useRef<HTMLInputElement>(null);
  // Default-expand the module the User is currently inside, so opening the
  // Drawer shows "where am I" already unfolded (§9) — collapsed otherwise.
  const [expandedId, setExpandedId] = useState<ModuleId | null>(activeModuleId);

  // Clearing the query on close means reopening search always starts fresh rather than
  // showing stale results from a previous search. Adjusted during render (React's
  // documented pattern for resetting state on a prop change, already used elsewhere in
  // this codebase — e.g. role-detail-drawer.tsx) rather than in the effect below, which
  // stays reserved for the one genuine imperative DOM action (focus).
  const [prevSearchOpen, setPrevSearchOpen] = useState(searchOpen);
  if (searchOpen !== prevSearchOpen) {
    setPrevSearchOpen(searchOpen);
    if (!searchOpen) setQuery('');
  }

  // Focus follows an explicit tap on the parent's Search icon (the `true`
  // transition of `searchOpen`), never the Drawer's own mount/open — that is
  // the entire fix for the reported autofocus/keyboard defect.
  useEffect(() => {
    if (searchOpen) {
      searchInputRef.current?.focus();
    }
  }, [searchOpen]);

  // §17 — the same page-level permission boundary the desktop sidebar applies. Threaded
  // into every moduleNavLinks() call below so the accordion, page search and Recent list
  // cannot offer a page this role is not allowed to see.
  const { can } = usePermission();
  const { context } = useAuthorization();
  const navOverrides = context.navigationOverrides;

  const trimmed = query.trim().toLowerCase();
  const searching = searchOpen && trimmed.length > 0;

  const grouped = useMemo(() => {
    const byFamily = new Map<ModuleFamily, AppModule[]>();
    for (const mod of modules) {
      const family = familyOf(mod.id);
      const list = byFamily.get(family) ?? [];
      list.push(mod);
      byFamily.set(family, list);
    }
    return FAMILY_ORDER.map((family) => ({ family, modules: byFamily.get(family) ?? [] })).filter(
      (group) => group.modules.length > 0,
    );
  }, [modules]);

  const searchResults = useMemo((): { moduleHits: AppModule[]; pageHits: PageHit[] } => {
    if (!searching) return { moduleHits: [], pageHits: [] };

    const moduleHits = modules.filter((mod) => navLabel.group(mod.id).toLowerCase().includes(trimmed));

    const pageHits: PageHit[] = [];
    for (const mod of modules) {
      for (const link of moduleNavLinks(mod.items, can, navOverrides)) {
        if (navLabel.item(link.key).toLowerCase().includes(trimmed)) {
          pageHits.push({ module: mod, path: link.path, key: link.key, icon: link.icon });
        }
      }
    }
    return { moduleHits, pageHits };
  }, [searching, trimmed, modules, navLabel, can, navOverrides]);

  const resolvedRecent = useMemo(() => {
    return recent
      .map((entry) => {
        const mod = modules.find((m) => m.id === entry.moduleId);
        if (!mod) return null;
        if (entry.itemKey) {
          const link = moduleNavLinks(mod.items, can, navOverrides).find((l) => l.key === entry.itemKey);
          if (!link) return null;
          return { path: entry.path, icon: link.icon, primary: navLabel.item(link.key), secondary: navLabel.group(mod.id) };
        }
        return { path: entry.path, icon: mod.icon, primary: navLabel.group(mod.id), secondary: undefined };
      })
      .filter((e): e is NonNullable<typeof e> => e !== null)
      // Sliced AFTER resolving+filtering, not on the raw storage list, so a
      // stale entry (a since-removed module) never displaces a genuinely
      // valid one out of the visible top 3 — `useRecentNav`'s own ordering
      // (most-recently-visited-first) is preserved exactly, just truncated
      // for display.
      .slice(0, RECENT_DISPLAY_LIMIT);
  }, [recent, modules, navLabel, can, navOverrides]);

  // Toggling a row in the grouped list itself — collapses if already open.
  function toggleModule(mod: AppModule) {
    if (moduleNavLinks(mod.items, can, navOverrides).length < 2) {
      recordVisit({ moduleId: mod.id, path: mod.defaultPath });
      onNavigate(mod.defaultPath);
    } else {
      setExpandedId((cur) => (cur === mod.id ? null : mod.id));
    }
  }

  // Selecting a module FROM SEARCH — always exits search and expands the
  // module in the grouped list (never a toggle: this is a fresh selection,
  // and the grouped list isn't even visible yet for a toggle to act on).
  function selectModuleFromSearch(mod: AppModule) {
    if (moduleNavLinks(mod.items, can, navOverrides).length < 2) {
      recordVisit({ moduleId: mod.id, path: mod.defaultPath });
      onNavigate(mod.defaultPath);
    } else {
      setQuery('');
      setExpandedId(mod.id);
    }
  }

  function selectPage(mod: AppModule, key: NavItemKey, path: string) {
    recordVisit({ moduleId: mod.id, itemKey: key, path });
    onNavigate(path);
  }

  return (
    <div className="flex h-full flex-col">
      {/* Search — rendered only while the parent Drawer's Search icon has it
          open; the input is focused by the effect above, not on mount. */}
      {searchOpen ? (
        <div className="shrink-0 border-b p-3">
          {/* Borderless like every other surface in this redesign — the
              focus ring alone (not an always-on border) signals "this is
              interactive", consistent with the flatter treatment below. */}
          <div className="flex items-center gap-2.5 rounded-2xl bg-muted/40 px-3.5 py-3 transition-colors focus-within:bg-background focus-within:ring-1 focus-within:ring-primary/40">
            <Search className="size-4 shrink-0 text-muted-foreground" aria-hidden />
            <input
              ref={searchInputRef}
              type="search"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder={t(($) => $.nav.searchModulesPlaceholder)}
              aria-label={t(($) => $.nav.searchModulesPlaceholder)}
              className="min-w-0 flex-1 bg-transparent text-[15px] outline-none placeholder:text-muted-foreground"
              autoComplete="off"
            />
          </div>
        </div>
      ) : null}

      <div className="flex-1 overflow-y-auto p-3">
        {searching ? (
          <SearchResults
            moduleHits={searchResults.moduleHits}
            pageHits={searchResults.pageHits}
            onSelectModule={selectModuleFromSearch}
            onSelectPage={selectPage}
          />
        ) : (
          <>
            {/* TASK-...-WORLD-CLASS-DESIGN-CLOSURE-002 — Recent is a compact,
                horizontally-scrolling chip row, deliberately lighter than the
                module list below it: it must read as secondary, never compete
                with primary navigation for vertical space or visual weight
                (task §10/§17). Still exactly the same 3 destinations, same
                `onNavigate` — only the presentation shrank. */}
            {resolvedRecent.length > 0 ? (
              <div className="mb-4">
                <p className="mb-2 px-1 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                  {t(($) => $.nav.recent)}
                </p>
                <div className="-mx-1 flex gap-2 overflow-x-auto px-1 pb-0.5">
                  {resolvedRecent.map((entry) => {
                    const Icon = entry.icon;
                    return (
                      <button
                        key={entry.path}
                        type="button"
                        onClick={() => onNavigate(entry.path)}
                        className="flex h-11 shrink-0 items-center gap-2 rounded-full bg-muted/60 ps-2 pe-3.5 text-start transition-colors active:bg-accent/60"
                      >
                        <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-background">
                          <Icon className="size-3.5 text-muted-foreground" aria-hidden />
                        </span>
                        <span className="max-w-28 truncate text-[13px] font-medium">
                          {entry.primary}
                        </span>
                      </button>
                    );
                  })}
                </div>
              </div>
            ) : null}

            {/* Module families — a flat, lightly-tinted grouped list (no
                border/shadow "card" per group) so the primary navigation
                reads as one calm, scannable list rather than a stack of
                dashboard-style panels (task §4/§24: the exact treatment that
                was previously identified as too heavy). */}
            {grouped.map(({ family, modules: familyModules }) => (
              <div key={family} className="mb-4 last:mb-0">
                <p className="mb-2 px-1 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                  {t(($) => $.nav.families[family])}
                </p>
                <div className="flex flex-col gap-0.5 rounded-2xl bg-muted/40 p-1.5">
                  {familyModules.map((mod) => (
                    <ModuleRow
                      key={mod.id}
                      module={mod}
                      isActiveModule={mod.id === activeModuleId}
                      isExpanded={expandedId === mod.id}
                      onToggle={() => toggleModule(mod)}
                      onNavigateChild={onNavigate}
                    />
                  ))}
                </div>
              </div>
            ))}
          </>
        )}
      </div>
    </div>
  );
}

// ── Price Review badge ─────────────────────────────────────────────────────
// The one existing unread/pending counter in the nav system (desktop rail —
// app-sidebar.tsx). Reuses the same canonical query; not a new capability.
function PriceReviewBadge() {
  const { data } = usePriceReviewBadge();
  const count = data?.pending ?? 0;
  if (count === 0) return null;
  return (
    <span className="ms-auto flex h-4 min-w-4 items-center justify-center rounded-full bg-amber-500 px-1 text-[10px] font-semibold leading-none text-white tabular-nums">
      {count > 99 ? '99+' : count}
    </span>
  );
}

// ── One module row — a direct link, or an inline-expanding group ───────────

type ModuleRowProps = {
  module: AppModule;
  isActiveModule: boolean;
  isExpanded: boolean;
  onToggle: () => void;
  onNavigateChild: (path: string) => void;
};

function ModuleRow({ module, isActiveModule, isExpanded, onToggle, onNavigateChild }: ModuleRowProps) {
  const navLabel = useNavLabel();
  const { recordVisit } = useRecentNav();
  const { can } = usePermission();
  const { context } = useAuthorization();
  const Icon = module.icon;
  const isLeaf = moduleNavLinks(module.items, can, context.navigationOverrides).length < 2;
  // §17 — section headers drop with their last visible child, same rule as the sidebar.
  const items = visibleModuleItems(module.items, can, context.navigationOverrides);

  return (
    <div>
      <button
        type="button"
        onClick={onToggle}
        aria-expanded={isLeaf ? undefined : isExpanded}
        aria-current={isActiveModule && isLeaf ? 'page' : undefined}
        className={cn(
          'flex min-h-11 w-full items-center gap-3 rounded-lg px-2.5 py-2.5 text-start transition-colors active:scale-[0.98]',
          isActiveModule ? 'bg-primary/10' : 'hover:bg-accent/50',
        )}
      >
        <span
          className={cn(
            'flex size-8 shrink-0 items-center justify-center rounded-lg',
            isActiveModule ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground',
          )}
        >
          <Icon className="size-4" aria-hidden />
        </span>
        <span className={cn('min-w-0 flex-1 truncate text-[15px] font-medium', isActiveModule ? 'text-primary' : 'text-foreground')}>
          {navLabel.group(module.id)}
        </span>
        {isLeaf ? (
          isActiveModule ? null : <ChevronRight className="size-4 shrink-0 text-muted-foreground" aria-hidden data-flip-rtl />
        ) : (
          <ChevronDown
            className={cn('size-4 shrink-0 text-muted-foreground transition-transform', isExpanded && 'rotate-180')}
            aria-hidden
          />
        )}
      </button>

      {!isLeaf && isExpanded ? (
        <div className="ms-[19px] flex flex-col gap-0.5 border-s ps-3.5 py-1">
          {items.map((item) => {
            if (item.isSection) {
              return (
                <p
                  key={item.key}
                  className="mb-1 mt-3 px-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground first:mt-1"
                >
                  {navLabel.item(item.key)}
                </p>
              );
            }
            const ItemIcon = item.icon;
            return (
              <NavLink
                key={item.key}
                to={item.path}
                onClick={() => {
                  recordVisit({ moduleId: module.id, itemKey: item.key, path: item.path });
                  onNavigateChild(item.path);
                }}
                className={({ isActive }) =>
                  cn(
                    'flex min-h-10 items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm font-medium transition-colors active:scale-[0.98]',
                    // A restrained tint, not a solid fill — matches the parent
                    // module row's own active treatment (task §12: "avoid
                    // excessive background blocks" for current-route emphasis).
                    isActive ? 'bg-primary/10 text-primary' : 'text-foreground hover:bg-accent/50',
                  )
                }
              >
                {({ isActive }) => (
                  <>
                    <span
                      className={cn(
                        'flex size-6 shrink-0 items-center justify-center rounded-md',
                        isActive ? 'bg-primary/15' : 'bg-muted',
                      )}
                    >
                      <ItemIcon className={cn('size-3.5', isActive ? 'text-primary' : 'text-muted-foreground')} aria-hidden />
                    </span>
                    <span className="min-w-0 flex-1 truncate">{navLabel.item(item.key)}</span>
                    {item.key === 'price-review' ? <PriceReviewBadge /> : null}
                  </>
                )}
              </NavLink>
            );
          })}
        </div>
      ) : null}
    </div>
  );
}

// ── Search results (unchanged row style — it already matched this direction) ──

type SearchResultsProps = {
  moduleHits: AppModule[];
  pageHits: PageHit[];
  onSelectModule: (mod: AppModule) => void;
  onSelectPage: (mod: AppModule, key: NavItemKey, path: string) => void;
};

function SearchResults({ moduleHits, pageHits, onSelectModule, onSelectPage }: SearchResultsProps) {
  const { t } = useTranslation('common');
  const navLabel = useNavLabel();

  if (moduleHits.length === 0 && pageHits.length === 0) {
    return (
      <p className="px-1 py-8 text-center text-sm text-muted-foreground">
        {t(($) => $.nav.noModuleMatches)}
      </p>
    );
  }

  return (
    <div className="flex flex-col gap-0.5 rounded-2xl bg-muted/40 p-1.5">
      {moduleHits.map((mod) => {
        const Icon = mod.icon;
        return (
          <button
            key={mod.id}
            type="button"
            onClick={() => onSelectModule(mod)}
            className="flex min-h-11 items-center gap-3 rounded-lg px-2.5 py-2.5 text-start transition-colors active:scale-[0.98] hover:bg-accent/50"
          >
            <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary/10">
              <Icon className="size-4 text-primary" aria-hidden />
            </span>
            <span className="min-w-0 flex-1 truncate text-[15px] font-medium">{navLabel.group(mod.id)}</span>
            <ChevronRight className="size-4 shrink-0 text-muted-foreground" aria-hidden data-flip-rtl />
          </button>
        );
      })}
      {pageHits.map(({ module, path, key, icon: Icon }) => (
        <button
          key={`${module.id}-${key}`}
          type="button"
          onClick={() => onSelectPage(module, key, path)}
          className="flex min-h-11 items-center gap-3 rounded-lg px-2.5 py-2.5 text-start transition-colors active:scale-[0.98] hover:bg-accent/50"
        >
          <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted">
            <Icon className="size-4 text-muted-foreground" aria-hidden />
          </span>
          <span className="min-w-0 flex-1 truncate text-sm font-medium">{navLabel.item(key)}</span>
          <span className="shrink-0 truncate text-xs text-muted-foreground">{navLabel.group(module.id)}</span>
        </button>
      ))}
    </div>
  );
}
