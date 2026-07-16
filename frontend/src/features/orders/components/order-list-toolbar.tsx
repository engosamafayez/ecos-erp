import { Clipboard, Download, Printer, Upload } from 'lucide-react';
import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';

import { ColumnVisibilityMenu } from '@/components/data-grid/column-visibility-menu';
import { SavedViewsMenu } from '@/components/data-grid/saved-views-menu';
import { SmartToolbar, type SmartToolbarBulkAction } from '@/components/data-grid/smart-toolbar';
import type { ColumnMeta, ColumnVisibilityState } from '@/components/data-grid/types';
import type { Order, OrderStatus } from '@/features/orders/types/order';

export type BulkActionKey =
  | 'confirm'
  | 'verify_payment'
  | 'move_to_preparation'
  | 'awaiting_stock'
  | 'resume'
  | 'resume_confirmed'
  | 'dispatch'
  | 'complete_delivery'
  | 'complete'
  | 'reschedule'
  | 'review'
  | 'return'
  | 'return_to_confirmed'
  | 'cancel';

// ── Part 1: Dynamic Transition Matrix ────────────────────────────────────────
// Maps each status to the set of bulk operations valid from that state.
// This is the single source of truth for what operations are permitted per status.

const BULK_TRANSITION_MATRIX: Record<OrderStatus, BulkActionKey[]> = {
  pending:          ['confirm', 'cancel'],
  awaiting_payment: ['verify_payment', 'confirm', 'cancel'],
  processing:       ['move_to_preparation', 'awaiting_stock', 'review', 'cancel'],
  awaiting_stock:   ['resume', 'cancel'],
  confirmed:        ['move_to_preparation', 'reschedule', 'cancel'],
  preparing:        ['dispatch', 'reschedule', 'review', 'cancel'],
  out_for_delivery: ['complete_delivery', 'return', 'reschedule', 'review'],
  delivered:        ['complete', 'review', 'resume', 'resume_confirmed', 'reschedule', 'cancel'],
  returned:         ['return_to_confirmed', 'reschedule', 'review', 'cancel'],
  review:           ['resume', 'reschedule', 'cancel'],
  rescheduled:      ['resume', 'reschedule', 'cancel'],
  completed:        [],
  cancelled:        [],
};

// Display config for each action key (destructive styling + separator before it).
const BULK_ACTION_DISPLAY: Partial<Record<BulkActionKey, { destructive?: boolean; separator?: boolean }>> = {
  cancel: { destructive: true, separator: true },
};

// Canonical order for rendering — ensures stable, predictable button order.
const BULK_ACTION_ORDER: BulkActionKey[] = [
  'confirm',
  'verify_payment',
  'move_to_preparation',
  'dispatch',
  'complete_delivery',
  'complete',
  'awaiting_stock',
  'resume',
  'resume_confirmed',
  'return_to_confirmed',
  'reschedule',
  'review',
  'return',
  'cancel',
];

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

  const bulkActions = useMemo<SmartToolbarBulkAction[]>(() => {
    if (!onBulkAction) return [];
    const validKeys = computeDynamicBulkActions(selectedOrders);
    return validKeys.map((key) => ({
      key,
      label: t(`bulk.${key}`),
      onClick: () => onBulkAction(key),
      ...BULK_ACTION_DISPLAY[key],
    }));
  }, [selectedOrders, onBulkAction, t]);

  return (
    <SmartToolbar
      primaryAction={{ label: t('actions.new'), onClick: onNew }}
      secondaryActions={[
        ...(onImport            ? [{ key: 'import', label: t('actions.import'),           onClick: onImport,            icon: Upload,    hideOnMobile: true }] : []),
        ...(onExport            ? [{ key: 'export', label: t('actions.export'),           onClick: onExport,            icon: Download,  hideOnMobile: true }] : []),
        ...(onCopyToClipboard   ? [{ key: 'copy',   label: t('actions.copyClipboard', 'Copy'), onClick: onCopyToClipboard, icon: Clipboard, hideOnMobile: true }] : []),
        ...(onPrint             ? [{ key: 'print',  label: t('actions.print', 'Print'),   onClick: onPrint,             icon: Printer,   hideOnMobile: true }] : []),
      ]}
      bulkActions={bulkActions}
      bulkActionsLabel={t('actions.bulkActions')}
      selectedCount={selectedCount}
      onRefresh={onRefresh}
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
          <SavedViewsMenu />
        </>
      }
    />
  );
}
