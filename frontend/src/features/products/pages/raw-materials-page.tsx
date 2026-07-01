import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { PageHeader } from '@/components/crud';
import { Card, CardContent } from '@/components/ui/card';
import { RawMaterialDetailDrawer } from '@/features/products/components/raw-material-detail-drawer';
import { ProductFormDrawer } from '@/features/products/components/product-form-drawer';
import { ProductsView } from '@/features/products/components/products-view';
import type { Product } from '@/features/products/types/product';
import { ROUTES } from '@/router/routes';

export function RawMaterialsPage() {
  const { t } = useTranslation('products');
  const { t: tCommon } = useTranslation('common');

  const [detailOpen, setDetailOpen] = useState(false);
  const [detailMaterial, setDetailMaterial] = useState<Product | null>(null);
  const [detailTab, setDetailTab] = useState('overview');

  const [editOpen, setEditOpen] = useState(false);
  const [editMaterial, setEditMaterial] = useState<Product | null>(null);

  function openDetail(material: Product, tab = 'overview') {
    setDetailMaterial(material);
    setDetailTab(tab);
    setDetailOpen(true);
  }

  function openEdit(material: Product) {
    setEditMaterial(material);
    setEditOpen(true);
  }

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
            onView={(material) => openDetail(material)}
            onViewTab={(material, tab) => openDetail(material, tab)}
          />
        </CardContent>
      </Card>

      <RawMaterialDetailDrawer
        material={detailMaterial}
        open={detailOpen}
        onOpenChange={(open) => {
          setDetailOpen(open);
          if (!open) setDetailMaterial(null);
        }}
        onEdit={openEdit}
        initialTab={detailTab}
      />

      <ProductFormDrawer
        open={editOpen}
        onOpenChange={(open) => {
          setEditOpen(open);
          if (!open) setEditMaterial(null);
        }}
        product={editMaterial}
        defaultType="raw_material"
      />
    </div>
  );
}
