/**
 * Mirrors the CORE-03 Task 1 backend contract exactly (Modules\AI\Presentation\
 * Http\Requests\AssistantMessageRequest / Modules\AI\Application\ValueObjects\
 * AIAssistantResponse). No field here is invented.
 */

export type AssistantEntityReference = {
  type: string;
  id: string;
  label: string;
  route: string;
};

export type AssistantResponseStatus = 'ok' | 'denied' | 'unavailable' | 'tool_limit_reached';

export type AssistantApiResponse = {
  status: AssistantResponseStatus;
  message: string | null;
  references: AssistantEntityReference[];
};

/** One turn of the bounded, client-resent recent history (§9/§26 — no server persistence). */
export type AssistantHistoryTurn = {
  role: 'user' | 'assistant';
  content: string;
};

/** The bounded, approved context hints §6/§7 allow — never full page state. */
export type AssistantContextHints = {
  route?: string;
  module?: string;
  page?: string;
  entity_type?: string;
  entity_id?: string;
  brand_id?: string;
};

export type AssistantMessageRequestPayload = AssistantContextHints & {
  message: string;
  history?: AssistantHistoryTurn[];
};

/** One rendered turn in the drawer's own local conversation state. */
export type AssistantConversationTurn = {
  id: string;
  role: 'user' | 'assistant';
  content: string;
  status?: AssistantResponseStatus;
  references?: AssistantEntityReference[];
};

/**
 * TASK-ECOS-V1.1-FINAL-AI-ASSISTANT-PERSONALIZED-COMPANION-046 §6 — mirrors
 * Modules\AI\Application\ValueObjects\AssistantPreferences and its own backing
 * enums exactly (Modules\AI\Domain\Enums\Assistant{Avatar,Persona,
 * SpeakingStyle,Language}). Stable machine keys only (§6A) — display labels are
 * resolved separately through i18n, never persisted.
 */
export type AssistantAvatarKey =
  | 'ecos_blue_bot'
  | 'ecos_purple_bot'
  | 'ecos_ember_companion'
  | 'ecos_owl_companion'
  | 'ecos_rock_companion'
  | 'ecos_growth_companion'
  | 'ecos_stack_companion'
  | 'ecos_screen_companion';

export type AssistantPersonaKey = 'male' | 'female' | 'neutral';

export type AssistantSpeakingStyleKey =
  | 'egyptian_casual'
  | 'formal'
  | 'concise'
  | 'friendly'
  | 'technical'
  | 'detailed';

export type AssistantLanguageKey = 'ar' | 'en' | 'bilingual';

export type AssistantPreferences = {
  avatar_key: AssistantAvatarKey;
  name: string;
  persona: AssistantPersonaKey;
  speaking_style: AssistantSpeakingStyleKey;
  language: AssistantLanguageKey;
  /**
   * CTO scope override (same task 046) — voice is a client-side I/O preference
   * only; `voice_choice` is a best-effort device-local Web Speech voice name
   * (see use-assistant-voice.ts's own docblock for why this can't be a closed
   * enum, and why a value saved on one device may not exist on another).
   */
  voice_enabled: boolean;
  wake_by_name_enabled: boolean;
  voice_choice: string | null;
};
