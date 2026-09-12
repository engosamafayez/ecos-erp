/**
 * Compatibility re-export (TASK-ECOS-V1.1-CORE-01-UI-01-CANONICAL-FOUNDATION-045).
 * The real implementation moved to the canonical crud kit as `PermissionState`
 * — import from `@/components/crud` (or `@/components/foundation`) in new
 * code. No consumers of this path were found at the time of this change; kept
 * as a compatibility shim rather than deleted outright, per the "ratchet, not
 * a cliff" migration rule.
 */
export { PermissionState as PagePermissionState } from '@/components/crud/permission-state';
