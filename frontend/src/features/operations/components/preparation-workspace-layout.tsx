import { Link, Outlet, useLocation } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { History, Layers2, Zap } from 'lucide-react';

import { ROUTES } from '@/router/routes';

// ── Top-level Preparation destinations ────────────────────────────────────────

// TASK-PREPARATION-WORKSPACE-FIX-003 §3 — Preparation is ONE destination in the
// Operations sidebar ("Preparation Workspace"). Archive and Settings are no longer
// sibling sidebar entries; they are tabs of this shell. The paths themselves are
// unchanged, so every existing deep link and bookmark keeps working.
const PREPARATION_TABS = [
  { key: 'today',    path: ROUTES.waveWorkspace, Icon: Layers2 },
  { key: 'archive',  path: ROUTES.waveArchive,   Icon: History },
  { key: 'settings', path: ROUTES.waveEngine,    Icon: Zap     },
] as const;

export function PreparationWorkspaceLayout() {
  const { t } = useTranslation('operations');
  const { pathname } = useLocation();

  return (
    <div className="flex flex-col h-full">

      {/* ── Preparation Workspace identity + top-level tabs ─────────────────── */}
      <nav
        className="flex items-center gap-1 border-b border-border/60 bg-card px-4 shrink-0 overflow-x-auto"
        aria-label={t($ => $.wave.preparationWorkspace.title)}
      >
        <span className="text-xs font-semibold text-muted-foreground whitespace-nowrap pe-3">
          {t($ => $.wave.preparationWorkspace.title)}
        </span>
        {PREPARATION_TABS.map(({ key, path, Icon }) => {
          // Today's Preparation owns every nested workspace tab, so match the
          // subtree rather than the exact path.
          const active = pathname === path || pathname.startsWith(`${path}/`);
          return (
            <Link
              key={key}
              to={path}
              className={`flex items-center gap-1.5 px-3.5 py-2.5 text-xs font-medium whitespace-nowrap border-b-2 transition-colors ${
                active
                  ? 'border-primary text-primary'
                  : 'border-transparent text-muted-foreground hover:text-foreground hover:border-border'
              }`}
              aria-current={active ? 'page' : undefined}
            >
              <Icon className="h-3.5 w-3.5" />
              {t($ => $.wave.preparationWorkspace.tabs[key])}
            </Link>
          );
        })}
      </nav>

      {/* ── Selected destination ─────────────────────────────────────────────── */}
      <div className="flex-1 overflow-hidden">
        <Outlet />
      </div>
    </div>
  );
}
