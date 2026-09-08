import { renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const mockRecordGps = vi.hoisted(() => vi.fn().mockResolvedValue(undefined));
vi.mock('../services/driver-mobile-service', () => ({
  recordGps: mockRecordGps,
}));

import { useGpsReporting } from './use-gps-reporting';

/**
 * TASK-ECOS-SHIPPING-OS-REDESIGN-004 §15/§16/§23.
 *
 * The hook's own job is narrow — it must only report while `active` is true
 * (the caller's canonical on-the-road check), and it must convert the
 * Geolocation API's metres/second speed into the backend's speed_kph column,
 * which the ×3.6 factor is easy to silently drop or invert.
 */
describe('useGpsReporting', () => {
  const originalGeolocation = navigator.geolocation;

  beforeEach(() => {
    vi.useFakeTimers();
    mockRecordGps.mockClear();
  });

  afterEach(() => {
    vi.useRealTimers();
    Object.defineProperty(navigator, 'geolocation', { value: originalGeolocation, configurable: true });
  });

  function stubGeolocation(coords: { latitude: number; longitude: number; speed: number | null; accuracy: number | null }) {
    Object.defineProperty(navigator, 'geolocation', {
      configurable: true,
      value: {
        getCurrentPosition: (success: (pos: unknown) => void) => {
          success({ coords, timestamp: Date.now() });
        },
      },
    });
  }

  it('never reports while inactive', () => {
    stubGeolocation({ latitude: 30, longitude: 31, speed: null, accuracy: null });
    renderHook(() => useGpsReporting('trip-1', false));

    expect(mockRecordGps).not.toHaveBeenCalled();
  });

  it('reports immediately once active, converting m/s to km/h', () => {
    stubGeolocation({ latitude: 30.123, longitude: 31.456, speed: 10, accuracy: 5 });
    renderHook(() => useGpsReporting('trip-1', true));

    expect(mockRecordGps).toHaveBeenCalledTimes(1);
    expect(mockRecordGps).toHaveBeenCalledWith('trip-1', 30.123, 31.456, 36, 5);
  });

  it('omits speed when the device does not report one, rather than sending a fabricated 0', () => {
    stubGeolocation({ latitude: 30, longitude: 31, speed: null, accuracy: null });
    renderHook(() => useGpsReporting('trip-1', true));

    expect(mockRecordGps).toHaveBeenCalledWith('trip-1', 30, 31, undefined, undefined);
  });

  it('reports again on the next interval tick, not more often (bounded cadence)', async () => {
    stubGeolocation({ latitude: 30, longitude: 31, speed: null, accuracy: null });
    renderHook(() => useGpsReporting('trip-1', true));

    expect(mockRecordGps).toHaveBeenCalledTimes(1);

    await vi.advanceTimersByTimeAsync(30_000);
    expect(mockRecordGps).toHaveBeenCalledTimes(2);
  });

  it('stops reporting once no longer active', async () => {
    stubGeolocation({ latitude: 30, longitude: 31, speed: null, accuracy: null });
    const { rerender } = renderHook(({ active }) => useGpsReporting('trip-1', active), {
      initialProps: { active: true },
    });

    expect(mockRecordGps).toHaveBeenCalledTimes(1);
    rerender({ active: false });

    await vi.advanceTimersByTimeAsync(60_000);
    expect(mockRecordGps).toHaveBeenCalledTimes(1);
  });

  it('does nothing when the Geolocation API is unavailable, rather than throwing or faking a position', () => {
    Object.defineProperty(navigator, 'geolocation', { value: undefined, configurable: true });

    expect(() => renderHook(() => useGpsReporting('trip-1', true))).not.toThrow();
    expect(mockRecordGps).not.toHaveBeenCalled();
  });
});
