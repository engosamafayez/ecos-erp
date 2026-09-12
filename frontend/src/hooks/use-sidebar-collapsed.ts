import { useState } from 'react';

/**
 * Persists the canonical AppSidebar's collapsed/expanded preference across
 * reloads (TASK-ECOS-V1.1-CORE-01-UI-02-SHELL-NAVIGATION-046 §5 — the shell
 * already collapsed/expanded correctly, it just forgot the choice on reload).
 *
 * Per-device UI convenience only, the same class of state as `ecos-theme`
 * (theme-provider.tsx) and `ecos.nav.recent.v1` (use-recent-nav.ts) — not
 * business data, so a plain localStorage boolean is enough; no server
 * round-trip, no new backend state.
 */
const STORAGE_KEY = 'ecos.shell.sidebar-collapsed.v1';

function readStored(): boolean {
  try {
    return window.localStorage.getItem(STORAGE_KEY) === '1';
  } catch {
    return false;
  }
}

function writeStored(value: boolean): void {
  try {
    window.localStorage.setItem(STORAGE_KEY, value ? '1' : '0');
  } catch {
    // Storage unavailable (private mode, quota, disabled) — the preference
    // simply doesn't survive reload this time. Never throw for this.
  }
}

export function useSidebarCollapsed(): [boolean, (next: boolean) => void] {
  const [collapsed, setCollapsedState] = useState<boolean>(() => readStored());

  function setCollapsed(next: boolean) {
    writeStored(next);
    setCollapsedState(next);
  }

  return [collapsed, setCollapsed];
}
