import { CheckCircle2, XCircle } from 'lucide-react';

import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import type { ImportResult } from '@/features/channels/types/channel';

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  result: ImportResult | null;
  channelName?: string;
};

export function ImportResultDialog({ open, onOpenChange, result, channelName }: Props) {
  if (!result) return null;

  const hasErrors = result.errors.length > 0;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>اكتمل الاستيراد</DialogTitle>
          <DialogDescription>
            {channelName ? `نتائج "${channelName}"` : 'نتائج استيراد المنتجات'}
          </DialogDescription>
        </DialogHeader>

        <div className="flex flex-col gap-3">
          <div className="grid grid-cols-2 gap-2">
            <Stat label="المنتجات المعالَجة" value={result.imported} />
            <Stat label="المنتجات المنشأة" value={result.created_products} />
            <Stat label="الربط المنشأ" value={result.created_mappings} />
            <Stat label="التصنيفات المنشأة" value={result.categories_created} />
            <Stat label="التصنيفات المحدَّثة" value={result.categories_updated} />
            <Stat label="فاشل" value={result.failed} highlight={result.failed > 0} />
          </div>

          {hasErrors && (
            <div className="flex flex-col gap-1.5">
              <div className="flex items-center gap-1.5 text-sm font-medium text-rose-600">
                <XCircle className="size-4" />
                أخطاء
              </div>
              <ul className="text-muted-foreground max-h-32 overflow-y-auto rounded-md border p-2 text-xs">
                {result.errors.map((err, i) => (
                  <li key={i} className="py-0.5">
                    {err}
                  </li>
                ))}
              </ul>
            </div>
          )}

          {!hasErrors && result.imported > 0 && (
            <div className="flex items-center gap-1.5 text-sm text-emerald-600">
              <CheckCircle2 className="size-4" />
              تم استيراد جميع المنتجات بنجاح.
            </div>
          )}
        </div>

        <DialogFooter>
          <Button onClick={() => onOpenChange(false)}>إغلاق</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function Stat({ label, value, highlight }: { label: string; value: number; highlight?: boolean }) {
  return (
    <div className="bg-muted/50 flex flex-col rounded-md p-3">
      <span className="text-muted-foreground text-xs">{label}</span>
      <span className={`text-xl font-bold ${highlight ? 'text-rose-600' : ''}`}>{value}</span>
    </div>
  );
}
