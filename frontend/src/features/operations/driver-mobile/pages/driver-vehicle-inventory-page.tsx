import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { AlertTriangle, ArrowLeft, Eye, Package, PackageCheck, RotateCcw, Truck } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import { ROUTES } from '@/router/routes';

import { useVehicleInventory } from '../hooks/use-driver-mobile';
import {
  buildProductReconciliation,
  buildReturnTotals,
  type ProductReconciliationStatus,
} from '../lib/returns-reconciliation';
import type { VehicleInventoryItemRow } from '../types/driver-mobile';

/**
 * Driver Vehicle Inventory & Return Reconciliation — READ-ONLY visibility of what the
 * vehicle carries and what is expected back. It consumes the canonical driver-scoped read
 * model (GET /driver/vehicle-inventory) and derives ONLY presentation:
 *   - Expected Return = max(0, loaded − delivered) — the canonical ADR-015 §6.4 identity,
 *     never an arbitrary counter (§1);
 *   - a per-product reconciliation status (§4) and the visible discrepancy (§6).
 *
 * It records nothing. The driver can never edit, add, transfer, reconcile or receive stock
 * here — actual received / accepted / damaged / shortage are the Warehouse's to record (§3),
 * surfaced back through the canonical custody figures once the warehouse posts receipt.
 */
export function DriverVehicleInventoryPage() {
  const { t } = useTranslation('driver-mobile');
  const navigate = useNavigate();
  const { data, isLoading, isError, isFetching, refetch } = useVehicleInventory();

  const summary = data?.summary ?? null;
  const items = data?.items ?? [];
  const totals = buildReturnTotals(items);

  const noData = data === undefined;
  const showSkeleton = noData && !isError && (isFetching || isLoading);
  const showError = isError || (noData && !showSkeleton);
  const isEmpty = !showSkeleton && !showError && items.length === 0;

  return (
    <div className="min-h-screen bg-background pb-8">
      <div className="sticky top-0 z-10 flex items-center gap-3 border-b bg-background px-4 py-3">
        <Button
          variant="ghost"
          size="icon"
          aria-label={t(($) => $.nav.home)}
          onClick={() => navigate(ROUTES.driverHome)}
        >
          <ArrowLeft className="h-5 w-5" aria-hidden="true" />
        </Button>
        <div className="min-w-0 flex-1">
          <h1 className="truncate text-base font-semibold leading-tight">{t(($) => $.vehicleInventory.title)}</h1>
          <p className="text-xs text-muted-foreground">{t(($) => $.vehicleInventory.subtitle)}</p>
        </div>
        <Badge variant="secondary" className="gap-1 shrink-0">
          <Eye className="h-3 w-3" aria-hidden="true" />
          {t(($) => $.vehicleInventory.readOnly)}
        </Badge>
      </div>

      <div className="space-y-4 p-4">
        {showSkeleton ? (
          <>
            <Skeleton className="h-24 w-full rounded-xl" />
            <Skeleton className="h-20 w-full rounded-xl" />
            <Skeleton className="h-20 w-full rounded-xl" />
          </>
        ) : showError ? (
          <div className="flex flex-col items-center justify-center gap-3 py-16 text-muted-foreground">
            <AlertTriangle className="h-10 w-10 text-destructive/70" aria-hidden="true" />
            <p className="text-sm">{t(($) => $.vehicleInventory.error)}</p>
            <Button variant="outline" size="sm" onClick={() => void refetch()}>
              {t(($) => $.vehicleInventory.retry)}
            </Button>
          </div>
        ) : isEmpty ? (
          <div className="flex flex-col items-center justify-center py-20 text-center text-muted-foreground">
            <Truck className="mb-3 h-12 w-12 opacity-30" aria-hidden="true" />
            <p className="text-base font-medium">{t(($) => $.vehicleInventory.empty.title)}</p>
            <p className="mt-1 text-sm">{t(($) => $.vehicleInventory.empty.subtitle)}</p>
          </div>
        ) : (
          <>
            {/* Summary — the four canonical totals. */}
            <div className="grid grid-cols-2 gap-3">
              <SummaryTile
                icon={Package}
                label={t(($) => $.vehicleInventory.summary.loaded)}
                value={summary?.total_quantity_loaded ?? 0}
              />
              <SummaryTile
                icon={PackageCheck}
                label={t(($) => $.vehicleInventory.summary.delivered)}
                value={summary?.total_quantity_delivered ?? 0}
                tone="text-green-600"
              />
              <SummaryTile
                icon={RotateCcw}
                label={t(($) => $.vehicleInventory.summary.returned)}
                value={summary?.total_quantity_returned ?? 0}
                tone="text-amber-600"
              />
              <SummaryTile
                icon={Truck}
                label={t(($) => $.vehicleInventory.summary.onHand)}
                value={summary?.total_quantity_on_hand ?? 0}
                emphasis
              />
            </div>

            {/* Reconciliation banner — Expected Return (loaded − delivered) vs received vs remaining. */}
            <div className="rounded-xl border bg-card p-4">
              <div className="grid grid-cols-3 gap-2 text-center">
                <BannerStat label={t(($) => $.vehicleInventory.recon.expectedReturn)} value={totals.expectedReturn} />
                <BannerStat
                  label={t(($) => $.vehicleInventory.recon.receivedBack)}
                  value={totals.received}
                  tone="text-green-600"
                />
                <BannerStat
                  label={t(($) => $.vehicleInventory.recon.stillOnVehicle)}
                  value={totals.remaining}
                  tone="text-amber-600"
                />
              </div>
              <p className="mt-3 border-t pt-3 text-[11px] leading-snug text-muted-foreground">
                {t(($) => $.vehicleInventory.recon.warehouseNote)}
              </p>
            </div>

            {/* Per-product reconciliation rows. */}
            <div className="space-y-3">
              {items.map((item) => (
                <InventoryRow key={item.id} item={item} />
              ))}
            </div>
          </>
        )}
      </div>
    </div>
  );
}

function SummaryTile({
  icon: Icon,
  label,
  value,
  tone,
  emphasis,
}: {
  icon: typeof Package;
  label: string;
  value: number;
  tone?: string;
  emphasis?: boolean;
}) {
  return (
    <div
      className={`rounded-xl border bg-card p-4 text-center ${emphasis ? 'ring-1 ring-primary/30' : ''}`}
    >
      <Icon className={`mx-auto mb-1 h-5 w-5 ${tone ?? 'text-muted-foreground'}`} aria-hidden="true" />
      <p className={`text-2xl font-bold tabular-nums leading-none ${emphasis ? 'text-primary' : ''}`}>{value}</p>
      <p className="mt-1 text-xs text-muted-foreground">{label}</p>
    </div>
  );
}

function BannerStat({ label, value, tone }: { label: string; value: number; tone?: string }) {
  return (
    <div>
      <p className={`text-xl font-bold tabular-nums leading-none ${tone ?? ''}`}>{value}</p>
      <p className="mt-1 text-[11px] text-muted-foreground">{label}</p>
    </div>
  );
}

const STATUS_TONE: Record<ProductReconciliationStatus, string> = {
  fully_delivered: 'bg-muted text-muted-foreground',
  awaiting_return: 'bg-amber-100 text-amber-700',
  reconciled: 'bg-green-100 text-green-700',
  partial_return: 'bg-red-100 text-red-700',
};

const STATUS_KEY: Record<ProductReconciliationStatus, 'fullyDelivered' | 'awaitingReturn' | 'reconciled' | 'partialReturn'> = {
  fully_delivered: 'fullyDelivered',
  awaiting_return: 'awaitingReturn',
  reconciled: 'reconciled',
  partial_return: 'partialReturn',
};

function InventoryRow({ item }: { item: VehicleInventoryItemRow }) {
  const { t } = useTranslation('driver-mobile');
  const label = item.name_snapshot || item.sku_snapshot;
  const recon = buildProductReconciliation(item);

  return (
    <div className="rounded-xl border bg-card p-4 shadow-sm">
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="truncate text-sm font-semibold">{label}</p>
          <p className="text-xs text-muted-foreground" dir="ltr">{item.sku_snapshot}</p>
        </div>
        <span className={cn('shrink-0 rounded-full px-2 py-0.5 text-[10px] font-medium', STATUS_TONE[recon.status])}>
          {t(($) => $.vehicleInventory.recon.status[STATUS_KEY[recon.status]])}
        </span>
      </div>
      <div className="mt-3 grid grid-cols-4 gap-x-2 text-xs">
        <QtyCell label={t(($) => $.vehicleInventory.perProduct.loaded)} value={item.quantity_loaded} />
        <QtyCell
          label={t(($) => $.vehicleInventory.perProduct.delivered)}
          value={item.quantity_delivered}
          tone="text-green-600"
        />
        <QtyCell
          label={t(($) => $.vehicleInventory.perProduct.expectedReturn)}
          value={recon.expectedReturn}
        />
        <QtyCell
          label={t(($) => $.vehicleInventory.perProduct.returned)}
          value={item.quantity_returned}
          tone="text-amber-600"
        />
      </div>
      {recon.hasDiscrepancy && (
        <p className="mt-2 text-[11px] font-medium text-red-600">
          {t(($) => $.vehicleInventory.recon.shortage, { count: recon.remaining })}
        </p>
      )}
    </div>
  );
}

function QtyCell({ label, value, tone }: { label: string; value: number; tone?: string }) {
  return (
    <div className="flex flex-col items-center rounded-lg bg-muted/40 py-2">
      <span className={`text-base font-semibold tabular-nums ${tone ?? ''}`}>{value}</span>
      <span className="text-[10px] text-muted-foreground">{label}</span>
    </div>
  );
}
