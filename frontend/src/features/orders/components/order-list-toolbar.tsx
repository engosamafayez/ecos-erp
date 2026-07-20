import { Clipboard, Download, Printer, Upload } from 'lucide-react';
import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';

import { ColumnVisibilityMenu } from '@/components/data-grid/column-visibility-menu';
import { SavedViewsMenu } from '@/components/data-grid/saved-views-menu';
import { SmartToolbar, type SmartToolbarBulkAction } from '@/components/data-grid/smart-toolbar';
import type { ColumnMeta, ColumnVisibilityState } from '@/components/data-grid/types';
import type { BulkActionKey, Order, OrderStatus } from '@/features/orders/types/order';
import { useOrderBulkLabels } from '@/features/orders/hooks/use-order-labels';

export type { BulkActionKey };

// ── Part 1: Context-Aware Transition Matrix ───────────────────────────────────
// Maps each order status to the set of bulk operations valid from that state.
// This is the single source of truth for allowed operations per status.
// Mixed selections show only the INTERSECTION of valid actions.

const BULK_TRANSITION_MATRIX: Record<OrderStatus, BulkActionKey[]> = {
  pending:          ['confirm', 'move_to_awaiting_payment', 'cancel'],
  awaiting_payment: ['confirm', 'cancel'],
  processing:       ['move_to_preparation', 'cancel'],
  awaiting_stock:   ['retry_reservation', 'start_manufacturing', 'purchase_materials'],
  confirmed:        ['move_to_preparation', 'cancel'],
  preparing:        ['dispatch', 'return_to_preparation'],
  out_for_delivery: ['complete_delivery', 'return', 'delivery_failed'],
  delivered:        ['complete', 'resume_confirmed'],
  returned:         ['inspect_return', 'return_to_stock', 'scrap'],
  review:           ['resume', 'reschedule', 'cancel'],
  rescheduled:      ['resume', 'reschedule', 'cancel'],
  scheduled:        [],
  completed:        [],
  cancelled:        [],
};

// Display config for each action key (destructive styling + separator before it).
const BULK_ACTION_DISPLAY: Partial<Record<BulkActionKey, { destructive?: boolean; separator?: boolean }>> = {
  scrap:  { destructive: true, separator: true },
  cancel: { destructive: true, separator: true },
};

// Canonical order for rendering — ensures stable, predictable button order.
const BULK_ACTION_ORDER: BulkActionKey[] = [
  'confirm',
  'move_to_awaiting_payment',
  'verify_payment',
  'move_to_preparation',
  'return_to_preparation',
  'dispatch',
  'complete_delivery',
  'complete',
  'retry_reservation',
  'start_manufacturing',
  'purchase_materials',
  'awaiting_stock',
  'resume',
  'resume_confirmed',
  'return_to_confirmed',
  'delivery_failed',
  'inspect_return',
  'return_to_stock',
  'reschedule',
  'review',
  'return',
  'scrap',
  'cancel',
];

// Actions that cannot be undone — shown with an irreversible warning in the dialog.
export const IRREVERSIBLE_BULK_ACTIONS = new Set<BulkActionKey>(['cancel', 'complete', 'scrap']);

// Human-readable target outcome for each action (used in confirmation dialog).
export const BULK_ACTION_TARGET_LABEL: Partial<Record<BulkActionKey, string>> = {
  confirm:                  'Confirmed',
  move_to_awaiting_payment: 'Awaiting Payment',
  verify_payment:           'Confirmed',
  move_to_preparation:      'Preparing',
  return_to_preparation:    'Preparing (Reset)',
  dispatch:                 'Out for Delivery',
  complete_delivery:        'Delivered',
  complete:                 'Completed',
  retry_reservation:        'Processing',
  start_manufacturing:      'Manufacturing Started',
  purchase_materials:       'Procurement Queue',
  awaiting_stock:           'Awaiting Stock',
  resume:                   'Processing',
  resume_confirmed:         'Confirmed',
  return_to_confirmed:      'Confirmed',
  delivery_failed:          'Under Review',
  inspect_return:           'Return Inspection',
  return_to_stock:          'Returned to Stock',
  scrap:                    'Scrapped',
  reschedule:               'Rescheduled',
  review:                   'Under Review',
  return:                   'Returned',
  cancel:                   'Cancelled',
};

/**
 * Computes the intersection of valid bulk actions across all selected orders.
 * Only actions permitted for every selected order are returned.
 * If selections span different statuses, only shared valid transitions appear.
 */
export function computeDynamicBulkActions(selectedOrders: Order[]): BulkActionKey[] {
  if (selectedOrders.length === 0) return [];

  // Build a Set of valid actions for each order
  const perOrderSets = selectedOrders.map(
    (o) => new Set<BulkActionKey>(BULK_TRANSITION_MATRIX[o.status] ?? []),
  );

  // Keep only keys that appear in all sets, in canonical order
  return BULK_ACTION_ORDER.filter((key) => perOrderSets.every((s) => s.has(key)));
}

// ── Props ─────────────────────────────────────────────────────────────────────

type OrderListToolbarProps = {
  /** Orders currently selected — actions are computed from their statuses. */
  selectedOrders: Order[];
  selectedCount: number;
  isFetching: boolean;
  columns: ColumnMeta[];
  columnVisibility: ColumnVisibilityState;
  onNew: () => void;
  onRefresh: () => void;
  onColumnToggle: (key: string) => void;
  onColumnReset: () => void;
  onImport?: () => void;
  onExport?: () => void;
  onCopyToClipboard?: () => void;
  onPrint?: () => void;
  onBulkAction?: (action: BulkActionKey) => void;
};

export function OrderListToolbar({
  selectedOrders,
  selectedCount,
  isFetching,
  columns,
  columnVisibility,
  onNew,
  onRefresh,
  onColumnToggle,
  onColumnReset,
  onImport,
  onExport,
  onCopyToClipboard,
  onPrint,
  onBulkAction,
}: OrderListToolbarProps) {
  const { t } = useTranslation('orders');
  const { bulkLabel } = useOrderBulkLabels();

  const bulkActions = useMemo<SmartToolbarBulkAction[]>(() => {
    if (!onBulkAction) return [];
    const validKeys = computeDynamicBulkActions(selectedOrders);
    return validKeys.map((key) => ({
      key,
      label: bulkLabel[key],
      onClick: () => onBulkAction(key),
      ...BULK_ACTION_DISPLAY[key],
    }));
  }, [selectedOrders, onBulkAction, bulkLabel]);

  return (
    <SmartToolbar
      primaryAction={{ label: t('actions.new'), onClick: onNew }}
      secondaryActions={[
        ...(onImport            ? [{ key: 'import', label: t('actions.import'),           onClick: onImport,            icon: Upload,    hideOnMobile: true }] : []),
        ...(onExport            ? [{ key: 'export', label: t('actions.export'),           onClick: onExport,            icon: Download,  hideOnMobile: true }] : []),
        ...(onCopyToClipboard   ? [{ key: 'copy',   label: t('actions.copyClipboard'), onClick: onCopyToClipboard, icon: Clipboard, hideOnMobile: true }] : []),
        ...(onPrint             ? [{ key: 'print',  label: t('actions.print'),          onClick: onPrint,           icon: Printer,   hideOnMobile: true }] : []),
      ]}
      bulkActions={bulkActions}
      bulkActionsLabel={t('actions.bulkActions')}
      selectedCount={selectedCount}
      onRefresh={onRefresh}
      refreshLabel={t('actions.refresh')}
      isFetching={isFetching}
      viewControls={
        <>
          <ColumnVisibilityMenu
            columns={columns}
            visibility={columnVisibility}
            onToggle={onColumnToggle}
            onReset={onColumnReset}
            label={t('toolbar.columns')}
          />
          <SavedViewsMenu label={t('toolbar.views')} />
        </>
      }
    />
  );
}
