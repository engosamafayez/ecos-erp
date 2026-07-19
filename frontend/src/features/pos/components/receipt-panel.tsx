import { Plus, Printer, X } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { Badge } from '@/components/ui/badge';
import { useReceipt, useReprintReceipt } from '@/features/pos/hooks/use-pos-queries';
import { usePosStore } from '@/features/pos/store/pos-store';

type ReceiptPanelProps = {
  receiptId?: string | null;
  onClose: () => void;
  onNewSale?: () => void;
};

export function ReceiptPanel({ receiptId, onClose, onNewSale }: ReceiptPanelProps) {
  const { lastReceiptId } = usePosStore();
  const id = receiptId ?? lastReceiptId;
  const { data: receipt, isLoading } = useReceipt(id);
  const reprint = useReprintReceipt();

  if (!id) return null;

  const RECEIPT_TYPE_LABELS: Record<string, string> = {
    sale:     'بيع',
    return:   'مرتجع',
    exchange: 'استبدال',
    void:     'ملغى',
    reprint:  'إعادة طباعة',
  };

  return (
    <div className="flex flex-col h-full">
      {/* Header */}
      <div className="flex items-center justify-between px-4 py-3 shrink-0">
        <h2 className="text-base font-semibold">الإيصال</h2>
        <div className="flex items-center gap-1">
          <Button
            variant="ghost"
            size="icon"
            className="size-8"
            title="Reprint"
            disabled={reprint.isPending || !receipt}
            onClick={() => receipt && reprint.mutate(receipt.id)}
          >
            <Printer className="size-4" />
          </Button>
          <Button variant="ghost" size="icon" className="size-8" onClick={onClose}>
            <X className="size-4" />
          </Button>
        </div>
      </div>

      <Separator />

      {isLoading ? (
        <div className="flex-1 flex items-center justify-center text-sm text-muted-foreground">
          جارٍ تحميل الإيصال...
        </div>
      ) : !receipt ? (
        <div className="flex-1 flex items-center justify-center text-sm text-muted-foreground">
          الإيصال غير موجود
        </div>
      ) : (
        <div className="flex-1 overflow-y-auto px-4 py-3 space-y-4 font-mono text-sm">
          {/* Store header */}
          <div className="text-center space-y-0.5">
            <p className="font-bold text-base">ECOS ERP</p>
            <p className="text-xs text-muted-foreground">الطرفية: {receipt.terminal_id}</p>
            <Badge variant="outline" className="text-[10px]">
              {RECEIPT_TYPE_LABELS[receipt.type] ?? receipt.type}
            </Badge>
          </div>

          <Separator />

          {/* Receipt info */}
          <div className="space-y-0.5 text-xs text-muted-foreground">
            <div className="flex justify-between">
              <span>إيصال #</span>
              <span>{receipt.receipt_number}</span>
            </div>
            <div className="flex justify-between">
              <span>التاريخ</span>
              <span>{new Date(receipt.issued_at).toLocaleString('ar-EG')}</span>
            </div>
            {receipt.cashier_name && (
              <div className="flex justify-between">
                <span>الكاشير</span>
                <span>{receipt.cashier_name}</span>
              </div>
            )}
            {receipt.customer_name && (
              <div className="flex justify-between">
                <span>العميل</span>
                <span>{receipt.customer_name}</span>
              </div>
            )}
          </div>

          <Separator />

          {/* Line items */}
          <div className="space-y-1.5">
            {receipt.line_items.map((item, i) => (
              <div key={i} className="space-y-0.5">
                <div className="flex justify-between font-medium text-xs">
                  <span className="flex-1 truncate">{item.product_name}</span>
                  <span className="ml-2 tabular-nums">{item.line_total.amount}</span>
                </div>
                <div className="text-[10px] text-muted-foreground">
                  {item.sku} × {item.quantity} @ {item.unit_price.amount}
                </div>
              </div>
            ))}
          </div>

          <Separator />

          {/* Totals */}
          <div className="space-y-0.5 text-xs">
            <div className="flex justify-between text-muted-foreground">
              <span>المجموع الجزئي</span>
              <span className="tabular-nums">{receipt.totals.subtotal.amount}</span>
            </div>
            {parseFloat(receipt.totals.discount.amount) > 0 && (
              <div className="flex justify-between text-emerald-600">
                <span>الخصم</span>
                <span className="tabular-nums">-{receipt.totals.discount.amount}</span>
              </div>
            )}
            {parseFloat(receipt.totals.tax.amount) > 0 && (
              <div className="flex justify-between text-muted-foreground">
                <span>الضريبة</span>
                <span className="tabular-nums">{receipt.totals.tax.amount}</span>
              </div>
            )}
            <div className="flex justify-between font-bold text-sm">
              <span>الإجمالي</span>
              <span className="tabular-nums">{receipt.currency} {receipt.totals.total.amount}</span>
            </div>
          </div>

          <Separator />

          {/* Payments */}
          <div className="space-y-0.5 text-xs text-muted-foreground">
            {receipt.payments.map((p, i) => (
              <div key={i} className="flex justify-between">
                <span className="capitalize">{p.method.replace('_', ' ')}</span>
                <span className="tabular-nums">{p.amount.amount}</span>
              </div>
            ))}
            <div className="flex justify-between">
              <span>المبلغ المدفوع</span>
              <span className="tabular-nums">{receipt.totals.tendered.amount}</span>
            </div>
            <div className="flex justify-between font-medium text-foreground">
              <span>الباقي</span>
              <span className="tabular-nums">{receipt.totals.change.amount}</span>
            </div>
          </div>

          {/* Void notice */}
          {receipt.is_voided && (
            <>
              <Separator />
              <div className="rounded bg-destructive/10 px-3 py-2 text-center text-xs text-destructive font-semibold">
                ملغى — {receipt.void_reason}
              </div>
            </>
          )}

          {/* Reprint notice */}
          {receipt.type === 'reprint' && (
            <p className="text-center text-[10px] text-muted-foreground">*** إعادة طباعة ***</p>
          )}
        </div>
      )}

      {/* New Sale CTA */}
      {receipt && (
        <>
          <Separator />
          <div className="px-4 py-3 shrink-0">
            <Button
              className="w-full gap-2"
              size="lg"
              onClick={onNewSale ?? onClose}
            >
              <Plus className="size-4" />
              بيع جديد
              <kbd className="ms-auto rounded border border-primary-foreground/30 bg-primary-foreground/10 px-1.5 py-0.5 font-mono text-[10px]">
                Ctrl+N
              </kbd>
            </Button>
          </div>
        </>
      )}
    </div>
  );
}
