import { useTranslation } from 'react-i18next';
import { ArrowRight } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import type { SupplierInvoiceAction, SupplierInvoiceDisplayStatus } from '@/features/supplier-invoices/types/supplier-invoice';

/**
 * TASK-ECOS-PROCUREMENT-SUPPLIER-INVOICE-FINAL-LIFECYCLE-020 §5/§6.
 *
 * Renders the 6-word (+ "processing") user-approved vocabulary from the server-computed
 * `display_status` — never the raw `status`/`status_label` (§6: never expose raw status text).
 * Optionally renders the discoverable "Next: …" hint beside it, derived from
 * `available_actions` by a fixed priority — the first FORWARD-moving action found, never
 * `cancel`/`delete` (those are escape hatches, not "what happens next").
 */

const DISPLAY_STATUS_CLASS: Record<SupplierInvoiceDisplayStatus, string> = {
  draft: 'bg-gray-100 text-gray-700',
  commercially_approved: 'bg-blue-100 text-blue-800',
  partial_received: 'bg-amber-100 text-amber-800',
  fully_received: 'bg-teal-100 text-teal-800',
  processing: 'bg-yellow-100 text-yellow-800',
  posted: 'bg-green-100 text-green-800',
  failed: 'bg-red-100 text-red-700',
  cancelled: 'bg-rose-50 text-rose-600',
};

const NEXT_ACTION_PRIORITY: SupplierInvoiceAction[] = ['validate', 'post', 'edit'];

type Props = {
  displayStatus: SupplierInvoiceDisplayStatus;
  availableActions?: SupplierInvoiceAction[];
  /** Show the "Next: …" hint beside the badge. Off by default for dense contexts (e.g. a table
   *  cell that already has its own action menu) — on for the drawer header. */
  showNextAction?: boolean;
  className?: string;
};

export function SupplierInvoiceStatusBadge({ displayStatus, availableActions, showNextAction, className }: Props) {
  const { t } = useTranslation('supplier-invoices');
  const tAny = t as (key: string) => string;

  const nextAction = showNextAction
    ? NEXT_ACTION_PRIORITY.find((a) => availableActions?.includes(a))
    : undefined;

  return (
    <span className={`inline-flex items-center gap-1.5 ${className ?? ''}`}>
      <Badge className={`${DISPLAY_STATUS_CLASS[displayStatus]} border-0 text-xs flex-shrink-0`} variant="secondary">
        {tAny(`displayStatus.${displayStatus}`)}
      </Badge>
      {nextAction && (
        <span className="inline-flex items-center gap-0.5 text-xs text-muted-foreground">
          <ArrowRight className="w-3 h-3" />
          {tAny(`displayStatus.nextAction.${nextAction}`)}
        </span>
      )}
    </span>
  );
}
