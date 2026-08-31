import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { AlertCircle, Clock, Loader2, Save, Settings2, Warehouse } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { useToastStore } from '@/components/ds/use-toast';
import { useWaveEngineConfig, useUpdateWaveEngineConfig } from '../hooks/use-preparation';
import type { WaveEngineConfig } from '../types/preparation';

/**
 * Wave Engine settings — the operational cycle (Start / Intake Cutoff / End) per warehouse.
 *
 * Consumes GET/PUT /configuration/wave-engine. The three times are wall-clock in the
 * company-local timezone and may cross midnight. Timezone is read-only here on purpose —
 * companies.timezone is the single authority, edited under Company settings (ADR-027 G-2).
 */

function fmtCycle(iso: string): string {
  return new Date(iso).toLocaleString([], {
    month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
  });
}

function ToggleRow({ label, checked, onChange }: {
  label: string;
  checked: boolean;
  onChange: (v: boolean) => void;
}) {
  return (
    <div className="flex items-center justify-between py-1">
      <span className="text-xs">{label}</span>
      <Switch checked={checked} onCheckedChange={onChange} />
    </div>
  );
}

function ConfigCard({ config }: { config: WaveEngineConfig }) {
  const { t } = useTranslation('settings');
  const toast = useToastStore((s) => s.toast);
  const update = useUpdateWaveEngineConfig();

  const [start, setStart] = useState(config.collection_start_time);
  const [cutoff, setCutoff] = useState(config.preparation_start_time);
  const [end, setEnd] = useState(config.wave_end_time);
  const [autoCreate, setAutoCreate] = useState(config.auto_create);
  const [autoAssign, setAutoAssign] = useState(config.auto_assign_orders);
  const [autoMove, setAutoMove] = useState(config.auto_move_to_preparing);
  const [isActive, setIsActive] = useState(config.is_active);

  const dirty =
    start !== config.collection_start_time ||
    cutoff !== config.preparation_start_time ||
    end !== config.wave_end_time ||
    autoCreate !== config.auto_create ||
    autoAssign !== config.auto_assign_orders ||
    autoMove !== config.auto_move_to_preparing ||
    isActive !== config.is_active;

  async function handleSave() {
    try {
      await update.mutateAsync({
        id: config.id,
        payload: {
          collection_start_time: start,
          preparation_start_time: cutoff,
          wave_end_time: end,
          auto_create: autoCreate,
          auto_assign_orders: autoAssign,
          auto_move_to_preparing: autoMove,
          is_active: isActive,
        },
      });
      toast({ type: 'success', title: t($ => $.waveEngine.saved) });
    } catch {
      toast({ type: 'error', title: t($ => $.waveEngine.saveError) });
    }
  }

  const cycle = config.current_cycle;

  return (
    <div className="rounded-lg border border-border/60 bg-card p-4 space-y-4">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2">
          <Warehouse className="h-4 w-4 text-muted-foreground" />
          <span className="text-sm font-medium">{config.warehouse_name ?? config.warehouse_id}</span>
          {!config.is_active && (
            <Badge variant="outline" className="text-[10px]">{t($ => $.waveEngine.inactive)}</Badge>
          )}
          {config.crosses_midnight && (
            <Badge variant="outline" className="text-[10px]">{t($ => $.waveEngine.crossesMidnight)}</Badge>
          )}
        </div>
        <Button size="sm" className="h-8 text-xs" onClick={handleSave} disabled={!dirty || update.isPending}>
          {update.isPending
            ? <Loader2 className="h-3.5 w-3.5 mr-1.5 animate-spin" />
            : <Save className="h-3.5 w-3.5 mr-1.5" />}
          {t($ => $.waveEngine.save)}
        </Button>
      </div>

      <div className="grid grid-cols-3 gap-3">
        <div className="space-y-1">
          <Label className="text-xs">{t($ => $.waveEngine.start)}</Label>
          <Input type="time" value={start} onChange={(e) => setStart(e.target.value)} className="h-8 text-sm" />
        </div>
        <div className="space-y-1">
          <Label className="text-xs">{t($ => $.waveEngine.cutoff)}</Label>
          <Input type="time" value={cutoff} onChange={(e) => setCutoff(e.target.value)} className="h-8 text-sm" />
        </div>
        <div className="space-y-1">
          <Label className="text-xs">{t($ => $.waveEngine.end)}</Label>
          <Input type="time" value={end} onChange={(e) => setEnd(e.target.value)} className="h-8 text-sm" />
        </div>
      </div>

      {cycle && (
        <div className="rounded-md bg-muted/40 px-3 py-2 text-xs text-muted-foreground flex items-center gap-2">
          <Clock className="h-3.5 w-3.5 shrink-0" />
          <span>
            {t($ => $.waveEngine.currentCycle)}: {fmtCycle(cycle.starts_at)} → {fmtCycle(cycle.intake_closes_at)} → {fmtCycle(cycle.ends_at)}
          </span>
        </div>
      )}

      <div className="space-y-0.5 pt-1 border-t border-border/40">
        <ToggleRow label={t($ => $.waveEngine.autoCreate)} checked={autoCreate} onChange={setAutoCreate} />
        <ToggleRow label={t($ => $.waveEngine.autoAssign)} checked={autoAssign} onChange={setAutoAssign} />
        <ToggleRow label={t($ => $.waveEngine.autoMove)} checked={autoMove} onChange={setAutoMove} />
        <ToggleRow label={t($ => $.waveEngine.active)} checked={isActive} onChange={setIsActive} />
      </div>
    </div>
  );
}

export function WaveEngineSettingsPage() {
  const { t } = useTranslation('settings');
  const { data, isLoading } = useWaveEngineConfig();

  return (
    <div className="flex flex-col h-full">
      <div className="px-4 py-3 border-b">
        <h1 className="text-base font-semibold flex items-center gap-2">
          <Settings2 className="size-4" />
          {t($ => $.waveEngine.title)}
        </h1>
        <p className="text-xs text-muted-foreground">{t($ => $.waveEngine.subtitle)}</p>
      </div>

      <div className="flex-1 overflow-auto p-4 space-y-4">
        {isLoading ? (
          <div className="flex items-center justify-center h-64 gap-2 text-muted-foreground">
            <Loader2 className="h-4 w-4 animate-spin" />
            <span className="text-sm">{t($ => $.waveEngine.loading)}</span>
          </div>
        ) : (
          <>
            <div className="rounded-lg border border-border/60 bg-card px-4 py-3 flex items-start gap-3">
              <Clock className="h-4 w-4 text-muted-foreground mt-0.5 shrink-0" />
              <div className="flex-1">
                <p className="text-sm font-medium">
                  {t($ => $.waveEngine.timezone)}: {data?.operational_timezone ?? '—'}
                </p>
                <p className="text-xs text-muted-foreground mt-0.5">{t($ => $.waveEngine.timezoneReadonly)}</p>
                {!data?.operational_timezone && (
                  <p className="text-xs text-amber-600 mt-1 flex items-center gap-1">
                    <AlertCircle className="h-3.5 w-3.5" /> {t($ => $.waveEngine.noTimezone)}
                  </p>
                )}
              </div>
            </div>

            {(data?.configurations.length ?? 0) === 0 ? (
              <div className="flex flex-col items-center justify-center h-48 gap-2 text-muted-foreground">
                <Settings2 className="h-8 w-8 opacity-30" />
                <p className="text-sm">{t($ => $.waveEngine.empty)}</p>
              </div>
            ) : (
              data?.configurations.map((c) => <ConfigCard key={c.id} config={c} />)
            )}
          </>
        )}
      </div>
    </div>
  );
}
