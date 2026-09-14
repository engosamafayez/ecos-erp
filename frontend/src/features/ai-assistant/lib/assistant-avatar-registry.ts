import type { AssistantAvatarKey } from '@/features/ai-assistant/types/assistant';

/**
 * TASK-ECOS-V1.1-FINAL-AI-ASSISTANT-PERSONALIZED-COMPANION-046 §6A — the pure,
 * non-component data behind the avatar picker, kept in its own file (separate
 * from components/assistant-avatars.tsx) so that file can stay component-only
 * for React Fast Refresh.
 */
export const ASSISTANT_AVATAR_KEYS: AssistantAvatarKey[] = [
  'ecos_blue_bot',
  'ecos_purple_bot',
  'ecos_ember_companion',
  'ecos_owl_companion',
  'ecos_rock_companion',
  'ecos_growth_companion',
  'ecos_stack_companion',
  'ecos_screen_companion',
];

export const DEFAULT_ASSISTANT_AVATAR: AssistantAvatarKey = 'ecos_blue_bot';

export function isKnownAssistantAvatar(key: string): key is AssistantAvatarKey {
  return (ASSISTANT_AVATAR_KEYS as string[]).includes(key);
}
