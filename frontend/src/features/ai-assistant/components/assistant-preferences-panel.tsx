import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Switch } from '@/components/ui/switch';
import { toast } from '@/components/ds/use-toast';
import {
  useAssistantPreferencesQuery,
  useUpdateAssistantPreferencesMutation,
} from '@/features/ai-assistant/hooks/use-assistant-preferences';
import {
  isSpeechRecognitionSupported,
  isSpeechSynthesisSupported,
} from '@/features/ai-assistant/hooks/use-assistant-voice';
import { AssistantAvatarIcon } from '@/features/ai-assistant/components/assistant-avatars';
import { ASSISTANT_AVATAR_KEYS } from '@/features/ai-assistant/lib/assistant-avatar-registry';
import type {
  AssistantLanguageKey,
  AssistantPersonaKey,
  AssistantPreferences,
  AssistantSpeakingStyleKey,
} from '@/features/ai-assistant/types/assistant';

/** Real, device-reported voices only (never a fabricated list) — populated asynchronously on some browsers. */
function useAvailableSpeechVoices(): SpeechSynthesisVoice[] {
  const [voices, setVoices] = useState<SpeechSynthesisVoice[]>([]);

  useEffect(() => {
    if (!isSpeechSynthesisSupported()) return;

    const load = () => setVoices(window.speechSynthesis.getVoices());
    load();
    window.speechSynthesis.addEventListener('voiceschanged', load);
    return () => window.speechSynthesis.removeEventListener('voiceschanged', load);
  }, []);

  return voices;
}

const PERSONA_OPTIONS: AssistantPersonaKey[] = ['neutral', 'female', 'male'];
const LANGUAGE_OPTIONS: AssistantLanguageKey[] = ['bilingual', 'ar', 'en'];
const SPEAKING_STYLE_OPTIONS: AssistantSpeakingStyleKey[] = [
  'friendly',
  'egyptian_casual',
  'formal',
  'concise',
  'technical',
  'detailed',
];

/**
 * TASK-ECOS-V1.1-FINAL-AI-ASSISTANT-PERSONALIZED-COMPANION-046 §6/§8 — the one
 * personalization editor, reused by both the dedicated "/me/assistant" page and
 * (per §8's own "quick access entry") anywhere else it may be embedded later —
 * mirrors the existing NotificationPreferencesPanel's own "one panel, multiple
 * entry points" convention.
 *
 * §9 — server is authoritative: the form only ever writes through
 * useUpdateAssistantPreferencesMutation, never to localStorage.
 */
export function AssistantPreferencesPanel({ hideHeader = false }: { hideHeader?: boolean }) {
  const { t } = useTranslation('ai-assistant');
  const preferencesQuery = useAssistantPreferencesQuery();
  const updateMutation = useUpdateAssistantPreferencesMutation();
  const availableVoices = useAvailableSpeechVoices();
  const sttSupported = isSpeechRecognitionSupported();
  const ttsSupported = isSpeechSynthesisSupported();

  const [draft, setDraft] = useState<AssistantPreferences | null>(null);

  // React's documented "adjust state during render" pattern (not an effect):
  // prefills the draft the first time server data arrives, without a setState-
  // in-effect cascade. Safe because it's gated on `draft === null` — it never
  // re-fires once the user starts editing.
  if (preferencesQuery.data && draft === null) {
    setDraft(preferencesQuery.data);
  }

  // isError is checked BEFORE the loading/draft-null fallback below — a failed
  // fetch never sets `draft`, so checking `draft === null` first would show an
  // endless skeleton instead of the honest error state.
  if (preferencesQuery.isError) {
    return <p className="text-destructive text-sm">{t($ => $.personalize.loadError)}</p>;
  }

  if (preferencesQuery.isLoading || draft === null) {
    return (
      <div className="flex flex-col gap-3">
        <Skeleton className="h-8 w-48" />
        <Skeleton className="h-24 w-full" />
      </div>
    );
  }

  const handleSave = () => {
    if (!draft) return;

    updateMutation.mutate(draft, {
      onSuccess: () => toast.success(t($ => $.personalize.saveSuccess)),
      onError: () => toast.error(t($ => $.personalize.saveError)),
    });
  };

  return (
    <div className="flex flex-col gap-6">
      {!hideHeader ? (
        <div>
          <h2 className="text-lg font-semibold">{t($ => $.personalize.sectionTitle)}</h2>
          <p className="text-muted-foreground text-sm">{t($ => $.personalize.sectionDescription)}</p>
        </div>
      ) : null}

      {/* Avatar grid */}
      <div className="flex flex-col gap-2">
        <Label>{t($ => $.personalize.avatarLabel)}</Label>
        <div className="grid grid-cols-4 gap-2 sm:grid-cols-8">
          {ASSISTANT_AVATAR_KEYS.map((key) => {
            const selected = draft.avatar_key === key;
            return (
              <button
                key={key}
                type="button"
                onClick={() => setDraft({ ...draft, avatar_key: key })}
                aria-pressed={selected}
                aria-label={t($ => $.personalize.avatars[key])}
                className={`flex flex-col items-center gap-1 rounded-lg border p-2 transition-colors ${
                  selected ? 'border-primary bg-primary/5' : 'border-border hover:bg-accent'
                }`}
              >
                <AssistantAvatarIcon avatarKey={key} className="size-10" />
              </button>
            );
          })}
        </div>
      </div>

      {/* Name */}
      <div className="flex max-w-sm flex-col gap-1.5">
        <Label htmlFor="assistant-name">{t($ => $.personalize.nameLabel)}</Label>
        <Input
          id="assistant-name"
          value={draft.name}
          onChange={(e) => setDraft({ ...draft, name: e.target.value })}
          placeholder={t($ => $.personalize.namePlaceholder)}
          maxLength={40}
        />
      </div>

      {/* Persona */}
      <div className="flex flex-col gap-1.5">
        <Label>{t($ => $.personalize.personaLabel)}</Label>
        <div className="flex flex-wrap gap-2">
          {PERSONA_OPTIONS.map((persona) => (
            <Button
              key={persona}
              type="button"
              size="sm"
              variant={draft.persona === persona ? 'default' : 'outline'}
              onClick={() => setDraft({ ...draft, persona })}
            >
              {t($ => $.personalize.persona[persona])}
            </Button>
          ))}
        </div>
      </div>

      {/* Speaking style */}
      <div className="flex max-w-sm flex-col gap-1.5">
        <Label htmlFor="assistant-speaking-style">{t($ => $.personalize.speakingStyleLabel)}</Label>
        <select
          id="assistant-speaking-style"
          className="border-input bg-background h-9 rounded-md border px-3 text-sm"
          value={draft.speaking_style}
          onChange={(e) => setDraft({ ...draft, speaking_style: e.target.value as AssistantSpeakingStyleKey })}
        >
          {SPEAKING_STYLE_OPTIONS.map((style) => (
            <option key={style} value={style}>
              {t($ => $.personalize.speakingStyle[style])}
            </option>
          ))}
        </select>
      </div>

      {/* Language */}
      <div className="flex flex-col gap-1.5">
        <Label>{t($ => $.personalize.languageLabel)}</Label>
        <div className="flex flex-wrap gap-2">
          {LANGUAGE_OPTIONS.map((language) => (
            <Button
              key={language}
              type="button"
              size="sm"
              variant={draft.language === language ? 'default' : 'outline'}
              onClick={() => setDraft({ ...draft, language })}
            >
              {t($ => $.personalize.language[language])}
            </Button>
          ))}
        </div>
      </div>

      {/* Voice — CTO scope override (same task 046) */}
      <div className="flex flex-col gap-4 rounded-lg border p-3">
        <h3 className="text-sm font-semibold">{t($ => $.voice.settings.sectionTitle)}</h3>

        <div className="flex items-center justify-between gap-4">
          <div className="min-w-0">
            <Label htmlFor="voice-enabled">{t($ => $.voice.settings.voiceEnabledLabel)}</Label>
            <p className="text-muted-foreground text-xs">{t($ => $.voice.settings.voiceEnabledDescription)}</p>
            {!sttSupported && !ttsSupported ? (
              <p className="text-muted-foreground mt-1 text-xs italic">{t($ => $.voice.notSupported)}</p>
            ) : null}
          </div>
          <Switch
            id="voice-enabled"
            checked={draft.voice_enabled}
            disabled={!sttSupported && !ttsSupported}
            onCheckedChange={(checked) =>
              setDraft({ ...draft, voice_enabled: checked, wake_by_name_enabled: checked ? draft.wake_by_name_enabled : false })
            }
          />
        </div>

        <div className="flex items-center justify-between gap-4">
          <div className="min-w-0">
            <Label htmlFor="wake-by-name-enabled">{t($ => $.voice.settings.wakeByNameLabel)}</Label>
            <p className="text-muted-foreground text-xs">{t($ => $.voice.settings.wakeByNameDescription)}</p>
            {!sttSupported ? (
              <p className="text-muted-foreground mt-1 text-xs italic">{t($ => $.voice.settings.wakeByNameUnsupported)}</p>
            ) : null}
          </div>
          <Switch
            id="wake-by-name-enabled"
            checked={draft.wake_by_name_enabled}
            disabled={!sttSupported || !draft.voice_enabled}
            onCheckedChange={(checked) => setDraft({ ...draft, wake_by_name_enabled: checked })}
          />
        </div>

        {ttsSupported ? (
          <div className="flex max-w-sm flex-col gap-1.5">
            <Label htmlFor="voice-choice">{t($ => $.voice.settings.voiceChoiceLabel)}</Label>
            <select
              id="voice-choice"
              className="border-input bg-background h-9 rounded-md border px-3 text-sm"
              value={draft.voice_choice ?? ''}
              onChange={(e) => setDraft({ ...draft, voice_choice: e.target.value === '' ? null : e.target.value })}
            >
              <option value="">{t($ => $.voice.settings.voiceChoiceDefault)}</option>
              {availableVoices.map((voice) => (
                <option key={voice.voiceURI} value={voice.name}>
                  {voice.name} ({voice.lang})
                </option>
              ))}
            </select>
          </div>
        ) : null}

        {sttSupported ? (
          <p className="text-muted-foreground text-xs">{t($ => $.voice.settings.microphonePrivacyNotice)}</p>
        ) : null}
      </div>

      <div>
        <Button onClick={handleSave} disabled={updateMutation.isPending}>
          {updateMutation.isPending ? t($ => $.personalize.saving) : t($ => $.personalize.save)}
        </Button>
      </div>
    </div>
  );
}
