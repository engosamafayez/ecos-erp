import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import { useSidebarCollapsed } from './use-sidebar-collapsed';

const STORAGE_KEY = 'ecos.shell.sidebar-collapsed.v1';

/**
 * TASK-ECOS-V1.1-CORE-01-UI-02-SHELL-NAVIGATION-046 §5 — required proof:
 * collapse → navigate (i.e. unmount/remount the hook, as a route change
 * would) → reload (a fresh renderHook, simulating a full page reload reading
 * from scratch) → persisted state remains correct.
 */
describe('useSidebarCollapsed', () => {
  beforeEach(() => {
    window.localStorage.clear();
  });

  afterEach(() => {
    window.localStorage.clear();
  });

  it('defaults to expanded (not collapsed) with nothing stored', () => {
    const { result } = renderHook(() => useSidebarCollapsed());
    expect(result.current[0]).toBe(false);
  });

  it('collapsing updates state immediately and writes through to localStorage', () => {
    const { result } = renderHook(() => useSidebarCollapsed());
    act(() => result.current[1](true));
    expect(result.current[0]).toBe(true);
    expect(window.localStorage.getItem(STORAGE_KEY)).toBe('1');
  });

  it('persists across "navigate" (unmount/remount) and "reload" (a fresh hook instance)', () => {
    const first = renderHook(() => useSidebarCollapsed());
    act(() => first.result.current[1](true));
    first.unmount(); // simulates a route change unmounting AppShell's tree

    const second = renderHook(() => useSidebarCollapsed()); // simulates a fresh page load
    expect(second.result.current[0]).toBe(true);
  });

  it('expanding again persists the reverted preference', () => {
    const first = renderHook(() => useSidebarCollapsed());
    act(() => first.result.current[1](true));
    first.unmount();

    const second = renderHook(() => useSidebarCollapsed());
    act(() => second.result.current[1](false));
    second.unmount();

    const third = renderHook(() => useSidebarCollapsed());
    expect(third.result.current[0]).toBe(false);
    expect(window.localStorage.getItem(STORAGE_KEY)).toBe('0');
  });

  it('never throws when localStorage access fails (private mode / quota / disabled)', () => {
    const original = window.localStorage.getItem;
    window.localStorage.getItem = () => {
      throw new DOMException('blocked');
    };
    try {
      expect(() => renderHook(() => useSidebarCollapsed())).not.toThrow();
    } finally {
      window.localStorage.getItem = original;
    }
  });
});
