import { NavLink } from 'react-router-dom';

import { NAV_GROUPS } from '@/config/navigation';
import { cn } from '@/lib/utils';

type AppSidebarProps = {
  /** Called after a nav item is clicked (used to close the mobile drawer). */
  onNavigate?: () => void;
};

/**
 * Primary navigation list. Shared by the desktop sidebar and the mobile drawer.
 */
export function AppSidebar({ onNavigate }: AppSidebarProps) {
  return (
    <nav className="flex flex-col gap-5 p-3">
      {NAV_GROUPS.map((group) => (
        <div key={group.label} className="flex flex-col gap-1">
          <p className="text-muted-foreground px-3 py-1 text-xs font-medium tracking-wider uppercase">
            {group.label}
          </p>
          {group.items.map((item) => {
            const Icon = item.icon;
            return (
              <NavLink
                key={item.key}
                to={item.path}
                end
                onClick={onNavigate}
                className={({ isActive }) =>
                  cn(
                    'flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                    isActive
                      ? 'bg-accent text-accent-foreground'
                      : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
                  )
                }
              >
                <Icon className="size-4 shrink-0" />
                {item.label}
              </NavLink>
            );
          })}
        </div>
      ))}
    </nav>
  );
}
