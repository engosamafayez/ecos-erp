import { Waves } from 'lucide-react';
import { useSearchParams } from 'react-router-dom';

import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Badge } from '@/components/ui/badge';
import { usePreparationWaves } from '../hooks/use-preparation';
import type { WaveStatus } from '../types/preparation';

const STATUS_COLORS: Record<WaveStatus, string> = {
  draft:            'bg-gray-100 text-gray-700',
  collecting:       'bg-cyan-100 text-cyan-700',
  planning:         'bg-blue-100 text-blue-700',
  shortage_blocked: 'bg-amber-100 text-amber-700',
  preparing:        'bg-purple-100 text-purple-700',
  completed:        'bg-green-100 text-green-700',
  closed:           'bg-slate-100 text-slate-600',
  cancelled:        'bg-red-100 text-red-700',
};

const STATUS_LABELS: Record<WaveStatus, string> = {
  draft:            'Draft',
  collecting:       'Collecting',
  planning:         'Planning',
  shortage_blocked: 'Blocked',
  preparing:        'Preparing',
  completed:        'Completed',
  closed:           'Closed',
  cancelled:        'Cancelled',
};

type Props = {
  className?: string;
  showBadge?: boolean;
};

/** Reads/writes ?wave_id= in the current URL. Drop into any toolbar. */
export function WavePicker({ className, showBadge = true }: Props) {
  const [searchParams, setSearchParams] = useSearchParams();
  const waveId = searchParams.get('wave_id') ?? '';

  const { data, isLoading } = usePreparationWaves({ per_page: 50 });
  const waves = data?.data ?? [];

  const selected = waves.find((w) => w.id === waveId);

  function handleChange(id: string) {
    setSearchParams((p) => {
      p.set('wave_id', id);
      return p;
    });
  }

  return (
    <div className={`flex items-center gap-2 ${className ?? ''}`}>
      <Waves className="h-3.5 w-3.5 text-muted-foreground shrink-0" />
      <Select value={waveId} onValueChange={handleChange}>
        <SelectTrigger className="h-7 text-xs w-52 border-dashed">
          <SelectValue
            placeholder={isLoading ? 'Loading waves…' : 'Select a wave…'}
          />
        </SelectTrigger>
        <SelectContent>
          {waves.map((w) => (
            <SelectItem key={w.id} value={w.id} className="text-xs">
              <span className="font-mono font-medium">{w.wave_number}</span>
              <span className="ml-2 text-muted-foreground">
                {new Date(w.planning_date).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })}
              </span>
            </SelectItem>
          ))}
          {!isLoading && waves.length === 0 && (
            <SelectItem value="__none__" disabled className="text-xs text-muted-foreground">
              No waves found
            </SelectItem>
          )}
        </SelectContent>
      </Select>

      {showBadge && selected && (
        <Badge className={`text-[10px] h-5 px-1.5 ${STATUS_COLORS[selected.status]}`}>
          {STATUS_LABELS[selected.status]}
        </Badge>
      )}
    </div>
  );
}

/** Hook: returns the currently selected wave ID from the URL. */
export function useSelectedWaveId(): string | null {
  const [searchParams] = useSearchParams();
  return searchParams.get('wave_id');
}
