import { describe, expect, it } from 'vitest';

import {
  ASSISTANT_AVATAR_KEYS,
  DEFAULT_ASSISTANT_AVATAR,
  isKnownAssistantAvatar,
} from './assistant-avatar-registry';

/**
 * TASK-ECOS-V1.1-FINAL-AI-ASSISTANT-PERSONALIZED-COMPANION-046 §6A — the
 * frontend's avatar key set must stay byte-identical to the backend's
 * Modules\AI\Domain\Enums\AssistantAvatar (see backend/tests/Unit/AI/
 * UpsertAssistantPreferencesRequestTest.php for that side's own proof) — a
 * drifted key would mean the picker offers an avatar the server rejects, or
 * vice versa.
 */
const BACKEND_AVATAR_VALUES = [
  'ecos_blue_bot',
  'ecos_purple_bot',
  'ecos_ember_companion',
  'ecos_owl_companion',
  'ecos_rock_companion',
  'ecos_growth_companion',
  'ecos_stack_companion',
  'ecos_screen_companion',
];

describe('assistant avatar registry', () => {
  it('offers exactly 6-8 avatar options as required', () => {
    expect(ASSISTANT_AVATAR_KEYS.length).toBeGreaterThanOrEqual(6);
    expect(ASSISTANT_AVATAR_KEYS.length).toBeLessThanOrEqual(8);
  });

  it('has no duplicate keys', () => {
    expect(new Set(ASSISTANT_AVATAR_KEYS).size).toBe(ASSISTANT_AVATAR_KEYS.length);
  });

  it('matches the backend AssistantAvatar enum exactly', () => {
    expect([...ASSISTANT_AVATAR_KEYS].sort()).toEqual([...BACKEND_AVATAR_VALUES].sort());
  });

  it('the default avatar is a member of the registry', () => {
    expect(ASSISTANT_AVATAR_KEYS).toContain(DEFAULT_ASSISTANT_AVATAR);
  });

  it('isKnownAssistantAvatar rejects an unrecognized key', () => {
    expect(isKnownAssistantAvatar('not_a_real_avatar')).toBe(false);
    expect(isKnownAssistantAvatar(DEFAULT_ASSISTANT_AVATAR)).toBe(true);
  });
});
