import { z } from 'zod';

import type { Product, ProductPayload, ProductStockStatus, ProductType } from '@/features/products/types/product';

export const productSchema = z.object({
  sku: z.string().min(1, 'SKU is required.').max(100),
  barcode: z.string().max(100).optional(),
  name: z.string().min(1, 'Name is required.').max(255),
  description: z.string().max(1000).optional(),
  category_id: z.string().min(1, 'Category is required.'),
  unit_id: z.string().min(1, 'Unit is required.'),
  product_type: z.enum(['finished_good', 'raw_material']),
  is_active: z.boolean(),
  image_url: z.string().url().max(2048).optional().or(z.literal('')),
  regular_price: z.number().min(0).optional().nullable(),
  sale_price: z.number().min(0).optional().nullable(),
  short_description: z.string().max(500).optional(),
  long_description: z.string().optional(),
  stock_status: z.enum(['instock', 'outofstock', 'onbackorder']).optional().nullable(),
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
    image_url: product?.image_url ?? '',
    regular_price: product?.regular_price ?? null,
    sale_price: product?.sale_price ?? null,
    short_description: product?.short_description ?? '',
    long_description: product?.long_description ?? '',
    stock_status: (product?.stock_status as ProductStockStatus | null | undefined) ?? null,
  };
}

export function toPayload(values: ProductFormValues): ProductPayload {
  return {
    ...values,
    image_url: values.image_url === '' ? null : values.image_url,
    short_description: values.short_description === '' ? null : values.short_description,
    long_description: values.long_description === '' ? null : values.long_description,
  };
}
