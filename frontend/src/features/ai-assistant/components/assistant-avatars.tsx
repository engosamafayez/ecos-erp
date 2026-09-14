import type { ReactNode } from 'react';

import { DEFAULT_ASSISTANT_AVATAR, isKnownAssistantAvatar } from '@/features/ai-assistant/lib/assistant-avatar-registry';
import type { AssistantAvatarKey } from '@/features/ai-assistant/types/assistant';

/**
 * TASK-ECOS-V1.1-FINAL-AI-ASSISTANT-PERSONALIZED-COMPANION-046 §6A/§14/§FINAL —
 * ORIGINAL ECOS mascot artwork, authored as plain inline SVG (matching this
 * codebase's own existing convention for small custom icons — see e.g.
 * MailIcon/LockIcon/EyeIcon in features/auth/components/login-form.tsx — rather
 * than importing any image asset). Each glyph is a simple geometric
 * circle-with-face composition in an ECOS accent color; none reproduces or
 * derives from any third-party mascot (Codex/Dewey/Fireball/Hoots/Rocky/Seedy/
 * Stacky/BSOD or any other OpenAI visual asset) — these are original, unrelated
 * shapes chosen only for their own thematic name (§6A lists themes, not
 * artwork to copy).
 */

type GlyphProps = { className?: string };

function BaseFace({
  fill,
  className,
  children,
}: {
  fill: string;
  className?: string;
  children?: ReactNode;
}) {
  return (
    <svg viewBox="0 0 40 40" className={className} aria-hidden="true">
      <circle cx="20" cy="20" r="18" fill={fill} />
      <circle cx="14" cy="18" r="2.6" fill="white" />
      <circle cx="26" cy="18" r="2.6" fill="white" />
      {children}
    </svg>
  );
}

function Smile({ y = 26 }: { y?: number }) {
  return <path d={`M14 ${y} Q20 ${y + 4} 26 ${y}`} stroke="white" strokeWidth="2" strokeLinecap="round" fill="none" />;
}

/** ecos_blue_bot — the default, friendly ECOS-blue companion. */
function EcosBlueBot({ className }: GlyphProps) {
  return (
    <BaseFace fill="#2563eb" className={className}>
      <circle cx="20" cy="6" r="2" fill="#2563eb" stroke="white" strokeWidth="1.5" />
      <Smile />
    </BaseFace>
  );
}

/** ecos_purple_bot — same bot silhouette, a distinct antenna shape and color. */
function EcosPurpleBot({ className }: GlyphProps) {
  return (
    <BaseFace fill="#7c3aed" className={className}>
      <rect x="18" y="3" width="4" height="4" rx="1" fill="white" />
      <Smile />
    </BaseFace>
  );
}

/** ecos_ember_companion — an energetic, warm-toned companion with a small flame motif. */
function EcosEmberCompanion({ className }: GlyphProps) {
  return (
    <BaseFace fill="#ea580c" className={className}>
      <path d="M20 2 C17 6 17 9 20 11 C23 9 23 6 20 2 Z" fill="#fed7aa" />
      <Smile />
    </BaseFace>
  );
}

/** ecos_owl_companion — a knowledge-themed companion with large, round eyes. */
function EcosOwlCompanion({ className }: GlyphProps) {
  return (
    <svg viewBox="0 0 40 40" className={className} aria-hidden="true">
      <circle cx="20" cy="20" r="18" fill="#92400e" />
      <circle cx="14" cy="18" r="5" fill="white" />
      <circle cx="26" cy="18" r="5" fill="white" />
      <circle cx="14" cy="18" r="2" fill="#1c1917" />
      <circle cx="26" cy="18" r="2" fill="#1c1917" />
      <path d="M18 22 L20 25 L22 22 Z" fill="#fbbf24" />
    </svg>
  );
}

/** ecos_rock_companion — a steady, dependable companion with faceted texture lines. */
function EcosRockCompanion({ className }: GlyphProps) {
  return (
    <BaseFace fill="#64748b" className={className}>
      <path d="M8 14 L16 10 M24 9 L32 13" stroke="#334155" strokeWidth="1.5" strokeLinecap="round" />
      <Smile />
    </BaseFace>
  );
}

/** ecos_growth_companion — a calm, growth-themed companion with a sprout motif. */
function EcosGrowthCompanion({ className }: GlyphProps) {
  return (
    <BaseFace fill="#059669" className={className}>
      <path d="M20 8 C17 5 14 6 14 6 C14 9 17 10 20 8 Z" fill="#a7f3d0" />
      <path d="M20 8 C23 5 26 6 26 6 C26 9 23 10 20 8 Z" fill="#a7f3d0" />
      <Smile />
    </BaseFace>
  );
}

/** ecos_stack_companion — a focused, deep-work companion with a stacked-bars motif. */
function EcosStackCompanion({ className }: GlyphProps) {
  return (
    <BaseFace fill="#312e81" className={className}>
      <rect x="14" y="4" width="12" height="2" rx="1" fill="#a5b4fc" />
      <rect x="15" y="7" width="10" height="2" rx="1" fill="#a5b4fc" />
      <Smile />
    </BaseFace>
  );
}

/** ecos_screen_companion — a digital, always-on companion with a small screen motif. */
function EcosScreenCompanion({ className }: GlyphProps) {
  return (
    <svg viewBox="0 0 40 40" className={className} aria-hidden="true">
      <circle cx="20" cy="20" r="18" fill="#0891b2" />
      <rect x="10" y="14" width="20" height="14" rx="3" fill="#ecfeff" />
      <circle cx="16" cy="21" r="1.8" fill="#0891b2" />
      <circle cx="24" cy="21" r="1.8" fill="#0891b2" />
      <path d="M16 25 Q20 27 24 25" stroke="#0891b2" strokeWidth="1.5" strokeLinecap="round" fill="none" />
    </svg>
  );
}

/** Renders the glyph for a given key, falling back to the default when the key is unrecognized. */
export function AssistantAvatarIcon({ avatarKey, className }: { avatarKey: string; className?: string }) {
  const key: AssistantAvatarKey = isKnownAssistantAvatar(avatarKey) ? avatarKey : DEFAULT_ASSISTANT_AVATAR;

  switch (key) {
    case 'ecos_blue_bot':
      return <EcosBlueBot className={className} />;
    case 'ecos_purple_bot':
      return <EcosPurpleBot className={className} />;
    case 'ecos_ember_companion':
      return <EcosEmberCompanion className={className} />;
    case 'ecos_owl_companion':
      return <EcosOwlCompanion className={className} />;
    case 'ecos_rock_companion':
      return <EcosRockCompanion className={className} />;
    case 'ecos_growth_companion':
      return <EcosGrowthCompanion className={className} />;
    case 'ecos_stack_companion':
      return <EcosStackCompanion className={className} />;
    case 'ecos_screen_companion':
      return <EcosScreenCompanion className={className} />;
  }
}
