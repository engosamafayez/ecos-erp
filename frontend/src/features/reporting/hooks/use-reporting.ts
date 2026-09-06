import { useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import axios from 'axios';

import { usePermission } from '@/features/authorization';
import { useOrganizationContext } from '@/features/organization/context/organization-context';
import { reportingService } from '../services/reporting-service';
import type { ReportCatalogueEntry } from '../types';

/**
 * Reporting V1 hooks (TASK-ECOS-REPORTING-V1-USER-VISIBLE-NAVIGATION-CLOSURE-010).
 * Mirrors the Executive Platform's own query shape (`features/executive/hooks/
 * use-executive.ts`): company-prefixed cache keys, permission decides whether a
 * request is even sent, and the page — not the hook — decides which visual
 * state a permission gap renders as.
 */
const KEY = 'reporting';

function useCompanyKey() {
  const { activeCompanyId } = useOrganizationContext();

  return activeCompanyId ?? 'global';
}

/**
 * The full V1 catalogue. Gated server-side only on `auth:sanctum`
 * (`ReportCatalogueController`) — every authenticated user sees every report's
 * metadata (name, category, permission), the same way the catalogue is platform
 * metadata rather than business content. Per-report *execution* is what the
 * category permission actually gates, checked separately below.
 */
export function useReportCatalogueQuery() {
  const companyId = useCompanyKey();

  return useQuery({
    queryKey: ['company', companyId, KEY, 'catalogue'],
    queryFn: () => reportingService.catalogue(),
    staleTime: 60_000,
  });
}

/** One catalogue entry by id, derived from the already-loaded catalogue. */
export function useReportCatalogueEntry(
  reportId: string | undefined,
): { entry: ReportCatalogueEntry | undefined } & Pick<
  ReturnType<typeof useReportCatalogueQuery>,
  'isPending' | 'isError' | 'error' | 'refetch'
> {
  const query = useReportCatalogueQuery();

  const entry = useMemo(
    () => query.data?.reports.find((r) => r.id === reportId),
    [query.data, reportId],
  );

  return { entry, isPending: query.isPending, isError: query.isError, error: query.error, refetch: query.refetch };
}

/**
 * Whether the current viewer holds the report's own category permission —
 * mirrors the Executive Platform's `permitted[domain]` pre-check. Computed from
 * the catalogue entry already in hand, never a network round trip.
 */
export function useCanRunReport(entry: ReportCatalogueEntry | undefined): boolean {
  const { can } = usePermission();

  if (!entry) return false;

  return can(entry.permission);
}

/**
 * The ratified Metric Dictionary. Fetched once, independent of which report is
 * open — used only to resolve a KPI code (e.g. `MET-SALES-01`) to its ratified
 * display name, never to compute or re-derive a metric's value.
 */
export function useMetricDictionaryQuery() {
  const companyId = useCompanyKey();

  return useQuery({
    queryKey: ['company', companyId, KEY, 'metrics'],
    queryFn: () => reportingService.metrics(),
    staleTime: 60_000,
  });
}

/** True when an error is the server's own permission refusal (403), not a generic failure. */
export function isForbiddenError(error: unknown): boolean {
  return axios.isAxiosError(error) && error.response?.status === 403;
}

/**
 * Executes one report. Requires `canRun` in addition to a present `reportId` —
 * an unauthorised viewer never triggers the request; the page renders the
 * FORBIDDEN state from {@link useCanRunReport} instead of an API error. The
 * 403 short-circuit below is defense in depth only (a stale client-side
 * permission cache), not the primary gate.
 */
export function useReportExecutionQuery(reportId: string | undefined, canRun: boolean) {
  const companyId = useCompanyKey();

  return useQuery({
    queryKey: ['company', companyId, KEY, 'execute', reportId],
    queryFn: () => reportingService.execute(reportId as string),
    enabled: Boolean(reportId) && canRun,
    staleTime: 60_000,
    retry: (failureCount, error) => !isForbiddenError(error) && failureCount < 1,
  });
}
