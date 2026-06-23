import { z } from 'zod';

import type { Product, ProductPayload, ProductType } from '@/features/products/types/product';

export const productSchema = z.object({
  sku: z.string().min(1, 'SKU is required.').max(100),
  barcode: z.string().max(100).optional(),
  name: z.string().min(1, 'Name is required.').max(255),
  description: z.string().max(1000).optional(),
  category_id: z.string().min(1, 'Category is required.'),
  unit_id: z.string().min(1, 'Unit is required.'),
  product_type: z.enum(['finished_good', 'raw_material']),
  is_active: z.boolean(),
});

export type ProductFormValues = z.infer<typeof productSchema>;

/** Build form values from an existing product (or empty defaults for create). */
export function toFormValues(
  product?: Product | null,
  defaultType: ProductType = 'finished_good',
): ProductFormValues {
  return {
    sku: product?.sku ?? '',
    barcode: product?.barcode ?? '',
    name: product?.name ?? '',
    description: product?.description ?? '',
    category_id: product?.category_id ?? '',
    unit_id: product?.unit_id ?? '',
    product_type: product?.product_type ?? defaultType,
    is_active: product?.is_active ?? true,
  };
}

export function toPayload(values: ProductFormValues): ProductPayload {
  return { ...values };
}
