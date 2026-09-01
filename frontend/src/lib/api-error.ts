import axios from 'axios';

/**
 * Extracts a human-readable message from an API error response — Laravel
 * validation errors (up to 3 field messages) take priority, then a plain
 * backend `message`, else a generic fallback. Safe to call with any thrown
 * value (guards on `axios.isAxiosError`).
 */
export function extractApiErrorMessage(error: unknown): string {
  if (!axios.isAxiosError(error)) {
    return 'Unexpected server error. Please contact your administrator.';
  }
  const data = error.response?.data as Record<string, unknown> | undefined;
  if (!data) {
    return 'Unexpected server error. Please contact your administrator.';
  }
  // Laravel validation errors — collect up to 3 field messages
  if (data.errors && typeof data.errors === 'object') {
    const msgs = Object.values(data.errors as Record<string, string[]>)
      .flat()
      .filter(Boolean)
      .slice(0, 3);
    if (msgs.length > 0) return msgs.join(' ');
  }
  // Backend message (business logic failures, auth errors, etc.)
  if (typeof data.message === 'string' && data.message) {
    return data.message;
  }
  return 'Unexpected server error. Please contact your administrator.';
}
