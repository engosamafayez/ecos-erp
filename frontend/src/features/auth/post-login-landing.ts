import { ROUTES } from '@/router/routes';
import type { AuthUser } from '@/features/auth/types';

/**
 * The driver runtime permission — the same string the backend gates `/api/driver/*` on
 * (`permission:loading.driver.operate`). Holding it is what makes a user a driver.
 */
const DRIVER_PERMISSION = 'loading.driver.operate';

/**
 * Enterprise permissions. A driver identity holds ONLY the driver runtime (plus a
 * read-only shipping view); any of these means the user also works the ERP and should
 * land on the enterprise dashboard, not the driver app. Kept deliberately small — it is
 * the "is this ALSO an enterprise user" test, not an exhaustive catalogue.
 */
const ENTERPRISE_PREFIXES = [
  'sales.',
  'inventory.',
  'purchasing.',
  'finance.',
  'accounting.',
  'marketing.',
  'crm.',
  'hr.',
  'manufacturing.',
  'iam.',
  'organization.',
  'configuration.',
  'logistics.distribution.', // the DISPATCHER surface — not a driver capability
];

/**
 * TRUE only for a DRIVER-ONLY account: holds the driver runtime permission, is not a
 * system user, and holds no enterprise permission. This is the single predicate both the
 * post-login resolver and the `/dashboard` deep-link guard consult, so the two entry
 * points can never disagree about who is "a driver".
 *
 * A pure decision on the effective permission list — testable without React, cannot drift
 * from what the backend authorises, changes NO permission, and is NOT a security boundary
 * (the APIs enforce their own access regardless of where the UI lands).
 */
export function isDriverOnly(user: AuthUser | null): boolean {
  if (user?.is_system) {
    // System users run the whole platform — never treat them as driver-only.
    return false;
  }

  const permissions = user?.permissions ?? [];

  if (!permissions.includes(DRIVER_PERMISSION)) {
    return false;
  }

  const alsoEnterprise = permissions.some((p) =>
    ENTERPRISE_PREFIXES.some((prefix) => p.startsWith(prefix)),
  );

  return !alsoEnterprise;
}

/**
 * Where a user should land immediately after login.
 *
 * ┌─ WHY THIS EXISTS ────────────────────────────────────────────────────────┐
 * │ Login sent EVERY user to `ROUTES.dashboard`, the enterprise dashboard      │
 * │ inside AppShell. A driver therefore logged in straight into the full ERP   │
 * │ shell and never reached `/driver/*`, so the dedicated DriverShell was       │
 * │ correct but unreachable by the normal flow. This routes a driver to their  │
 * │ own home instead.                                                          │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * A system user, or any user who also holds an enterprise permission, keeps the
 * enterprise dashboard: this only redirects a user whose ONLY operational capability is
 * the driver runtime.
 */
export function resolvePostLoginPath(user: AuthUser | null): string {
  return isDriverOnly(user) ? ROUTES.driverHome : ROUTES.dashboard;
}
