import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { ArrowLeft, Building2, ShoppingCart } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { ROUTES } from '@/router/routes';

import { GoodsInwardModeCard } from '../components/goods-inward-mode-card';

/**
 * Company-level configuration.
 *
 * `ROUTES.configurationCompany` was already defined and already linked from the Configuration
 * OS landing page, but was never registered in the router — the "Company Settings" card led to
 * the 404 page. This page is that missing destination, so adding it also repairs the dead link.
 *
 * Company-level rather than brand-level deliberately: every card on the landing grid routes
 * into the brand policy workspace, and Goods Inward Mode is a property of the COMPANY
 * (`companies.goods_inward_mode`), so placing it there would have written the wrong scope.
 */
export function CompanyConfigurationPage() {
  const { t } = useTranslation('settings');
  const navigate = useNavigate();

  return (
    <div className="space-y-6 p-4 sm:p-6">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-start gap-3">
          <span className="rounded-lg bg-primary/10 p-2 text-primary">
            <Building2 className="h-5 w-5" />
          </span>
          <div className="min-w-0">
            <h1 className="text-xl font-semibold">{t($ => $.companyConfig.title)}</h1>
            <p className="text-sm text-muted-foreground">{t($ => $.companyConfig.subtitle)}</p>
          </div>
        </div>

        <Button
          variant="outline"
          size="sm"
          className="gap-2 self-start"
          onClick={() => navigate(ROUTES.configurationOs)}
        >
          <ArrowLeft className="h-4 w-4" />
          {t($ => $.companyConfig.back)}
        </Button>
      </div>

      <section className="space-y-3">
        <h2 className="flex items-center gap-2 text-sm font-medium text-muted-foreground">
          <ShoppingCart className="h-4 w-4" />
          {t($ => $.companyConfig.procurementSection)}
        </h2>

        <GoodsInwardModeCard />
      </section>
    </div>
  );
}

export default CompanyConfigurationPage;
