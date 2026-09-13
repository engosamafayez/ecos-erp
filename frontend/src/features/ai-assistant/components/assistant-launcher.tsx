import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Sparkles } from 'lucide-react';

import { usePermission } from '@/features/authorization';
import { AssistantDrawer } from '@/features/ai-assistant/components/assistant-drawer';

/**
 * §4/§13 — the global AppShell entry. Hidden entirely without ai.assistant.use
 * (the preferred UX per §13) — server-side authorization in AIAssistantService
 * remains the real gate regardless; this is a convenience, not the boundary.
 *
 * Stacked directly above Collaboration's own FloatingChatLauncher (same fixed
 * bottom-end idiom, same size, one button-height + gap higher) rather than an
 * arbitrary offset, so the two floating actions read as one coherent stack
 * and never overlap (§22).
 */
export function AssistantLauncher() {
  const { t } = useTranslation('ai-assistant');
  const { can } = usePermission();
  const [open, setOpen] = useState(false);

  if (!can('ai.assistant.use')) {
    return null;
  }

  return (
    <>
      <button
        type="button"
        onClick={() => setOpen(true)}
        aria-label={t($ => $.launcher.ariaLabel)}
        className="no-print fixed bottom-40 end-4 z-30 flex size-14 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-lg transition-transform hover:scale-105 hover:opacity-95 md:bottom-24"
      >
        <Sparkles className="size-6" aria-hidden />
      </button>

      <AssistantDrawer open={open} onOpenChange={setOpen} />
    </>
  );
}
