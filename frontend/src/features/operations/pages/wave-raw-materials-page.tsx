import { useState } from 'react';
import { AlertTriangle, FlaskConical, Loader2, Waves } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { SmartToolbar } from '@/components/data-grid/smart-toolbar';
import { UniversalDataGrid } from '@/components/data-grid/universal-data-grid';
import { ColumnVisibilityMenu } from '@/components/data-grid/column-visibility-menu';
import { useColumnVisibility } from '@/components/data-grid/use-column-visibility';
import type { DataGridColumnDef } from '@/components/data-grid/types';
import { useWaveMaterialDemand } from '../hooks/use-preparation';
import { useSelectedWaveId } from '../components/wave-picker';
import type { WaveMaterialDemandItem } from '../types/preparation';

type ShortageFilter = 'all' | 'shortage' | 'ok';

const SHORTAGE_TABS: Array<{ value: ShortageFilter; label: string }> = [
  { value: 'all',      label: 'All'        },
  { value: 'shortage', label: 'Shortage'   },
  { value: 'ok',       label: 'Sufficient' },
];

function fmt(n: number) {
  return n.toLocaleString(undefined, { maximumFractionDigits: 3 });
}

// ── Columns ────────────────────────────────────────────────────────────────────

function buildColumns(): DataGridColumnDef<WaveMaterialDemandItem>[] {
  return [
    {
      key: 'material',
      label: 'Material',
      alwaysVisible: true,
      cell: (m) => (
        <div>
          <div className="text-sm font-medium">{m.material_name}</div>
          {m.material_sku && (
            <div className="text-[10px] text-muted-foreground font-mono">{m.material_sku}</div>
          )}
        </div>
      ),
    },
    {
      key: 'status',
      label: 'Status',
      alwaysVisible: true,
      cell: (m) => {
        if (m.missing_qty > 0) {
          return (
            <Badge className="text-xs bg-red-100 text-red-700 flex items-center gap-1 w-fit">
              <AlertTriangle className="h-3 w-3" />
              Shortage
            </Badge>
          );
        }
        return <Badge className="text-xs bg-green-100 text-green-700">Sufficient</Badge>;
      },
    },
    {
      key: 'required_qty',
      label: 'Required',
      defaultVisible: true,
      align: 'end',
      cell: (m) => <span className="text-sm tabular-nums">{fmt(m.required_qty)}</span>,
    },
    {
      key: 'available_qty',
      label: 'Available',
      defaultVisible: true,
      align: 'end',
      cell: (m) => (
        <span className={`text-sm tabular-nums ${m.missing_qty > 0 ? 'text-red-600' : 'text-emerald-700'}`}>
          {fmt(m.available_qty)}
        </span>
      ),
    },
    {
      key: 'missing_qty',
      label: 'Missing',
      defaultVisible: true,
      align: 'end',
      cell: (m) => (
        m.missing_qty > 0 ? (
          <span className="text-sm tabular-nums text-red-700 font-medium">{fmt(m.missing_qty)}</span>
        ) : (
          <span className="text-sm tabular-nums text-muted-foreground">—</span>
        )
      ),
    },
    {
      key: 'expected_today',
      label: 'Expected Today',
      defaultVisible: true,
      align: 'end',
      cell: (m) => (
        m.expected_today > 0 ? (
          <span className="text-sm tabular-nums text-blue-700">{fmt(m.expected_today)}</span>
        ) : (
          <span className="text-sm tabular-nums text-muted-foreground">—</span>
        )
      ),
    },
    {
      key: 'in_transit_qty',
      label: 'In Transit',
      defaultVisible: true,
      align: 'end',
      cell: (m) => (
        m.in_transit_qty > 0 ? (
          <span className="text-sm tabular-nums text-indigo-700">{fmt(m.in_transit_qty)}</span>
        ) : (
          <span className="text-sm tabular-nums text-muted-foreground">—</span>
        )
      ),
    },
    {
      key: 'coverage_pct',
      label: 'Coverage',
      defaultVisible: false,
      align: 'end',
      cell: (m) => (
        <span className={`text-sm tabular-nums ${m.coverage_pct >= 100 ? 'text-emerald-700' : 'text-amber-700'}`}>
          {m.coverage_pct.toFixed(1)}%
        </span>
      ),
    },
    {
      key: 'reserved_qty',
      label: 'Reserved',
      defaultVisible: false,
      align: 'end',
      cell: (m) => (
        m.reserved_qty > 0 ? (
          <span className="text-sm tabular-nums text-muted-foreground">{fmt(m.reserved_qty)}</span>
        ) : (
          <span className="text-sm tabular-nums text-muted-foreground">—</span>
        )
      ),
    },
  ];
}

const COL_METAS = buildColumns().map((c) => ({
  key: c.key,
  label: c.label,
  alwaysVisible: c.alwaysVisible,
  defaultVisible: c.defaultVisible,
}));

const COLUMNS = buildColumns();

// ── Page ──────────────────────────────────────────────────────────────────────

export function WaveRawMaterialsPage() {
  const waveId = useSelectedWaveId();
  const { data: materials, isLoading, isFetching, refetch } = useWaveMaterialDemand(waveId);

  const [search, setSearch]                 = useState('');
  const [shortageFilter, setShortageFilter] = useState<ShortageFilter>('all');

  const colVis = useColumnVisibility('wave-raw-materials-cols', COL_METAS);

  const allMaterials = materials ?? [];

  const countByFilter: Record<ShortageFilter, number> = {
    all:      allMaterials.length,
    shortage: allMaterials.filter((m) => m.missing_qty > 0).length,
    ok:       allMaterials.filter((m) => m.missing_qty === 0).length,
  };

  const filtered = allMaterials.filter((m) => {
    switch (shortageFilter) {
      case 'shortage': if (m.missing_qty === 0) return false; break;
      case 'ok':       if (m.missing_qty > 0) return false; break;
    }
    if (search) {
      return m.material_name.toLowerCase().includes(search.toLowerCase());
    }
    return true;
  });

  return (
    <div className="flex flex-col h-full">
      <SmartToolbar
        onRefresh={() => void refetch()}
        isFetching={isFetching}
        viewControls={
          <ColumnVisibilityMenu
            columns={COL_METAS}
            visibility={colVis.visibility}
            onToggle={colVis.toggle}
            onReset={colVis.reset}
          />
        }
      />

      <div className="flex items-center justify-between gap-3 px-4 py-2 border-b bg-muted/30 flex-wrap">
        <div className="flex items-center gap-1 overflow-x-auto">
          {SHORTAGE_TABS.map((tab) => {
            const active = shortageFilter === tab.value;
            const count  = countByFilter[tab.value];
            return (
              <button
                key={tab.value}
                onClick={() => setShortageFilter(tab.value)}
                className={`flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-medium whitespace-nowrap transition-colors ${
                  active
                    ? 'bg-background text-foreground shadow-sm border'
                    : 'text-muted-foreground hover:text-foreground hover:bg-background/60'
                }`}
              >
                {tab.label}
                <span className={`inline-flex h-4 min-w-4 items-center justify-center rounded-full px-1 text-[10px] tabular-nums ${
                  active ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground'
                }`}>
                  {count}
                </span>
              </button>
            );
          })}
        </div>

        <div className="flex items-center gap-2 shrink-0">
          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search material…"
            className="h-7 text-xs w-44"
          />
        </div>
      </div>

      <div className="flex-1 overflow-hidden">
        {!waveId ? (
          <div className="flex flex-col items-center justify-center h-64 gap-2 text-muted-foreground">
            <Waves className="h-8 w-8 opacity-30" />
            <p className="text-sm">Select a wave to view raw material demand.</p>
          </div>
        ) : isLoading ? (
          <div className="flex items-center justify-center h-64 gap-2 text-muted-foreground">
            <Loader2 className="h-4 w-4 animate-spin" />
            <span className="text-sm">Loading…</span>
          </div>
        ) : (
          <UniversalDataGrid<WaveMaterialDemandItem>
            columns={COLUMNS}
            data={filtered}
            rowId={(m) => m.id}
            loading={false}
            columnVisibility={colVis.visibility}
            emptyState={
              <div className="flex flex-col items-center justify-center py-16 gap-2 text-muted-foreground">
                <FlaskConical className="w-8 h-8" />
                <p className="text-sm">
                  {allMaterials.length === 0
                    ? 'No material demand data yet. Generate demand first.'
                    : 'No materials match the current filter.'}
                </p>
              </div>
            }
          />
        )}
      </div>
    </div>
  );
}
