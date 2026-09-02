import { useCallback, useEffect, useState } from 'react';

import { fetchMessageAttachmentBlob } from '../services/collaboration-service';
import { fetchTaskAttachmentBlob } from '../services/tasks-service';

interface SecureMediaResult {
  url: string | null;
  isLoading: boolean;
  isError: boolean;
}

/**
 * Attachments sit behind DocumentService's authenticated streaming routes, so a plain
 * <img src>/<audio src> would 401 — the bytes must be fetched with the bearer token and
 * turned into a same-origin object URL (mirrors payment-proof-section.tsx). Keyed so a
 * stale URL from a previous attachment can never be shown against a new one, and the
 * created URL is always revoked on unmount/key change.
 */
function useObjectUrl(key: string | null, load: (() => Promise<Blob>) | null): SecureMediaResult {
  const [result, setResult] = useState<{ key: string | null; url: string | null; isError: boolean }>({
    key: null,
    url: null,
    isError: false,
  });
  const [isLoading, setIsLoading] = useState(false);

  useEffect(() => {
    if (!key || !load) return;

    let cancelled = false;
    let created: string | null = null;
    // eslint-disable-next-line react-hooks/set-state-in-effect -- signals fetch-in-flight for this key change; there is no derivable-during-render source for it
    setIsLoading(true);

    load()
      .then((blob) => {
        if (cancelled) return;
        created = URL.createObjectURL(blob);
        setResult({ key, url: created, isError: false });
      })
      .catch(() => {
        if (!cancelled) setResult({ key, url: null, isError: true });
      })
      .finally(() => {
        if (!cancelled) setIsLoading(false);
      });

    return () => {
      cancelled = true;
      if (created) URL.revokeObjectURL(created);
    };
  }, [key, load]);

  const isCurrent = result.key === key;
  return {
    url: isCurrent ? result.url : null,
    isLoading,
    isError: isCurrent && result.isError,
  };
}

/** Eager inline preview/playback for a message's own image or voice attachment. */
export function useMessageAttachmentUrl(messageId: string | null): SecureMediaResult {
  const load = useCallback(() => fetchMessageAttachmentBlob(messageId as string), [messageId]);
  return useObjectUrl(messageId, messageId ? load : null);
}

/** Fetches a message attachment on demand (e.g. a "file" type the user chose to download). */
export async function downloadMessageAttachment(messageId: string, filename: string): Promise<void> {
  const blob = await fetchMessageAttachmentBlob(messageId);
  triggerDownload(blob, filename);
}

/** Fetches a task attachment on demand — task attachment lists never auto-fetch bytes. */
export async function downloadTaskAttachment(taskId: string, documentId: string, filename: string): Promise<void> {
  const blob = await fetchTaskAttachmentBlob(taskId, documentId);
  triggerDownload(blob, filename);
}

function triggerDownload(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = filename;
  anchor.click();
  URL.revokeObjectURL(url);
}
