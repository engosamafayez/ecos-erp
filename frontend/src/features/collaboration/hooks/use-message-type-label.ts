import { useTranslation } from 'react-i18next';

import type { MessageType } from '../types';

/**
 * A bracketed fallback label for a media message with no text body — `[Image]`,
 * `[File]`, `[Voice message]` — used wherever a snippet must be shown without
 * fetching/rendering the actual attachment (reply previews, composer quote bar).
 *
 * Every selector call here is static (compile-time key-checked): `$.message.types`
 * only has image/file/voice keys (text/system always carry a real body and never
 * need this fallback), so the dynamic `MessageType` only ever indexes this
 * already-resolved lookup, never the selector itself.
 */
export function useMessageTypeLabel(): (type: MessageType) => string {
  const { t } = useTranslation('collaboration');
  const labels: Partial<Record<MessageType, string>> = {
    image: t(($) => $.message.types.image),
    file: t(($) => $.message.types.file),
    voice: t(($) => $.message.types.voice),
  };
  return (type) => labels[type] ?? '';
}
