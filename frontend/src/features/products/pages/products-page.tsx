import { useTranslation } from 'react-i18next';

import { PageHeader } from '@/components/crud';
import { Card, CardContent } from '@/components/ui/card';
import { ProductsView } from '@/features/products/components/products-view';
import { ROUTES } from '@/router/routes';

export function ProductsPage() {
  const { t } = useTranslation('products');
  const { t: tCommon } = useTranslation('common');

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={t('finishedGoods.title')}
        subtitle={t('finishedGoods.subtitle')}
        breadcrumbs={[
          { label: tCommon('home'), to: ROUTES.dashboard },
          { label: 'Products', to: ROUTES.inventoryProducts },
          { label: t('finishedGoods.title') },
        ]}
      />

      <Card>
        <CardContent className="flex flex-col gap-4 pt-6">
          <ProductsView
            headless
            productType="finished_good"
            title={t('finishedGoods.title')}
            subtitle={t('finishedGoods.subtitle')}
            breadcrumbLabel={t('finishedGoods.title')}
            searchPlaceholder={t('search')}
            createLabel={t('actions.new')}
          />
        </CardContent>
      </Card>
    </div>
  );
}
