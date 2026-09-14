/**
 * Brand slug generation.
 *
 * Lives outside brand-form.tsx so it can be imported by tests without making the
 * form module export a non-component (react-refresh/only-export-components: a
 * mixed module breaks Fast Refresh for the component it also exports).
 */

// Arabic Unicode block (U+0600–U+06FF) → Latin equivalents for slug generation
const ARABIC_MAP: Record<string, string> = {
  'ا': 'a', 'أ': 'a', 'إ': 'i', 'آ': 'aa', 'ء': '',
  'ؤ': 'w', 'ئ': 'y',
  'ب': 'b', 'ت': 't', 'ث': 'th', 'ج': 'j', 'ح': 'h',
  'خ': 'kh', 'د': 'd', 'ذ': 'dh', 'ر': 'r', 'ز': 'z',
  'س': 's', 'ش': 'sh', 'ص': 's', 'ض': 'd', 'ط': 't',
  'ظ': 'z', 'ع': 'a', 'غ': 'gh', 'ف': 'f', 'ق': 'q',
  'ك': 'k', 'ل': 'l', 'م': 'm', 'ن': 'n', 'ه': 'h',
  'و': 'w', 'ي': 'y', 'ى': 'a', 'ة': 'a',
  // Tashkeel (diacritics) — strip entirely
  'ً': '', 'ٌ': '', 'ٍ': '', 'َ': '', 'ُ': '',
  'ِ': '', 'ّ': '', 'ْ': '', 'ٰ': '', 'ـ': '',
};

export function toSlug(value: string): string {
  let text = value.toLowerCase().trim();
  // Transliterate Arabic characters before the ASCII-only filter
  text = text.replace(/[؀-ۿ]/g, (ch) => ARABIC_MAP[ch] ?? '');
  // Strip Latin diacritics (é → e, ü → u, etc.)
  text = text.normalize('NFD').replace(/[̀-ͯ]/g, '');
  return text
    .replace(/[^a-z0-9\s-]/g, '')
    .replace(/\s+/g, '-')
    .replace(/-+/g, '-')
    .replace(/^-+|-+$/g, '');
}
