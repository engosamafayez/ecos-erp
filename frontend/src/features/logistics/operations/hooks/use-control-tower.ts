import { keepPreviousData, useQuery } from '@tanstack/react-query';

import { controlTowerService } from '../services/control-tower-service';

const KEY = 'logistics-control-tower';

export function useShippingSummary() {
  return useQuery({
    queryKey: [KEY, 'shipping'],
    queryFn: () => controlTowerService.shipping(),
    refetchInterval: 30_000,
  });
}

export function useCustodySummary() {
  return useQuery({
    queryKey: [KEY, 'custody'],
    queryFn: () => controlTowerService.custody(),
    refetchInterval: 30_000,
  });
}

export function useReturnsSummary() {
  return useQuery({
    queryKey: [KEY, 'returns'],
    queryFn: () => controlTowerService.returns(),
    refetchInterval: 30_000,
  });
}

export function useSettlementSummary() {
  return useQuery({
    queryKey: [KEY, 'settlement'],
    queryFn: () => controlTowerService.settlement(),
    refetchInterval: 30_000,
  });
}

export function useExternalCarrierSummary() {
  return useQuery({
    queryKey: [KEY, 'external-carrier'],
    queryFn: () => controlTowerService.externalCarrier(),
    refetchInterval: 30_000,
  });
}

export function useExpectedReturns(page = 1, perPage = 25) {
  return useQuery({
    queryKey: [KEY, 'expected-returns', page, perPage],
    queryFn: () => controlTowerService.expectedReturns(page, perPage),
    placeholderData: keepPreviousData,
  });
}
