import { Archive, Loader2 } from 'lucide-react';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ROUTES } from '@/router/routes';
import { usePreparationWaves } from '../hooks/use-preparation';

/**
 * Wave Archive / History.
 *
 * The operational selector shows only the 3 most recent ACTIVE waves. Everything
 * else remains fully intact and reachable here — archiving is a read/filter
 * concern, not a mutation: no wave record is altered and nothing is deleted.
 *
 * `lifecycle: 'archived'` maps to WaveStatus::terminalValues() on the server,
 * derived from the existing isTerminal() predicate, so this page and the domain
 * cannot disagree about what "archived" means.
 */
export function WaveArchivePage() {
  const { t } = useTranslation('operations');
  const tAny = t as (key: string, opts?: Record<string, unknown>) => string;
  const navigate = useNavigate();

  const { data, isLoading } = usePreparationWaves({
    lifecycle: 'archived',
    sort: '-planning_date',
    per_page: 50,
  });

  const waves = data?.data ?? [];

  // Cycle boundaries are ISO datetimes; planning_date supplies the day, so the columns show
  // just the time. Missing is derived (required − prepared), never stored.
  const fmtTime = (iso: string | null | undefined): string =>
    iso ? new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '—';

  return (
    <div className="flex flex-col h-full">
      <div className="px-4 py-3 border-b">
        <h1 className="text-base font-semibold flex items-center gap-2">
          <Archive className="size-4" />
          {t($ => $.wave.archive.title)}
        </h1>
        <p className="text-xs text-muted-foreground">{t($ => $.wave.archive.subtitle)}</p>
      </div>

      <div className="flex-1 overflow-auto">
        {isLoading ? (
          <div className="flex items-center justify-center h-64 gap-2 text-muted-foreground">
            <Loader2 className="size-4 animate-spin" />
            <span className="text-sm">{t($ => $.wave.loading)}</span>
          </div>
        ) : waves.length === 0 ? (
          <div className="flex flex-col items-center justify-center h-64 gap-2 text-muted-foreground">
            <Archive className="size-8 opacity-30" />
            <p className="text-sm">{t($ => $.wave.archive.empty)}</p>
          </div>
        ) : (
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b bg-muted/40 text-xs text-muted-foreground">
                <th className="px-4 py-2 text-start font-medium">{t($ => $.wave.archive.waveNumber)}</th>
                <th className="px-4 py-2 text-start font-medium">{t($ => $.wave.archive.planningDate)}</th>
                <th className="px-4 py-2 text-start font-medium">{t($ => $.wave.archive.start)}</th>
                <th className="px-4 py-2 text-start font-medium">{t($ => $.wave.archive.cutoff)}</th>
                <th className="px-4 py-2 text-start font-medium">{t($ => $.wave.archive.end)}</th>
                <th className="px-4 py-2 text-start font-medium">{t($ => $.wave.archive.status)}</th>
                <th className="px-4 py-2 text-end font-medium">{t($ => $.wave.archive.orders)}</th>
                <th className="px-4 py-2 text-end font-medium">{t($ => $.wave.archive.products)}</th>
                <th className="px-4 py-2 text-end font-medium">{t($ => $.wave.archive.required)}</th>
                <th className="px-4 py-2 text-end font-medium">{t($ => $.wave.archive.prepared)}</th>
                <th className="px-4 py-2 text-end font-medium">{t($ => $.wave.archive.missing)}</th>
                <th className="px-4 py-2" />
              </tr>
            </thead>
            <tbody>
              {waves.map((w) => {
                const required = w.total_units_required ?? 0;
                const prepared = w.total_units_prepared ?? 0;
                const missing = Math.max(0, required - prepared);
                return (
                  <tr key={w.id} className="border-b last:border-0 hover:bg-muted/30">
                    <td className="px-4 py-2 font-mono text-xs">{w.wave_number}</td>
                    <td className="px-4 py-2 whitespace-nowrap">{w.planning_date ?? '—'}</td>
                    <td className="px-4 py-2 tabular-nums whitespace-nowrap">{fmtTime(w.starts_at)}</td>
                    <td className="px-4 py-2 tabular-nums whitespace-nowrap">{fmtTime(w.intake_closes_at)}</td>
                    <td className="px-4 py-2 tabular-nums whitespace-nowrap">{fmtTime(w.ends_at)}</td>
                    <td className="px-4 py-2">
                      <Badge variant="outline" className="text-[10px]">
                        {tAny(`wave.stageLabels.${w.status}`)}
                      </Badge>
                    </td>
                    <td className="px-4 py-2 text-end tabular-nums">{w.orders_count ?? 0}</td>
                    <td className="px-4 py-2 text-end tabular-nums">{w.products_count ?? 0}</td>
                    <td className="px-4 py-2 text-end tabular-nums">{required}</td>
                    <td className="px-4 py-2 text-end tabular-nums">{prepared}</td>
                    <td className={`px-4 py-2 text-end tabular-nums ${missing > 0 ? 'text-amber-700' : ''}`}>{missing}</td>
                    <td className="px-4 py-2 text-end">
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => navigate(`${ROUTES.waveProductDemand}?wave_id=${encodeURIComponent(w.id)}`)}
                      >
                        {t($ => $.wave.archive.open)}
                      </Button>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}
