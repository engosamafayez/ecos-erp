import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { AlertTriangle, Loader2, PackageCheck } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { toast } from '@/components/ds/use-toast';
import { useReceiveAgainstPo, useReceivingPoDetail } from '../hooks/use-receiving';
import type { ReceivingPoLine } from '../types/receiving';

function fmt(n: number) {
  return n.toLocaleString(undefined, { maximumFractionDigits: 4 });
}

/** Clamp a draft "receive now" string to [0, remaining]. */
function clampReceive(raw: string, remaining: number): number {
  const v = Number(raw);
  if (!Number.isFinite(v) || v <= 0) return 0;
  return Math.min(v, remaining);
}

export function ReceiveDrawer({
  poId,
  poNumber,
  open,
  onOpenChange,
}: {
  poId: string | null;
  poNumber?: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const { t } = useTranslation('receiving-center');
  const { data: po, isLoading, isError } = useReceivingPoDetail(open ? poId : null);
  const receive = useReceiveAgainstPo();

  // Draft "receive now" per line id. Defaults to each line's remaining when a new PO loads.
  const [drafts, setDrafts] = useState<Record<string, string>>({});
  const [draftsForPo, setDraftsForPo] = useState<string | null>(null);

  // Initialise the drafts when a different PO loads — adjusted DURING RENDER (React's recommended
  // pattern for deriving state from props), not in an effect, so it never cascades renders.
  if (po && po.id !== draftsForPo) {
    setDraftsForPo(po.id);
    const initial: Record<string, string> = {};
    for (const line of po.lines) {
      if (line.remaining_qty > 0) initial[line.id] = String(line.remaining_qty);
    }
    setDrafts(initial);
  }

  const receivableLines = (po?.lines ?? []).filter((l) => l.remaining_qty > 0);

  function setDraft(lineId: string, value: string) {
    setDrafts((d) => ({ ...d, [lineId]: value }));
  }

  function receiveAll() {
    if (!po) return;
    const all: Record<string, string> = {};
    for (const line of po.lines) {
      if (line.remaining_qty > 0) all[line.id] = String(line.remaining_qty);
    }
    setDrafts(all);
  }

  function submit() {
    if (!po) return;
    const lines = po.lines
      .map((line) => ({
        purchase_order_line_id: line.id,
        receive_now: clampReceive(drafts[line.id] ?? '', line.remaining_qty),
      }))
      .filter((l) => l.receive_now > 0);

    if (lines.length === 0) return;

    receive.mutate(
      { purchaseOrderId: po.id, lines },
      {
        onSuccess: () => {
          toast.success(t($ => $.receive.success));
          onOpenChange(false);
        },
        onError: () => toast.error(t($ => $.receive.error)),
      },
    );
  }

  const anyToReceive = Object.entries(drafts).some(([id, v]) => {
    const line = po?.lines.find((l) => l.id === id);
    return line ? clampReceive(v, line.remaining_qty) > 0 : false;
  });

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-3xl">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <PackageCheck className="h-5 w-5 text-primary" />
            {t($ => $.receive.title)}
          </DialogTitle>
          <DialogDescription>
            {po?.po_number ?? poNumber ?? ''}
            {po?.supplier ? ` · ${po.supplier.name}` : ''}
            {po?.warehouse ? ` · ${po.warehouse.name}` : ''}
          </DialogDescription>
        </DialogHeader>

        {isLoading ? (
          <div className="flex items-center justify-center h-40 gap-2 text-muted-foreground">
            <Loader2 className="h-4 w-4 animate-spin" />
            <span className="text-sm">{t($ => $.receive.loading)}</span>
          </div>
        ) : isError || !po ? (
          <div className="flex flex-col items-center justify-center h-40 gap-2 text-muted-foreground">
            <AlertTriangle className="h-7 w-7 text-destructive/70" />
            <p className="text-sm">{t($ => $.receive.loadError)}</p>
          </div>
        ) : !po.can_receive ? (
          <div className="flex flex-col items-center justify-center h-40 gap-2 text-muted-foreground">
            <AlertTriangle className="h-7 w-7 text-amber-500" />
            <p className="text-sm">{t($ => $.receive.notReceivable)}</p>
          </div>
        ) : receivableLines.length === 0 ? (
          <div className="flex flex-col items-center justify-center h-40 gap-2 text-muted-foreground">
            <PackageCheck className="h-7 w-7 text-emerald-500" />
            <p className="text-sm">{t($ => $.receive.noReceivable)}</p>
          </div>
        ) : (
          <>
            <div className="flex justify-end">
              <Button variant="outline" size="sm" className="h-7 text-xs" onClick={receiveAll}>
                {t($ => $.receive.receiveAll)}
              </Button>
            </div>
            <div className="max-h-[55vh] overflow-auto rounded-lg border">
              <table className="w-full text-sm">
                <thead className="sticky top-0 bg-muted/60 text-muted-foreground">
                  <tr className="[&>th]:px-3 [&>th]:py-2 [&>th]:text-end [&>th:first-child]:text-start">
                    <th>{t($ => $.receive.columns.product)}</th>
                    <th>{t($ => $.receive.columns.ordered)}</th>
                    <th>{t($ => $.receive.columns.previouslyReceived)}</th>
                    <th>{t($ => $.receive.columns.receiveNow)}</th>
                    <th>{t($ => $.receive.columns.remainingAfter)}</th>
                  </tr>
                </thead>
                <tbody className="divide-y">
                  {po.lines.map((line: ReceivingPoLine) => {
                    const fullyReceived = line.remaining_qty <= 0;
                    const receiveNow = clampReceive(drafts[line.id] ?? '', line.remaining_qty);
                    const remainingAfter = Math.max(0, line.remaining_qty - receiveNow);
                    return (
                      <tr key={line.id} className={`[&>td]:px-3 [&>td]:py-2 [&>td]:text-end [&>td:first-child]:text-start tabular-nums ${fullyReceived ? 'opacity-50' : ''}`}>
                        <td className="text-start">
                          <div className="font-medium">{line.product_name ?? '—'}</div>
                          {line.product_sku && <div className="text-[10px] text-muted-foreground font-mono">{line.product_sku}</div>}
                        </td>
                        <td>{fmt(line.ordered_qty)}</td>
                        <td className="text-muted-foreground">{fmt(line.received_qty)}</td>
                        <td>
                          {fullyReceived ? (
                            <span className="text-muted-foreground">—</span>
                          ) : (
                            <div className="flex justify-end">
                              <Input
                                type="number"
                                inputMode="decimal"
                                min={0}
                                max={line.remaining_qty}
                                value={drafts[line.id] ?? ''}
                                onChange={(e) => setDraft(line.id, e.target.value)}
                                className="no-spinner h-8 w-24 text-end tabular-nums"
                              />
                            </div>
                          )}
                        </td>
                        <td className={remainingAfter > 0 ? 'text-amber-700 dark:text-amber-400' : 'text-muted-foreground'}>
                          {fmt(remainingAfter)}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          </>
        )}

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={receive.isPending}>
            {t($ => $.receive.cancel)}
          </Button>
          <Button
            onClick={submit}
            disabled={receive.isPending || !po?.can_receive || !anyToReceive}
          >
            {receive.isPending ? <Loader2 className="h-4 w-4 animate-spin" /> : t($ => $.receive.submit)}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
