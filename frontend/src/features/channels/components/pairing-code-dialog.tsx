import { useEffect, useState } from 'react';
import axios from 'axios';
import { Check, Copy, KeyRound } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { copyToClipboard } from '@/lib/clipboard';
import { useGeneratePairingCode } from '@/features/channels/hooks/use-channels';
import type { Channel, ConnectorHealthStatus, PairingCodeResult } from '@/features/channels/types/channel';

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  channel: Channel | null;
};

function extractMessage(error: unknown, fallback: string): string {
  return axios.isAxiosError(error) && typeof error.response?.data?.message === 'string'
    ? error.response.data.message
    : fallback;
}

const HEALTH_DOT: Record<ConnectorHealthStatus, string> = {
  never_connected: 'bg-gray-400',
  healthy: 'bg-emerald-500',
  degraded: 'bg-amber-500',
  disconnected: 'bg-rose-500',
};

/**
 * TASK-ECOS-V1.1-CRM-02-PAIRING-UI-FINAL-CLOSURE-011 — the one CRM-02 gap: an ECOS operator
 * had no way to obtain the pairing code the official ECOS WooCommerce Connector plugin requires.
 * Pure consumer of the existing pairing authority (GeneratePairingCodeAction via
 * POST /channels/{id}/pairing-code) — no expiry/hash/single-use rule is reimplemented here; the
 * countdown below is display-only, the server remains authoritative.
 */
export function PairingCodeDialog({ open, onOpenChange, channel }: Props) {
  const { t } = useTranslation('channels');
  const { t: tCommon } = useTranslation('common');
  const generate = useGeneratePairingCode();

  const [result, setResult] = useState<PairingCodeResult | null>(null);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [copied, setCopied] = useState(false);
  const [now, setNow] = useState(() => Date.now());

  // Explicit-click-only (Section 4): never generate on open. Also guarantees no stale code/
  // error survives from a previous channel or a previous re-open of this dialog. Adjusted
  // during render (React's documented pattern for resetting state on a prop change) rather
  // than in an effect, so there is no extra render with the previous channel's stale result.
  const [initializedFor, setInitializedFor] = useState<string | null>(null);
  const openSessionKey = open ? (channel?.id ?? '') : null;
  if (openSessionKey !== initializedFor) {
    if (open) {
      setResult(null);
      setErrorMessage(null);
      setCopied(false);
    }
    setInitializedFor(openSessionKey);
  }

  // Live "Expires"/"Expired" tick — display only; does not gate anything server-authoritative.
  useEffect(() => {
    if (!result?.expires_at) return;
    const id = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(id);
  }, [result?.expires_at]);

  if (!channel) return null;

  const isPaired = channel.transport_mode === 'connector';
  const expiresAt = result?.expires_at ? new Date(result.expires_at) : null;
  const isExpired = expiresAt !== null && expiresAt.getTime() <= now;
  // Primary CTA only when there is nothing already working to protect: unpaired with no code
  // yet, or an unpaired code that has expired. Once paired, generation always stays secondary
  // (Section 6) — the store is already connected, regenerating is a deliberate recovery action.
  const generateIsPrimary = !isPaired && (!result || isExpired);

  function handleGenerate() {
    if (!channel) return;
    // Cleared BEFORE the request, not after a failure — a regenerate attempt never leaves a
    // stale prior code on screen next to an error (Section 10).
    setResult(null);
    setErrorMessage(null);
    setCopied(false);
    generate.mutate(channel.id, {
      onSuccess: (data) => setResult(data),
      onError: (err) => setErrorMessage(extractMessage(err, t(($) => $.connector.errorGeneric))),
    });
  }

  async function handleCopy() {
    if (!result) return;
    const ok = await copyToClipboard(result.pairing_code);
    if (ok) {
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    }
  }

  const generateButtonLabel = generate.isPending
    ? t(($) => $.connector.generating)
    : result || isPaired
      ? t(($) => $.connector.generateNewCode)
      : t(($) => $.connector.generateButton);

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{t(($) => $.connector.dialogTitle)}</DialogTitle>
          <DialogDescription>{channel.name}</DialogDescription>
        </DialogHeader>

        <div className="flex flex-col gap-4">
          {isPaired ? (
            <div className="flex items-center gap-2 rounded-md border px-3 py-2">
              <span className={`size-2 shrink-0 rounded-full ${HEALTH_DOT[channel.connector_health]}`} />
              <span className="text-sm font-medium">{t(($) => $.connector.connected)}</span>
            </div>
          ) : null}

          {isPaired && !result ? (
            <p className="text-muted-foreground text-sm">{t(($) => $.connector.connectedDescription)}</p>
          ) : null}

          {!result ? (
            <p className="text-muted-foreground text-sm">{t(($) => $.connector.instructions)}</p>
          ) : (
            <div className="flex flex-col gap-1.5">
              <div className="flex items-center gap-2 rounded-md border px-3 py-2">
                <code
                  aria-label={t(($) => $.connector.code)}
                  className="min-w-0 flex-1 truncate font-mono text-base select-all"
                >
                  {result.pairing_code}
                </code>
                <Button
                  type="button"
                  variant="outline"
                  size="icon"
                  onClick={handleCopy}
                  aria-label={t(($) => $.connector.copy)}
                >
                  {copied ? <Check className="size-4" /> : <Copy className="size-4" />}
                </Button>
              </div>
              {copied ? <span className="text-xs text-emerald-600">{t(($) => $.connector.copied)}</span> : null}
              {expiresAt ? (
                <p className="text-muted-foreground text-xs">
                  {isExpired
                    ? t(($) => $.connector.expired)
                    : `${t(($) => $.connector.expires)} ${expiresAt.toLocaleTimeString()}`}
                </p>
              ) : null}
              <p className="text-muted-foreground text-sm">{t(($) => $.connector.instructions)}</p>
            </div>
          )}

          {errorMessage ? (
            <Alert variant="destructive">
              <AlertDescription>{errorMessage}</AlertDescription>
            </Alert>
          ) : null}

          <Button
            type="button"
            variant={generateIsPrimary ? 'default' : 'outline'}
            size={generateIsPrimary ? 'default' : 'sm'}
            className={generateIsPrimary ? undefined : 'self-start'}
            disabled={generate.isPending}
            onClick={handleGenerate}
          >
            <KeyRound className="size-4" />
            {generateButtonLabel}
          </Button>
        </div>

        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
            {tCommon(($) => $.common.close)}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
