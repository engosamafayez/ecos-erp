import { useTranslation } from 'react-i18next';
import { Settings } from 'lucide-react';

import { NotificationPreferencesPanel } from '../components/notification-preferences';

/**
 * D1 (TASK-ECOS-COMMERCE-IAM-NOTIFICATIONS-FINAL-USER-REVIEW-REMEDIATION-005) — the
 * dedicated full "Notification Settings" page, reached from the bell popover's
 * "Notification Settings" button (ROUTES.notificationSettings). The bell popover itself
 * stays QUICK CONTROLS ONLY (popup/sound/volume/Test Sound + a concise summary); the
 * per-priority effective table and the full, grouped per-type toggle list — every real
 * configurable notification type (§8 of the catalog reconciliation this same task did) —
 * live only here.
 *
 * Mirrors my-preferences-page.tsx's own established shell/registration pattern exactly
 * (same page-shell markup, same "render the shared panel, don't reinvent a layout"
 * idiom) — that page is left fully intact by this task and keeps working unchanged, per
 * this task's own instruction not to break it.
 */
export function NotificationSettingsPage() {
  const { t } = useTranslation('common');

  return (
    <div className="mx-auto max-w-2xl space-y-6 p-6">
      <div>
        <h1 className="flex items-center gap-2 text-xl font-semibold">
          <Settings className="text-muted-foreground size-5" aria-hidden />
          {t(($) => $.notifications.settingsPage.title)}
        </h1>
        <p className="text-muted-foreground mt-0.5 text-sm">
          {t(($) => $.notifications.settingsPage.description)}
        </p>
      </div>

      <div className="rounded-lg border bg-card p-4">
        {/* Non-compact (default) — the full panel: popup/sound/volume/Test Sound,
            the per-priority effective table, and every real configurable notification
            type grouped by module. */}
        <NotificationPreferencesPanel hideHeader />
      </div>
    </div>
  );
}
