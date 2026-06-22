import { env } from '@/lib/env';

/**
 * Application footer.
 */
export function AppFooter() {
  const year = new Date().getFullYear();

  return (
    <footer className="text-muted-foreground flex flex-col items-center justify-between gap-1 border-t px-4 py-3 text-xs sm:flex-row sm:px-6">
      <span>
        © {year} {env.appName}. All rights reserved.
      </span>
      <span>Application Shell · v1</span>
    </footer>
  );
}
