import { useState } from 'react';
import { Loader2, RefreshCw } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { usePreparationStations } from '../hooks/use-preparation';
import { useWarehouseOptions } from '@/features/products/hooks/use-warehouse-options';
import type { StationStatus, StationType } from '../types/preparation';

const STATUS_COLORS: Record<StationStatus, string> = {
  active:      'bg-green-100 text-green-700',
  inactive:    'bg-gray-100 text-gray-600',
  maintenance: 'bg-amber-100 text-amber-700',
};

const TYPE_LABELS: Record<StationType, string> = {
  picking:       'Picking',
  assembly:      'Assembly',
  quality_check: 'QC',
  packaging:     'Packaging',
  storage:       'Storage',
};

export function PreparationStationsPage() {
  const { data: warehouseOptions = [] } = useWarehouseOptions();
  const [warehouseId, setWarehouseId] = useState('');
  const [statusFilter, setStatusFilter] = useState('all');

  const effectiveWarehouseId = warehouseId || (warehouseOptions[0]?.value ?? '');

  const { data: stations = [], isLoading, isFetching, refetch } = usePreparationStations({
    warehouse_id: effectiveWarehouseId,
    status: statusFilter !== 'all' ? statusFilter : undefined,
  });

  return (
    <div className="flex flex-col gap-4 p-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-gray-900">Preparation Stations</h1>
          <p className="text-sm text-gray-500 mt-0.5">{stations.length} station{stations.length !== 1 ? 's' : ''}</p>
        </div>
        <Button variant="outline" size="sm" onClick={() => void refetch()} disabled={isFetching} aria-label="Refresh">
          <RefreshCw className={`w-4 h-4 ${isFetching ? 'animate-spin' : ''}`} />
        </Button>
      </div>

      {/* Filters */}
      <div className="flex flex-wrap gap-3">
        <Select value={effectiveWarehouseId} onValueChange={setWarehouseId}>
          <SelectTrigger className="h-9 text-sm w-48" aria-label="Select warehouse">
            <SelectValue placeholder="Select warehouse" />
          </SelectTrigger>
          <SelectContent>
            {warehouseOptions.map((w: { value: string; label: string }) => (
              <SelectItem key={w.value} value={w.value}>{w.label}</SelectItem>
            ))}
          </SelectContent>
        </Select>

        <Select value={statusFilter} onValueChange={setStatusFilter}>
          <SelectTrigger className="h-9 text-sm w-40" aria-label="Filter by status">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All Statuses</SelectItem>
            <SelectItem value="active">Active</SelectItem>
            <SelectItem value="inactive">Inactive</SelectItem>
            <SelectItem value="maintenance">Maintenance</SelectItem>
          </SelectContent>
        </Select>
      </div>

      {/* Grid */}
      {isLoading ? (
        <div className="flex items-center justify-center py-20">
          <Loader2 className="w-5 h-5 animate-spin text-gray-400" />
        </div>
      ) : stations.length === 0 ? (
        <div className="text-center py-16 text-sm text-gray-400">
          {effectiveWarehouseId ? 'No stations configured for this warehouse.' : 'Select a warehouse.'}
        </div>
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
          {stations.map((station) => (
            <div key={station.id} className="rounded-lg border p-4 space-y-3">
              <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                  <p className="text-sm font-medium text-gray-900 truncate">{station.name}</p>
                  {station.zone && <p className="text-xs text-gray-500">Zone: {station.zone}</p>}
                </div>
                <Badge className={`text-xs shrink-0 ${STATUS_COLORS[station.status]}`}>
                  {station.status}
                </Badge>
              </div>
              <div className="flex items-center justify-between text-xs text-gray-500">
                <Badge variant="outline" className="text-xs">
                  {TYPE_LABELS[station.station_type]}
                </Badge>
                {station.capacity !== null && (
                  <span>Cap: {station.capacity}</span>
                )}
              </div>
              <div className="text-xs text-gray-400">
                {station.current_workers} worker{station.current_workers !== 1 ? 's' : ''} active
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
