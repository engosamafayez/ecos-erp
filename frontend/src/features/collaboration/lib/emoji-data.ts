/**
 * Curated common-emoji set for the composer's emoji picker (brief §12).
 * Deliberately a static list, not a third-party library: the requirement is
 * "clear emoji action, picker, insert at cursor" — a category grid of common
 * emoji satisfies that without a new dependency. Emoji are plain Unicode
 * text either way (§12 — "emoji remain normal message text"), so this list
 * only needs to be reasonably broad, not exhaustive.
 */
export type EmojiCategoryKey = 'smileys' | 'gestures' | 'hearts' | 'animals' | 'food' | 'activities' | 'objects' | 'symbols';

export const EMOJI_CATEGORIES: { key: EmojiCategoryKey; emoji: string[] }[] = [
  {
    key: 'smileys',
    emoji: [
      '😀', '😃', '😄', '😁', '😆', '😅', '🤣', '😂', '🙂', '🙃',
      '😉', '😊', '😇', '🥰', '😍', '🤩', '😘', '😋', '😛', '😜',
      '🤪', '🤔', '🤨', '😐', '😑', '😶', '🙄', '😏', '😴', '😪',
      '😢', '😭', '😤', '😠', '😡', '🥺', '😱', '😨', '😰', '😥',
      '😓', '🤗', '🤭', '🤫', '🤯', '😳', '🥵', '🥶', '😷', '🤒',
    ],
  },
  {
    key: 'gestures',
    emoji: [
      '👍', '👎', '👌', '✌️', '🤞', '🤝', '👏', '🙌', '🙏', '💪',
      '👋', '🤙', '👊', '✊', '🤟', '👆', '👇', '👈', '👉', '☝️',
      '🖐️', '✋', '🫡', '🫱',
    ],
  },
  {
    key: 'hearts',
    emoji: [
      '❤️', '🧡', '💛', '💚', '💙', '💜', '🖤', '🤍', '🤎', '💔',
      '❣️', '💕', '💞', '💓', '💗', '💖', '💘', '💝',
    ],
  },
  {
    key: 'animals',
    emoji: [
      '🐶', '🐱', '🐭', '🐹', '🐰', '🦊', '🐻', '🐼', '🐨', '🐯',
      '🦁', '🐮', '🐷', '🐸', '🐵', '🐔', '🐧', '🐦', '🐴', '🦄',
    ],
  },
  {
    key: 'food',
    emoji: [
      '🍎', '🍌', '🍉', '🍇', '🍓', '🍕', '🍔', '🍟', '🌮', '🍣',
      '🍩', '🍪', '🎂', '🍫', '🍿', '☕', '🍵', '🧃', '🥤', '🍰',
    ],
  },
  {
    key: 'activities',
    emoji: [
      '⚽', '🏀', '🏈', '🎾', '🏐', '🎮', '🎯', '🎳', '🎨', '🎵',
      '🎉', '🎁', '🏆', '🚀', '✈️', '🚗', '🏖️', '⏰', '📅', '✅',
    ],
  },
  {
    key: 'objects',
    emoji: [
      '📱', '💻', '📞', '📧', '📎', '📌', '📄', '📁', '🔒', '🔑',
      '💡', '🔔', '📢', '💰', '💳', '🛒', '📦', '🗓️', '⌛', '🔍',
    ],
  },
  {
    key: 'symbols',
    emoji: [
      '✅', '❌', '❗', '❓', '⚠️', '🔴', '🟢', '🟡', '🔵', '⭐',
      '✨', '🔥', '💯', '👀', '🆕', '♻️', '➡️', '⬅️', '🔝', '✔️',
    ],
  },
];
