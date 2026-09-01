import { useCallback, useSyncExternalStore } from 'react';

import type { ModuleId, NavItemKey } from '@/config/module-navigation';

/**
 * Recently-visited navigation destinations for the mobile Modules launcher
 * (TASK-ECOS-MOBILE-UX-COMPLETION-002, parent design report §5 "Recent" row).
 *
 * Per-device convenience only — a `localStorage` list of the last few
 * destinations the CURRENT user opened through the mobile navigation, so they
 * can jump back without re-drilling into a module. It carries no business data
 * and no permission decision: every recorded path was already an authorized
 * navigation the user actually took, and a stale/renamed entry is simply a
 * dead link the next click harmlessly hits 404 on, exactly as a browser
 * bookmark would.
 */

export type RecentNavEntry = {
  moduleId: ModuleId;
  itemKey?: NavItemKey;
  path: string;
  ts: number;
};

const STORAGE_KEY = 'ecos.nav.recent.v1';
const MAX_ENTRIES = 5;

function readAll(): RecentNavEntry[] {
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY);
    if (!raw) return [];
    const parsed = JSON.parse(raw);
    if (!Array.isArray(parsed)) return [];
    return parsed.filter(
      (e): e is RecentNavEntry =>
        e && typeof e === 'object' && typeof e.path === 'string' && typeof e.moduleId === 'string',
    );
  } catch {
    return [];
  }
}

function writeAll(entries: RecentNavEntry[]): void {
  try {
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(entries));
  } catch {
    // Storage unavailable (private mode, quota, disabled) — the launcher simply
    // shows no Recent row next time. Never throw for a convenience feature.
  }
}

// A tiny external-store so every hook instance re-renders when another one
// records a visit, without lifting state into a provider for a single row.
const listeners = new Set<() => void>();

function subscribe(onChange: () => void): () => void {
  listeners.add(onChange);
  return () => listeners.delete(onChange);
}

function getSnapshot(): string {
  try {
    return window.localStorage.getItem(STORAGE_KEY) ?? '';
  } catch {
    return '';
  }
}

function getServerSnapshot(): string {
  return '';
}

export function useRecentNav(): {
  recent: RecentNavEntry[];
  recordVisit: (entry: Omit<RecentNavEntry, 'ts'>) => void;
} {
  useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot);

  const recordVisit = useCallback((entry: Omit<RecentNavEntry, 'ts'>) => {
    const existing = readAll().filter((e) => e.path !== entry.path);
    const next = [{ ...entry, ts: Date.now() }, ...existing].slice(0, MAX_ENTRIES);
    writeAll(next);
    listeners.forEach((l) => l());
  }, []);

  return { recent: readAll(), recordVisit };
}
