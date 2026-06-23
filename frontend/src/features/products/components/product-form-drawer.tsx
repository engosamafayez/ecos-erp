import { useEffect, useState } from 'react';
import axios from 'axios';
import { zodResolver } from '@hookform/resolvers/zod';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';

import { EntityDrawer, EntityForm } from '@/components/crud';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { ProductFormFields } from '@/features/products/components/product-form';
import {
  productSchema,
  toFormValues,
  toPayload,
  type ProductFormValues,
} from '@/features/products/components/product-form-schema';
import { useCreateProduct, useUpdateProduct } from '@/features/products/hooks/use-products';
import type { Product, ProductType } from '@/features/products/types/product';

const FORM_ID = 'product-form';

type ProductFormDrawerProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  product?: Product | null;
  defaultType?: ProductType;
};

function extractMessage(error: unknown): string {
  return axios.isAxiosError(error) && typeof error.response?.data?.message === 'string'
    ? error.response.data.message
    : 'Something went wrong. Please try again.';
}

export function ProductFormDrawer({
  open,
  onOpenChange,
  product,
  defaultType = 'finished_good',
}: ProductFormDrawerProps) {
  const { t } = useTranslation('products');
  const { t: tCommon } = useTranslation('common');
  const isEdit = Boolean(product);
  const createProduct = useCreateProduct();
  const updateProduct = useUpdateProduct();
  const [serverError, setServerError] = useState<string | null>(null);

  const form = useForm<ProductFormValues>({
    resolver: zodResolver(productSchema),
    defaultValues: toFormValues(product, defaultType),
  });

  useEffect(() => {
    if (open) {
      form.reset(toFormValues(product, defaultType));
    }
  }, [open, product, defaultType, form]);

  const isPending = createProduct.isPending || updateProduct.isPending;

  const handleOpenChange = (next: boolean) => {
    if (!next) setServerError(null);
    onOpenChange(next);
  };

  const handleSubmit = (values: ProductFormValues) => {
    setServerError(null);
    const payload = toPayload(values);
    const handlers = {
      onSuccess: () => handleOpenChange(false),
      onError: (error: unknown) => setServerError(extractMessage(error)),
    };

    if (isEdit && product) {
      updateProduct.mutate({ id: product.id, payload }, handlers);
    } else {
      createProduct.mutate(payload, handlers);
    }
  };

  return (
    <EntityDrawer
      open={open}
      onOpenChange={handleOpenChange}
      title={isEdit ? t('drawer.editTitle') : t('drawer.createTitle')}
      description={isEdit ? t('drawer.editSubtitle') : t('drawer.createSubtitle')}
      footer={
        <>
          <Button type="button" variant="outline" onClick={() => handleOpenChange(false)}>
            {tCommon('common.cancel')}
          </Button>
          <Button type="submit" form={FORM_ID} disabled={isPending}>
            {isPending
              ? t('drawer.saving')
              : isEdit
                ? t('drawer.submitEdit')
                : t('drawer.submitCreate')}
          </Button>
        </>
      }
    >
      {serverError ? (
        <Alert variant="destructive" className="mb-4">
          <AlertTitle>{t('drawer.errorTitle')}</AlertTitle>
          <AlertDescription>{serverError}</AlertDescription>
        </Alert>
      ) : null}

      <EntityForm form={form} id={FORM_ID} onSubmit={handleSubmit}>
        <ProductFormFields />
      </EntityForm>
    </EntityDrawer>
  );
}
