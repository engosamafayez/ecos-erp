import { useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { ArrowLeft, PlusCircle, CheckCircle } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Skeleton } from '@/components/ui/skeleton';
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet';
import { ROUTES } from '@/router/routes';
import { useTripReturns, useAddReturn } from '../hooks/use-driver-mobile';
import { ReturnForm } from '../components/return-form';
import type { DeliveryReturn } from '../types/driver-mobile';

/**
 * Driver Returns — the driver DECLARES what is coming back; the WAREHOUSE counts and confirms.
 * TASK-DRIVER-APP-PHASE-5-RETURNS-VEHICLE-RECONCILIATION-001.
 *
 * The driver is NOT the authority for warehouse receipt (§3/§13): this screen posts only the
 * canonical declaration (POST /driver/trips/{id}/returns → a trip return LOG with no inventory
 * effect) and then shows the warehouse's confirmation READ-ONLY. It offers no "confirm receipt"
 * control — recording actual received / accepted / damaged / shortage is the warehouse's, under
 * its own operator permission.
 */
export function DriverReturnsPage() {
  const { t } = useTranslation('driver-mobile');
  const { tripId = '' } = useParams<{ tripId: string }>();
  const navigate = useNavigate();
  const [sheetOpen, setSheetOpen] = useState(false);

  const { data: returns, isLoading } = useTripReturns(tripId);
  const addMutation = useAddReturn(tripId);

  return (
    <div className="min-h-screen bg-background pb-6">
      {/* Header */}
      <div className="sticky top-0 z-10 bg-background border-b px-4 py-3 flex items-center gap-3">
        <Button
          variant="ghost"
          size="icon"
          aria-label={t(($) => $.nav.home)}
          onClick={() => navigate(ROUTES.driverTrip.replace(':tripId', tripId))}
        >
          <ArrowLeft className="h-5 w-5" />
        </Button>
        <h1 className="font-semibold text-base flex-1">{t(($) => $.returns.title)}</h1>
        <Button size="sm" variant="outline" onClick={() => setSheetOpen(true)}>
          <PlusCircle className="mr-1.5 h-4 w-4" />
          {t(($) => $.returns.add)}
        </Button>
      </div>

      <p className="px-4 pt-3 text-xs text-muted-foreground">{t(($) => $.returns.intro)}</p>

      <div className="p-4 space-y-3">
        {isLoading ? (
          Array.from({ length: 3 }).map((_, i) => (
            <Skeleton key={i} className="h-24 w-full rounded-lg" />
          ))
        ) : returns && returns.length > 0 ? (
          returns.map((ret: DeliveryReturn) => (
            <div key={ret.id} className="rounded-lg border p-3 space-y-2">
              <div className="flex items-start justify-between gap-2">
                <div>
                  <p className="font-medium text-sm">{ret.product_name}</p>
                  <p className="text-xs text-muted-foreground">
                    {t(($) => $.returns.qty, { qty: ret.returned_qty })} ·{' '}
                    <Badge variant="outline" className="text-xs">
                      {t(($) => $.returns.type[ret.return_type])}
                    </Badge>
                  </p>
                </div>
                {ret.warehouse_confirmed_at ? (
                  <Badge className="gap-1 bg-green-100 text-green-700 shrink-0">
                    <CheckCircle className="h-3.5 w-3.5" />
                    {t(($) => $.returns.confirmed)}
                  </Badge>
                ) : (
                  <Badge variant="secondary" className="text-xs shrink-0">{t(($) => $.returns.awaitingReceipt)}</Badge>
                )}
              </div>

              {ret.reason && (
                <p className="text-xs text-muted-foreground">{ret.reason}</p>
              )}

              {/* Warehouse-recorded receipt — READ-ONLY (the driver never records it). */}
              {ret.warehouse_confirmed_qty !== null && (
                <p className="text-xs">
                  {t(($) => $.returns.warehouseReceived, { qty: ret.warehouse_confirmed_qty })}
                  {ret.discrepancy_qty !== null && ret.discrepancy_qty !== 0 && (
                    <span className="text-red-600 ml-1">{t(($) => $.returns.discrepancy, { qty: ret.discrepancy_qty })}</span>
                  )}
                </p>
              )}
            </div>
          ))
        ) : (
          <p className="text-center text-sm text-muted-foreground py-10">
            {t(($) => $.returns.empty)}
          </p>
        )}
      </div>

      {/* Add return sheet */}
      <Sheet open={sheetOpen} onOpenChange={setSheetOpen}>
        <SheetContent side="bottom" className="max-h-[85vh] overflow-y-auto">
          <SheetHeader className="mb-4">
            <SheetTitle>{t(($) => $.returns.recordTitle)}</SheetTitle>
          </SheetHeader>
          <ReturnForm
            orderId={0}
            isLoading={addMutation.isPending}
            onSubmit={(payload) => {
              addMutation.mutate(payload, {
                onSuccess: () => setSheetOpen(false),
              });
            }}
            onCancel={() => setSheetOpen(false)}
          />
        </SheetContent>
      </Sheet>
    </div>
  );
}
