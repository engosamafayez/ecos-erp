/**
 * TASK-ECOS-...-020 §6/§27 — storage for the customer's own guest order-tracking session.
 *
 * Deliberately NOT the staff app's `tokenStorage` (localStorage, `ecos_token`, wired into the
 * shared `api` Axios instance's interceptors) — mixing the two would attach a staff bearer token
 * to `/api/track/*` calls (or vice versa) whenever the same browser also has an internal ERP
 * session, which is exactly the security-context mixing §28 forbids.
 *
 * Uses sessionStorage rather than localStorage: the tracking token is real bearer-credential
 * material for one specific order, valid for backend-enforced 7 days, but there is no product
 * need for it to outlive the browser tab/session on a shared or public computer. sessionStorage
 * still survives a page refresh (the actual UX requirement — don't lose the session on reload)
 * while narrowing the exposure window versus localStorage. This is a bounded, documented choice,
 * not a redesign of Task 1's auth model (§27) — the backend contract (opaque bearer token,
 * 7-day server-side expiry) is unchanged.
 */

const STORAGE_KEY = 'ecos_customer_tracking_session';

export type TrackingSession = {
  token: string;
  expiresAt: string;
};

function safeSessionStorage(): Storage | null {
  try {
    return window.sessionStorage;
  } catch {
    // Private-browsing / storage-disabled contexts: degrade to no persistence rather than throw.
    return null;
  }
}

export const trackingTokenStorage = {
  get(): TrackingSession | null {
    const storage = safeSessionStorage();
    if (!storage) return null;
    const raw = storage.getItem(STORAGE_KEY);
    if (!raw) return null;
    try {
      const parsed = JSON.parse(raw) as TrackingSession;
      if (typeof parsed.token !== 'string' || typeof parsed.expiresAt !== 'string') return null;
      if (new Date(parsed.expiresAt).getTime() <= Date.now()) {
        storage.removeItem(STORAGE_KEY);
        return null;
      }
      return parsed;
    } catch {
      storage.removeItem(STORAGE_KEY);
      return null;
    }
  },
  set(session: TrackingSession): void {
    safeSessionStorage()?.setItem(STORAGE_KEY, JSON.stringify(session));
  },
  clear(): void {
    safeSessionStorage()?.removeItem(STORAGE_KEY);
  },
};
