import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react';

import { setOnSessionInvalid } from '@/features/customer-portal/services/customer-portal-service';
import { trackingTokenStorage } from '@/features/customer-portal/lib/tracking-token-storage';
import { TrackingSessionContext } from '@/features/customer-portal/context/tracking-session-context';

export function TrackingSessionProvider({ children }: { children: ReactNode }) {
  const [isVerified, setIsVerified] = useState(() => trackingTokenStorage.get() !== null);

  const endSession = useCallback(() => {
    trackingTokenStorage.clear();
    setIsVerified(false);
  }, []);

  const startSession = useCallback((token: string, expiresAt: string) => {
    trackingTokenStorage.set({ token, expiresAt });
    setIsVerified(true);
  }, []);

  useEffect(() => {
    // A 401 from any /track/* call (expired/revoked/invalid token) returns here to verification.
    setOnSessionInvalid(() => setIsVerified(false));
    return () => setOnSessionInvalid(null);
  }, []);

  const value = useMemo(
    () => ({ isVerified, startSession, endSession }),
    [isVerified, startSession, endSession],
  );

  return (
    <TrackingSessionContext.Provider value={value}>{children}</TrackingSessionContext.Provider>
  );
}
