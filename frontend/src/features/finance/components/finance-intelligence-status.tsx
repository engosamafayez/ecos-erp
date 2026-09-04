import type { ReactNode } from 'react';
import { Info } from 'lucide-react';

import { Card, CardContent } from '@/components/ui/card';

/**
 * Card-shaped loading/error state for the non-tabular Finance Intelligence
 * views (KPI tiles built from a single-object payload — company
 * profitability, cost breakdown totals, cash-flow current/forecast). This is
 * the equivalent of UniversalDataGrid's `loading`/`error` props for a view
 * that isn't a grid, matching expenses-page.tsx's grid states in spirit.
 *
 * There is no `empty` branch here: every one of these endpoints always
 * returns a body (its numeric fields may be zero, but the object itself is
 * never absent on success) — a genuinely empty *list* (e.g. zero profitability
 * rows, zero risk alerts) is handled at the call site instead, either via
 * UniversalDataGrid's own `emptyState` or a small inline message. Never use
 * this component for the backend's explicit `available:false` shape — that
 * is FinanceIntelligenceUnavailableCard below, a different state entirely.
 */
export function FinanceIntelligenceStateCard({
  loading: _loading,
  error,
  loadingLabel,
  errorLabel,
}: {
  loading: boolean;
  error: boolean;
  loadingLabel: string;
  errorLabel: string;
}) {
  return (
    <Card>
      <CardContent className="py-10 text-center text-sm text-muted-foreground">
        {error ? errorLabel : loadingLabel}
      </CardContent>
    </Card>
  );
}

/**
 * The backend's explicit "not yet available" shape (`available: false`) —
 * e.g. product/channel profitability, which the ledger cannot tag by that
 * dimension yet (ProfitabilityService::byUntaggedDimension()).
 *
 * This is NOT an error state and NOT an empty-but-successful response: it is
 * a documented, permanent design decision the backend states explicitly via
 * `note`, rendered verbatim (never paraphrased or hidden). It must look
 * distinct from both an error and an empty grid — dashed border, an info
 * icon, no destructive styling — so a viewer never mistakes "we chose not to
 * fabricate this" for "this is broken" or "there is nothing here".
 */
export function FinanceIntelligenceUnavailableCard({
  heading,
  note,
  children,
}: {
  heading: string;
  /** The backend's own explanation — rendered exactly as returned. */
  note: string;
  children?: ReactNode;
}) {
  return (
    <Card className="border-dashed">
      <CardContent className="flex flex-col gap-3 py-6 text-sm">
        <div className="flex items-start gap-2">
          <Info className="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden />
          <div>
            <p className="font-medium">{heading}</p>
            <p className="mt-0.5 text-muted-foreground">{note}</p>
          </div>
        </div>
        {children}
      </CardContent>
    </Card>
  );
}
