import type { ModuleId } from '@/config/module-navigation';

/**
 * Presentation-only grouping of the canonical modules for the mobile Modules
 * launcher (TASK-ECOS-MOBILE-UX-COMPLETION-002, per parent design report §5).
 *
 * This groups EXISTING module ids for display only — it creates no module, no
 * route, and no permission. Visibility and order within a family still come
 * entirely from `useNavigation()` (RBAC-filtered `APP_MODULES`); a family
 * simply renders whichever of its members are present in that authorized set,
 * in their canonical order, and disappears entirely when none of its members
 * are visible to the current user.
 */
export type ModuleFamily =
  | 'overview'
  | 'commerceMarketing'
  | 'operationsFulfillment'
  | 'inventoryProcurement'
  | 'financePeople'
  | 'platformAdmin'
  | 'other';

/** Every module id maps to exactly one family. `other` is a forward-compatible
 * fallback for a module id added later without a family assignment — it is
 * never expected to render today. */
const MODULE_FAMILY: Record<ModuleId, ModuleFamily> = {
  dashboard: 'overview',
  executive: 'overview',
  commerce: 'commerceMarketing',
  crm: 'commerceMarketing',
  customerEngagement: 'commerceMarketing',
  omnichannel: 'commerceMarketing',
  marketing: 'commerceMarketing',
  pos: 'commerceMarketing',
  operations: 'operationsFulfillment',
  shipping: 'operationsFulfillment',
  logistics: 'operationsFulfillment',
  inventory: 'inventoryProcurement',
  purchasing: 'inventoryProcurement',
  manufacturing: 'inventoryProcurement',
  finance: 'financePeople',
  hr: 'financePeople',
  core: 'platformAdmin',
  administration: 'platformAdmin',
  engineering: 'platformAdmin',
  reports: 'platformAdmin',
};

/** Display order of families in the launcher grid. */
export const FAMILY_ORDER: ModuleFamily[] = [
  'overview',
  'commerceMarketing',
  'operationsFulfillment',
  'inventoryProcurement',
  'financePeople',
  'platformAdmin',
  'other',
];

export function familyOf(moduleId: ModuleId): ModuleFamily {
  return MODULE_FAMILY[moduleId] ?? 'other';
}
