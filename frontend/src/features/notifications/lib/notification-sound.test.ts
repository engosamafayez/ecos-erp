import { afterEach, describe, expect, it, vi } from 'vitest';

import { playAttentionSound } from './notification-sound';

/**
 * ADR-047 §26.9 — sound is best-effort only. These tests pin two things: (1) it never
 * throws, in any environment, and (2) when a (mocked) AudioContext is available it
 * actually drives it, so the "attention" isn't silently a no-op when the API exists.
 */
describe('playAttentionSound', () => {
  const originalAudioContext = window.AudioContext;

  afterEach(() => {
    window.AudioContext = originalAudioContext;
    vi.restoreAllMocks();
  });

  it('does not throw when no AudioContext is available (jsdom default)', () => {
    // @ts-expect-error -- simulating an environment with no Web Audio API at all
    delete window.AudioContext;

    expect(() => playAttentionSound('normal')).not.toThrow();
  });

  it('does not throw when the AudioContext constructor itself throws (autoplay-blocked)', () => {
    // A real `function`, not an arrow — vi.fn() wraps this so it stays usable via `new`.
    const ThrowingAudioContext = vi.fn(function ThrowingAudioContext() {
      throw new DOMException('not allowed', 'NotAllowedError');
    });
    window.AudioContext = ThrowingAudioContext as unknown as typeof AudioContext;

    expect(() => playAttentionSound('critical')).not.toThrow();
  });

  it('drives one oscillator per beep for the given profile when AudioContext is available', () => {
    const start = vi.fn();
    const stop = vi.fn();
    const connectOsc = vi.fn();
    const connectGain = vi.fn();
    const oscillators: unknown[] = [];

    class FakeAudioContext {
      currentTime = 0;
      destination = {};
      createOscillator() {
        const osc = {
          type: 'sine',
          frequency: { value: 0 },
          connect: connectOsc,
          start,
          stop,
        };
        oscillators.push(osc);
        return osc;
      }
      createGain() {
        return {
          gain: { setValueAtTime: vi.fn(), exponentialRampToValueAtTime: vi.fn() },
          connect: connectGain,
        };
      }
      close() {
        return Promise.resolve();
      }
    }

    // @ts-expect-error -- test double stands in for the real Web Audio API
    window.AudioContext = FakeAudioContext;

    playAttentionSound('important'); // 2 beeps per the profile spec

    expect(oscillators).toHaveLength(2);
    expect(start).toHaveBeenCalledTimes(2);
    expect(stop).toHaveBeenCalledTimes(2);
  });

  it.each(['normal', 'important', 'critical'] as const)(
    'never throws for the %s profile even without AudioContext support',
    (profile) => {
      // @ts-expect-error -- simulating an environment with no Web Audio API at all
      delete window.AudioContext;
      expect(() => playAttentionSound(profile)).not.toThrow();
    },
  );
});
