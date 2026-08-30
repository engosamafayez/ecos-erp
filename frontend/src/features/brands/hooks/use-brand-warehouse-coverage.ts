import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import {
  brandWarehouseCoverageService,
  type BrandWarehouseCoverageRow,
} from '@/features/brands/services/brand-warehouse-coverage-service';

const key = (brandId: string) => ['brand-warehouse-coverage', brandId] as const;

export function useBrandWarehouseCoverage(brandId: string, enabled = true) {
  return useQuery({
    queryKey: key(brandId),
    queryFn: () => brandWarehouseCoverageService.list(brandId),
    enabled: enabled && Boolean(brandId),
  });
}

export function useSaveBrandWarehouseCoverage(brandId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (warehouseIds: string[]) => brandWarehouseCoverageService.save(brandId, warehouseIds),
    onSuccess: (data: BrandWarehouseCoverageRow[]) => {
      qc.setQueryData(key(brandId), data);
    },
  });
}
