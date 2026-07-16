import { CheckCircle2, XCircle, Clock, PhoneMissed } from 'lucide-react';

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

const RESULT_CONFIG = {
  confirmed: {
    label: 'Confirmed',
    icon: CheckCircle2,
    className:
      'bg-emerald-100 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-900/30 dark:text-emerald-400',
  },
  not_answered: {
    label: 'Not Answered',
    icon: PhoneMissed,
    className:
      'bg-amber-100 text-amber-700 ring-amber-600/20 dark:bg-amber-900/30 dark:text-amber-400',
  },
  rejected: {
    label: 'Rejected',
    icon: XCircle,
    className:
      'bg-red-100 text-red-700 ring-red-600/20 dark:bg-red-900/30 dark:text-red-400',
  },
  postponed: {
    label: 'Postponed',
    icon: Clock,
    className:
      'bg-blue-100 text-blue-700 ring-blue-600/20 dark:bg-blue-900/30 dark:text-blue-400',
  },
} as const;

export function OrderConfirmationBadge({ order }: Props) {
  const result = order.confirmation_result;
  const at = order.customer_confirmed_at;
  const by = order.customer_confirmed_by;

  if (!result) return <span className="text-muted-foreground">—</span>;

  const config = RESULT_CONFIG[result] ?? RESULT_CONFIG.confirmed;
  const Icon = config.icon;

  return (
    <TooltipProvider delayDuration={400}>
      <Tooltip>
        <TooltipTrigger asChild>
          <span
            className={`inline-flex cursor-default items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-medium ring-1 ring-inset ${config.className}`}
          >
            <Icon className="size-2.5" />
            {config.label}
          </span>
        </TooltipTrigger>
        <TooltipContent side="bottom" className="text-xs space-y-0.5">
          <p className="font-medium">Confirmation: {config.label}</p>
          {at ? <p className="text-muted-foreground">{fmtDateTime(at)}</p> : null}
          {by ? (
            <p>
              By: <span className="font-medium">{by}</span>
            </p>
          ) : null}
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  );
}
