import { RefreshCw } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import type { IntelligenceFilters } from '../../types/intelligence';

const DATE_PRESETS = [
  { value: 'today',      label: 'Today' },
  { value: 'yesterday',  label: 'Yesterday' },
  { value: 'last_7d',    label: 'Last 7 days' },
  { value: 'last_30d',   label: 'Last 30 days' },
  { value: 'last_90d',   label: 'Last 90 days' },
  { value: 'last_180d',  label: 'Last 180 days' },
  { value: 'this_month', label: 'This month' },
  { value: 'last_month', label: 'Last month' },
];

interface Props {
  filters:         IntelligenceFilters;
  onFilterChange:  (patch: Partial<IntelligenceFilters>) => void;
  onRefresh?:      () => void;
  isFetching?:     boolean;
  /** Extra controls rendered after the date picker */
  children?:       React.ReactNode;
}

export function IntelligenceFilterBar({
  filters,
  onFilterChange,
  onRefresh,
  isFetching,
  children,
}: Props) {
  return (
    <div className="flex flex-wrap items-center gap-2">
      <Select
        value={filters.date_preset ?? 'last_30d'}
        onValueChange={(v) => onFilterChange({ date_preset: v, date_start: undefined, date_stop: undefined })}
      >
        <SelectTrigger className="w-36 h-8 text-sm">
          <SelectValue placeholder="Date range" />
        </SelectTrigger>
        <SelectContent>
          {DATE_PRESETS.map((p) => (
            <SelectItem key={p.value} value={p.value}>{p.label}</SelectItem>
          ))}
        </SelectContent>
      </Select>

      {children}

      {onRefresh && (
        <Button
          size="sm"
          variant="ghost"
          className="h-8 px-2"
          onClick={onRefresh}
          disabled={isFetching}
        >
          <RefreshCw className={`h-3.5 w-3.5 ${isFetching ? 'animate-spin' : ''}`} />
        </Button>
      )}
    </div>
  );
}
