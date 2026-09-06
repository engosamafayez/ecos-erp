import { api } from '@/lib/axios';

import type {
  MetricDictionaryPayload,
  ReportCataloguePayload,
  ReportResult,
} from '../types';

/**
 * Reads the three existing Reporting endpoints verbatim (`routes/api.php`,
 * `reporting` prefix). No metric or KPI is ever computed here — every value
 * rendered from this service's output is exactly what the server returned.
 */

/** Some payloads are bare, others wrapped in `data` — normalise once, here. */
function unwrap<T>(body: unknown): T {
  if (body !== null && typeof body === 'object' && 'data' in (body as Record<string, unknown>)) {
    return (body as { data: T }).data;
  }

  return body as T;
}

export const reportingService = {
  /** GET api/reporting/catalogue — every V1 report, optionally narrowed by category. */
  async catalogue(category?: string): Promise<ReportCataloguePayload> {
    const { data } = await api.get('/reporting/catalogue', {
      params: category ? { category } : undefined,
    });

    return unwrap<ReportCataloguePayload>(data);
  },

  /** GET api/reporting/metrics — the ratified Metric Dictionary. */
  async metrics(): Promise<MetricDictionaryPayload> {
    const { data } = await api.get('/reporting/metrics');

    return unwrap<MetricDictionaryPayload>(data);
  },

  /** GET api/reporting/reports/{reportId}/execute — the one generic execution surface. */
  async execute(reportId: string): Promise<ReportResult> {
    const { data } = await api.get(`/reporting/reports/${reportId}/execute`);

    return unwrap<ReportResult>(data);
  },
};
