import { useMemo, useState } from 'react';
import { ChevronRight, History, Search } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { cn } from '@/lib/utils';
import { useNavigation } from '@/features/authorization';
import { moduleNavLinks, type AppModule, type ModuleId, type NavItemKey } from '@/config/module-navigation';
import { useRecentNav } from '@/hooks/use-recent-nav';
import { FAMILY_ORDER, familyOf, type ModuleFamily } from './mobile-module-families';
import { useNavLabel } from './use-nav-label';

type MobileModulesLauncherProps = {
  activeModuleId: ModuleId | null;
  onOpenModule: (moduleId: ModuleId) => void;
  onNavigate: (path: string) => void;
};

type PageHit = { module: AppModule; path: string; key: NavItemKey; icon: AppModule['icon'] };

/**
 * Full-screen Modules launcher (TASK-ECOS-MOBILE-UX-COMPLETION-002, parent
 * design report §5) — replaces the single flat 20-item accordion list with a
 * searchable, family-grouped grid plus a Recent row, addressing Navigation
 * Problems #3 (no search/favorites/recency on a flat list) and #8 (no
 * distinct visual identity).
 *
 * Modules and their pages come from `useNavigation()` / `moduleNavLinks()` —
 * the SAME canonical RBAC-filtered authority the desktop rail and sidebar use.
 * This component adds no visibility rule of its own: it only searches and
 * groups data that authority already returned.
 */
export function MobileModulesLauncher({
  activeModuleId,
  onOpenModule,
  onNavigate,
}: MobileModulesLauncherProps) {
  const { t } = useTranslation('common');
  const navLabel = useNavLabel();
  const { modules } = useNavigation();
  const { recent, recordVisit } = useRecentNav();
  const [query, setQuery] = useState('');

  const trimmed = query.trim().toLowerCase();
  const searching = trimmed.length > 0;

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
      for (const link of moduleNavLinks(mod.items)) {
        if (navLabel.item(link.key).toLowerCase().includes(trimmed)) {
          pageHits.push({ module: mod, path: link.path, key: link.key, icon: link.icon });
        }
      }
    }
    return { moduleHits, pageHits };
  }, [searching, trimmed, modules, navLabel]);

  const resolvedRecent = useMemo(() => {
    return recent
      .map((entry) => {
        const mod = modules.find((m) => m.id === entry.moduleId);
        if (!mod) return null;
        if (entry.itemKey) {
          const link = moduleNavLinks(mod.items).find((l) => l.key === entry.itemKey);
          if (!link) return null;
          return { path: entry.path, icon: link.icon, primary: navLabel.item(link.key), secondary: navLabel.group(mod.id) };
        }
        return { path: entry.path, icon: mod.icon, primary: navLabel.group(mod.id), secondary: undefined };
      })
      .filter((e): e is NonNullable<typeof e> => e !== null);
  }, [recent, modules, navLabel]);

  function selectModule(mod: AppModule) {
    if (moduleNavLinks(mod.items).length < 2) {
      recordVisit({ moduleId: mod.id, path: mod.defaultPath });
      onNavigate(mod.defaultPath);
    } else {
      onOpenModule(mod.id);
    }
  }

  function selectPage(mod: AppModule, key: NavItemKey, path: string) {
    recordVisit({ moduleId: mod.id, itemKey: key, path });
    onNavigate(path);
  }

  return (
    <div className="flex h-full flex-col">
      {/* Search */}
      <div className="shrink-0 border-b p-3">
        <div className="flex items-center gap-2 rounded-lg border bg-muted/40 px-3 py-2">
          <Search className="size-4 shrink-0 text-muted-foreground" aria-hidden />
          <input
            type="search"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder={t(($) => $.nav.searchModulesPlaceholder)}
            aria-label={t(($) => $.nav.searchModulesPlaceholder)}
            className="min-w-0 flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground"
            autoComplete="off"
          />
        </div>
      </div>

      <div className="flex-1 overflow-y-auto p-3">
        {searching ? (
          <SearchResults
            moduleHits={searchResults.moduleHits}
            pageHits={searchResults.pageHits}
            onSelectModule={selectModule}
            onSelectPage={selectPage}
          />
        ) : (
          <>
            {resolvedRecent.length > 0 ? (
              <div className="mb-5">
                <p className="mb-2 px-1 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                  {t(($) => $.nav.recent)}
                </p>
                <div className="flex flex-col gap-1">
                  {resolvedRecent.map((entry) => {
                    const Icon = entry.icon;
                    return (
                      <button
                        key={entry.path}
                        type="button"
                        onClick={() => onNavigate(entry.path)}
                        className="flex min-h-11 items-center gap-3 rounded-lg border bg-card px-3 py-2.5 text-start transition-colors hover:bg-accent/40"
                      >
                        <History className="size-4 shrink-0 text-muted-foreground" aria-hidden />
                        <Icon className="size-4 shrink-0 text-muted-foreground" aria-hidden />
                        <span className="min-w-0 flex-1 truncate text-sm font-medium">
                          {entry.primary}
                        </span>
                        {entry.secondary ? (
                          <span className="shrink-0 truncate text-xs text-muted-foreground">
                            {entry.secondary}
                          </span>
                        ) : null}
                      </button>
                    );
                  })}
                </div>
              </div>
            ) : null}

            {grouped.map(({ family, modules: familyModules }) => (
              <div key={family} className="mb-5 last:mb-0">
                <p className="mb-2 px-1 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                  {t(($) => $.nav.families[family])}
                </p>
                <div className="grid grid-cols-3 gap-2">
                  {familyModules.map((mod) => {
                    const Icon = mod.icon;
                    const isCurrent = mod.id === activeModuleId;
                    return (
                      <button
                        key={mod.id}
                        type="button"
                        onClick={() => selectModule(mod)}
                        aria-current={isCurrent ? 'true' : undefined}
                        className={cn(
                          'relative flex min-h-[76px] flex-col items-center justify-center gap-1.5 rounded-xl border p-2 text-center transition-colors',
                          isCurrent
                            ? 'border-primary/50 bg-primary/5'
                            : 'border-border bg-card hover:border-primary/40 hover:bg-accent/40',
                        )}
                      >
                        {isCurrent ? (
                          <span className="absolute end-1.5 top-1.5 rounded-full bg-primary px-1.5 py-0.5 text-[9px] font-semibold leading-none text-primary-foreground">
                            {t(($) => $.nav.current)}
                          </span>
                        ) : null}
                        <span className="flex size-9 items-center justify-center rounded-lg bg-primary/10">
                          <Icon className="size-5 text-primary" aria-hidden />
                        </span>
                        <span className="line-clamp-2 w-full break-words text-[11px] font-medium leading-tight text-foreground">
                          {navLabel.group(mod.id)}
                        </span>
                      </button>
                    );
                  })}
                </div>
              </div>
            ))}
          </>
        )}
      </div>
    </div>
  );
}

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
    <div className="flex flex-col gap-1">
      {moduleHits.map((mod) => {
        const Icon = mod.icon;
        return (
          <button
            key={mod.id}
            type="button"
            onClick={() => onSelectModule(mod)}
            className="flex min-h-11 items-center gap-3 rounded-lg border bg-card px-3 py-2.5 text-start transition-colors hover:bg-accent/40"
          >
            <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary/10">
              <Icon className="size-4 text-primary" aria-hidden />
            </span>
            <span className="min-w-0 flex-1 truncate text-sm font-semibold">{navLabel.group(mod.id)}</span>
            <ChevronRight className="size-4 shrink-0 text-muted-foreground" aria-hidden data-flip-rtl />
          </button>
        );
      })}
      {pageHits.map(({ module, path, key, icon: Icon }) => (
        <button
          key={`${module.id}-${key}`}
          type="button"
          onClick={() => onSelectPage(module, key, path)}
          className="flex min-h-11 items-center gap-3 rounded-lg border bg-card px-3 py-2.5 text-start transition-colors hover:bg-accent/40"
        >
          <Icon className="size-4 shrink-0 text-muted-foreground" aria-hidden />
          <span className="min-w-0 flex-1 truncate text-sm font-medium">{navLabel.item(key)}</span>
          <span className="shrink-0 truncate text-xs text-muted-foreground">{navLabel.group(module.id)}</span>
        </button>
      ))}
    </div>
  );
}
