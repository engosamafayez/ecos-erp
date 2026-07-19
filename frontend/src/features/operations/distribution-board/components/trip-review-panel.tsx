import { CheckCircle2, Package, XCircle } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import type { TripReviewManifest, TripReviewCustody } from '../types/distribution-board';
import { CUSTODY_ITEM_LABELS } from '../types/distribution-board';
import { cn } from '@/lib/utils';

interface Props {
  manifest: TripReviewManifest | null;
  custody: TripReviewCustody;
}

function StatusChip({ status }: { status: string }) {
  const map: Record<string, string> = {
    confirmed: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300',
    shortage:  'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300',
    pending:   'bg-muted text-muted-foreground',
    skipped:   'bg-slate-100 text-slate-600',
  };
  const labels: Record<string, string> = { confirmed: 'مؤكد', shortage: 'نقص', pending: 'قيد الانتظار', skipped: 'تجاوز' };
  return (
    <span className={cn('text-xs px-1.5 py-0.5 rounded-md font-medium', map[status] ?? 'bg-muted text-muted-foreground')}>
      {labels[status] ?? status}
    </span>
  );
}

export function TripReviewPanel({ manifest, custody }: Props) {
  return (
    <div className="space-y-6">
      {/* Manifest */}
      <section>
        <h3 className="text-sm font-semibold mb-3 flex items-center gap-2">
          <Package className="h-4 w-4 text-muted-foreground" />
          المنتجات المحمّلة
          {manifest && (
            <Badge variant="outline" className="text-xs ms-auto">
              {manifest.confirmed_products}/{manifest.total_products} مؤكد
            </Badge>
          )}
        </h3>

        {!manifest ? (
          <p className="text-sm text-muted-foreground">لم يتم إنشاء بيان.</p>
        ) : manifest.items.length === 0 ? (
          <p className="text-sm text-muted-foreground">لا توجد منتجات في البيان.</p>
        ) : (
          <div className="rounded-lg border overflow-hidden">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b bg-muted/40">
                  <th className="px-3 py-2 text-start font-medium text-muted-foreground text-xs">المنتج</th>
                  <th className="px-3 py-2 text-end font-medium text-muted-foreground text-xs">مطلوب</th>
                  <th className="px-3 py-2 text-end font-medium text-muted-foreground text-xs">محمّل</th>
                  <th className="px-3 py-2 text-end font-medium text-muted-foreground text-xs">نقص</th>
                  <th className="px-3 py-2 text-end font-medium text-muted-foreground text-xs">الحالة</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {manifest.items.map((item) => (
                  <tr key={item.id} className="hover:bg-muted/20">
                    <td className="px-3 py-2">
                      <div className="font-medium text-sm leading-tight">{item.product_name}</div>
                      {item.product_sku && (
                        <div className="text-xs text-muted-foreground font-mono">{item.product_sku}</div>
                      )}
                    </td>
                    <td className="px-3 py-2 text-end tabular-nums">{item.required_qty}</td>
                    <td className="px-3 py-2 text-end tabular-nums">{item.loaded_qty ?? '—'}</td>
                    <td className="px-3 py-2 text-end tabular-nums text-red-600 dark:text-red-400">
                      {item.shortage_qty ? item.shortage_qty : '—'}
                    </td>
                    <td className="px-3 py-2 text-end">
                      <StatusChip status={item.status} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {/* Custody */}
      {custody.total > 0 && (
        <section>
          <h3 className="text-sm font-semibold mb-3 flex items-center gap-2">
            العهدة
            <Badge variant="outline" className="text-xs ms-auto">
              {custody.confirmed}/{custody.total} مؤكدة
            </Badge>
          </h3>

          <div className="space-y-2">
            {custody.items.map((item) => (
              <div key={item.id} className="flex items-center gap-3 p-3 rounded-lg border">
                {item.is_driver_confirmed ? (
                  <CheckCircle2 className="h-4 w-4 text-emerald-500 shrink-0" />
                ) : (
                  <XCircle className="h-4 w-4 text-muted-foreground shrink-0" />
                )}
                <div className="flex-1 min-w-0">
                  <div className="text-sm font-medium">{item.label}</div>
                  <div className="text-xs text-muted-foreground">
                    {CUSTODY_ITEM_LABELS[item.item_type] ?? item.item_type} · الكمية: {item.quantity}
                    {item.received_quantity !== null && ` · المستلم: ${item.received_quantity}`}
                  </div>
                </div>
                {item.is_driver_confirmed ? (
                  <Badge className="text-xs bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300">
                    مؤكد
                  </Badge>
                ) : (
                  <Badge variant="outline" className="text-xs">قيد الانتظار</Badge>
                )}
              </div>
            ))}
          </div>
        </section>
      )}

      {custody.total === 0 && (
        <section>
          <h3 className="text-sm font-semibold mb-2">العهدة</h3>
          <p className="text-sm text-muted-foreground">لا توجد عهدة مُعيَّنة لهذه الرحلة.</p>
        </section>
      )}
    </div>
  );
}
