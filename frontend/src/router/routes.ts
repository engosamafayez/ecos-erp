/**
 * Centralized route path constants. Reference these instead of string literals.
 */
export const ROUTES = {
  home: '/',
  login: '/login',
  dashboard: '/dashboard',
  companies: '/companies',
  branches: '/branches',
  inventory: '/inventory',
  purchasing: '/purchasing',
  sales: '/sales',
  accounting: '/accounting',
  crm: '/crm',
  hr: '/hr',
  reports: '/reports',
  settings: '/settings',
} as const;

export type RoutePath = (typeof ROUTES)[keyof typeof ROUTES];
