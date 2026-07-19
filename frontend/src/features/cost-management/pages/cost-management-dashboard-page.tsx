import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  AlertTriangle,
  ArrowDown,
  ArrowUp,
  BarChart3,
  CheckCircle2,
  Clock,
  TrendingDown,
  TrendingUp,
} from 'lucide-react';
import { costDashboardService } from '@/features/cost-management/services/pricing-review-service';
import type { CostDashboardStats } from '@/features/cost-management/types/pricing-review';
import { ROUTES } from '@/router/routes';

function KpiCard({
  label,
  value,
  icon: Icon,
  description,
  onClick,
  variant = 'default',
}: {
  label: string;
  value: string | number;
  icon: React.ElementType;
  description?: string;
  onClick?: () => void;
  variant?: 'default' | 'warning' | 'success' | 'info';
}) {
  const variantClasses = {
    default: 'border-border',
    warning: 'border-amber-500/40 bg-amber-500/5',
    success: 'border-green-500/40 bg-green-500/5',
    info:    'border-blue-500/40 bg-blue-500/5',
  };

  const iconClasses = {
    default: 'text-muted-foreground',
    warning: 'text-amber-500',
    success: 'text-green-500',
    info:    'text-blue-500',
  };

  return (
    <div
      role={onClick ? 'button' : undefined}
      tabIndex={onClick ? 0 : undefined}
      onClick={onClick}
      onKeyDown={onClick ? (e) => { if (e.key === 'Enter') onClick(); } : undefined}
      className={`rounded-lg border p-4 ${variantClasses[variant]} ${onClick ? 'cursor-pointer hover:bg-muted/40 transition-colors' : ''}`}
    >
      <div className="flex items-start justify-between">
        <div>
          <p className="text-sm text-muted-foreground">{label}</p>
          <p className="mt-1 text-2xl font-semibold tabular-nums">{value}</p>
          {description && <p className="mt-1 text-xs text-muted-foreground">{description}</p>}
        </div>
        <Icon className={`size-5 ${iconClasses[variant]}`} />
      </div>
    </div>
  );
}

export function CostManagementDashboardPage() {
  const navigate  = useNavigate();
  const [stats, setStats]     = useState<CostDashboardStats | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    costDashboardService.getStats()
      .then(setStats)
      .finally(() => setLoading(false));
  }, []);

  const fmt = (n: number | null | undefined, decimals = 0) =>
    n == null ? '—' : n.toLocaleString(undefined, { minimumFractionDigits: decimals, maximumFractionDigits: decimals });

  return (
    <div className="flex flex-col gap-6 p-6">
      {/* Header */}
      <div className="flex items-center gap-3">
        <BarChart3 className="size-5 text-primary" />
        <div>
          <h1 className="text-lg font-semibold">لوحة إدارة التكاليف</h1>
          <p className="text-sm text-muted-foreground">
            نظرة مباشرة على تغييرات التكاليف وحالة مراجعة التسعير
          </p>
        </div>
      </div>

      {loading ? (
        <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
          {Array.from({ length: 7 }).map((_, i) => (
            <div key={i} className="h-24 animate-pulse rounded-lg border bg-muted/30" />
          ))}
        </div>
      ) : (
        <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
          <KpiCard
            label="مراجعات معلّقة"
            value={fmt(stats?.pending_reviews)}
            icon={Clock}
            description="بانتظار قرار الإدارة"
            onClick={() => navigate(ROUTES.costManagementPriceReview)}
            variant={stats && stats.pending_reviews > 0 ? 'warning' : 'default'}
          />
          <KpiCard
            label="أقل من الهامش المستهدف"
            value={fmt(stats?.below_target_margin)}
            icon={AlertTriangle}
            description="منتجات تحت الهامش المستهدف"
            onClick={() => navigate(ROUTES.costManagementPriceReview)}
            variant={stats && stats.below_target_margin > 0 ? 'warning' : 'success'}
          />
          <KpiCard
            label="تكاليف ارتفعت اليوم"
            value={fmt(stats?.cost_increased_today)}
            icon={TrendingUp}
            description="تكاليف مواد ارتفعت منذ منتصف الليل"
            onClick={() => navigate(ROUTES.costManagementCostHistory)}
            variant={stats && stats.cost_increased_today > 0 ? 'warning' : 'default'}
          />
          <KpiCard
            label="تكاليف انخفضت اليوم"
            value={fmt(stats?.cost_decreased_today)}
            icon={TrendingDown}
            description="تكاليف مواد انخفضت منذ منتصف الليل"
            onClick={() => navigate(ROUTES.costManagementCostHistory)}
            variant={stats && stats.cost_decreased_today > 0 ? 'success' : 'default'}
          />
          <KpiCard
            label="الأثر المتوقع على الربح"
            value={fmt(stats?.expected_profit_impact, 2)}
            icon={stats && stats.expected_profit_impact >= 0 ? ArrowUp : ArrowDown}
            description="مجموع فروق التكاليف المعلّقة"
            variant="info"
          />
          <KpiCard
            label="متوسط الهامش"
            value={stats?.average_margin != null ? `${fmt(stats.average_margin, 1)}%` : '—'}
            icon={BarChart3}
            description="عبر المراجعات المعلّقة"
          />
          <KpiCard
            label="بانتظار الموافقة"
            value={fmt(stats?.awaiting_approval)}
            icon={CheckCircle2}
            description="مراجعات مُعيَّنة بانتظار التوقيع"
            onClick={() => navigate(ROUTES.costManagementPriceReview)}
            variant={stats && stats.awaiting_approval > 0 ? 'info' : 'default'}
          />
        </div>
      )}

      {/* Quick links */}
      <div className="flex flex-wrap gap-2 pt-2">
        <button
          type="button"
          onClick={() => navigate(ROUTES.costManagementPriceReview)}
          className="rounded-md border px-3 py-1.5 text-sm hover:bg-muted/40 transition-colors"
        >
          مركز مراجعة التسعير
        </button>
        <button
          type="button"
          onClick={() => navigate(ROUTES.costManagementCostHistory)}
          className="rounded-md border px-3 py-1.5 text-sm hover:bg-muted/40 transition-colors"
        >
          عرض سجل التكاليف
        </button>
      </div>
    </div>
  );
}
