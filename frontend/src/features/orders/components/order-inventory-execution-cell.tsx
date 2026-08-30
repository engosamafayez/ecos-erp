import { useTranslation } from 'react-i18next';

import { Badge } from '@/components/ui/badge'
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip'
import type { ReservationStatus } from '../types/order'

interface Props {
  reservationStatus: ReservationStatus | null | undefined
  failureReason?: string | null
}

interface StatusConfig {
  variant: 'default' | 'secondary' | 'destructive' | 'outline'
  className: string
  dot: string
}

const STATUS_CONFIG: Record<ReservationStatus, StatusConfig> = {
  // `pending` is NOT a reservation outcome and is never the answer to "is this product
  // available" — the three business outcomes below are (Reserved / Partial / Awaiting
  // Stock). It survives in the backend enum for exactly one situation: reservation
  // EXECUTION postponed because no warehouse is assigned yet (ADR-027 §2/§10, RC-10),
  // which is a geography blocker, not a stock one.
  //
  // So it is deliberately not labelled "Pending" — that word described the system's
  // internal wait rather than any business fact, and it read as "not reserved", which
  // made a missing warehouse indistinguishable from a real shortage. The label now names
  // the actual blocker, and the reason (`Warehouse Not Assigned`) shows on hover.
  pending: {
    variant: 'outline',
    className: 'bg-slate-100 text-slate-700 border-slate-300 dark:bg-slate-800 dark:text-slate-300 dark:border-slate-600',
    dot: 'bg-slate-400',
  },
  reserved: {
    variant: 'default',
    className: 'bg-emerald-100 text-emerald-800 border-emerald-200 dark:bg-emerald-900/30 dark:text-emerald-300 dark:border-emerald-800',
    dot: 'bg-emerald-500',
  },
  partial_reserved: {
    variant: 'default',
    className: 'bg-amber-100 text-amber-800 border-amber-200 dark:bg-amber-900/30 dark:text-amber-300 dark:border-amber-800',
    dot: 'bg-amber-500',
  },
  awaiting_stock: {
    variant: 'default',
    className: 'bg-orange-100 text-orange-800 border-orange-200 dark:bg-orange-900/30 dark:text-orange-300 dark:border-orange-800',
    dot: 'bg-orange-500',
  },
  released: {
    variant: 'outline',
    className: 'text-muted-foreground border-muted-foreground/30',
    dot: 'bg-muted-foreground/40',
  },
  transferred: {
    variant: 'default',
    className: 'bg-blue-100 text-blue-800 border-blue-200 dark:bg-blue-900/30 dark:text-blue-300 dark:border-blue-800',
    dot: 'bg-blue-500',
  },
  consumed: {
    variant: 'default',
    className: 'bg-slate-100 text-slate-600 border-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:border-slate-700',
    dot: 'bg-slate-400',
  },
  failed: {
    variant: 'destructive',
    className: 'bg-red-100 text-red-800 border-red-200 dark:bg-red-900/30 dark:text-red-300 dark:border-red-800',
    dot: 'bg-red-500',
  },
}

export function OrderInventoryExecutionCell({ reservationStatus, failureReason }: Props) {
  const { t } = useTranslation('orders');

  // No reservation state at all is NOT a state — it means no availability decision has
  // been taken yet, which today is only a Scheduled order awaiting its D-1 activation
  // (or a legacy row predating the column). Defaulting this to `pending` was how the word
  // "Pending" reached the screen for orders that had simply never been evaluated, so a
  // null renders as an explicit absence instead of a fabricated state.
  if (!reservationStatus) {
    return <span className="text-[10px] text-muted-foreground">—</span>
  }

  const status = reservationStatus
  const config = STATUS_CONFIG[status] ?? STATUS_CONFIG.pending
  const label = t($ => $.reservationBadge[status] as `reservationBadge.${ReservationStatus}`)

  const showTooltip = (status === 'awaiting_stock' || status === 'partial_reserved' || status === 'failed' || status === 'pending') && failureReason

  const badge = (
    <Badge
      variant="outline"
      className={`inline-flex items-center gap-1.5 text-[10px] font-medium px-1.5 py-0.5 whitespace-nowrap ${config.className}`}
    >
      <span className={`h-1.5 w-1.5 rounded-full flex-shrink-0 ${config.dot}`} />
      {label}
    </Badge>
  )

  if (!showTooltip) return badge

  return (
    <TooltipProvider delayDuration={200}>
      <Tooltip>
        <TooltipTrigger asChild>{badge}</TooltipTrigger>
        <TooltipContent side="top" className="max-w-[220px] text-xs">
          {failureReason}
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  )
}
