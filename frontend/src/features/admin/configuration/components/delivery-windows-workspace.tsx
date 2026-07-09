import { useState } from 'react';
import { Clock, GripVertical, Loader2, Moon, Plus, Sun, Sunset, Trash2 } from 'lucide-react';

import { Badge }   from '@/components/ui/badge';
import { Button }  from '@/components/ui/button';
import { Input }   from '@/components/ui/input';
import { Label }   from '@/components/ui/label';
import { Switch }  from '@/components/ui/switch';
import { useToast } from '@/components/ds/use-toast';

import {
  useCreateDeliveryWindow,
  useDeleteDeliveryWindow,
  useDeliveryWindows,
  useSeedDefaultWindows,
  useUpdateDeliveryWindow,
} from '../hooks/use-configuration';
import type { DeliveryWindow } from '../types/configuration';

// ── Helpers ───────────────────────────────────────────────────────────────────

function formatTime(t: string): string {
  const [h, m] = t.split(':').map(Number);
  const suffix = h >= 12 ? 'PM' : 'AM';
  const hour   = h % 12 || 12;
  return `${hour}:${String(m).padStart(2, '0')} ${suffix}`;
}

function slotIcon(starts_at: string) {
  const hour = parseInt(starts_at.split(':')[0], 10);
  if (hour < 12) return <Sun  className="h-4 w-4 text-amber-500" />;
  if (hour < 18) return <Sunset className="h-4 w-4 text-orange-500" />;
  return <Moon className="h-4 w-4 text-indigo-500" />;
}

// ── Component ─────────────────────────────────────────────────────────────────

export function DeliveryWindowsWorkspace({ brandId }: { brandId: string }) {
  const { toast }                                    = useToast();
  const { data: windows = [], isLoading }            = useDeliveryWindows(brandId);
  const createWindow  = useCreateDeliveryWindow(brandId);
  const updateWindow  = useUpdateDeliveryWindow(brandId);
  const deleteWindow  = useDeleteDeliveryWindow(brandId);
  const seedDefaults  = useSeedDefaultWindows(brandId);

  const [showAdd,  setShowAdd]  = useState(false);
  const [form,     setForm]     = useState({ label: '', starts_at: '', ends_at: '' });
  const [formErr,  setFormErr]  = useState('');

  const enabledCount  = windows.filter((w) => w.is_enabled).length;
  const disabledCount = windows.length - enabledCount;

  async function handleCreate() {
    if (!form.label.trim()) { setFormErr('Label is required.'); return; }
    if (!form.starts_at)    { setFormErr('Start time is required.'); return; }
    if (!form.ends_at)      { setFormErr('End time is required.'); return; }
    if (form.starts_at >= form.ends_at) { setFormErr('End time must be after start time.'); return; }

    setFormErr('');
    await createWindow.mutateAsync({ label: form.label.trim(), starts_at: form.starts_at, ends_at: form.ends_at });
    setForm({ label: '', starts_at: '', ends_at: '' });
    setShowAdd(false);
    toast({ title: 'Delivery window created', type: 'success' });
  }

  async function handleSeedDefaults() {
    await seedDefaults.mutateAsync();
    toast({ title: 'Default windows seeded', type: 'success' });
  }

  async function handleToggle(window: DeliveryWindow, enabled: boolean) {
    await updateWindow.mutateAsync({ id: window.id, payload: { is_enabled: enabled } });
    toast({ title: enabled ? `${window.label} enabled` : `${window.label} disabled`, type: 'success' });
  }

  async function handleDelete(window: DeliveryWindow) {
    if (!confirm(`Delete "${window.label}"?`)) return;
    await deleteWindow.mutateAsync(window.id);
    toast({ title: 'Window deleted', type: 'success' });
  }

  if (isLoading) {
    return (
      <div className="p-6 flex items-center justify-center py-16 gap-2 text-muted-foreground">
        <Loader2 className="h-4 w-4 animate-spin" />
        <span className="text-sm">Loading delivery windows…</span>
      </div>
    );
  }

  return (
    <div className="p-6 space-y-5 max-w-3xl">

      {/* KPI row */}
      <div className="grid grid-cols-3 gap-3">
        <div className="rounded-lg border border-border/60 bg-card p-3 flex items-center gap-2.5">
          <Clock className="h-4 w-4 text-muted-foreground shrink-0" />
          <div>
            <div className="text-base font-bold leading-none">{windows.length}</div>
            <div className="text-[10px] text-muted-foreground mt-0.5">Total Windows</div>
          </div>
        </div>
        <div className="rounded-lg border border-border/60 bg-card p-3 flex items-center gap-2.5">
          <div className="h-2 w-2 rounded-full bg-emerald-500 shrink-0" />
          <div>
            <div className="text-base font-bold leading-none">{enabledCount}</div>
            <div className="text-[10px] text-muted-foreground mt-0.5">Active</div>
          </div>
        </div>
        <div className="rounded-lg border border-border/60 bg-card p-3 flex items-center gap-2.5">
          <div className="h-2 w-2 rounded-full bg-muted shrink-0" />
          <div>
            <div className="text-base font-bold leading-none">{disabledCount}</div>
            <div className="text-[10px] text-muted-foreground mt-0.5">Inactive</div>
          </div>
        </div>
      </div>

      {/* Actions */}
      <div className="flex items-center gap-3">
        <Button
          size="sm"
          variant="outline"
          onClick={handleSeedDefaults}
          disabled={seedDefaults.isPending}
          className="gap-1.5 text-xs h-8"
        >
          {seedDefaults.isPending && <Loader2 className="h-3 w-3 animate-spin" />}
          Seed Default Windows
        </Button>
        <Button
          size="sm"
          variant="outline"
          onClick={() => setShowAdd(!showAdd)}
          className="gap-1.5 text-xs h-8"
        >
          <Plus className="h-3.5 w-3.5" />
          Add Window
        </Button>
      </div>

      {/* Inline add form */}
      {showAdd && (
        <div className="rounded-lg border border-primary/30 bg-primary/5 p-4 space-y-3">
          <p className="text-xs font-semibold">New Delivery Window</p>
          <div className="grid grid-cols-3 gap-3">
            <div className="space-y-1">
              <Label className="text-xs">Label *</Label>
              <Input
                value={form.label}
                onChange={(e) => setForm({ ...form, label: e.target.value })}
                placeholder="e.g. Morning Shift"
                className="h-8 text-sm"
                autoFocus
              />
            </div>
            <div className="space-y-1">
              <Label className="text-xs">Start Time *</Label>
              <Input
                type="time"
                value={form.starts_at}
                onChange={(e) => setForm({ ...form, starts_at: e.target.value })}
                className="h-8 text-sm"
              />
            </div>
            <div className="space-y-1">
              <Label className="text-xs">End Time *</Label>
              <Input
                type="time"
                value={form.ends_at}
                onChange={(e) => setForm({ ...form, ends_at: e.target.value })}
                className="h-8 text-sm"
              />
            </div>
          </div>
          {formErr && <p className="text-xs text-destructive">{formErr}</p>}
          <div className="flex gap-2">
            <Button
              size="sm"
              onClick={handleCreate}
              disabled={createWindow.isPending}
              className="gap-1.5"
            >
              {createWindow.isPending && <Loader2 className="h-3.5 w-3.5 animate-spin" />}
              Create Window
            </Button>
            <Button size="sm" variant="ghost" onClick={() => { setShowAdd(false); setFormErr(''); }}>
              Cancel
            </Button>
          </div>
        </div>
      )}

      {/* Windows grid */}
      {windows.length === 0 ? (
        <div className="flex flex-col items-center justify-center py-16 rounded-lg border border-dashed border-border/60 gap-3">
          <Clock className="h-10 w-10 text-muted-foreground/30" />
          <div className="text-center">
            <p className="text-sm font-medium text-muted-foreground">No Delivery Windows</p>
            <p className="text-xs text-muted-foreground/70 mt-0.5">
              Use "Seed Default Windows" to add standard time slots, or create custom ones.
            </p>
          </div>
          <Button size="sm" variant="outline" onClick={handleSeedDefaults} disabled={seedDefaults.isPending}>
            {seedDefaults.isPending ? <Loader2 className="h-3.5 w-3.5 animate-spin mr-1" /> : null}
            Seed Default Windows
          </Button>
        </div>
      ) : (
        <div className="space-y-2">
          <p className="text-xs text-muted-foreground font-medium">
            Configure which time slots customers can receive deliveries.
            Disabled windows remain visible to staff but are not shown to customers.
          </p>
          <div className="grid sm:grid-cols-2 gap-3">
            {windows
              .slice()
              .sort((a, b) => a.sort_order - b.sort_order)
              .map((w) => (
                <WindowCard
                  key={w.id}
                  window={w}
                  onToggle={(en) => handleToggle(w, en)}
                  onDelete={() => handleDelete(w)}
                  isUpdating={updateWindow.isPending}
                  isDeleting={deleteWindow.isPending}
                />
              ))
            }
          </div>
        </div>
      )}
    </div>
  );
}

// ── Window Card ───────────────────────────────────────────────────────────────

function WindowCard({
  window: w,
  onToggle,
  onDelete,
  isUpdating,
  isDeleting,
}: {
  window:     DeliveryWindow;
  onToggle:   (enabled: boolean) => void;
  onDelete:   () => void;
  isUpdating: boolean;
  isDeleting: boolean;
}) {
  return (
    <div className={`rounded-xl border transition-all ${
      w.is_enabled
        ? 'border-border/60 bg-card shadow-sm'
        : 'border-border/40 bg-muted/10 opacity-60'
    }`}>
      <div className="flex items-start gap-3 p-4">
        {/* Drag handle placeholder (visual only — keyboard reorder via sort_order) */}
        <div className="mt-0.5 text-muted-foreground/30 shrink-0">
          <GripVertical className="h-4 w-4" />
        </div>

        {/* Time icon */}
        <div className="shrink-0 mt-0.5">
          {slotIcon(w.starts_at)}
        </div>

        {/* Content */}
        <div className="flex-1 min-w-0">
          <div className="flex items-center gap-2 flex-wrap">
            <span className="text-sm font-semibold">{w.label}</span>
            {!w.is_enabled && (
              <Badge className="text-[10px] py-0 h-4 bg-muted text-muted-foreground border-0">Inactive</Badge>
            )}
          </div>
          <div className="flex items-center gap-1.5 mt-1">
            <span className="font-mono text-sm text-muted-foreground">
              {formatTime(w.starts_at)}
            </span>
            <span className="text-muted-foreground/50">→</span>
            <span className="font-mono text-sm text-muted-foreground">
              {formatTime(w.ends_at)}
            </span>
          </div>
        </div>

        {/* Controls */}
        <div className="flex items-center gap-2 shrink-0">
          <Switch
            checked={w.is_enabled}
            onCheckedChange={onToggle}
            disabled={isUpdating}
            className="scale-90"
          />
          <button
            onClick={onDelete}
            disabled={isDeleting}
            className="text-muted-foreground hover:text-destructive transition-colors p-1 rounded"
            title="Delete window"
          >
            {isDeleting
              ? <Loader2 className="h-3.5 w-3.5 animate-spin" />
              : <Trash2  className="h-3.5 w-3.5" />
            }
          </button>
        </div>
      </div>
    </div>
  );
}
