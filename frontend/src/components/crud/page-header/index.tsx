import type { ReactNode } from 'react';
import { ChevronRight } from 'lucide-react';
import { Link } from 'react-router-dom';

import type { BreadcrumbItem } from '@/components/crud/types';

type PageHeaderProps = {
  title: string;
  subtitle?: ReactNode;
  breadcrumbs?: BreadcrumbItem[];
  /** Primary action(s) shown on the right (e.g. a "New" button). */
  actions?: ReactNode;
};

/**
 * Reusable page header: breadcrumbs, title, subtitle and primary actions.
 */
export function PageHeader({ title, subtitle, breadcrumbs, actions }: PageHeaderProps) {
  return (
    <div className="flex flex-col gap-3">
      {breadcrumbs && breadcrumbs.length > 0 ? (
        <nav className="text-muted-foreground flex items-center gap-1.5 text-sm">
          {breadcrumbs.map((crumb, index) => {
            const isLast = index === breadcrumbs.length - 1;
            return (
              <span key={`${crumb.label}-${index}`} className="flex items-center gap-1.5">
                {index > 0 ? <ChevronRight className="size-3.5" /> : null}
                {crumb.to && !isLast ? (
                  <Link to={crumb.to} className="hover:text-foreground transition-colors">
                    {crumb.label}
                  </Link>
                ) : (
                  <span className={isLast ? 'text-foreground font-medium' : undefined}>
                    {crumb.label}
                  </span>
                )}
              </span>
            );
          })}
        </nav>
      ) : null}

      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
          {subtitle ? <div className="text-muted-foreground mt-1 text-sm">{subtitle}</div> : null}
        </div>
        {actions ? <div className="flex items-center gap-2">{actions}</div> : null}
      </div>
    </div>
  );
}
