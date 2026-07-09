import { useQuery } from '@tanstack/react-query';

import { brandsService } from '@/features/brands/services/brands-service';

export function useBrandDeliveryGeography(brandId: string | null) {
  return useQuery({
    queryKey: ['brand-delivery-geography', brandId ?? ''],
    queryFn: () => brandsService.getDeliveryGeography(brandId!),
    enabled: Boolean(brandId),
    staleTime: 5 * 60 * 1000,
  });
}

export function useBrandDeliveryWindows(brandId: string | null) {
  return useQuery({
    queryKey: ['brand-delivery-windows', brandId ?? ''],
    queryFn: () => brandsService.getDeliveryWindows(brandId!),
    enabled: Boolean(brandId),
    staleTime: 10 * 60 * 1000,
  });
}

export function useBrandConfigHealth(brandId: string | null) {
  return useQuery({
    queryKey: ['brand-config-health', brandId ?? ''],
    queryFn: () => brandsService.getBrandConfigHealth(brandId!),
    enabled: Boolean(brandId),
    staleTime: 60 * 1000,
  });
}
