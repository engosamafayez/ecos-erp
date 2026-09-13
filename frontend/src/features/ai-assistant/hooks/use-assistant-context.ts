import { useMemo } from 'react';
import { matchPath, useLocation } from 'react-router-dom';

import { ROUTES } from '@/router/routes';
import type { AssistantContextHints } from '@/features/ai-assistant/types/assistant';

type ContextRule = {
  pattern: string;
  module: string;
  page: string;
  /** Route param name holding the entity id, when this exact route carries one. */
  entityParam?: string;
  entityType?: string;
};

/**
 * §7 — a small, explicit table, not a giant brittle switch. Only routes that
 * genuinely carry an entity id in the URL (e.g. .../:customerId) resolve
 * entity_type/entity_id; list/workspace pages that open entities in a drawer
 * (Orders, most of CRM) correctly resolve page/module only — this hook never
 * reaches into another feature's local drawer-open state to fabricate one
 * (§7: "Do not fabricate entity ids").
 */
const RULES: ContextRule[] = [
  { pattern: ROUTES.reportDetail, module: 'reporting', page: 'report-detail', entityParam: 'reportId', entityType: 'report' },
  { pattern: ROUTES.reports, module: 'reporting', page: 'reports' },
  { pattern: ROUTES.customerDetail, module: 'crm', page: 'customer-detail', entityParam: 'customerId', entityType: 'customer' },
  { pattern: ROUTES.crmCustomers, module: 'crm', page: 'customers' },
  { pattern: ROUTES.crmLeads, module: 'crm', page: 'leads' },
  { pattern: ROUTES.crmPipeline, module: 'crm', page: 'pipeline' },
  { pattern: ROUTES.crmMyWork, module: 'crm', page: 'my-work' },
  { pattern: ROUTES.crmExecutive, module: 'crm', page: 'executive' },
  { pattern: ROUTES.crmPortfolio, module: 'crm', page: 'portfolio' },
  { pattern: ROUTES.customers, module: 'crm', page: 'customers' },
  { pattern: ROUTES.orders, module: 'commerce', page: 'orders' },
  { pattern: ROUTES.fulfillments, module: 'commerce', page: 'fulfillments' },
  { pattern: ROUTES.hrEmployee360, module: 'hr', page: 'employee-360', entityParam: 'employeeId', entityType: 'employee' },
  { pattern: ROUTES.hrEmployees, module: 'hr', page: 'employees' },
  { pattern: ROUTES.inventory, module: 'inventory', page: 'inventory' },
  { pattern: ROUTES.products, module: 'inventory', page: 'products' },
  { pattern: ROUTES.stockLedger, module: 'inventory', page: 'stock-ledger' },
  { pattern: ROUTES.rawMaterials, module: 'inventory', page: 'raw-materials' },
  { pattern: ROUTES.distributionBoard, module: 'logistics', page: 'distribution-board' },
  { pattern: ROUTES.shippingOrders, module: 'logistics', page: 'shipping-orders' },
  { pattern: ROUTES.dispatchGate, module: 'logistics', page: 'dispatch-gate' },
  { pattern: ROUTES.accounting, module: 'finance', page: 'accounting' },
  { pattern: ROUTES.financeStatements, module: 'finance', page: 'statements' },
  { pattern: ROUTES.financeReceivables, module: 'finance', page: 'receivables' },
  { pattern: ROUTES.financePayables, module: 'finance', page: 'payables' },
  { pattern: ROUTES.purchaseOrders, module: 'procurement', page: 'purchase-orders' },
  { pattern: ROUTES.suppliers, module: 'procurement', page: 'suppliers' },
];

/**
 * Resolves the bounded context hints for the assistant from the current route
 * only (§6/§7) — route/module/page and, where the URL itself carries one, a
 * validated entity_type/entity_id. Never sends full page state, loaded API
 * data, or a guessed id.
 */
export function useAssistantContext(): AssistantContextHints {
  const location = useLocation();

  return useMemo(() => {
    const matched = RULES.map((rule) => ({
      rule,
      match: matchPath({ path: rule.pattern, end: rule.entityParam === undefined }, location.pathname),
    })).find((entry) => entry.match !== null);

    if (!matched || !matched.match) {
      // Unrecognized page — page/module context only is the honest fallback
      // (§7), never a fabricated guess.
      return { route: location.pathname };
    }

    const { rule, match } = matched;
    const entityId = rule.entityParam ? match.params[rule.entityParam] : undefined;

    return {
      route: location.pathname,
      module: rule.module,
      page: rule.page,
      ...(entityId && rule.entityType ? { entity_type: rule.entityType, entity_id: entityId } : {}),
    };
  }, [location.pathname]);
}
