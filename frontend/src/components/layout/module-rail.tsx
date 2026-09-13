import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';

import { cn } from '@/lib/utils';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { type AppModule } from '@/config/module-navigation';
import { useNavigation } from '@/features/authorization';
import { useLanguage } from '@/providers/language-context';
import { useNavLabel } from './use-nav-label';

type ModuleRailProps = {
  activeModule: AppModule | undefined;
  className?: string;
};

export function ModuleRail({ activeModule, className }: ModuleRailProps) {
  const { t } = useTranslation('common');
  const navLabel = useNavLabel();
  const { dir } = useLanguage();
  // Dynamic sidebar (TASK-IAM-005 / ADR-041): the rail renders the user's
  // effective navigation rather than every module unconditionally. Resolved
  // through the committed authorization context — no permission logic here.
  const { modules } = useNavigation();
  // The rail sits at the inline-start edge (border-e marks its trailing/end
  // side, adjacent to the sidebar/content); tooltips point toward content,
  // away from the viewport edge — same reasoning AppSidebar's collapsed
  // tooltips use one column over.
  const tooltipSide = dir === 'rtl' ? 'left' : 'right';
  return (
    <nav
      aria-label={t($ => $.nav.moduleNavigation)}
      className={cn(
        'w-[72px] shrink-0 flex-col border-e bg-sidebar',
        className,
      )}
    >
      <div className="flex flex-col items-center gap-0.5 overflow-y-auto py-2 px-1.5">
        {modules.map((mod) => {
          const Icon = mod.icon;
          const isActive = activeModule?.id === mod.id;
          const label = navLabel.group(mod.id);

          return (
            <Tooltip key={mod.id}>
              <TooltipTrigger asChild>
                <Link
                  to={mod.defaultPath}
                  aria-label={label}
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
                  <span className="line-clamp-2 w-full break-words text-center text-[10px] font-medium leading-tight">
                    {label}
                  </span>
                </Link>
              </TooltipTrigger>
              <TooltipContent side={tooltipSide}>{label}</TooltipContent>
            </Tooltip>
          );
        })}
      </div>
    </nav>
  );
}
