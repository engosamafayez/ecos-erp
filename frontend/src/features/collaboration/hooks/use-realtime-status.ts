/**
 * The entire realtime-adapter boundary (brief §15/§41). No `laravel-echo`/
 * `pusher-js` package is installed in this frontend, and the Reverb package
 * itself was deliberately left uninstalled on the backend (Task 3's CTO
 * ruling, preserved by this task per its own explicit instruction not to
 * touch composer.json/composer.lock for Reverb). `window.Echo` is therefore
 * always `undefined` today, so this always resolves to `'polling'` — which
 * is the real, functioning delivery path (see the `refetchInterval` usage in
 * use-conversations.ts / use-messages.ts).
 *
 * When the canonical device activates Reverb, the future integration work
 * is: (1) `npm install laravel-echo pusher-js`, (2) initialize
 * `window.Echo = new Echo({ broadcaster: 'reverb', ... })` once at app
 * bootstrap, (3) this hook then reports `'connected'` automatically with
 * zero changes here, and the consuming hooks below stop polling
 * automatically too (their `refetchInterval` already branches on this
 * value). No other frontend code needs to change.
 */
export type CollaborationRealtimeStatus = 'connected' | 'polling';

declare global {
  interface Window {
    Echo?: unknown;
  }
}

export function useRealtimeStatus(): CollaborationRealtimeStatus {
  if (typeof window !== 'undefined' && window.Echo) {
    return 'connected';
  }

  return 'polling';
}
