import { Keyboard } from 'lucide-react';

import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Separator } from '@/components/ui/separator';
import { usePosStore } from '@/features/pos/store/pos-store';

type Shortcut = { keys: string[]; description: string };

const SHORTCUT_GROUPS: { heading: string; shortcuts: Shortcut[] }[] = [
  {
    heading: 'المعاملات',
    shortcuts: [
      { keys: ['Ctrl', 'N'],  description: 'بيع جديد' },
      { keys: ['F8'],         description: 'فتح الدفع' },
      { keys: ['F9'],         description: 'حفظ السلة' },
      { keys: ['Ctrl', 'H'], description: 'عرض السلات المحفوظة' },
      { keys: ['Escape'],     description: 'إلغاء / إغلاق اللوحة' },
    ],
  },
  {
    heading: 'الأوضاع',
    shortcuts: [
      { keys: ['Alt', '1'],   description: 'وضع البيع' },
      { keys: ['Ctrl', 'R'],  description: 'وضع المرتجعات' },
      { keys: ['Ctrl', 'E'],  description: 'وضع الاستبدال' },
      { keys: ['Ctrl', 'M'],  description: 'عرض المدير' },
    ],
  },
  {
    heading: 'التنقل',
    shortcuts: [
      { keys: ['/'],          description: 'البحث عن منتج' },
      { keys: ['Enter'],      description: 'تأكيد قراءة الباركود' },
      { keys: ['Shift', '?'], description: 'تبديل لوحة المساعدة' },
    ],
  },
];

export function KeyboardHelp() {
  const { keyboardHelpOpen, toggleKeyboardHelp } = usePosStore();

  return (
    <Dialog open={keyboardHelpOpen} onOpenChange={toggleKeyboardHelp}>
      <DialogContent className="max-w-sm">
        <DialogHeader>
          <div className="flex items-center gap-2">
            <Keyboard className="size-5" />
            <DialogTitle>اختصارات لوحة المفاتيح</DialogTitle>
          </div>
        </DialogHeader>

        <div className="space-y-4">
          {SHORTCUT_GROUPS.map((group) => (
            <div key={group.heading}>
              <p className="mb-1.5 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                {group.heading}
              </p>
              <div className="space-y-1">
                {group.shortcuts.map((shortcut, i) => (
                  <div key={i} className="flex items-center justify-between py-0.5">
                    <span className="text-sm text-muted-foreground">{shortcut.description}</span>
                    <div className="flex items-center gap-1">
                      {shortcut.keys.map((key) => (
                        <kbd
                          key={key}
                          className="rounded border bg-muted px-1.5 py-0.5 font-mono text-xs font-medium"
                        >
                          {key}
                        </kbd>
                      ))}
                    </div>
                  </div>
                ))}
              </div>
            </div>
          ))}
        </div>

        <Separator />

        <p className="text-xs text-muted-foreground text-center">
          اضغط <kbd className="rounded border bg-muted px-1 py-0.5 font-mono text-xs">?</kbd> للتبديل
        </p>
      </DialogContent>
    </Dialog>
  );
}
