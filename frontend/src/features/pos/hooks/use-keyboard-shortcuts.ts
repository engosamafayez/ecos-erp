import { useEffect, useCallback } from 'react';

export type ShortcutHandler = (e: KeyboardEvent) => void;

export type KeyboardShortcut = {
  key: string;
  ctrl?: boolean;
  alt?: boolean;
  shift?: boolean;
  description: string;
  handler: ShortcutHandler;
};

function matches(e: KeyboardEvent, shortcut: KeyboardShortcut): boolean {
  const keyMatch = e.key.toLowerCase() === shortcut.key.toLowerCase();
  const ctrlMatch = !!shortcut.ctrl === (e.ctrlKey || e.metaKey);
  const altMatch = !!shortcut.alt === e.altKey;
  const shiftMatch = !!shortcut.shift === e.shiftKey;
  return keyMatch && ctrlMatch && altMatch && shiftMatch;
}

export function useKeyboardShortcuts(shortcuts: KeyboardShortcut[], enabled = true) {
  const handler = useCallback(
    (e: KeyboardEvent) => {
      if (!enabled) return;

      // Don't intercept when typing in inputs
      const tag = (e.target as HTMLElement)?.tagName;
      if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') {
        // Allow Escape to bubble even from inputs
        if (e.key !== 'Escape') return;
      }

      for (const shortcut of shortcuts) {
        if (matches(e, shortcut)) {
          e.preventDefault();
          shortcut.handler(e);
          break;
        }
      }
    },
    [shortcuts, enabled],
  );

  useEffect(() => {
    window.addEventListener('keydown', handler);
    return () => window.removeEventListener('keydown', handler);
  }, [handler]);
}

/** Canonical POS keyboard shortcut definitions (for display in help panel). */
export const POS_SHORTCUTS = {
  newSale:      { key: 'n',      ctrl: true,  description: 'New sale' },
  payment:      { key: 'F8',                  description: 'Open payment' },
  holdCart:     { key: 'F9',                  description: 'Hold cart' },
  cancelCart:   { key: 'Escape',              description: 'Cancel / close' },
  returnMode:   { key: 'r',      ctrl: true,  description: 'Return mode' },
  exchangeMode: { key: 'e',      ctrl: true,  description: 'Exchange mode' },
  saleMode:     { key: '1',      alt:  true,  description: 'Sale mode' },
  managerMode:  { key: 'm',      ctrl: true,  description: 'Manager view' },
  searchFocus:  { key: '/',                   description: 'Focus product search' },
  keyboardHelp: { key: '?',      shift: true, description: 'Keyboard shortcuts help' },
} as const;
