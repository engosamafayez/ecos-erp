import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { Warehouse } from 'lucide-react';

import { ROUTES } from '@/router/routes';

import { GapCard } from './gap-card';

/**
 * Warehouse Receipt tab.
 *
 * No backend model is literally named "Warehouse Receipt" — it is implemented
 * as `VehicleShiftReconciliationLine.warehouse_receipt_at` /
 * `quantity_accepted` / `quantity_damaged`, written via `ReceiveVehicleReturnAction`
 * and read through `GET /loading/sessions/{sessionId}/assignments/{assignmentId}/reconciliation`
 * (see `loadingOsService.getReconciliation`). That read is scoped to one
 * session + one vehicle assignment; the Loading OS service exposes no
 * cross-trip/cross-session list to build a compact preview from here, so this
 * tab stays an honest deep link into the Loading Workspace rather than an
 * invented rollup.
 */
export function WarehouseReceiptTab() {
  const { t } = useTranslation('returns-settlement');
  const navigate = useNavigate();

  return (
    <GapCard
      icon={Warehouse}
      title={t(($) => $.warehouseReceipt.title)}
      description={t(($) => $.warehouseReceipt.description)}
      note={t(($) => $.warehouseReceipt.note)}
      actions={[
        { label: t(($) => $.warehouseReceipt.action), onClick: () => navigate(ROUTES.loadingOsWorkspace) },
      ]}
    />
  );
}
