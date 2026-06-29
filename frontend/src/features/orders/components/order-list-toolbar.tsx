import { Download, Upload } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { ColumnVisibilityMenu } from '@/components/data-grid/column-visibility-menu';
import { SavedViewsMenu } from '@/components/data-grid/saved-views-menu';
import { SmartToolbar, type SmartToolbarBulkAction } from '@/components/data-grid/smart-toolbar';
import type { ColumnMeta, ColumnVisibilityState } from '@/components/data-grid/types';

export type BulkAction = 'confirm' | 'shipping' | 'delivered' | 'cancel';

type OrderListToolbarProps = {
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
  onBulkAction?: (action: BulkAction) => void;
};

/**
 * Orders list toolbar — thin wrapper over SmartToolbar with order-specific labels and bulk actions.
 * The Smart Operations Toolbar (DD-028/029) is a separate layer rendered below the Status Tabs.
 */
export function OrderListToolbar({
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
  onBulkAction,
}: OrderListToolbarProps) {
  const { t } = useTranslation('orders');

  const bulkActions: SmartToolbarBulkAction[] = [
    { key: 'confirm',   label: t('bulk.confirm'),       onClick: () => onBulkAction?.('confirm') },
    { key: 'shipping',  label: t('bulk.markShipping'),  onClick: () => onBulkAction?.('shipping') },
    { key: 'delivered', label: t('bulk.markDelivered'), onClick: () => onBulkAction?.('delivered') },
    { key: 'cancel',    label: t('bulk.cancel'),        onClick: () => onBulkAction?.('cancel'), destructive: true, separator: true },
  ];

  return (
    <SmartToolbar
      primaryAction={{ label: t('actions.new'), onClick: onNew }}
      secondaryActions={[
        ...(onImport ? [{ key: 'import', label: t('actions.import'), onClick: onImport, icon: Upload, hideOnMobile: true }] : []),
        ...(onExport ? [{ key: 'export', label: t('actions.export'), onClick: onExport, icon: Download, hideOnMobile: true }] : []),
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
