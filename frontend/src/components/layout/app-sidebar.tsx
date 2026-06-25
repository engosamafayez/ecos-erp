import { NavLink } from 'react-router-dom';
import { useTranslation } from 'react-i18next';

import { BrandLogo } from '@/components/common/brand-logo';
import { CompanySwitcher } from '@/components/layout/company-switcher';
import { NAV_GROUPS } from '@/config/navigation';
import { cn } from '@/lib/utils';

type AppSidebarProps = {
  onNavigate?: () => void;
};

export function AppSidebar({ onNavigate }: AppSidebarProps) {
  const { t } = useTranslation('common');

  return (
    <div className="flex h-full flex-col">
      {/* Sidebar header */}
      <div className="flex flex-col gap-3 border-b px-4 py-4">
        <BrandLogo />
        <CompanySwitcher />
      </div>

      {/* Nav */}
      <nav className="flex flex-col gap-4 overflow-y-auto p-3 flex-1">
        {NAV_GROUPS.map((group) => (
          <div key={group.label} className="flex flex-col gap-0.5">
            <p className="text-muted-foreground px-2.5 pb-1 pt-1 text-[11px] font-semibold tracking-widest uppercase">
              {t(`nav.groups.${group.label.toLowerCase()}`)}
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
                      'flex items-center gap-2.5 rounded-md px-2.5 py-2 text-sm font-medium transition-colors',
                      isActive
                        ? 'bg-primary text-primary-foreground shadow-xs'
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
    </div>
  );
}
