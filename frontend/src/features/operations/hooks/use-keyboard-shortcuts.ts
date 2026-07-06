import { useEffect } from 'react';

type ShortcutMap = {
  /** N — New Wave */
  onNew?: () => void;
  /** R — Refresh */
  onRefresh?: () => void;
  /** F — Toggle filter / focus search */
  onFilter?: () => void;
  /** Escape — Close/deselect */
  onEscape?: () => void;
  /** Ctrl+E — Export */
  onExport?: () => void;
  /** ? — Open shortcuts help */
  onHelp?: () => void;
};

/**
 * Registers keyboard shortcuts for Preparation OS pages.
 * Automatically ignores shortcuts when focus is inside an input, textarea, or select.
 */
export function useKeyboardShortcuts(shortcuts: ShortcutMap) {
  useEffect(() => {
    function handler(e: KeyboardEvent) {
      const target = e.target as HTMLElement;
      const inInput = ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName)
        || target.isContentEditable;

      if (inInput && e.key !== 'Escape') return;

      if (e.ctrlKey && e.key === 'e') {
        e.preventDefault();
        shortcuts.onExport?.();
        return;
      }

      if (e.ctrlKey || e.altKey || e.metaKey) return;

      switch (e.key) {
        case 'n':
        case 'N':
          shortcuts.onNew?.();
          break;
        case 'r':
        case 'R':
          shortcuts.onRefresh?.();
          break;
        case 'f':
        case 'F':
          shortcuts.onFilter?.();
          break;
        case 'Escape':
          shortcuts.onEscape?.();
          break;
        case '?':
          shortcuts.onHelp?.();
          break;
      }
    }

    window.addEventListener('keydown', handler);
    return () => window.removeEventListener('keydown', handler);
  }, [shortcuts]);
}
