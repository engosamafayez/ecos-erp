import { useState } from 'react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { SHORTAGE_RESOLUTION_LABELS, type ShortageResolution } from '../types/distribution-board';

const RESOLUTION_DESCRIPTIONS: Record<ShortageResolution, string> = {
  priority_allocation: 'إعادة توزيع من طلب آخر وفق قواعد الأولوية.',
  manual_selection:    'يختار المشرف يدوياً الطلبات التي ستحصل على المنتج.',
  return_preparation:  'إرجاع إلى نظام التحضير لتغطية النقص.',
  send_manufacturing:  'إرسال أمر إنتاج إلى التصنيع.',
  delay_orders:        'تحديد الطلبات المتأثرة كمؤجلة وإشعار العملاء.',
};

interface ShortageResolutionDialogProps {
  open: boolean;
  onClose: () => void;
  productName: string;
  shortageQty: number;
  unit: string;
  onResolve: (resolution: ShortageResolution, notes?: string) => void;
  isPending: boolean;
}

export function ShortageResolutionDialog({
  open,
  onClose,
  productName,
  shortageQty,
  unit,
  onResolve,
  isPending,
}: ShortageResolutionDialogProps) {
  const [selected, setSelected] = useState<ShortageResolution | null>(null);
  const [notes, setNotes]       = useState('');

  const resolutions = Object.keys(SHORTAGE_RESOLUTION_LABELS) as ShortageResolution[];

  function handleSubmit() {
    if (!selected) return;
    onResolve(selected, notes || undefined);
  }

  return (
    <Dialog open={open} onOpenChange={(v) => { if (!v) onClose(); }}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle className="text-red-600 dark:text-red-400">معالجة النقص</DialogTitle>
          <DialogDescription>
            <span className="font-medium">{productName}</span> — نقص مقداره{' '}
            <span className="font-semibold text-red-600 dark:text-red-400">
              {shortageQty} {unit}
            </span>
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-3 py-1">
          <p className="text-xs text-muted-foreground">اختر كيفية معالجة هذا النقص:</p>

          <div className="space-y-2">
            {resolutions.map((res) => (
              <button
                key={res}
                onClick={() => setSelected(res)}
                className={cn(
                  'w-full text-start px-3 py-2.5 rounded-lg border text-sm transition-colors',
                  selected === res
                    ? 'border-primary bg-primary/5 dark:bg-primary/10'
                    : 'hover:bg-muted/50',
                )}
              >
                <p className="font-medium">{SHORTAGE_RESOLUTION_LABELS[res]}</p>
                <p className="text-xs text-muted-foreground mt-0.5">{RESOLUTION_DESCRIPTIONS[res]}</p>
              </button>
            ))}
          </div>

          <div className="space-y-1.5">
            <label className="text-xs font-medium text-muted-foreground">ملاحظات (اختياري)</label>
            <Textarea
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              placeholder="سياق إضافي أو تعليمات…"
              rows={2}
              className="text-sm resize-none"
            />
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" size="sm" onClick={onClose} disabled={isPending}>
            إلغاء
          </Button>
          <Button
            size="sm"
            disabled={!selected || isPending}
            onClick={handleSubmit}
          >
            تطبيق الحل
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
