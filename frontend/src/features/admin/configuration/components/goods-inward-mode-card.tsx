import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { AlertTriangle, Loader2, PackageCheck, Receipt, Save } from 'lucide-react';

import { ConfirmDialog } from '@/components/crud';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { useToast } from '@/components/ds/use-toast';
import { usePermission } from '@/features/authorization';
import { cn } from '@/lib/utils';

import { useGoodsInwardMode, useUpdateGoodsInwardMode } from '../hooks/use-configuration';
import type { GoodsInwardModeValue } from '../types/configuration';

const OPTION_ICONS: Record<GoodsInwardModeValue, typeof PackageCheck> = {
  goods_receipt:    PackageCheck,
  supplier_invoice: Receipt,
};

/**
 * Goods Inward Mode — the company's authoritative purchase-inbound document.
 *
 * THIS COMPONENT DECIDES NOTHING. It reads the current mode from the API and writes the
 * chosen one back; `GoodsInwardAuthority` on the server remains the sole authority for which
 * document posts inventory, creates FIFO layers and writes the stock ledger. There is no
 * business rule in this file, and the default is never computed here — `is_default` arrives
 * from the backend.
 *
 * Changing this alters how purchases reach stock for the whole company, so the write is
 * behind a confirmation step rather than firing on selection.
 */
export function GoodsInwardModeCard() {
  const { t } = useTranslation('settings');
  const { toast } = useToast();
  const { can } = usePermission();

  const { data, isLoading, isError, refetch } = useGoodsInwardMode();
  const { mutateAsync, isPending } = useUpdateGoodsInwardMode();

  const [pendingMode, setPendingMode] = useState<GoodsInwardModeValue | null>(null);
  const [reason, setReason] = useState('');

  const mayManage = can('configuration.settings.manage');

  // ── Loading ────────────────────────────────────────────────────────────────
  if (isLoading) {
    return (
      <Card>
        <CardHeader>
          <CardTitle>{t($ => $.goodsInward.title)}</CardTitle>
          <CardDescription>{t($ => $.goodsInward.description)}</CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          <Skeleton className="h-20 w-full" />
          <Skeleton className="h-20 w-full" />
        </CardContent>
      </Card>
    );
  }

  // ── Error ──────────────────────────────────────────────────────────────────
  if (isError || !data) {
    return (
      <Card>
        <CardHeader>
          <CardTitle>{t($ => $.goodsInward.title)}</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col items-start gap-3">
          <p className="flex items-center gap-2 text-sm text-destructive">
            <AlertTriangle className="h-4 w-4 shrink-0" />
            {t($ => $.goodsInward.loadError)}
          </p>
          <Button variant="outline" size="sm" onClick={() => void refetch()}>
            {t($ => $.goodsInward.retry)}
          </Button>
        </CardContent>
      </Card>
    );
  }

  async function handleConfirm() {
    if (pendingMode === null) return;

    try {
      await mutateAsync({ mode: pendingMode, reason: reason.trim() || undefined });
      toast({ title: t($ => $.goodsInward.saved), type: 'success' });
      setPendingMode(null);
      setReason('');
    } catch {
      // The mutation already rolled back nothing (the server rejected the write), so the
      // rendered value stays the server's. Surface the failure instead of failing silently.
      toast({ title: t($ => $.goodsInward.saveFailed), type: 'error' });
    }
  }

  return (
    <Card data-testid="goods-inward-mode-card">
      <CardHeader>
        <div className="flex flex-wrap items-center gap-2">
          <CardTitle>{t($ => $.goodsInward.title)}</CardTitle>
          {data.is_default && (
            <Badge variant="secondary" data-testid="goods-inward-default-badge">
              {t($ => $.goodsInward.defaultBadge)}
            </Badge>
          )}
        </div>
        <CardDescription>{t($ => $.goodsInward.description)}</CardDescription>
      </CardHeader>

      <CardContent className="space-y-4">
        {/* Options come from the server, so the frontend can never drift from the enum. */}
        <div className="flex flex-col gap-3 sm:flex-row">
          {data.options.map(opt => {
            const Icon = OPTION_ICONS[opt.value] ?? PackageCheck;
            const active = data.mode === opt.value;

            return (
              <button
                key={opt.value}
                type="button"
                role="radio"
                aria-checked={active}
                disabled={!mayManage || isPending}
                data-testid={`goods-inward-option-${opt.value}`}
                onClick={() => { if (!active) setPendingMode(opt.value); }}
                className={cn(
                  'flex-1 rounded-lg border p-4 text-start transition-colors',
                  'disabled:cursor-not-allowed disabled:opacity-60',
                  active
                    ? 'border-primary bg-primary/5'
                    : 'border-border hover:border-primary/40',
                )}
              >
                <span className="flex items-center gap-2 font-medium">
                  <Icon className="h-4 w-4 shrink-0" />
                  {opt.value === 'goods_receipt'
                    ? t($ => $.goodsInward.optionGoodsReceipt)
                    : t($ => $.goodsInward.optionSupplierInvoice)}
                </span>
                <span className="mt-1 block text-sm text-muted-foreground">
                  {opt.value === 'goods_receipt'
                    ? t($ => $.goodsInward.optionGoodsReceiptDesc)
                    : t($ => $.goodsInward.optionSupplierInvoiceDesc)}
                </span>
              </button>
            );
          })}
        </div>

        {mayManage ? (
          <div className="space-y-2">
            <label className="text-sm text-muted-foreground" htmlFor="goods-inward-reason">
              {t($ => $.goodsInward.reasonLabel)}
            </label>
            <Input
              id="goods-inward-reason"
              value={reason}
              onChange={e => setReason(e.target.value)}
              placeholder={t($ => $.goodsInward.reasonPlaceholder)}
              maxLength={500}
              disabled={isPending}
            />
          </div>
        ) : (
          <p className="text-sm text-muted-foreground" data-testid="goods-inward-readonly">
            {t($ => $.goodsInward.permissionDenied)}
          </p>
        )}
      </CardContent>

      <ConfirmDialog
        open={pendingMode !== null}
        onOpenChange={open => { if (!open) setPendingMode(null); }}
        title={t($ => $.goodsInward.confirmTitle)}
        description={t($ => $.goodsInward.confirmDescription)}
        confirmLabel={
          isPending
            ? t($ => $.goodsInward.saving)
            : t($ => $.goodsInward.confirmAction)
        }
        onConfirm={() => void handleConfirm()}
        loading={isPending}
      />

      {isPending && (
        <span className="sr-only" role="status" data-testid="goods-inward-saving">
          <Loader2 className="h-3 w-3 animate-spin" />
          <Save className="h-3 w-3" />
          {t($ => $.goodsInward.saving)}
        </span>
      )}
    </Card>
  );
}
