import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { productsService } from '@/features/products/services/products-service';
import type { Product, ProductsQuery, ProductPayload } from '@/features/products/types/product';

const PRODUCTS_KEY = 'products';

/** Paginated, filtered, sorted products list. */
export function useProductsQuery(params: ProductsQuery) {
  return useQuery({
    queryKey: [PRODUCTS_KEY, params],
    queryFn: () => productsService.list(params),
    placeholderData: keepPreviousData,
  });
}

export function useCreateProduct() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: ProductPayload) => productsService.create(payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [PRODUCTS_KEY] }),
  });
}

export function useUpdateProduct() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: ProductPayload }) =>
      productsService.update(id, payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [PRODUCTS_KEY] }),
  });
}

export function useDeleteProduct() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => productsService.remove(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [PRODUCTS_KEY] }),
  });
}

export function useToggleProductStatus() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (product: Product) =>
      productsService.update(product.id, {
        sku: product.sku,
        barcode: product.barcode ?? undefined,
        name: product.name,
        description: product.description ?? undefined,
        category_id: product.category_id,
        unit_id: product.unit_id,
        product_type: product.product_type,
        is_active: !product.is_active,
        image_url: product.image_url,
        regular_price: product.regular_price,
        sale_price: product.sale_price,
        short_description: product.short_description,
        long_description: product.long_description,
        stock_status: product.stock_status,
      }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [PRODUCTS_KEY] }),
  });
}
