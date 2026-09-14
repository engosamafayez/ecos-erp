import { useState } from 'react';

import { useLanguage } from '@/providers/language-context';
import { useTrackingSession } from '@/features/customer-portal/context/tracking-session-context';
import { TrackingSessionProvider } from '@/features/customer-portal/context/tracking-session-provider';
import { TrackOrderLanding } from '@/features/customer-portal/components/track-order-landing';
import { VerifyCodeForm } from '@/features/customer-portal/components/verify-code-form';
import { OrderTrackingView } from '@/features/customer-portal/components/order-tracking-view';

type Contact = { orderNumber: string; contact: string };

function TrackOrderContent() {
  const { isVerified } = useTrackingSession();
  const { language } = useLanguage();
  const [pending, setPending] = useState<Contact | null>(null);

  if (isVerified) {
    return <OrderTrackingView language={language} />;
  }

  if (pending) {
    return (
      <VerifyCodeForm
        orderNumber={pending.orderNumber}
        contact={pending.contact}
        onBack={() => setPending(null)}
      />
    );
  }

  return <TrackOrderLanding onRequested={setPending} />;
}

/**
 * TASK-ECOS-V1.1-CRM-04-CUSTOMER-SELF-SERVICE-UX-AND-FINAL-CLOSURE-020 §2/§3.
 *
 * PUBLIC — rendered outside ProtectedRoute and outside every internal ERP shell (see
 * router.ts's careers-portal precedent). A visitor has no ECOS session, no company context, and
 * no navigation rail. V1 is deliberately order-scoped, not a customer-wide "My Orders" account:
 * a verified session only ever exposes the ONE order its tracking token was issued for.
 */
export function TrackOrderPage() {
  return (
    <TrackingSessionProvider>
      <TrackOrderContent />
    </TrackingSessionProvider>
  );
}
