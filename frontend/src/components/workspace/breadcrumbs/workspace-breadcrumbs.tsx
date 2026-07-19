import { ChevronRight, Home } from 'lucide-react';
import { Link } from 'react-router-dom';

import { cn } from '@/lib/utils';
import { ROUTES } from '@/router/routes';
import type { WorkspaceBreadcrumb } from '../types';

type Props = {
  crumbs: WorkspaceBreadcrumb[];
  className?: string;
};

export function WorkspaceBreadcrumbs({ crumbs, className }: Props) {
  const all: WorkspaceBreadcrumb[] = [
    { label: 'الرئيسية', to: ROUTES.dashboard, icon: Home },
    ...crumbs,
  ];

  // Collapse middle segments when path is deep (> 4 items total)
  const visible =
    all.length <= 4
      ? all
      : [all[0], { label: '…' } as WorkspaceBreadcrumb, all[all.length - 2], all[all.length - 1]];

  return (
    <nav aria-label="Breadcrumb" className={cn('flex items-center text-xs text-muted-foreground', className)}>
      <ol className="flex flex-wrap items-center gap-0.5">
        {visible.map((crumb, i) => {
          const isLast = i === visible.length - 1;
          const isEllipsis = crumb.label === '…';
          const Icon = crumb.icon;
          return (
            <li key={i} className="flex items-center gap-0.5">
              {i > 0 ? (
                <ChevronRight className="mx-0.5 size-3 shrink-0 text-muted-foreground/40" aria-hidden />
              ) : null}
              {isEllipsis ? (
                <span className="px-1 text-muted-foreground/60">…</span>
              ) : crumb.to && !isLast ? (
                <Link
                  to={crumb.to}
                  className={cn(
                    'flex items-center gap-1 rounded px-1 py-0.5 transition-colors',
                    'hover:bg-accent hover:text-foreground',
                    'focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring',
                  )}
                >
                  {Icon ? <Icon className="size-3 shrink-0" aria-hidden /> : null}
                  <span className="max-w-[9rem] truncate">{crumb.label}</span>
                </Link>
              ) : (
                <span
                  className={cn(
                    'flex items-center gap-1 px-1 py-0.5',
                    isLast && 'font-medium text-foreground',
                  )}
                  aria-current={isLast ? 'page' : undefined}
                >
                  {Icon ? <Icon className="size-3 shrink-0" aria-hidden /> : null}
                  <span className="max-w-[12rem] truncate">{crumb.label}</span>
                </span>
              )}
            </li>
          );
        })}
      </ol>
    </nav>
  );
}
