import { useTranslation } from 'react-i18next';

import { ProductsView } from '@/features/products/components/products-view';

export function ProductsPage() {
  const { t } = useTranslation('products');

  return (
    <ProductsView
      productType="finished_good"
      title={t('finishedGoods.title')}
      subtitle={t('finishedGoods.subtitle')}
      breadcrumbLabel={t('finishedGoods.title')}
      searchPlaceholder={t('search')}
      createLabel={t('actions.new')}
      entityNoun="product"
    />
  );
}
