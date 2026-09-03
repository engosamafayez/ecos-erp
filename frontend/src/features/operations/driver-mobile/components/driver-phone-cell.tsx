import { useTranslation } from 'react-i18next';

import { PhoneCell } from '@/components/ecos/phone-cell';

type DriverPhoneCellProps = {
  phone: string | null;
  variant?: 'text' | 'icon';
  ariaLabel?: string;
  className?: string;
};

/**
 * Reuses the canonical PhoneCell (Call / WhatsApp / Copy) instead of the driver-mobile
 * feature's own hand-rolled tel:/wa.me links — TASK-ECOS-DRIVER-ORDERS-LIST-PAGE-
 * CLOSURE-001 §5. Mirrors OrderPhoneCell's pattern of supplying translated labels from
 * the feature's own i18n namespace.
 */
export function DriverPhoneCell({ phone, variant, ariaLabel, className }: DriverPhoneCellProps) {
  const { t } = useTranslation('driver-mobile');

  return (
    <PhoneCell
      phone={phone}
      variant={variant}
      ariaLabel={ariaLabel}
      className={className}
      labels={{
        call: t($ => $.stop.phoneActions.call),
        whatsapp: t($ => $.stop.phoneActions.whatsapp),
        copy: t($ => $.stop.phoneActions.copy),
        copied: t($ => $.stop.phoneActions.copied),
      }}
    />
  );
}
