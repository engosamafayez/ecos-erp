import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  AlertTriangle,
  ChevronRight,
  Clock,
  Loader2,
  PackageCheck,
  RefreshCw,
  Users,
  Waves,
  Zap,
} from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import { usePreparationDashboard } from '../hooks/use-preparation';
import { PreparationWaveDrawer } from '../components/preparation-wave-drawer';
import { ROUTES } from '@/router/routes';
import type { WaveStatus } from '../types/preparation';

const STATUS_COLORS: Record<WaveStatus, string> = {
  draft:            'bg-gray-100 text-gray-700',
  planning:         'bg-blue-100 text-blue-700',
  shortage_blocked: 'bg-amber-100 text-amber-700',
  preparing:        'bg-purple-100 text-purple-700',
  completed:        'bg-green-100 text-green-700',
  cancelled:        'bg-red-100 text-red-700',
};

const STATUS_LABELS: Record<WaveStatus, string> = {
  draft:            'Draft',
  planning:         'Planning',
  shortage_blocked: 'Blocked',
  preparing:        'Preparing',
  completed:        'Completed',
  cancelled:        'Cancelled',
};

type KpiCardProps = {
  label: string;
  value: string | number;
  sub?: string;
  color?: 'blue' | 'green' | 'amber' | 'red' | 'purple' | 'gray';
  icon: React.ElementType;
  onClick?: () => void;
};

function KpiCard({ label, value, sub, color = 'blue', icon: Icon, onClick }: KpiCardProps) {
  const colorMap = {
    blue:   'bg-blue-50 text-blue-600',
    green:  'bg-green-50 text-green-600',
    amber:  'bg-amber-50 text-amber-600',
    red:    'bg-red-50 text-red-600',
    purple: 'bg-purple-50 text-purple-600',
    gray:   'bg-gray-50 text-gray-600',
  };

  return (
    <Card
      className={`border border-gray-200 shadow-none ${onClick ? 'cursor-pointer hover:border-primary/50 transition-colors' : ''}`}
      onClick={onClick}
    >
      <CardContent className="p-4">
        <div className="flex items-start justify-between">
          <div className="min-w-0">
            <p className="text-xs text-gray-500 mb-1">{label}</p>
            <p className="text-2xl font-semibold text-gray-900">{value}</p>
            {sub && <p className="text-xs text-gray-400 mt-0.5">{sub}</p>}
          </div>
          <div className={`p-2 rounded-lg shrink-0 ${colorMap[color]}`}>
            <Icon className="w-4 h-4" />
          </div>
        </div>
      </CardContent>
    </Card>
  );
}

export function PreparationDashboardPage() {
  const navigate = useNavigate();
  const today = new Date().toISOString().split('T')[0];
  const [planningDate] = useState(today);
  const [selectedWaveId, setSelectedWaveId] = useState<string | null>(null);

  const { data, isLoading, refetch, isFetching } = usePreparationDashboard({ planning_date: planningDate });

  function goToWaves(params?: string) {
    navigate(`${ROUTES.preparationWaves}${params ? `?${params}` : ''}`);
  }

  return (
    <div className="flex flex-col gap-6 p-6">
      {/* Page header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-gray-900">Preparation OS</h1>
          <p className="text-sm text-gray-500 mt-0.5">
            {new Date(planningDate).toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Button
            variant="outline"
            size="sm"
            onClick={() => void refetch()}
            disabled={isFetching}
            aria-label="Refresh dashboard"
          >
            <RefreshCw className={`w-4 h-4 ${isFetching ? 'animate-spin' : ''}`} />
          </Button>
          <Button size="sm" onClick={() => goToWaves()}>
            <Waves className="w-4 h-4 mr-1.5" />
            View Waves
          </Button>
        </div>
      </div>

      {isLoading ? (
        <div className="flex items-center justify-center py-20">
          <Loader2 className="w-6 h-6 animate-spin text-gray-400" />
        </div>
      ) : !data ? (
        <div className="text-center py-20 text-gray-400">Failed to load dashboard.</div>
      ) : (
        <>
          {/* KPI cards */}
          <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
            <KpiCard
              label="Waves Today"
              value={data.kpis.waves_total}
              color="blue"
              icon={Waves}
              onClick={() => goToWaves(`planning_date=${planningDate}`)}
            />
            <KpiCard
              label="Preparing"
              value={data.kpis.waves_by_status.preparing ?? 0}
              color="purple"
              icon={Zap}
              onClick={() => goToWaves('status=preparing')}
            />
            <KpiCard
              label="Completion"
              value={`${data.kpis.completion_pct.toFixed(1)}%`}
              sub={`${data.kpis.units_prepared.toLocaleString()} of ${data.kpis.units_required.toLocaleString()} units`}
              color="green"
              icon={PackageCheck}
            />
            <KpiCard
              label="Open Exceptions"
              value={data.kpis.open_exceptions}
              color={data.kpis.open_exceptions > 0 ? 'red' : 'gray'}
              icon={AlertTriangle}
              onClick={data.kpis.open_exceptions > 0 ? () => goToWaves('sort=open_exceptions') : undefined}
            />
            <KpiCard
              label="Workers Active"
              value={data.kpis.workers_active}
              color="blue"
              icon={Users}
            />
          </div>

          {/* Alerts */}
          {data.alerts.length > 0 && (
            <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 space-y-2">
              {data.alerts.map((alert, idx) => (
                <div key={idx} className="flex items-center justify-between gap-3">
                  <div className="flex items-center gap-2">
                    <AlertTriangle className="w-4 h-4 text-amber-600 shrink-0" />
                    <p className="text-sm text-amber-800">{alert.message}</p>
                  </div>
                  <Button
                    size="sm"
                    variant="outline"
                    className="text-xs border-amber-300 text-amber-700 hover:bg-amber-100 shrink-0"
                    onClick={() => setSelectedWaveId(alert.wave_id)}
                  >
                    View Wave
                  </Button>
                </div>
              ))}
            </div>
          )}

          {/* Active waves */}
          <div>
            <div className="flex items-center justify-between mb-3">
              <h2 className="text-sm font-semibold text-gray-900">Active Waves</h2>
              <Button variant="ghost" size="sm" className="text-xs gap-1" onClick={() => goToWaves()}>
                View All Waves <ChevronRight className="w-3 h-3" />
              </Button>
            </div>

            {data.active_waves.length === 0 ? (
              <Card className="border border-dashed shadow-none">
                <CardContent className="flex flex-col items-center justify-center py-10 text-gray-400">
                  <Waves className="w-8 h-8 mb-2" />
                  <p className="text-sm">No active waves for today.</p>
                </CardContent>
              </Card>
            ) : (
              <div className="space-y-2">
                {data.active_waves.map((wave) => (
                  <Card
                    key={wave.id}
                    className="border border-gray-200 shadow-none cursor-pointer hover:border-primary/50 transition-colors"
                    onClick={() => setSelectedWaveId(wave.id)}
                  >
                    <CardContent className="p-4">
                      <div className="flex items-start justify-between gap-3 mb-2">
                        <div className="min-w-0">
                          <div className="flex items-center gap-2">
                            <span className="text-sm font-medium text-gray-900">{wave.wave_number}</span>
                            <Badge className={`text-xs ${STATUS_COLORS[wave.status]}`}>
                              {STATUS_LABELS[wave.status]}
                            </Badge>
                            {wave.shortage_detected && (
                              <AlertTriangle className="w-3.5 h-3.5 text-amber-500" />
                            )}
                          </div>
                          <p className="text-xs text-gray-500 mt-0.5">
                            {wave.orders_count} orders
                            {wave.started_at && (
                              <>
                                {' · '}
                                <Clock className="w-3 h-3 inline -mt-0.5" />{' '}
                                Started {new Date(wave.started_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                              </>
                            )}
                          </p>
                        </div>
                        <ChevronRight className="w-4 h-4 text-gray-400 shrink-0 mt-0.5" />
                      </div>
                      <div className="space-y-1">
                        <Progress value={wave.completion_pct} className="h-1.5" />
                        <p className="text-xs text-gray-400">{wave.completion_pct.toFixed(1)}% complete</p>
                      </div>
                    </CardContent>
                  </Card>
                ))}
              </div>
            )}
          </div>

          {/* Pool summary */}
          <div className="rounded-lg border p-4">
            <div className="flex items-center justify-between mb-2">
              <h2 className="text-sm font-semibold text-gray-900">Prepared Pool</h2>
              <Button variant="ghost" size="sm" className="text-xs gap-1" onClick={() => navigate(ROUTES.preparedPool)}>
                View Pool <ChevronRight className="w-3 h-3" />
              </Button>
            </div>
            <div className="flex items-center gap-2">
              <PackageCheck className="w-4 h-4 text-green-600" />
              <p className="text-sm text-gray-600">
                <span className="font-medium">{data.kpis.pool_available_units.toLocaleString()}</span> units available for loading
              </p>
            </div>
          </div>
        </>
      )}

      <PreparationWaveDrawer waveId={selectedWaveId} onClose={() => setSelectedWaveId(null)} />
    </div>
  );
}
