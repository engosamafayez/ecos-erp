import { useTranslation } from 'react-i18next';
import { Settings } from 'lucide-react';

import { NotificationPreferencesPanel } from '../components/notification-preferences';

/**
 * TASK-ECOS-NOTIFICATIONS-USER-REVIEW-VISIBILITY-REMEDIATION-009 — the destination for
 * the user-menu's "Preferences" item (ROUTES.myPreferences), reached without knowing a
 * hidden URL. Today this page has exactly one section because exactly one personal
 * preference surface exists (notifications); it is deliberately not a bigger "Settings
 * hub" mockup for surfaces that do not exist yet — adding one would be the redesign this
 * task's own instructions say to avoid.
 *
 * Renders the exact same `NotificationPreferencesPanel` the bell's popover already
 * uses — one preference authority, one component, two ways to reach it.
 */
export function MyPreferencesPage() {
  const { t } = useTranslation('common');

  return (
    <div className="mx-auto max-w-2xl space-y-6 p-6">
      <div>
        <h1 className="flex items-center gap-2 text-xl font-semibold">
          <Settings className="text-muted-foreground size-5" aria-hidden />
          {t(($) => $.notifications.preferences.title)}
        </h1>
        <p className="text-muted-foreground mt-0.5 text-sm">
          {t(($) => $.notifications.preferences.description)}
        </p>
      </div>

      <div className="rounded-lg border bg-card p-4">
        <NotificationPreferencesPanel hideHeader />
      </div>
    </div>
  );
}
