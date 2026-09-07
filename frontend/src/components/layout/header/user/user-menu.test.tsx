import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

/**
 * TASK-ECOS-NOTIFICATIONS-USER-REVIEW-VISIBILITY-REMEDIATION-009.
 *
 * A real DEV review after Task 008 reported "I cannot see the Settings / Preferences
 * page" — traced to this exact menu item: it navigated to ROUTES.settings, which
 * redirects to the admin-only Configuration OS, invisible to any user without
 * iam/organization/configuration permissions. This proves the fix: the item now
 * navigates to the personal, gate-free ROUTES.myPreferences instead.
 */

const mockNavigate = vi.hoisted(() => vi.fn());
vi.mock('react-router-dom', () => ({
  useNavigate: () => mockNavigate,
}));

const authState = {
  user: { name: 'Administrator', email: 'admin@ecos.local' },
  logout: vi.fn(),
};
vi.mock('@/features/auth/store/auth-store', () => ({
  useAuthStore: (sel: (s: typeof authState) => unknown) => sel(authState),
}));

// LanguageSwitcher (rendered in the Appearance row) reads LanguageContext directly —
// unrelated to this file's own behavior, stubbed exactly like notification-center.test.tsx's
// established convention for the same context.
vi.mock('@/providers/language-context', () => ({
  useLanguage: () => ({ language: 'en', dir: 'ltr', setLanguage: vi.fn() }),
}));

// ThemeToggle (also in the Appearance row) reads ThemeContext directly — same reason.
vi.mock('@/hooks/use-theme', () => ({
  useTheme: () => ({ theme: 'light', setTheme: vi.fn() }),
}));

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (sel: unknown, opts?: { name?: string }) => {
      if (typeof sel !== 'function') return String(sel);
      const path: string[] = [];
      const proxy: unknown = new Proxy(
        {},
        { get: (_t, prop: string) => { path.push(prop); return proxy; } },
      );
      (sel as (p: unknown) => unknown)(proxy);
      const key = path[path.length - 1] ?? '';
      return opts?.name ? `${key}:${opts.name}` : key;
    },
  }),
}));

import { UserMenu } from './user-menu';
import { ROUTES } from '@/router/routes';

describe('UserMenu', () => {
  it('navigates the "Preferences" item to ROUTES.myPreferences, not the admin-only ROUTES.settings redirect', async () => {
    render(<UserMenu />);

    await userEvent.click(screen.getByRole('button', { name: /ariaLabel/ }));
    await userEvent.click(screen.getByText('preferences'));

    expect(mockNavigate).toHaveBeenCalledWith(ROUTES.myPreferences);
    expect(mockNavigate).not.toHaveBeenCalledWith(ROUTES.settings);
    expect(ROUTES.myPreferences).not.toBe(ROUTES.settings);
  });

  it('leaves the still-unbuilt Profile/Keyboard Shortcuts/Activity Log items disabled', async () => {
    render(<UserMenu />);
    await userEvent.click(screen.getByRole('button', { name: /ariaLabel/ }));

    expect(screen.getByText('profile').closest('[role="menuitem"]')).toHaveAttribute('data-disabled');
    expect(screen.getByText('shortcuts').closest('[role="menuitem"]')).toHaveAttribute('data-disabled');
    expect(screen.getByText('activityLog').closest('[role="menuitem"]')).toHaveAttribute('data-disabled');
  });

  it('logout remains wired to the auth store', async () => {
    render(<UserMenu />);
    await userEvent.click(screen.getByRole('button', { name: /ariaLabel/ }));
    await userEvent.click(screen.getByText('logout'));

    expect(authState.logout).toHaveBeenCalled();
  });
});
