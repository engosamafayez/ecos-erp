import { ChevronDown, Download, Plus, RefreshCw, Upload } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { ColumnVisibilityMenu } from '@/components/data-grid/column-visibility-menu';
import type { ColumnMeta, ColumnVisibilityState } from '@/components/data-grid/types';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';

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
 * Standard ERP actions toolbar: New / Import / Export / Bulk Actions | Refresh / Column Manager.
 * Lives between the Page Header and Status Tabs.
 * The Smart Operations Toolbar (DD-028/029) is a separate layer below the Status Tabs.
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
  const hasSelection = selectedCount > 0;

  return (
    <div className="flex items-center gap-2 border-b bg-background px-4 py-2">
      {/* ── Left: primary actions ── */}
      <div className="flex items-center gap-1.5">
        <Button size="sm" onClick={onNew} className="gap-1.5">
          <Plus className="size-3.5" />
          {t('actions.new')}
        </Button>

        <Button
          variant="outline"
          size="sm"
          onClick={onImport}
          className="hidden gap-1.5 sm:flex"
          aria-label={t('actions.import')}
        >
          <Upload className="size-3.5" />
          {t('actions.import')}
        </Button>

        <Button
          variant="outline"
          size="sm"
          onClick={onExport}
          className="hidden gap-1.5 sm:flex"
          aria-label={t('actions.export')}
        >
          <Download className="size-3.5" />
          {t('actions.export')}
        </Button>

        {/* Bulk Actions — only visible when rows are selected */}
        {hasSelection ? (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="secondary" size="sm" className="gap-1.5">
                {t('actions.bulkActions')}
                <span className="inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-foreground/20 px-1 text-[10px] font-semibold tabular-nums">
                  {selectedCount}
                </span>
                <ChevronDown className="size-3" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-48">
              <DropdownMenuItem onClick={() => onBulkAction?.('confirm')}>
                {t('bulk.confirm')}
              </DropdownMenuItem>
              <DropdownMenuItem onClick={() => onBulkAction?.('shipping')}>
                {t('bulk.markShipping')}
              </DropdownMenuItem>
              <DropdownMenuItem onClick={() => onBulkAction?.('delivered')}>
                {t('bulk.markDelivered')}
              </DropdownMenuItem>
              <DropdownMenuSeparator />
              <DropdownMenuItem
                variant="destructive"
                onClick={() => onBulkAction?.('cancel')}
              >
                {t('bulk.cancel')}
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        ) : null}
      </div>

      <div className="flex-1" />

      {/* ── Right: view controls ── */}
      <div className="flex items-center gap-1.5">
        <Button
          variant="ghost"
          size="icon"
          className="size-8"
          onClick={onRefresh}
          disabled={isFetching}
          aria-label={t('actions.refresh')}
        >
          <RefreshCw className={cn('size-3.5', isFetching && 'animate-spin')} />
        </Button>

        <ColumnVisibilityMenu
          columns={columns}
          visibility={columnVisibility}
          onToggle={onColumnToggle}
          onReset={onColumnReset}
          label={t('toolbar.columns')}
        />
      </div>
    </div>
  );
}
