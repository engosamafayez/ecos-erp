import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { useRouteReplay } from './use-route-replay';

/**
 * TASK-ECOS-SHIPPING-OS-REDESIGN-004 §18/§19 — Route Replay must move
 * through REAL recorded samples one at a time, in order, never simulating or
 * interpolating a position that was not actually recorded.
 */
describe('useRouteReplay', () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('starts stopped at the first sample', () => {
    const { result } = renderHook(() => useRouteReplay(5));

    expect(result.current.index).toBe(0);
    expect(result.current.isPlaying).toBe(false);
  });

  it('advances one sample at a time while playing, never skipping or interpolating', () => {
    const { result } = renderHook(() => useRouteReplay(5));

    act(() => result.current.play());
    expect(result.current.isPlaying).toBe(true);

    act(() => vi.advanceTimersByTime(800));
    expect(result.current.index).toBe(1);

    act(() => vi.advanceTimersByTime(800));
    expect(result.current.index).toBe(2);
  });

  it('stops automatically at the last sample instead of looping or fabricating further movement', () => {
    const { result } = renderHook(() => useRouteReplay(3));

    act(() => result.current.play());
    act(() => vi.advanceTimersByTime(800 * 5));

    expect(result.current.index).toBe(2);
    expect(result.current.isPlaying).toBe(false);
  });

  it('pauses without losing position', () => {
    const { result } = renderHook(() => useRouteReplay(5));

    act(() => result.current.play());
    act(() => vi.advanceTimersByTime(800));
    act(() => result.current.pause());

    expect(result.current.isPlaying).toBe(false);
    expect(result.current.index).toBe(1);

    act(() => vi.advanceTimersByTime(800 * 3));
    expect(result.current.index).toBe(1);
  });

  it('restart returns to the first sample and stops', () => {
    const { result } = renderHook(() => useRouteReplay(5));

    act(() => result.current.play());
    act(() => vi.advanceTimersByTime(800 * 2));
    act(() => result.current.restart());

    expect(result.current.index).toBe(0);
    expect(result.current.isPlaying).toBe(false);
  });

  it('replaying from the end restarts from the first sample', () => {
    const { result } = renderHook(() => useRouteReplay(2));

    act(() => result.current.play());
    act(() => vi.advanceTimersByTime(800 * 5));
    expect(result.current.index).toBe(1);
    expect(result.current.isPlaying).toBe(false);

    act(() => result.current.play());
    expect(result.current.index).toBe(0);
    expect(result.current.isPlaying).toBe(true);
  });

  it('seek jumps directly to a sample and pauses', () => {
    const { result } = renderHook(() => useRouteReplay(10));

    act(() => result.current.play());
    act(() => result.current.seek(6));

    expect(result.current.index).toBe(6);
    expect(result.current.isPlaying).toBe(false);
  });

  it('seek clamps to the valid sample range', () => {
    const { result } = renderHook(() => useRouteReplay(5));

    act(() => result.current.seek(999));
    expect(result.current.index).toBe(4);

    act(() => result.current.seek(-3));
    expect(result.current.index).toBe(0);
  });

  it('a higher speed advances more samples in the same wall-clock time', () => {
    const { result } = renderHook(() => useRouteReplay(20));

    act(() => result.current.setSpeed(4));
    act(() => result.current.play());
    act(() => vi.advanceTimersByTime(800));

    // At 4x, one base-interval's worth of wall-clock time covers ~4 samples.
    expect(result.current.index).toBeGreaterThanOrEqual(3);
  });

  it('a changed sample count (new trip/history loaded) resets to the first sample, stopped', () => {
    const { result, rerender } = renderHook(({ count }) => useRouteReplay(count), {
      initialProps: { count: 5 },
    });

    act(() => result.current.play());
    act(() => vi.advanceTimersByTime(800 * 2));
    expect(result.current.index).toBe(2);

    rerender({ count: 8 });

    expect(result.current.index).toBe(0);
    expect(result.current.isPlaying).toBe(false);
  });
});
