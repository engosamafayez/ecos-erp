import { useTranslation } from 'react-i18next';
import { useFormatter } from '@/hooks/use-formatter';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import type {
  SupplierInvoicePayment,
  SupplierInvoicePaymentStatus,
} from '@/features/supplier-invoices/types/supplier-invoice';

const PAYMENT_STATUS_COLORS: Record<SupplierInvoicePaymentStatus, string> = {
  unpaid:         'bg-gray-100 text-gray-700',
  partially_paid: 'bg-amber-100 text-amber-800',
  paid:           'bg-emerald-100 text-emerald-800',
};

/**
 * Derived payment read-model card (§9–§12; AP-PAYMENT-INTEGRATION-001).
 *
 * Shows Total / Paid / Remaining / Status / Due plus the canonical payment HISTORY — each posted
 * supplier payment applied to this invoice's payable through the AP allocation authority. Strictly
 * read-only: recording a payment is a separate Finance/AP flow (finance/ap/payments/*), guarded by
 * its own permissions; this card never writes. Renders compactly for the mobile detail drawer.
 */
export function PaymentSummaryCard({ payment }: { payment: SupplierInvoicePayment }) {
  const { t } = useTranslation('supplier-invoices');
  const fmt = useFormatter();

  return (
    <div className="rounded-lg border p-4 space-y-2">
      <p className="text-xs font-medium text-muted-foreground uppercase">{t($ => $.detail.payment.title)}</p>

      <div className="flex justify-between text-sm">
        <span className="text-muted-foreground">{t($ => $.detail.payment.total)}</span>
        <span className="tabular-nums">{fmt.money(payment.total)}</span>
      </div>
      <div className="flex justify-between text-sm">
        <span className="text-muted-foreground">{t($ => $.detail.payment.paid)}</span>
        <span className="tabular-nums">{fmt.money(payment.paid)}</span>
      </div>
      <div className="flex justify-between text-sm">
        <span className="text-muted-foreground">{t($ => $.detail.payment.remaining)}</span>
        <span className="tabular-nums font-medium">{fmt.money(payment.remaining)}</span>
      </div>
      {payment.due_date && (
        <div className="flex justify-between text-sm">
          <span className="text-muted-foreground">{t($ => $.detail.payment.due)}</span>
          <span className="tabular-nums">{payment.due_date}</span>
        </div>
      )}
      <div className="flex justify-between items-center text-sm">
        <span className="text-muted-foreground">{t($ => $.detail.payment.status)}</span>
        <Badge className={`${PAYMENT_STATUS_COLORS[payment.payment_status]} border-0 text-xs`} variant="secondary">
          {t($ => $.detail.payment.statuses[payment.payment_status])}
        </Badge>
      </div>

      <Separator className="my-1" />

      <p className="text-xs font-medium text-muted-foreground uppercase">{t($ => $.detail.payment.historyTitle)}</p>
      {payment.history.length === 0 ? (
        <p className="text-xs text-muted-foreground">{t($ => $.detail.payment.noHistory)}</p>
      ) : (
        <div className="space-y-1.5" data-testid="payment-history">
          {payment.history.map((entry, i) => (
            <div key={`${entry.payment_number ?? 'pay'}-${i}`} className="flex justify-between items-center gap-2 text-xs">
              <div className="min-w-0">
                <span className="font-mono">{entry.payment_number ?? '—'}</span>
                {entry.payment_date && <span className="text-muted-foreground"> · {entry.payment_date}</span>}
                {entry.payment_status && (
                  <span className="text-muted-foreground uppercase"> · {entry.payment_status}</span>
                )}
              </div>
              <span className="tabular-nums font-medium shrink-0">{fmt.money(entry.amount)}</span>
            </div>
          ))}
        </div>
      )}

      <p className="text-[10px] text-muted-foreground pt-1">{t($ => $.detail.payment.derivedNote)}</p>
      <p className="text-[10px] text-muted-foreground">{t($ => $.detail.payment.apNote)}</p>
    </div>
  );
}
