import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi, beforeEach } from 'vitest';

import '@testing-library/jest-dom';

import type { AssistantPreferences } from '@/features/ai-assistant/types/assistant';

/**
 * TASK-ECOS-V1.1-FINAL-AI-ASSISTANT-PERSONALIZED-COMPANION-046 §6/§8/§9 — the
 * personalization editor: every field is present, avatar selection updates the
 * draft, and saving calls the update mutation with the full payload (never a
 * partial one the backend's `required` rules would reject).
 */
function pathProxy(path: string): unknown {
  const target = () => path;
  return new Proxy(target, {
    get(_t, prop) {
      if (prop === Symbol.toPrimitive || prop === 'toString' || prop === 'valueOf') return () => path;
      return pathProxy(path ? `${path}.${String(prop)}` : String(prop));
    },
  });
}
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (sel: unknown) => (typeof sel === 'function' ? String((sel as (p: unknown) => unknown)(pathProxy(''))) : String(sel)),
  }),
}));

const BASE_PREFS: AssistantPreferences = {
  avatar_key: 'ecos_blue_bot',
  name: 'ECOS Assistant',
  persona: 'neutral',
  speaking_style: 'friendly',
  language: 'bilingual',
  voice_enabled: false,
  wake_by_name_enabled: false,
  voice_choice: null,
};

let mockData: AssistantPreferences | undefined = BASE_PREFS;
let mockIsLoading = false;
let mockIsError = false;
const updateMutate = vi.fn();
vi.mock('@/features/ai-assistant/hooks/use-assistant-preferences', () => ({
  useAssistantPreferencesQuery: () => ({ data: mockData, isLoading: mockIsLoading, isError: mockIsError }),
  useUpdateAssistantPreferencesMutation: () => ({ mutate: updateMutate, isPending: false }),
}));

let mockSttSupported = true;
let mockTtsSupported = true;
vi.mock('@/features/ai-assistant/hooks/use-assistant-voice', () => ({
  isSpeechRecognitionSupported: () => mockSttSupported,
  isSpeechSynthesisSupported: () => mockTtsSupported,
}));

import { AssistantPreferencesPanel } from './assistant-preferences-panel';

describe('AssistantPreferencesPanel', () => {
  beforeEach(() => {
    mockData = BASE_PREFS;
    mockIsLoading = false;
    mockIsError = false;
    mockSttSupported = true;
    mockTtsSupported = true;
    updateMutate.mockReset();
  });

  it('renders every required personalization field', () => {
    render(<AssistantPreferencesPanel />);

    expect(screen.getByText('personalize.avatarLabel')).toBeInTheDocument();
    expect(screen.getByLabelText('personalize.nameLabel')).toBeInTheDocument();
    expect(screen.getByText('personalize.personaLabel')).toBeInTheDocument();
    expect(screen.getByLabelText('personalize.speakingStyleLabel')).toBeInTheDocument();
    expect(screen.getByText('personalize.languageLabel')).toBeInTheDocument();
    // 8 avatar options
    expect(screen.getAllByRole('button', { name: /personalize\.avatars\./ }).length).toBe(8);
  });

  it('shows the load-error state honestly when the query fails, never a blank/broken form', () => {
    mockIsError = true;
    mockData = undefined;
    render(<AssistantPreferencesPanel />);

    expect(screen.getByText('personalize.loadError')).toBeInTheDocument();
    expect(screen.queryByLabelText('personalize.avatarLabel')).not.toBeInTheDocument();
  });

  it('selecting a different avatar and saving sends the FULL updated payload, not a partial one', async () => {
    const user = userEvent.setup();
    render(<AssistantPreferencesPanel />);

    await user.click(screen.getByRole('button', { name: 'personalize.avatars.ecos_owl_companion' }));
    await user.click(screen.getByRole('button', { name: 'personalize.save' }));

    await waitFor(() => {
      expect(updateMutate).toHaveBeenCalledWith(
        expect.objectContaining({ ...BASE_PREFS, avatar_key: 'ecos_owl_companion' }),
        expect.anything(),
      );
    });
  });

  it('turning voice off also turns Wake by Name off, since it depends on voice being enabled', async () => {
    mockData = { ...BASE_PREFS, voice_enabled: true, wake_by_name_enabled: true };
    const user = userEvent.setup();
    render(<AssistantPreferencesPanel />);

    await user.click(screen.getByLabelText('voice.settings.voiceEnabledLabel'));
    await user.click(screen.getByRole('button', { name: 'personalize.save' }));

    await waitFor(() => {
      expect(updateMutate).toHaveBeenCalledWith(
        expect.objectContaining({ voice_enabled: false, wake_by_name_enabled: false }),
        expect.anything(),
      );
    });
  });

  it('disables Wake by Name entirely when the browser has no SpeechRecognition support', () => {
    mockSttSupported = false;
    render(<AssistantPreferencesPanel />);

    expect(screen.getByLabelText('voice.settings.wakeByNameLabel')).toBeDisabled();
    expect(screen.getByText('voice.settings.wakeByNameUnsupported')).toBeInTheDocument();
  });
});
