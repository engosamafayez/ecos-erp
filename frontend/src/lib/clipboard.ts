/**
 * Copies text to the clipboard, working around `navigator.clipboard` being
 * unavailable in non-secure (plain HTTP, non-localhost) browser contexts —
 * the Clipboard API is spec-restricted to secure contexts, so
 * `navigator.clipboard` itself is `undefined` there rather than merely
 * throwing when called.
 *
 * The legacy fallback is checked BEFORE any `await`, so it still runs
 * synchronously inside the original click handler's call stack — Safari and
 * older engines require `document.execCommand('copy')` to run within the
 * user-gesture window, which a preceding `await` would already have closed.
 *
 * @returns true if the text reached the clipboard by either path.
 */
export async function copyToClipboard(text: string): Promise<boolean> {
  const canUseModernApi =
    typeof navigator !== 'undefined' &&
    !!navigator.clipboard?.writeText &&
    typeof window !== 'undefined' &&
    window.isSecureContext;

  if (canUseModernApi) {
    try {
      await navigator.clipboard.writeText(text);
      return true;
    } catch {
      // Permission denied or otherwise rejected — fall through to the legacy path.
    }
  }

  return legacyCopy(text);
}

/** `document.execCommand('copy')` via a hidden, off-screen textarea — the bounded
 *  compatibility fallback for browser contexts where the Clipboard API is absent. */
function legacyCopy(text: string): boolean {
  if (typeof document === 'undefined') {
    return false;
  }

  const textarea = document.createElement('textarea');
  textarea.value = text;
  textarea.setAttribute('readonly', '');
  textarea.style.position = 'fixed';
  textarea.style.top = '0';
  textarea.style.left = '-9999px';

  document.body.appendChild(textarea);
  textarea.select();
  textarea.setSelectionRange(0, textarea.value.length);

  try {
    return document.execCommand('copy');
  } catch {
    return false;
  } finally {
    document.body.removeChild(textarea);
  }
}
