import { DollarSign, Package, ShoppingCart, Users } from 'lucide-react';

import { useAuthStore } from '@/features/auth/store/auth-store';
import { KpiCard } from '@/features/dashboard/components/kpi-card';
import { QuickActions } from '@/features/dashboard/components/quick-actions';
import { RecentActivity } from '@/features/dashboard/components/recent-activity';
import { SystemStatus } from '@/features/dashboard/components/system-status';

const KPIS = [
  {
    title: 'Total Revenue',
    value: '$0.00',
    delta: 'No data yet',
    trend: 'neutral',
    icon: DollarSign,
  },
  { title: 'Orders', value: '0', delta: 'No data yet', trend: 'neutral', icon: ShoppingCart },
  { title: 'Products', value: '0', delta: 'No data yet', trend: 'neutral', icon: Package },
  { title: 'Active Users', value: '1', delta: '+1 this week', trend: 'up', icon: Users },
] as const;

/**
 * Dashboard home. Placeholder widgets only — not connected to the backend.
 */
export function DashboardPage() {
  const user = useAuthStore((state) => state.user);
  const firstName = user?.name.split(' ')[0] ?? 'there';

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Welcome back, {firstName} 👋</h1>
        <p className="text-muted-foreground text-sm">Here is an overview of your ECOS workspace.</p>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {KPIS.map((kpi) => (
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
