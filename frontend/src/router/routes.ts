/**
 * Centralized route path constants. Reference these instead of string literals.
 */
export const ROUTES = {
  home: '/',
  login: '/login',
  dashboard: '/dashboard',
} as const;

export type RoutePath = (typeof ROUTES)[keyof typeof ROUTES];
