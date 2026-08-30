import { useParams, useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { ArrowLeft, DollarSign } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { ROUTES } from '@/router/routes';

/**
 * Driver collections.
 *
 * The three per-trip money totals this screen used to sum (cash / bank / pre-paid)
 * were never sent by the backend, and the driver money endpoints are deliberately
 * 403-frozen (D-02 baseline). Rather than fabricate zeros, the screen states plainly
 * that collection is not available from the driver runtime.
 */
export function DriverCollectionsPage() {
  const { t } = useTranslation('driver-mobile');
  const { tripId = '' } = useParams<{ tripId: string }>();
  const navigate = useNavigate();

  return (
    <div className="min-h-screen bg-background pb-6">
      {/* Header */}
      <div className="sticky top-0 z-10 bg-background border-b px-4 py-3 flex items-center gap-3">
        <Button
          variant="ghost"
          size="icon"
          onClick={() => navigate(ROUTES.driverTrip.replace(':tripId', tripId))}
        >
          <ArrowLeft className="h-5 w-5" />
        </Button>
        <h1 className="font-semibold text-base">{t(($) => $.collections.title)}</h1>
      </div>

      <div className="p-4">
        <div className="flex flex-col items-center justify-center rounded-xl border bg-muted/30 py-16 text-center text-muted-foreground">
          <DollarSign className="h-12 w-12 mb-3 opacity-30" />
          <p className="text-base font-medium">{t(($) => $.collections.unavailable.title)}</p>
          <p className="text-sm mt-1 max-w-xs">{t(($) => $.collections.unavailable.subtitle)}</p>
        </div>
      </div>
    </div>
  );
}
