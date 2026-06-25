import { useTranslation } from 'react-i18next';

import { StatusBadge } from '@/components/crud';
import type { PaymentStatus } from '@/features/goods-receipts/types/goods-receipt';

const PAYMENT_STATUS_VARIANTS: Record<PaymentStatus, 'active' | 'pending' | 'inactive'> = {
  paid: 'active',
  partially_paid: 'pending',
  unpaid: 'inactive',
};

const PAYMENT_STATUS_KEYS: Record<PaymentStatus, string> = {
  paid: 'paymentStatus.paid',
  partially_paid: 'paymentStatus.partiallyPaid',
  unpaid: 'paymentStatus.unpaid',
};

export function GrPaymentStatusBadge({ status }: { status: PaymentStatus }) {
  const { t } = useTranslation('goods-receipts');
  return <StatusBadge status={PAYMENT_STATUS_VARIANTS[status]} label={t(PAYMENT_STATUS_KEYS[status])} />;
}
