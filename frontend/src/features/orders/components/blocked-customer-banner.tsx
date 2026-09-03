import { Ban } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import type { Order } from '@/features/orders/types/order';

/**
 * TASK-ECOS-COMMERCE-CUSTOMERS-BATCH-02-BLOCKED-CUSTOMERS-009-R1 (§5).
 *
 * Shared blocked-customer context banner — extracted from order-detail-page.tsx
 * (Task 4) so order-detail-drawer.tsx (Orders list / Distribution / Driver
 * Settlement) can show the SAME "On Hold — Blocked Customer" context instead of
 * a second, hand-rolled copy. One component, one place the copy/logic can ever
 * drift.
 *
 * Distinguishes ON HOLD — BLOCKED CUSTOMER from every other On Hold cause; never
 * replaces the canonical OrderStatusBadge itself.
 */
export function BlockedCustomerBanner({ order }: { order: Order }) {
  const { t } = useTranslation('orders');

  if (order.status !== 'on_hold' || order.hold_reason_code !== 'blocked_customer') {
    return null;
  }

  return (
    <Alert variant="destructive">
      <Ban />
      <AlertTitle>{t($ => $.orderDetail.blockedCustomer.bannerTitle)}</AlertTitle>
      <AlertDescription>
        {order.is_blocked_customer_hold
          ? t($ => $.orderDetail.blockedCustomer.bannerDescription)
          : t($ => $.orderDetail.blockedCustomer.bannerDescriptionOverridden)}
      </AlertDescription>
    </Alert>
  );
}
