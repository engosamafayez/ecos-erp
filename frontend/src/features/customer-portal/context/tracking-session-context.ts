import { createContext, useContext } from 'react';

export type TrackingSessionContextValue = {
  /** True once a live (unexpired, not cleared) tracking token exists for this order. */
  isVerified: boolean;
  /** Called after a successful POST /track/verify. */
  startSession: (token: string, expiresAt: string) => void;
  /** Client-side disposal — "End secure session" (§6). No server-side revoke endpoint exists in
   *  Task 1 to call here; inventing one would be a fabricated backend authority (§6's own text
   *  permits, but does not require, adding a real bounded revoke endpoint — not done in this
   *  task; disposal here is honestly client-side only). */
  endSession: () => void;
};

export const TrackingSessionContext = createContext<TrackingSessionContextValue | null>(null);

export function useTrackingSession(): TrackingSessionContextValue {
  const ctx = useContext(TrackingSessionContext);
  if (!ctx) throw new Error('useTrackingSession must be used within a TrackingSessionProvider');
  return ctx;
}
