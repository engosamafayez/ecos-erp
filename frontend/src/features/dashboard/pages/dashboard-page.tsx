import { DollarSign, Package, ShoppingCart, Users } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { useAuthStore } from '@/features/auth/store/auth-store';
import { KpiCard } from '@/features/dashboard/components/kpi-card';
import { QuickActions } from '@/features/dashboard/components/quick-actions';
import { RecentActivity } from '@/features/dashboard/components/recent-activity';
import { SystemStatus } from '@/features/dashboard/components/system-status';

export function DashboardPage() {
  const { t } = useTranslation('dashboard');
  const user = useAuthStore((state) => state.user);
  const firstName = user?.name.split(' ')[0] ?? 'there';

  const kpis = [
    { title: t('kpi.revenue'), value: '$0.00', delta: t('kpi.noData'), trend: 'neutral', icon: DollarSign },
    { title: t('kpi.orders'), value: '0', delta: t('kpi.noData'), trend: 'neutral', icon: ShoppingCart },
    { title: t('kpi.products'), value: '0', delta: t('kpi.noData'), trend: 'neutral', icon: Package },
    { title: t('kpi.activeUsers'), value: '1', delta: t('kpi.thisWeek'), trend: 'up', icon: Users },
  ] as const;

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">{t('welcome', { firstName })}</h1>
        <p className="text-muted-foreground text-sm">{t('subtitle')}</p>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {kpis.map((kpi) => (
          <KpiCard key={kpi.title} {...kpi} />
        ))}
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <div className="lg:col-span-2">
          <RecentActivity />
        </div>
        <div className="flex flex-col gap-4">
          <QuickActions />
          <SystemStatus />
        </div>
      </div>
    </div>
  );
}
