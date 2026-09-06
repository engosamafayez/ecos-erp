import type { SoundProfile } from '../types/notification';

/**
 * ADR-047 §26.8 — at most three sound profiles, never a unique sound per notification
 * type or module. Synthesized via the Web Audio API rather than shipped as audio assets:
 * no binary file, no licensing question, and no network fetch on the delivery path.
 */
const PROFILE_SPEC: Record<SoundProfile, { beeps: number; frequencyHz: number; beepSeconds: number; gapSeconds: number }> = {
  normal: { beeps: 1, frequencyHz: 440, beepSeconds: 0.12, gapSeconds: 0 },
  important: { beeps: 2, frequencyHz: 660, beepSeconds: 0.1, gapSeconds: 0.08 },
  critical: { beeps: 3, frequencyHz: 880, beepSeconds: 0.09, gapSeconds: 0.06 },
};

type AudioContextCtor = typeof AudioContext;

function resolveAudioContextCtor(): AudioContextCtor | undefined {
  if (typeof window === 'undefined') return undefined;

  return (
    window.AudioContext ??
    (window as unknown as { webkitAudioContext?: AudioContextCtor }).webkitAudioContext
  );
}

/**
 * ADR-047 §26.9 — a best-effort attention signal, never canonical. Browser autoplay
 * policy may silently block this (no user gesture yet); an unsupported or already-closed
 * AudioContext must never throw into the caller. The persistent in-app notification
 * record — never this function — remains the sole source of truth regardless of whether
 * sound actually played.
 */
export function playAttentionSound(profile: SoundProfile): void {
  try {
    const Ctx = resolveAudioContextCtor();
    if (!Ctx) return;

    const ctx = new Ctx();
    const spec = PROFILE_SPEC[profile];

    for (let i = 0; i < spec.beeps; i++) {
      const startAt = ctx.currentTime + i * (spec.beepSeconds + spec.gapSeconds);
      const oscillator = ctx.createOscillator();
      const gain = ctx.createGain();

      oscillator.type = 'sine';
      oscillator.frequency.value = spec.frequencyHz;
      // A short exponential decay reads as a "beep" rather than a click or a drone.
      gain.gain.setValueAtTime(0.15, startAt);
      gain.gain.exponentialRampToValueAtTime(0.0001, startAt + spec.beepSeconds);

      oscillator.connect(gain);
      gain.connect(ctx.destination);
      oscillator.start(startAt);
      oscillator.stop(startAt + spec.beepSeconds);
    }

    const totalSeconds = spec.beeps * (spec.beepSeconds + spec.gapSeconds);
    setTimeout(() => void ctx.close(), (totalSeconds + 0.2) * 1000);
  } catch {
    // Autoplay restriction, unsupported API, or a context already closed — never let a
    // best-effort sound failure interrupt the caller (ADR-047 §26.9).
  }
}
