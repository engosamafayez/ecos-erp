import { useState } from 'react';
import { useFormatter } from '@/hooks/use-formatter';
import { CheckCircle, ExternalLink, Loader2 } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet';

import { useWarehouseLiabilityQuery } from '../hooks/use-inventory-count';
import type { WarehouseLiability } from '../types/inventory-count';
import { WasteInvestigationDetailDrawer } from './waste-investigation-detail-drawer';

const fmt = (n: number | null | undefined, decimals = 2) =>
  n == null ? '—' : n.toLocaleString(undefined, { minimumFractionDigits: decimals, maximumFractionDigits: decimals });

const STATUS_BADGE_CLASS: Record<WarehouseLiability['status'], string> = {
  approved: 'text-emerald-600 border-emerald-200 bg-emerald-50 dark:bg-emerald-950/20 text-xs',
  rejected: 'text-destructive border-destructive/30 bg-destructive/10 text-xs',
  pending:  'text-amber-600 border-amber-200 bg-amber-50 dark:bg-amber-950/20 text-xs',
};

type Props = {
  liabilityId: string | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
};

export function WarehouseLiabilityDetailDrawer({ liabilityId, open, onOpenChange }: Props) {
  const { data, isLoading } = useWarehouseLiabilityQuery(liabilityId ?? '');
  const [linkedInvestigationId, setLinkedInvestigationId] = useState<string | null>(null);

  return (
    <>
      <Sheet open={open} onOpenChange={onOpenChange}>
        <SheetContent className="flex flex-col p-0 gap-0 overflow-hidden">
          {isLoading || !data ? (
            <div className="flex-1 flex items-center justify-center">
              <Loader2 className="size-6 animate-spin text-muted-foreground" />
            </div>
          ) : (
            <DrawerContent liability={data} onViewInvestigation={setLinkedInvestigationId} />
          )}
        </SheetContent>
      </Sheet>

      <WasteInvestigationDetailDrawer
        investigationId={linkedInvestigationId}
        open={!!linkedInvestigationId}
        onOpenChange={(o) => {
          if (!o) setLinkedInvestigationId(null);
        }}
      />
    </>
  );
}

function DrawerContent({
  liability,
  onViewInvestigation,
}: {
  liability: WarehouseLiability;
  onViewInvestigation: (id: string) => void;
}) {
  const { t } = useTranslation('inventory-count');
  const { currency } = useFormatter();
  const tAny = t as (key: string, opts?: Record<string, unknown>) => string;

  const statusKey =
    liability.status === 'approved' ? 'approved' : liability.status === 'rejected' ? 'rejected' : 'pending';
  const isDecided = liability.status !== 'pending';

  return (
    <>
      <SheetHeader className="px-6 py-4 border-b shrink-0">
        <div className="flex items-start gap-3">
          <div className="flex-1 min-w-0">
            <SheetTitle className="text-base font-semibold truncate">
              {liability.product?.name ?? t($ => $.liability.detail.header.fallbackTitle)}
            </SheetTitle>
            <SheetDescription className="flex items-center gap-2 mt-1 flex-wrap">
              <Badge variant="outline" className={STATUS_BADGE_CLASS[liability.status]}>
                {t($ => $.liability.detail.header[statusKey])}
              </Badge>
              <span className="text-xs text-muted-foreground">
                {t($ => $.liability.types[liability.liability_type === 'waste_transferred' ? 'waste_transferred' : 'shortage'])}
              </span>
            </SheetDescription>
          </div>
        </div>
      </SheetHeader>

      <div className="flex-1 overflow-auto px-6">
        <div className="space-y-5 py-4">
          {/* Product */}
          <div className="rounded-lg border bg-muted/30 px-4 py-3">
            <p className="text-xs text-muted-foreground mb-1">{t($ => $.liability.detail.summary.product)}</p>
            <p className="font-semibold">{liability.product?.name ?? '—'}</p>
            <p className="text-xs text-muted-foreground">{liability.product?.sku}</p>
          </div>

          {/* Quantities */}
          <div className="grid grid-cols-3 gap-3">
            <div className="rounded-lg border bg-card p-3 text-center">
              <p className="text-xs text-muted-foreground">{t($ => $.liability.detail.summary.quantity)}</p>
              <p className="text-lg font-semibold mt-0.5 tabular-nums">{fmt(liability.quantity, 2)}</p>
            </div>
            <div className="rounded-lg border bg-card p-3 text-center">
              <p className="text-xs text-muted-foreground">{t($ => $.liability.detail.summary.unitCost)}</p>
              <p className="text-lg font-semibold mt-0.5 tabular-nums">
                {liability.cost_snapshot_unit_cost != null ? fmt(liability.cost_snapshot_unit_cost, 4) : fmt(liability.unit_cost, 4)}
              </p>
            </div>
            <div className="rounded-lg border bg-card p-3 text-center">
              <p className="text-xs text-muted-foreground">{t($ => $.liability.detail.summary.totalValue)}</p>
              <p className="text-lg font-semibold mt-0.5 tabular-nums">
                {liability.cost_snapshot_total_value != null ? fmt(liability.cost_snapshot_total_value) : fmt(liability.total_cost)}
              </p>
            </div>
          </div>

          {/* Cost snapshot badge */}
          {liability.cost_method && (
            <div className="flex items-center gap-2 text-xs text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-950/20 rounded-md border border-emerald-200 dark:border-emerald-800 px-3 py-2">
              <CheckCircle className="size-3.5 shrink-0" />
              <span>
                {tAny('liability.detail.summary.fifoSnapshotFull', {
                  method: liability.cost_method ?? 'FIFO',
                  currency: liability.currency ?? currency,
                })}
              </span>
            </div>
          )}

          {/* Details grid */}
          <div className="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
            <div>
              <p className="text-xs text-muted-foreground">{t($ => $.liability.detail.summary.type)}</p>
              <p className="mt-0.5 font-medium">
                {t($ => $.liability.types[liability.liability_type === 'waste_transferred' ? 'waste_transferred' : 'shortage'])}
              </p>
            </div>
            <div>
              <p className="text-xs text-muted-foreground">{t($ => $.liability.detail.summary.warehouse)}</p>
              <p className="mt-0.5 font-medium">{liability.warehouse?.name ?? '—'}</p>
            </div>
            <div>
              <p className="text-xs text-muted-foreground">{t($ => $.liability.detail.summary.manager)}</p>
              <p className="mt-0.5 font-medium">{liability.warehouse_manager ?? '—'}</p>
            </div>
            <div>
              <p className="text-xs text-muted-foreground">{t($ => $.liability.detail.summary.status)}</p>
              <p className="mt-0.5 font-medium">{t($ => $.liability.status[statusKey])}</p>
            </div>
            {isDecided && liability.approved_by && (
              <div>
                <p className="text-xs text-muted-foreground">
                  {t($ => $.liability.detail.summary[liability.status === 'rejected' ? 'rejectedBy' : 'approvedBy'])}
                </p>
                <p className="mt-0.5 font-medium">{liability.approved_by}</p>
              </div>
            )}
            {isDecided && liability.approved_at && (
              <div>
                <p className="text-xs text-muted-foreground">{t($ => $.liability.detail.summary.decisionDate)}</p>
                <p className="mt-0.5 font-medium">{new Date(liability.approved_at).toLocaleDateString()}</p>
              </div>
            )}
            <div>
              <p className="text-xs text-muted-foreground">{t($ => $.liability.detail.summary.month)}</p>
              <p className="mt-0.5 font-medium">{liability.month}</p>
            </div>
          </div>

          {liability.notes && (
            <div>
              <p className="text-xs text-muted-foreground mb-1">{t($ => $.liability.detail.summary.notes)}</p>
              <p className="text-sm rounded-md border bg-muted/30 px-3 py-2">{liability.notes}</p>
            </div>
          )}

          {/* Related waste investigation */}
          <div>
            <p className="text-xs text-muted-foreground mb-1">{t($ => $.liability.detail.summary.sourceInvestigation)}</p>
            {liability.waste_investigation_id ? (
              <Button
                size="sm"
                variant="outline"
                className="gap-1.5"
                onClick={() => onViewInvestigation(liability.waste_investigation_id!)}
              >
                <ExternalLink className="size-3.5" />
                {t($ => $.liability.detail.summary.viewInvestigation)}
              </Button>
            ) : (
              <p className="text-sm text-muted-foreground">{t($ => $.liability.detail.summary.noInvestigation)}</p>
            )}
          </div>

          {/* Future integration readiness */}
          {liability.metadata && Object.keys(liability.metadata).length > 0 && (
            <div>
              <p className="text-xs text-muted-foreground mb-1">{t($ => $.liability.detail.summary.integrationRefs)}</p>
              <div className="text-xs font-mono bg-muted/50 rounded px-3 py-2 space-y-0.5">
                {Object.entries(liability.metadata).map(([k, v]) => (
                  <div key={k}><span className="text-muted-foreground">{k}:</span> {String(v)}</div>
                ))}
              </div>
            </div>
          )}
        </div>
      </div>
    </>
  );
}
