import { Link } from 'react-router-dom';

import { cn } from '@/lib/utils';
import { APP_MODULES, type AppModule } from '@/config/module-navigation';

type ModuleRailProps = {
  activeModule: AppModule | undefined;
  className?: string;
};

export function ModuleRail({ activeModule, className }: ModuleRailProps) {
  return (
    <nav
      aria-label="Module navigation"
      className={cn(
        'w-[72px] shrink-0 flex-col border-e bg-sidebar',
        className,
      )}
    >
      <div className="flex flex-col items-center gap-0.5 overflow-y-auto py-2 px-1.5">
        {APP_MODULES.map((mod) => {
          const Icon = mod.icon;
          const isActive = activeModule?.id === mod.id;

          return (
            <Link
              key={mod.id}
              to={mod.defaultPath}
              title={mod.label}
              aria-label={mod.label}
              aria-current={isActive ? 'page' : undefined}
              className={cn(
                'group flex w-full flex-col items-center gap-1 rounded-lg px-1 py-2 transition-colors',
                isActive
                  ? 'text-primary'
                  : 'text-muted-foreground hover:bg-accent hover:text-foreground',
              )}
            >
              <span
                className={cn(
                  'flex size-9 items-center justify-center rounded-lg transition-colors',
                  isActive
                    ? 'bg-primary text-primary-foreground shadow-sm'
                    : 'group-hover:bg-accent',
                )}
              >
                <Icon className="size-[18px]" aria-hidden />
              </span>
              <span className="w-full truncate text-center text-[10px] font-medium leading-tight">
                {mod.railLabel}
              </span>
            </Link>
          );
        })}
      </div>
    </nav>
  );
}
