import type { ReactNode } from 'react';

import { QueryProvider } from '@/providers/query-provider';
import { ThemeProvider } from '@/providers/theme-provider';

/**
 * Composes all global providers used across the application.
 */
export function AppProviders({ children }: { children: ReactNode }) {
  return (
    <ThemeProvider defaultTheme="system" storageKey="ecos-theme">
      <QueryProvider>{children}</QueryProvider>
    </ThemeProvider>
  );
}
