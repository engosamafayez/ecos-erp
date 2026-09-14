import { useTranslation } from 'react-i18next';
import { Settings, Sparkles } from 'lucide-react';

import { NotificationPreferencesPanel } from '../components/notification-preferences';
import { AssistantPreferencesPanel } from '@/features/ai-assistant/components/assistant-preferences-panel';

/**
 * TASK-ECOS-NOTIFICATIONS-USER-REVIEW-VISIBILITY-REMEDIATION-009 — the destination for
 * the user-menu's "Preferences" item (ROUTES.myPreferences), reached without knowing a
 * hidden URL. Originally scoped to exactly one section because exactly one personal
 * preference surface existed (notifications).
 *
 * TASK-ECOS-V1.1-FINAL-AI-ASSISTANT-PERSONALIZED-COMPANION-046 §8 adds a genuine
 * second section — "Settings → AI Assistant" — now that a second real, ownership-
 * scoped preference surface exists. This is the natural growth the original
 * docblock anticipated, not the "bigger Settings hub mockup for surfaces that don't
 * exist yet" that task explicitly avoided; the dedicated /me/assistant route
 * (reached from the assistant drawer's own "Customize" entry) renders the exact same
 * `AssistantPreferencesPanel` shown here — one editor, two entry points.
 */
export function MyPreferencesPage() {
  const { t } = useTranslation('common');
  const { t: tAssistant } = useTranslation('ai-assistant');

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

      <div>
        <h2 className="flex items-center gap-2 text-lg font-semibold">
          <Sparkles className="text-muted-foreground size-4" aria-hidden />
          {tAssistant($ => $.personalize.sectionTitle)}
        </h2>
        <p className="text-muted-foreground mt-0.5 text-sm">
          {tAssistant($ => $.personalize.sectionDescription)}
        </p>
      </div>

      <div className="rounded-lg border bg-card p-4">
        <AssistantPreferencesPanel hideHeader />
      </div>
    </div>
  );
}
