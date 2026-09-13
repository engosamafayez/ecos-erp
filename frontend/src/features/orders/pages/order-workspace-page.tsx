import { useParams, useLocation, useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';

import { ManualOrderFormWorkspace } from '@/features/orders/components/manual-order-form';
import { OrderDetailDrawer } from '@/features/orders/components/order-detail-drawer';
import { PageHeader } from '@/components/crud';
import { useOrderQuery } from '@/features/orders/hooks/use-orders';
import { ROUTES } from '@/router/routes';

// ─────────────────────────────────────────────────────────────────────────────
// Page entry point
// ─────────────────────────────────────────────────────────────────────────────

export function OrderWorkspacePage() {
  const { t } = useTranslation('orders');
  const { t: tCommon } = useTranslation('common');
  const { id } = useParams<{ id: string }>();
  const { pathname, state } = useLocation();
  const navigate = useNavigate();
  // "New Order" from a customer's Quick Action Card carries the phone through
  // navigation state (same mechanism recipe-workspace-page.tsx already uses).
  const locationState = state as { customerPhone?: string } | null;

  const mode: 'create' | 'edit' | 'view' = !id
    ? 'create'
    : pathname.endsWith('/edit')
    ? 'edit'
    : 'view';

  // enabled: false in create mode (id is undefined)
  const { data: order, isLoading } = useOrderQuery(id ?? '');

  // Loading state — only for edit/view
  if (id && isLoading) {
    return (
      <div className="flex flex-col gap-6">
        <PageHeader
          title={t($ => $.detail.loading)}
          breadcrumbs={[
            { label: tCommon($ => $.home), to: ROUTES.dashboard },
            { label: t($ => $.title), to: ROUTES.orders },
            { label: '…' },
          ]}
        />
      </div>
    );
  }

  // Not found — only for edit/view
  if (id && !order) {
    return (
      <div className="flex flex-col gap-6">
        <PageHeader
          title={t($ => $.detail.notFound)}
          breadcrumbs={[
            { label: tCommon($ => $.home), to: ROUTES.dashboard },
            { label: t($ => $.title), to: ROUTES.orders },
          ]}
        />
        <p className="text-muted-foreground text-sm">{t($ => $.detail.notFoundMessage)}</p>
      </div>
    );
  }

  // DETAIL-DRAWER-STANDARD (TASK-ECOS-V1.1-CORE-01-UI-04 §2/§6) — a direct /orders/:id
  // visit renders the SAME canonical drawer in-app row-click navigation opens (see
  // customer-profile-page.tsx for the identical pattern), instead of a second,
  // independently-drifting full-page implementation. `order` here is already loaded
  // by the `useOrderQuery` call above; the drawer's own internal `useOrderQuery` call
  // reads the same cache entry, so this is not a second network request.
  if (mode === 'view') {
    return (
      <OrderDetailDrawer
        order={order ?? null}
        open
        onOpenChange={(open) => { if (!open) navigate(ROUTES.orders); }}
        onEdit={(o) => navigate(`${ROUTES.orders}/${o.id}/edit`)}
      />
    );
  }
  // key forces a clean remount whenever the mode or order changes.
  // Without it, React reconciles create and edit as the same component instance,
  // allowing serverError / slotError / ref state to bleed across SPA navigations.
  if (mode === 'create') return <ManualOrderFormWorkspace key="create" initialCustomerPhone={locationState?.customerPhone} />;
  return <ManualOrderFormWorkspace key={order!.id} mode="edit" order={order!} />;
}
