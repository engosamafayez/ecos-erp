import { useTranslation } from 'react-i18next';

import type enLogistics from '@/i18n/locales/en/logistics.json';

import type { ShippingAddress } from '../types';

/** A `logistics` namespace selector — resolved at render, never stored translated. */
type LogisticsLabel = ($: typeof enLogistics) => string;

/**
 * The Order's FULL shipping address.
 *
 * ┌─ WHY THIS EXISTS ────────────────────────────────────────────────────────┐
 * │ The workspace used to show "City / Governorate" only, and the underlying  │
 * │ read model selected `billing_address_1` — NULL on every manually-entered  │
 * │ order. A driver cannot deliver to "Maadi / Cairo".                        │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * Two rules govern every line below:
 *
 *   1. NOTHING IS RECONSTRUCTED. A missing street is not replaced by the zone,
 *      the area or the city. The address shown is the address stored.
 *   2. MISSING DATA IS NAMED, NOT HIDDEN. When the street line is absent the
 *      operator is told which field to fix, because a silently short address
 *      looks like a complete one.
 */

/** Fields that make an address deliverable. Their absence is worth reporting. */
const REQUIRED: ReadonlyArray<[keyof ShippingAddress, LogisticsLabel]> = [
  ['street', ($) => $.distributionWorkspace.address.field.street],
  ['city', ($) => $.distributionWorkspace.address.field.city],
  ['governorate', ($) => $.distributionWorkspace.address.field.governorate],
];

function missingFields(address: ShippingAddress): LogisticsLabel[] {
  return REQUIRED.filter(([key]) => {
    const value = address[key];
    return value === null || value === undefined || String(value).trim() === '';
  }).map(([, label]) => label);
}

/** Joins the parts that exist, dropping the ones that do not. */
function line(...parts: Array<string | null | undefined>): string {
  return parts
    .map((p) => (p === null || p === undefined ? '' : String(p).trim()))
    .filter((p) => p !== '')
    .join(' · ');
}

/**
 * `showRecipient` exists because the grid ALREADY has a Customer column carrying
 * the name and phone. Repeating them here printed the same two facts twice on
 * every row and pushed the actual street out of sight — the address column has
 * one job, which is to say where the driver goes.
 */
export function OrderAddressCell({
  address,
  showRecipient = false,
}: {
  address: ShippingAddress;
  showRecipient?: boolean;
}) {
  const { t } = useTranslation('logistics');
  const missing = missingFields(address).map((label) => t(label));

  // Building / floor / apartment are one physical location, so they read as one
  // line. Landmark stays separate: it is a direction, not part of the address.
  const unit = line(
    address.building
      ? t(($) => $.distributionWorkspace.address.bldg, { value: address.building })
      : null,
    address.floor
      ? t(($) => $.distributionWorkspace.address.floor, { value: address.floor })
      : null,
    address.apartment
      ? t(($) => $.distributionWorkspace.address.apt, { value: address.apartment })
      : null,
  );

  const locality = line(address.area, address.city, address.governorate, address.postcode);

  return (
    <div className="flex max-w-[22rem] flex-col gap-0.5 text-sm">
      {showRecipient && address.recipient ? (
        <span className="font-medium">{address.recipient}</span>
      ) : null}

      {showRecipient && (address.phone || address.secondary_phone) ? (
        <span className="text-xs text-muted-foreground" dir="ltr">
          {line(address.phone, address.secondary_phone)}
        </span>
      ) : null}

      {/* Street and unit are one physical place, so they read as one line. */}
      {line(address.street, unit) ? <span>{line(address.street, unit)}</span> : null}
      {locality ? <span className="text-muted-foreground">{locality}</span> : null}

      {address.landmark ? (
        <span className="text-xs text-muted-foreground">
          {t(($) => $.distributionWorkspace.address.landmark, { value: address.landmark })}
        </span>
      ) : null}

      {address.notes ? (
        <span className="text-xs text-muted-foreground">
          {t(($) => $.distributionWorkspace.address.notes, { value: address.notes })}
        </span>
      ) : null}

      {missing.length > 0 ? (
        <span className="text-xs font-medium text-amber-700">
          {t(($) => $.distributionWorkspace.address.missing, { fields: missing.join(', ') })}
        </span>
      ) : null}
    </div>
  );
}
