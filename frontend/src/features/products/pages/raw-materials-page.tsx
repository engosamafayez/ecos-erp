import { useTranslation } from 'react-i18next';

import { PageHeader } from '@/components/crud';
import { Card, CardContent } from '@/components/ui/card';
import { ProductsView } from '@/features/products/components/products-view';
import { ROUTES } from '@/router/routes';

export function RawMaterialsPage() {
  const { t } = useTranslation('products');
  const { t: tCommon } = useTranslation('common');

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={t('rawMaterials.title')}
        subtitle={t('rawMaterials.subtitle')}
        breadcrumbs={[
          { label: tCommon('home'), to: ROUTES.dashboard },
          { label: 'Products', to: ROUTES.inventoryProducts },
          { label: t('rawMaterials.title') },
        ]}
      />

      <Card>
        <CardContent className="flex flex-col gap-4 pt-6">
          <ProductsView
            headless
            productType="raw_material"
            title={t('rawMaterials.title')}
            subtitle={t('rawMaterials.subtitle')}
            breadcrumbLabel={t('rawMaterials.title')}
            searchPlaceholder={t('search')}
            createLabel={t('actions.new')}
          />
        </CardContent>
      </Card>
    </div>
  );
}
