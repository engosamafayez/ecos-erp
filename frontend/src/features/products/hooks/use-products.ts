import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { productsService } from '@/features/products/services/products-service';
import type { ProductsQuery, ProductPayload } from '@/features/products/types/product';

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
