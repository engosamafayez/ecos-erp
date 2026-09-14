import { describe, expect, it } from 'vitest';

import { matchesWakePhrase } from './wake-phrase';

/**
 * TASK-ECOS-V1.1-FINAL-AI-ASSISTANT-PERSONALIZED-COMPANION-046 (CTO voice scope
 * override) §5 — wake detection ONLY activates listening state; it must never
 * be confused with a business command. These tests prove the matcher itself is
 * a plain substring check on the user's OWN configured name — nothing else.
 */
describe('matchesWakePhrase', () => {
  it('matches when the transcript contains the assistant name', () => {
    // eslint-disable-next-line ecos-i18n/no-arabic-literals -- simulated spoken-transcript fixture, never rendered through i18n
    expect(matchesWakePhrase('يا ليلى ساعديني', 'ليلى')).toBe(true);
    expect(matchesWakePhrase('hey Nour, what is the status', 'Nour')).toBe(true);
  });

  it('is case-insensitive for Latin-script names', () => {
    expect(matchesWakePhrase('HEY NOUR', 'nour')).toBe(true);
  });

  it('does not match when the name is absent', () => {
    expect(matchesWakePhrase('cancel this order please', 'Nour')).toBe(false);
  });

  it('never matches an empty assistant name, even against an empty transcript', () => {
    expect(matchesWakePhrase('', '')).toBe(false);
    expect(matchesWakePhrase('anything at all', '')).toBe(false);
  });

  it('never matches an empty transcript', () => {
    expect(matchesWakePhrase('', 'Nour')).toBe(false);
  });

  it('matches even when the name is only part of a longer utterance (a real business phrase around it never becomes a match by itself)', () => {
    // The name itself is the ONLY thing checked — a business phrase alone,
    // with no name in it, never matches.
    expect(matchesWakePhrase('cancel order 123 for the customer', 'Nour')).toBe(false);
    // Saying the name alongside a request still just flips listening state —
    // the caller (use-assistant-voice.ts) never executes anything from this
    // match itself, it only starts push-to-talk.
    expect(matchesWakePhrase('Nour cancel order 123', 'Nour')).toBe(true);
  });
});
