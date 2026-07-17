import { useState } from 'react';
import {
  AlertCircle,
  Bell,
  CalendarDays,
  CheckCircle2,
  FlaskConical,
  Layers,
  Loader2,
  RefreshCw,
  Waves,
  Zap,
} from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { useToastStore } from '@/components/ds/use-toast';
import {
  usePreparationWave,
  useGenerateDemand,
  useAnalyzeMaterials,
  useApproveWave,
  useRecalculateWave,
} from '../hooks/use-preparation';
import { useSelectedWaveId } from '../components/wave-picker';
import type { WaveStatus } from '../types/preparation';

const STATUS_COLORS: Record<WaveStatus, string> = {
  draft:            'bg-gray-100 text-gray-700',
  collecting:       'bg-sky-100 text-sky-700',
  planning:         'bg-blue-100 text-blue-700',
  shortage_blocked: 'bg-amber-100 text-amber-700',
  preparing:        'bg-purple-100 text-purple-700',
  completed:        'bg-green-100 text-green-700',
  closed:           'bg-slate-100 text-slate-700',
  cancelled:        'bg-red-100 text-red-700',
};

const STATUS_LABELS: Record<WaveStatus, string> = {
  draft:            'Draft',
  collecting:       'Collecting',
  planning:         'Planning',
  shortage_blocked: 'Shortage Blocked',
  preparing:        'Preparing',
  completed:        'Completed',
  closed:           'Closed',
  cancelled:        'Cancelled',
};

// ── Coming Soon pill ──────────────────────────────────────────────────────────

function ComingSoon() {
  return (
    <span className="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium bg-muted text-muted-foreground ml-2">
      Coming soon
    </span>
  );
}

// ── Info row ──────────────────────────────────────────────────────────────────

function InfoRow({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="flex items-start gap-4 py-2.5 border-b border-border/40 last:border-0">
      <span className="text-xs text-muted-foreground font-medium w-36 shrink-0 pt-0.5">{label}</span>
      <span className="text-sm text-foreground">{value}</span>
    </div>
  );
}

// ── Action row ────────────────────────────────────────────────────────────────

function ActionRow({
  icon,
  title,
  description,
  action,
  actionLabel,
  loading,
  disabled,
  variant = 'default',
}: {
  icon: React.ReactNode;
  title: string;
  description: string;
  action: () => void;
  actionLabel: string;
  loading?: boolean;
  disabled?: boolean;
  variant?: 'default' | 'destructive' | 'outline';
}) {
  return (
    <div className="flex items-start gap-4 py-3 border-b border-border/40 last:border-0">
      <span className="text-muted-foreground shrink-0 mt-0.5">{icon}</span>
      <div className="flex-1 min-w-0">
        <p className="text-sm font-medium">{title}</p>
        <p className="text-xs text-muted-foreground mt-0.5">{description}</p>
      </div>
      <Button
        size="sm"
        variant={variant}
        className="h-8 text-xs shrink-0"
        onClick={action}
        disabled={disabled || loading}
      >
        {loading && <Loader2 className="h-3.5 w-3.5 mr-1.5 animate-spin" />}
        {actionLabel}
      </Button>
    </div>
  );
}

// ── Page ──────────────────────────────────────────────────────────────────────

export function WaveSettingsPage() {
  const waveId = useSelectedWaveId();
  const toast  = useToastStore((s) => s.toast);

  const { data: wave, isLoading } = usePreparationWave(waveId);

  const generateDemand   = useGenerateDemand();
  const analyzeMaterials = useAnalyzeMaterials();
  const approveWave      = useApproveWave();
  const recalculate      = useRecalculateWave();

  const [notes, setNotes] = useState<string>('');
  const [notesChanged, setNotesChanged] = useState(false);

  // Sync notes from wave data
  if (wave && !notesChanged && notes === '' && wave.notes) {
    setNotes(wave.notes);
  }

  async function handleGenerateDemand() {
    if (!waveId) return;
    try {
      await generateDemand.mutateAsync(waveId);
      toast({ type: 'success', title: 'Demand generated successfully.' });
    } catch {
      toast({ type: 'error', title: 'Failed to generate demand.' });
    }
  }

  async function handleAnalyzeMaterials() {
    if (!waveId) return;
    try {
      await analyzeMaterials.mutateAsync(waveId);
      toast({ type: 'success', title: 'Material analysis complete.' });
    } catch {
      toast({ type: 'error', title: 'Failed to analyze materials.' });
    }
  }

  async function handleApprove() {
    if (!waveId) return;
    try {
      await approveWave.mutateAsync({ id: waveId, payload: { notes: notes || undefined } });
      toast({ type: 'success', title: 'Wave approved.' });
    } catch {
      toast({ type: 'error', title: 'Failed to approve wave.' });
    }
  }

  async function handleRecalculate() {
    if (!waveId) return;
    try {
      await recalculate.mutateAsync({ id: waveId, payload: {} });
      toast({ type: 'success', title: 'Wave recalculated.' });
    } catch {
      toast({ type: 'error', title: 'Recalculation failed.' });
    }
  }

  return (
    <div className="flex flex-col h-full">
      {!waveId ? (
        <div className="flex flex-col items-center justify-center h-64 gap-2 text-muted-foreground">
          <Waves className="h-8 w-8 opacity-30" />
          <p className="text-sm">Select a wave to manage its settings.</p>
        </div>
      ) : isLoading ? (
        <div className="flex items-center justify-center h-64 gap-2 text-muted-foreground">
          <Loader2 className="h-4 w-4 animate-spin" />
          <span className="text-sm">Loading…</span>
        </div>
      ) : !wave ? (
        <div className="flex flex-col items-center justify-center h-64 gap-2 text-muted-foreground">
          <AlertCircle className="h-6 w-6" />
          <p className="text-sm">Wave not found.</p>
        </div>
      ) : (
        <div className="flex-1 overflow-auto p-5">
          <Tabs defaultValue="general" className="space-y-4">
            <TabsList className="h-8">
              <TabsTrigger value="general" className="text-xs">General</TabsTrigger>
              <TabsTrigger value="automation" className="text-xs">Automation</TabsTrigger>
              <TabsTrigger value="schedule" className="text-xs">Schedule</TabsTrigger>
              <TabsTrigger value="notifications" className="text-xs">Notifications</TabsTrigger>
            </TabsList>

            {/* ── General tab ─────────────────────────────────────────────── */}
            <TabsContent value="general" className="mt-0 space-y-5">
              <div className="rounded-lg border border-border/60 bg-card px-4 py-1">
                <InfoRow label="Wave #"         value={<span className="font-mono font-medium">{wave.wave_number}</span>} />
                <InfoRow label="Status"         value={<Badge className={`text-xs ${STATUS_COLORS[wave.status]}`}>{STATUS_LABELS[wave.status]}</Badge>} />
                <InfoRow label="Planning Date"  value={new Date(wave.planning_date).toLocaleDateString(undefined, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })} />
                <InfoRow label="Warehouse ID"   value={<span className="font-mono text-xs text-muted-foreground">{wave.warehouse_id}</span>} />
                <InfoRow label="Orders"         value={wave.orders_count} />
                <InfoRow label="Products"       value={wave.products_count} />
                <InfoRow label="Completion"     value={`${wave.completion_pct.toFixed(1)}%`} />
                {wave.approved_at && (
                  <InfoRow
                    label="Approved At"
                    value={new Date(wave.approved_at).toLocaleString()}
                  />
                )}
                {wave.started_at && (
                  <InfoRow
                    label="Started At"
                    value={new Date(wave.started_at).toLocaleString()}
                  />
                )}
              </div>

              {/* Notes */}
              <div>
                <label className="text-xs font-medium text-muted-foreground mb-1.5 block">
                  Wave Notes
                </label>
                <Textarea
                  value={notes}
                  onChange={(e) => { setNotes(e.target.value); setNotesChanged(true); }}
                  placeholder="Add internal notes for this wave…"
                  rows={4}
                  className="text-sm resize-none"
                />
                {notesChanged && (
                  <p className="text-[10px] text-amber-600 mt-1">
                    Note: notes are for reference only and are saved when you Approve the wave.
                  </p>
                )}
              </div>
            </TabsContent>

            {/* ── Automation tab ──────────────────────────────────────────── */}
            <TabsContent value="automation" className="mt-0">
              <div className="rounded-lg border border-border/60 bg-card px-4 py-1 space-y-0">
                <ActionRow
                  icon={<Layers className="h-4 w-4" />}
                  title="Generate Demand"
                  description="Calculate required product quantities from attached orders. Run this after adding or removing orders."
                  action={handleGenerateDemand}
                  actionLabel="Generate"
                  loading={generateDemand.isPending}
                  disabled={wave.status === 'cancelled' || wave.status === 'completed'}
                />
                <ActionRow
                  icon={<FlaskConical className="h-4 w-4" />}
                  title="Analyze Materials"
                  description="Run material requirement analysis based on product recipes. Identifies shortages."
                  action={handleAnalyzeMaterials}
                  actionLabel="Analyze"
                  loading={analyzeMaterials.isPending}
                  disabled={wave.status === 'cancelled' || wave.status === 'completed'}
                />
                <ActionRow
                  icon={<RefreshCw className="h-4 w-4" />}
                  title="Recalculate Wave"
                  description="Recalculate totals and completion metrics. Use after manual adjustments."
                  action={handleRecalculate}
                  actionLabel="Recalculate"
                  loading={recalculate.isPending}
                  disabled={wave.status === 'cancelled'}
                />
                <ActionRow
                  icon={<CheckCircle2 className="h-4 w-4" />}
                  title="Approve Wave"
                  description="Mark this wave as approved and ready for loading. This action is recorded."
                  action={handleApprove}
                  actionLabel="Approve"
                  loading={approveWave.isPending}
                  disabled={wave.status !== 'completed'}
                />
              </div>

              <div className="mt-4 rounded-lg border border-border/40 bg-muted/30 px-4 py-3 space-y-2">
                <p className="text-xs font-medium text-muted-foreground uppercase tracking-wide">
                  Automation Rules <ComingSoon />
                </p>
                <p className="text-xs text-muted-foreground">
                  Configure auto-triggers for demand generation and material analysis.
                  Automation rules will fire based on wave lifecycle events.
                </p>
                <div className="flex items-center gap-2 opacity-40">
                  <Zap className="h-3.5 w-3.5" />
                  <span className="text-xs">Auto-generate demand on order attachment</span>
                </div>
                <div className="flex items-center gap-2 opacity-40">
                  <Zap className="h-3.5 w-3.5" />
                  <span className="text-xs">Auto-analyze materials after demand generation</span>
                </div>
              </div>
            </TabsContent>

            {/* ── Schedule tab ────────────────────────────────────────────── */}
            <TabsContent value="schedule" className="mt-0">
              <div className="rounded-lg border border-border/40 bg-muted/20 px-5 py-8 flex flex-col items-center gap-3 text-muted-foreground">
                <CalendarDays className="h-8 w-8 opacity-40" />
                <p className="text-sm font-medium">Wave Scheduling</p>
                <p className="text-xs text-center max-w-sm">
                  Schedule recurring wave creation and define cutoff times for order batching.
                  Available in the next platform release.
                </p>
                <ComingSoon />
              </div>
            </TabsContent>

            {/* ── Notifications tab ───────────────────────────────────────── */}
            <TabsContent value="notifications" className="mt-0">
              <div className="rounded-lg border border-border/40 bg-muted/20 px-5 py-8 flex flex-col items-center gap-3 text-muted-foreground">
                <Bell className="h-8 w-8 opacity-40" />
                <p className="text-sm font-medium">Wave Notifications</p>
                <p className="text-xs text-center max-w-sm">
                  Configure alerts for shortage detection, completion milestones, and approval
                  requests. Notification channels will be available in a future update.
                </p>
                <ComingSoon />
              </div>
            </TabsContent>
          </Tabs>
        </div>
      )}
    </div>
  );
}
