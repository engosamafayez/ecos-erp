import { useTranslation } from 'react-i18next';
import { Sparkles } from 'lucide-react';

import { AssistantPreferencesPanel } from '@/features/ai-assistant/components/assistant-preferences-panel';

/**
 * TASK-ECOS-V1.1-FINAL-AI-ASSISTANT-PERSONALIZED-COMPANION-046 §8 — the
 * dedicated "/me/assistant" destination ("Settings → AI Assistant"), reached
 * from MyPreferencesPage's new AI Assistant section and from the assistant
 * drawer's own "Customize" quick-access entry. Renders the exact same
 * AssistantPreferencesPanel both surfaces use — one editor, two entry points,
 * mirroring MyPreferencesPage/NotificationSettingsPage's own established
 * pattern for personal, ownership-scoped settings.
 */
export function AssistantPreferencesPage() {
  const { t } = useTranslation('ai-assistant');

  return (
    <div className="mx-auto max-w-2xl space-y-6 p-6">
      <div>
        <h1 className="flex items-center gap-2 text-xl font-semibold">
          <Sparkles className="text-muted-foreground size-5" aria-hidden />
          {t($ => $.personalize.sectionTitle)}
        </h1>
        <p className="text-muted-foreground mt-0.5 text-sm">{t($ => $.personalize.sectionDescription)}</p>
      </div>

      <div className="rounded-lg border bg-card p-4">
        <AssistantPreferencesPanel hideHeader />
      </div>
    </div>
  );
}
