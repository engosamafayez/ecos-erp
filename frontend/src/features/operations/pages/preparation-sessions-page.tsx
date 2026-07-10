import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Calendar, Plus } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Progress } from '@/components/ui/progress';
import { UniversalDataGrid } from '@/components/data-grid/universal-data-grid';
import { ColumnVisibilityMenu } from '@/components/data-grid/column-visibility-menu';
import { useColumnVisibility } from '@/components/data-grid/use-column-visibility';
import { useRowSelection } from '@/components/data-grid/use-row-selection';
import type { DataGridColumnDef } from '@/components/data-grid/types';
import {
  usePreparationSessions,
} from '../hooks/use-preparation';
import { CreateSessionDialog } from '../components/create-session-dialog';
import type { PreparationSession, SessionStatus } from '../types/preparation';

const STATUS_COLORS: Record<SessionStatus, string> = {
  draft:       'bg-gray-100 text-gray-700',
  planning:    'bg-blue-100 text-blue-700',
  in_progress: 'bg-purple-100 text-purple-700',
  paused:      'bg-amber-100 text-amber-700',
  frozen:      'bg-cyan-100 text-cyan-700',
  completed:   'bg-green-100 text-green-700',
  approved:    'bg-emerald-100 text-emerald-700',
  closed:      'bg-slate-100 text-slate-700',
  cancelled:   'bg-red-100 text-red-700',
};

const STATUS_LABELS: Record<SessionStatus, string> = {
  draft:       'Draft',
  planning:    'Planning',
  in_progress: 'In Progress',
  paused:      'Paused',
  frozen:      'Frozen',
  completed:   'Completed',
  approved:    'Approved',
  closed:      'Closed',
  cancelled:   'Cancelled',
};

type StatusTab = { value: SessionStatus | 'all'; label: string };

const STATUS_TABS: StatusTab[] = [
  { value: 'all',         label: 'All' },
  { value: 'draft',       label: 'Draft' },
  { value: 'planning',    label: 'Planning' },
  { value: 'in_progress', label: 'In Progress' },
  { value: 'paused',      label: 'Paused' },
  { value: 'frozen',      label: 'Frozen' },
  { value: 'completed',   label: 'Completed' },
  { value: 'approved',    label: 'Approved' },
  { value: 'closed',      label: 'Closed' },
  { value: 'cancelled',   label: 'Cancelled' },
];

const COLUMN_DEFS: DataGridColumnDef<PreparationSession>[] = [
  { key: 'session_number', label: 'Session #',    cell: (r) => <span className="font-mono text-xs">{r.session_number}</span> },
  { key: 'planning_date',  label: 'Planning Date', cell: (r) => r.planning_date },
  {
    key: 'status',
    label: 'Status',
    cell: (r) => (
      <Badge className={STATUS_COLORS[r.status] ?? 'bg-gray-100 text-gray-700'}>
        {STATUS_LABELS[r.status] ?? r.status}
      </Badge>
    ),
  },
  { key: 'waves_count',    label: 'Waves',    cell: (r) => r.waves_count },
  { key: 'products_count', label: 'Products', cell: (r) => r.products_count },
  {
    key: 'units_progress',
    label: 'Units Progress',
    cell: (r) => (
      <div className="flex items-center gap-2 min-w-[140px]">
        <Progress value={r.completion_pct} className="h-1.5 flex-1" />
        <span className="text-xs text-muted-foreground tabular-nums">
          {r.completion_pct.toFixed(0)}%
        </span>
      </div>
    ),
  },
  {
    key: 'started_at',
    label: 'Started',
    cell: (r) => r.started_at ? new Date(r.started_at).toLocaleString() : '—',
  },
  {
    key: 'approved_at',
    label: 'Approved',
    cell: (r) => r.approved_at ? new Date(r.approved_at).toLocaleString() : '—',
  },
  {
    key: 'completed_at',
    label: 'Completed',
    cell: (r) => r.completed_at ? new Date(r.completed_at).toLocaleString() : '—',
  },
  {
    key: 'created_at',
    label: 'Created',
    cell: (r) => new Date(r.created_at).toLocaleDateString(),
  },
];


export default function PreparationSessionsPage() {
  const [searchParams, setSearchParams] = useSearchParams();
  const [createOpen, setCreateOpen] = useState(false);
  const [search, setSearch]         = useState('');

  const activeStatus = (searchParams.get('status') ?? 'all') as SessionStatus | 'all';
  const page         = Number(searchParams.get('page') ?? 1);

  const { data, isLoading } = usePreparationSessions({
    status:  activeStatus === 'all' ? undefined : activeStatus,
    search:  search || undefined,
    page,
    per_page: 25,
  });

  const sessions = data?.data ?? [];
  const meta     = data?.meta;

  const { visibility, toggle: toggleColumn, reset: resetColumns } = useColumnVisibility('prep-sessions-cols', COLUMN_DEFS);
  const { selectedIds, selectRow, clearSelection } = useRowSelection({
    items: sessions,
    getId: (s: PreparationSession) => s.id,
  });

  const visibleDefs = COLUMN_DEFS.filter((c) => visibility[c.key] !== false);

  function handleStatusTab(val: SessionStatus | 'all') {
    setSearchParams((p) => { p.set('status', val); p.set('page', '1'); return p; });
    clearSelection();
  }

  return (
    <div className="flex flex-col h-full">
      {/* Status tabs */}
      <div className="flex gap-1 px-4 pt-4 border-b border-border/60 overflow-x-auto">
        {STATUS_TABS.map((tab) => (
          <button
            key={tab.value}
            onClick={() => handleStatusTab(tab.value)}
            className={[
              'px-3 py-1.5 text-sm font-medium rounded-t whitespace-nowrap transition-colors',
              activeStatus === tab.value
                ? 'bg-background border border-b-background border-border/60 text-foreground'
                : 'text-muted-foreground hover:text-foreground',
            ].join(' ')}
          >
            {tab.label}
          </button>
        ))}
      </div>

      {/* Toolbar */}
      <div className="flex items-center gap-2 border-b bg-background px-4 py-2">
        <Input
          placeholder="Search by session number…"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          className="h-8 w-56"
        />
        {meta && (
          <span className="text-xs text-muted-foreground">{meta.total} sessions</span>
        )}
        <div className="flex-1" />
        <ColumnVisibilityMenu
          columns={COLUMN_DEFS}
          visibility={visibility}
          onToggle={toggleColumn}
          onReset={resetColumns}
        />
        <Button size="sm" onClick={() => setCreateOpen(true)}>
          <Plus className="h-3.5 w-3.5 mr-1.5" />
          New Session
        </Button>
      </div>

      {/* Grid */}
      <div className="flex-1 overflow-auto px-4 pb-4">
        <UniversalDataGrid<PreparationSession>
          data={sessions}
          columns={visibleDefs}
          loading={isLoading}
          rowId={(r: PreparationSession) => r.id}
          selection={selectedIds ? { selectedIds, selectedCount: selectedIds.size, isSelected: (id) => selectedIds.has(id), allSelected: false, someSelected: selectedIds.size > 0, selectRow, selectAll: () => {} } : undefined}
          emptyState={
            <div className="flex flex-col items-center justify-center gap-2 py-16 text-muted-foreground">
              <Calendar className="h-8 w-8 opacity-40" />
              <p className="text-sm">No sessions found</p>
              <Button variant="outline" size="sm" onClick={() => setCreateOpen(true)}>
                <Plus className="h-3.5 w-3.5 mr-1.5" />
                Create first session
              </Button>
            </div>
          }
        />

        {/* Pagination */}
        {meta && meta.last_page > 1 && (
          <div className="flex justify-end gap-2 pt-3">
            <Button
              variant="outline"
              size="sm"
              disabled={page <= 1}
              onClick={() => setSearchParams((p) => { p.set('page', String(page - 1)); return p; })}
            >
              Previous
            </Button>
            <span className="text-xs text-muted-foreground self-center">
              Page {page} of {meta.last_page}
            </span>
            <Button
              variant="outline"
              size="sm"
              disabled={page >= meta.last_page}
              onClick={() => setSearchParams((p) => { p.set('page', String(page + 1)); return p; })}
            >
              Next
            </Button>
          </div>
        )}
      </div>

      <CreateSessionDialog open={createOpen} onClose={() => setCreateOpen(false)} />
    </div>
  );
}
