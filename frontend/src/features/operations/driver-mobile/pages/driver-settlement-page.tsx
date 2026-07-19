import { useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { ArrowLeft } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Skeleton } from '@/components/ui/skeleton';
import { ROUTES } from '@/router/routes';
import { useTripSettlement, useSubmitSettlement, useCloseTrip } from '../hooks/use-driver-mobile';
import { SettlementSummary } from '../components/settlement-summary';

export function DriverSettlementPage() {
  const { tripId = '' } = useParams<{ tripId: string }>();
  const navigate = useNavigate();

  const { data: settlement, isLoading } = useTripSettlement(tripId);
  const submitMutation = useSubmitSettlement(tripId);
  const closeMutation  = useCloseTrip(tripId);

  const [cashSubmitted, setCashSubmitted] = useState('');
  const [notes,         setNotes]         = useState('');

  if (isLoading) {
    return (
      <div className="p-4 space-y-4">
        <Skeleton className="h-10 w-full" />
        <Skeleton className="h-60 w-full" />
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-background pb-8">
      {/* Header */}
      <div className="sticky top-0 z-10 bg-background border-b px-4 py-3 flex items-center gap-3">
        <Button
          variant="ghost"
          size="icon"
          onClick={() => navigate(ROUTES.driverTrip.replace(':tripId', tripId))}
        >
          <ArrowLeft className="h-5 w-5" />
        </Button>
        <h1 className="font-semibold text-base">التسوية</h1>
      </div>

      <div className="p-4 space-y-4">
        {settlement && <SettlementSummary settlement={settlement} />}

        {/* Submit form — only when draft */}
        {settlement?.status === 'draft' && (
          <div className="rounded-xl border p-4 space-y-3">
            <p className="font-semibold text-sm">تقديم التسوية</p>

            <div className="space-y-1.5">
              <Label>النقد الذي تقدّمه (EGP)</Label>
              <Input
                type="number"
                min="0"
                step="0.01"
                value={cashSubmitted}
                onChange={(e) => setCashSubmitted(e.target.value)}
                placeholder="0.00"
              />
            </div>

            <div className="space-y-1.5">
              <Label>ملاحظات</Label>
              <Textarea
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
                placeholder="ملاحظات حول أي فارق في التسوية..."
                rows={2}
              />
            </div>

            <Button
              className="w-full"
              disabled={!cashSubmitted || submitMutation.isPending}
              onClick={() =>
                submitMutation.mutate({
                  cashSubmitted: parseFloat(cashSubmitted),
                  notes:         notes || undefined,
                })
              }
            >
              {submitMutation.isPending ? 'جارٍ التقديم...' : 'تقديم التسوية'}
            </Button>
          </div>
        )}

        {/* Close trip (verified status) */}
        {settlement?.status === 'verified' && (
          <Button
            variant="default"
            className="w-full"
            onClick={() => closeMutation.mutate()}
            disabled={closeMutation.isPending}
          >
            {closeMutation.isPending ? 'جارٍ الإغلاق...' : 'إغلاق الرحلة'}
          </Button>
        )}

        {/* Custody + Returns links */}
        <div className="grid grid-cols-2 gap-2">
          <Button
            variant="outline"
            onClick={() => navigate(ROUTES.driverTripCustody.replace(':tripId', tripId))}
          >
            مرتجعات العهدة
          </Button>
          <Button
            variant="outline"
            onClick={() => navigate(ROUTES.driverTripReturns.replace(':tripId', tripId))}
          >
            مرتجعات المنتجات
          </Button>
        </div>
      </div>
    </div>
  );
}
