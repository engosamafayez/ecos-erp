import { useTranslation } from 'react-i18next';
import { ExternalLink } from 'lucide-react';

import { useFormatter } from '@/hooks/use-formatter';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import type {
  SupplierInvoiceReceiving,
  SupplierInvoiceReceivingStatus,
} from '@/features/supplier-invoices/types/supplier-invoice';

const RECEIVING_STATUS_COLORS: Record<SupplierInvoiceReceivingStatus, string> = {
  not_applicable:     'bg-gray-100 text-gray-700',
  awaiting:           'bg-amber-100 text-amber-800',
  partially_received: 'bg-blue-100 text-blue-800',
  reconciled:         'bg-emerald-100 text-emerald-800',
};

/**
 * Derived invoice-first receiving read-model card (TASK-...-014). Shows the auto-linked Goods
 * Receipt's identity/status plus per-line invoiced vs accepted qty and variance — strictly
 * read-only; recording quantities happens on the Goods Receipt itself via `onOpenReceipt`.
 * Renders in place of the generic receipt-links block only when a receipt was auto-created for
 * this invoice (§7 — Goods Receipt stays the sole receiving surface, no duplicate panel here).
 */
export function ReceivingSummaryCard({
  receiving,
  onOpenReceipt,
}: {
  receiving: SupplierInvoiceReceiving;
  onOpenReceipt: (receiptId: string) => void;
}) {
  const { t } = useTranslation('supplier-invoices');
  const fmt = useFormatter();

  return (
    <div className="rounded-lg border p-4 space-y-2">
      <div className="flex items-center justify-between">
        <p className="text-xs font-medium text-muted-foreground uppercase">{t($ => $.detail.receiving.title)}</p>
        <Badge className={`${RECEIVING_STATUS_COLORS[receiving.status]} border-0 text-xs`} variant="secondary">
          {t($ => $.detail.receiving.statuses[receiving.status])}
        </Badge>
      </div>

      {receiving.receipt_number && (
        <div className="flex justify-between items-center text-sm">
          <span className="text-muted-foreground">{t($ => $.detail.receiving.receiptNumber)}</span>
          <div className="flex items-center gap-2">
            <span className="font-mono">{receiving.receipt_number}</span>
            {receiving.receipt_id && (
              <Button
                size="sm"
                variant="outline"
                className="h-6 px-2 gap-1 text-xs"
                onClick={() => onOpenReceipt(receiving.receipt_id as string)}
              >
                <ExternalLink className="w-3 h-3" />
                {t($ => $.detail.receiving.openReceipt)}
              </Button>
            )}
          </div>
        </div>
      )}

      <Separator className="my-1" />

      {receiving.lines.length === 0 ? (
        <p className="text-xs text-muted-foreground">{t($ => $.detail.receiving.noLines)}</p>
      ) : (
        <div className="space-y-1.5" data-testid="receiving-lines">
          {receiving.lines.map((line) => (
            <div key={line.line_id} className="text-xs space-y-1 p-2 rounded border">
              <div className="flex justify-between gap-2">
                <span className="font-medium truncate">{line.product_name ?? '—'}</span>
                {line.sku && <span className="text-muted-foreground shrink-0">{line.sku}</span>}
              </div>
              <div className="grid grid-cols-3 gap-2 tabular-nums">
                <div><span className="text-muted-foreground">{t($ => $.detail.receiving.expected)}: </span>{line.expected_qty}</div>
                <div><span className="text-muted-foreground">{t($ => $.detail.receiving.accepted)}: </span>{line.accepted_qty}</div>
                <div className={line.variance < 0 ? 'text-amber-600' : line.variance > 0 ? 'text-destructive' : 'text-muted-foreground'}>
                  <span className="text-muted-foreground">{t($ => $.detail.receiving.variance)}: </span>
                  {line.variance > 0 ? '+' : ''}{line.variance}
                </div>
              </div>
              {line.final_landed_unit_cost !== null && (
                <p className="text-blue-600">
                  {t($ => $.detail.receiving.landedCost, { value: fmt.money(line.final_landed_unit_cost) })}
                </p>
              )}
            </div>
          ))}
        </div>
      )}

      {receiving.status !== 'not_applicable' && !receiving.ready_to_post && (
        <p className="text-[10px] text-amber-700 pt-1">{t($ => $.detail.receiving.notReadyHint)}</p>
      )}
    </div>
  );
}
