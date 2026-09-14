import { describe, expect, it, beforeEach } from 'vitest';

import { trackingTokenStorage } from './tracking-token-storage';

/**
 * TASK-ECOS-...-020 §6/§27 — the customer tracking session is isolated from the staff app's
 * own `localStorage`-backed `ecos_token`, and never outlives an expired session.
 */
describe('trackingTokenStorage', () => {
  beforeEach(() => {
    window.sessionStorage.clear();
    window.localStorage.clear();
  });

  it('persists to sessionStorage, never to localStorage', () => {
    trackingTokenStorage.set({
      token: 'raw-token',
      expiresAt: new Date(Date.now() + 60_000).toISOString(),
    });

    expect(window.sessionStorage.length).toBeGreaterThan(0);
    expect(window.localStorage.length).toBe(0);
  });

  it('returns the session while it has not yet expired', () => {
    trackingTokenStorage.set({
      token: 'raw-token',
      expiresAt: new Date(Date.now() + 60_000).toISOString(),
    });

    expect(trackingTokenStorage.get()).toEqual({
      token: 'raw-token',
      expiresAt: expect.any(String),
    });
  });

  it('returns null and clears storage once the session has expired', () => {
    trackingTokenStorage.set({
      token: 'raw-token',
      expiresAt: new Date(Date.now() - 1000).toISOString(),
    });

    expect(trackingTokenStorage.get()).toBeNull();
    expect(window.sessionStorage.length).toBe(0);
  });

  it('clear() removes the session', () => {
    trackingTokenStorage.set({
      token: 'raw-token',
      expiresAt: new Date(Date.now() + 60_000).toISOString(),
    });
    trackingTokenStorage.clear();

    expect(trackingTokenStorage.get()).toBeNull();
  });
});
