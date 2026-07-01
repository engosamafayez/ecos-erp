import { Keyboard, X } from 'lucide-react';

import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Separator } from '@/components/ui/separator';
import { usePosStore } from '@/features/pos/store/pos-store';

type Shortcut = { keys: string[]; description: string };

const SHORTCUTS: Shortcut[] = [
  { keys: ['Ctrl', 'N'],     description: 'New sale' },
  { keys: ['F8'],            description: 'Open payment' },
  { keys: ['F9'],            description: 'Hold cart' },
  { keys: ['Escape'],        description: 'Cancel / close dialog' },
  { keys: ['Ctrl', 'R'],     description: 'Switch to Return mode' },
  { keys: ['Ctrl', 'E'],     description: 'Switch to Exchange mode' },
  { keys: ['Alt', '1'],      description: 'Switch to Sale mode' },
  { keys: ['Ctrl', 'M'],     description: 'Manager view' },
  { keys: ['/'],             description: 'Focus product search' },
  { keys: ['Shift', '?'],    description: 'Toggle this help panel' },
  { keys: ['Enter'],         description: 'Add scanned barcode item' },
];

export function KeyboardHelp() {
  const { keyboardHelpOpen, toggleKeyboardHelp } = usePosStore();

  return (
    <Dialog open={keyboardHelpOpen} onOpenChange={toggleKeyboardHelp}>
      <DialogContent className="max-w-sm">
        <DialogHeader>
          <div className="flex items-center gap-2">
            <Keyboard className="size-5" />
            <DialogTitle>Keyboard Shortcuts</DialogTitle>
          </div>
        </DialogHeader>

        <div className="space-y-1.5">
          {SHORTCUTS.map((shortcut, i) => (
            <div key={i} className="flex items-center justify-between py-1">
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

        <Separator />

        <p className="text-xs text-muted-foreground text-center">
          Press <kbd className="rounded border bg-muted px-1 py-0.5 font-mono text-xs">?</kbd> to toggle
        </p>
      </DialogContent>
    </Dialog>
  );
}
