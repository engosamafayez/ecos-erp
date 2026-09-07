import { describe, it, expect, afterEach } from 'vitest';

import { copyToClipboard } from './clipboard';

/**
 * TASK-ECOS-COMMERCE-CUSTOMERS-FINAL-USER-REVIEW-REMEDIATION-004.
 *
 * DEV runs on a non-secure HTTP origin, where `navigator.clipboard` is `undefined`
 * (the Clipboard API is spec-restricted to secure contexts) rather than merely
 * throwing when called — that's the actual, proven root cause of "Copy doesn't work"
 * (see the task report). These tests drive both branches directly rather than
 * asserting on real browser clipboard state, which jsdom doesn't provide.
 */

const originalClipboard = navigator.clipboard;
const originalIsSecureContext = window.isSecureContext;
const originalExecCommand = document.execCommand;

function setClipboard(value: { writeText: (text: string) => Promise<void> } | undefined): void {
  Object.defineProperty(navigator, 'clipboard', { value, configurable: true, writable: true });
}

function setSecureContext(value: boolean): void {
  Object.defineProperty(window, 'isSecureContext', { value, configurable: true, writable: true });
}

afterEach(() => {
  setClipboard(originalClipboard);
  setSecureContext(originalIsSecureContext);
  document.execCommand = originalExecCommand;
});

describe('copyToClipboard', () => {
  it('uses navigator.clipboard.writeText in a secure context', async () => {
    const writeText = async () => {};
    let calledWith: string | undefined;
    setClipboard({ writeText: async (text) => { calledWith = text; await writeText(); } });
    setSecureContext(true);
    let execCalled = false;
    document.execCommand = () => { execCalled = true; return true; };

    const ok = await copyToClipboard('0501112222');

    expect(ok).toBe(true);
    expect(calledWith).toBe('0501112222');
    expect(execCalled).toBe(false);
  });

  it('falls back to the legacy path when navigator.clipboard is unavailable — the real DEV-over-HTTP case', async () => {
    setClipboard(undefined);
    setSecureContext(false);
    let execCalledWith: string | undefined;
    document.execCommand = (cmd) => { execCalledWith = cmd; return true; };

    const ok = await copyToClipboard('0501112222');

    expect(ok).toBe(true);
    expect(execCalledWith).toBe('copy');
  });

  it('falls back to the legacy path when the modern API is present but rejects', async () => {
    setClipboard({ writeText: async () => { throw new Error('permission denied'); } });
    setSecureContext(true);
    let execCalled = false;
    document.execCommand = () => { execCalled = true; return true; };

    const ok = await copyToClipboard('0501112222');

    expect(ok).toBe(true);
    expect(execCalled).toBe(true);
  });

  it('returns false when every path fails, rather than reporting a false success', async () => {
    setClipboard(undefined);
    setSecureContext(false);
    document.execCommand = () => false;

    const ok = await copyToClipboard('0501112222');

    expect(ok).toBe(false);
  });

  it('returns false, not a thrown error, when execCommand itself throws', async () => {
    setClipboard(undefined);
    setSecureContext(false);
    document.execCommand = () => { throw new Error('not supported'); };

    await expect(copyToClipboard('0501112222')).resolves.toBe(false);
  });

  it('removes the temporary textarea it creates for the legacy path', async () => {
    setClipboard(undefined);
    setSecureContext(false);
    document.execCommand = () => true;

    await copyToClipboard('0501112222');

    expect(document.querySelector('textarea')).toBeNull();
  });
});
