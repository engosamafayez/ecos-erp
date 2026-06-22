import { ChevronRight } from 'lucide-react';
import { Link, useLocation } from 'react-router-dom';

import { findNavItemByPath } from '@/config/navigation';
import { ROUTES } from '@/router/routes';

/**
 * Breadcrumb bar. Derives the current segment from the active navigation item.
 */
export function AppBreadcrumbs() {
  const { pathname } = useLocation();
  const current = findNavItemByPath(pathname);
  const isDashboard = pathname === ROUTES.dashboard;

  return (
    <div className="text-muted-foreground flex items-center gap-1.5 border-b px-4 py-2.5 text-sm sm:px-6">
      <Link to={ROUTES.dashboard} className="hover:text-foreground transition-colors">
        Home
      </Link>
      {!isDashboard && current ? (
        <>
          <ChevronRight className="size-3.5" />
          <span className="text-foreground font-medium">{current.label}</span>
        </>
      ) : null}
    </div>
  );
}
