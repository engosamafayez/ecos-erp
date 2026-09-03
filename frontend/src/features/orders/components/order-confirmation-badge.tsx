import { PhoneCall, XCircle, Clock, PhoneMissed } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip';

import type { Order } from '../types/order';

type Props = { order: Order };

function fmtDateTime(d: string): string {
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'short', timeStyle: 'short' }).format(new Date(d));
}

// TASK-ECOS-COMMERCE-ORDERS-BATCH-02-SCHEDULED-LIFECYCLE-002 (§2) — this badge
// reports the CUSTOMER PHONE CONFIRMATION CALL outcome (confirmation_result),
// a fact independent of the canonical OrderStatus (which can separately show
// "In Progress" on the very same row). The `confirmed` case previously used a
// generic checkmark and the bare word "Confirmed" — easily misread as the
// order's lifecycle status. A phone icon (matching the sibling `not_answered`
// state's phone icon) plus a "Call Confirmed" label makes the call-outcome
// authority unambiguous without touching confirmation_result itself, which
// remains a permanent record of the call (never reset by a later status
// change) per this task's own instruction to preserve the underlying state.
const RESULT_CONFIG = {
  confirmed: {
    icon: PhoneCall,
    className:
      'bg-emerald-100 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-900/30 dark:text-emerald-400',
  },
  not_answered: {
    icon: PhoneMissed,
    className:
      'bg-amber-100 text-amber-700 ring-amber-600/20 dark:bg-amber-900/30 dark:text-amber-400',
  },
  rejected: {
    icon: XCircle,
    className:
      'bg-red-100 text-red-700 ring-red-600/20 dark:bg-red-900/30 dark:text-red-400',
  },
  postponed: {
    icon: Clock,
    className:
      'bg-blue-100 text-blue-700 ring-blue-600/20 dark:bg-blue-900/30 dark:text-blue-400',
  },
} as const;

type ResultKey = keyof typeof RESULT_CONFIG;

export function OrderConfirmationBadge({ order }: Props) {
  const { t } = useTranslation('orders');
  const result = order.confirmation_result;
  const at = order.customer_confirmed_at;
  const by = order.customer_confirmed_by;

  if (!result) return <span className="text-muted-foreground">—</span>;

  const config = RESULT_CONFIG[result as ResultKey] ?? RESULT_CONFIG.confirmed;
  const Icon = config.icon;
  const label = t($ => $.confirmationBadge[result as ResultKey]);

  return (
    <TooltipProvider delayDuration={400}>
      <Tooltip>
        <TooltipTrigger asChild>
          <span
            className={`inline-flex cursor-default items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-medium ring-1 ring-inset ${config.className}`}
          >
            <Icon className="size-2.5" />
            {label}
          </span>
        </TooltipTrigger>
        <TooltipContent side="bottom" className="text-xs space-y-0.5">
          <p className="font-medium">{t($ => $.confirmationBadge.tooltipTitle, { label })}</p>
          {at ? <p className="text-muted-foreground">{fmtDateTime(at)}</p> : null}
          {by ? (
            <p>
              {t($ => $.confirmationBadge.by)} <span className="font-medium">{by}</span>
            </p>
          ) : null}
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  );
}
