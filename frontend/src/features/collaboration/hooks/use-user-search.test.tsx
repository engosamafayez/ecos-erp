import type { ReactNode } from 'react';
import { act, renderHook } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('../services/collaboration-service', () => ({
  searchAddressableUsers: vi.fn(),
}));

import { searchAddressableUsers } from '../services/collaboration-service';
import type { AddressableUser } from '../types';
import { useUserSearch } from './use-user-search';

const mockSearch = searchAddressableUsers as unknown as ReturnType<typeof vi.fn>;

// The client must stay the SAME instance across re-renders — creating it inline inside
// `wrapper` would hand useQuery a brand-new (empty) cache on every re-render triggered by
// the debounce state update, discarding whatever fetch was already in flight.
let queryClient: QueryClient;

function wrapper({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;
}

describe('useUserSearch', () => {
  beforeEach(() => {
    mockSearch.mockReset();
    mockSearch.mockResolvedValue([]);
    queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    vi.useFakeTimers();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('never calls searchAddressableUsers for fewer than 2 characters, even after the debounce window passes', async () => {
    const { result } = renderHook(() => useUserSearch(), { wrapper });

    act(() => {
      result.current.setQuery('a');
    });
    await act(async () => {
      await vi.advanceTimersByTimeAsync(300);
    });

    expect(mockSearch).not.toHaveBeenCalled();
    expect(result.current.isQueryTooShort).toBe(true);
    expect(result.current.results).toEqual([]);
  });

  it('queries with the trimmed value once 2+ characters have settled for 300ms, and exposes the resolved results', async () => {
    const users: AddressableUser[] = [{ id: 1, name: 'Ali Hassan', is_driver: false }];
    mockSearch.mockResolvedValue(users);
    const { result } = renderHook(() => useUserSearch(), { wrapper });

    act(() => {
      result.current.setQuery('  ali  ');
    });
    await act(async () => {
      await vi.advanceTimersByTimeAsync(300);
    });
    // react-query's notifyManager defers the observer's onStoreChange notification
    // through a real `setTimeout(fn, 0)` (systemSetTimeoutZero) — under fake timers
    // that macrotask needs an explicit further advance before React re-renders with
    // the resolved cache data, even though the queryFn promise itself already settled.
    await act(async () => {
      await vi.runAllTimersAsync();
    });

    expect(mockSearch).toHaveBeenCalledTimes(1);
    expect(mockSearch).toHaveBeenCalledWith('ali');
    expect(result.current.results).toEqual(users);
    expect(result.current.isQueryTooShort).toBe(false);
    expect(result.current.isSearching).toBe(false);
  });

  it('only fires one network call for the last of several rapid keystrokes inside the debounce window', async () => {
    const { result } = renderHook(() => useUserSearch(), { wrapper });

    act(() => {
      result.current.setQuery('ab');
    });
    await act(async () => {
      await vi.advanceTimersByTimeAsync(100);
    });
    expect(mockSearch).not.toHaveBeenCalled();

    act(() => {
      result.current.setQuery('abc');
    });
    await act(async () => {
      await vi.advanceTimersByTimeAsync(300);
    });

    expect(mockSearch).toHaveBeenCalledTimes(1);
    expect(mockSearch).toHaveBeenCalledWith('abc');
  });
});
