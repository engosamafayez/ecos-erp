import { useTranslation } from 'react-i18next';

import { ProductsView } from '@/features/products/components/products-view';

export function RawMaterialsPage() {
  const { t } = useTranslation('products');

  return (
    <ProductsView
      productType="raw_material"
      title={t('rawMaterials.title')}
      subtitle={t('rawMaterials.subtitle')}
      breadcrumbLabel={t('rawMaterials.title')}
      searchPlaceholder={t('search')}
      createLabel={t('actions.new')}
      entityNoun="raw material"
    />
  );
}
