import { CheckCircle2, ExternalLink, Upload, XCircle } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useToast } from '@/components/ds/use-toast';
import { usePermission } from '@/features/authorization/use-authorization';
import { cn } from '@/lib/utils';
import {
  usePaymentProofActions,
  usePaymentProofs,
} from '@/features/orders/hooks/use-payment-proofs';
import {
  paymentProofService,
  type PaymentProof,
  type PaymentProofState,
} from '@/features/orders/services/payment-proof-service';

/** Methods for which the approved brand policy requires proof (COD → none, card → optional). */
const PROOF_REQUIRED_METHODS = ['instapay', 'bank_transfer'];

type Props = { orderId: string; paymentMethod: string | null };

export function PaymentProofSection({ orderId, paymentMethod }: Props) {
  const { t } = useTranslation('orders');
  const { toast } = useToast();

  // Declared before the list query so the query can be skipped entirely: `index` and
  // `download` are now gated on `sales.orders.proof_view`, so a reader without it would
  // otherwise fire a request that can only 403.
  const { can } = usePermission();
  const canRead = can('sales.orders.proof_view');

  const { data: proofs = [] } = usePaymentProofs(orderId, canRead);
  const { upload, verify, reject } = usePaymentProofActions(orderId);
  const fileRef = useRef<HTMLInputElement>(null);
  const [rejectingId, setRejectingId] = useState<string | null>(null);
  const [reason, setReason] = useState('');

  const active = proofs.find((p) => p.is_active) ?? null;
  const required = PROOF_REQUIRED_METHODS.includes((paymentMethod ?? '').toLowerCase());
  const fail = () => toast({ title: t(($) => $.orderDetail.proofActionFailed), variant: 'destructive' });

  // A7 — the server already enforces these permissions on the proof routes; the UI
  // mirrors them so an operator without the right is not shown a button that would 403.
  const canUpload = can('sales.orders.proof_upload');
  const canVerify = can('sales.orders.proof_verify');
  const canReject = can('sales.orders.proof_reject');

  const onPick = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (file) {
      upload.mutate(file, {
        onError: () => toast({ title: t(($) => $.orderDetail.proofUploadFailed), variant: 'destructive' }),
      });
    }
    e.target.value = '';
  };
  const doReject = () => {
    if (!rejectingId || !reason.trim()) return;
    reject.mutate(
      { proofId: rejectingId, reason: reason.trim() },
      { onSuccess: () => { setRejectingId(null); setReason(''); }, onError: fail },
    );
  };
  const view = (p: PaymentProof) => { void paymentProofService.view(p).catch(fail); };

  // Inline preview of the ACTIVE proof. The file sits on a private disk behind an
  // authenticated route, so it must be fetched with the bearer token and turned into an
  // object URL — a plain <img src> would 401. The operator should see the evidence
  // without having to open another tab.
  // Keyed by proof id so a stale object URL can never be shown against a newer proof,
  // and so the effect never has to null it synchronously.
  const [preview, setPreview] = useState<{ id: string; url: string } | null>(null);
  const activeId = active?.id ?? null;
  const previewSrc = (active?.mime_type ?? '').startsWith('image/') ? (active?.download_url ?? null) : null;

  useEffect(() => {
    if (activeId == null || previewSrc == null) return;

    let cancelled = false;
    let created: string | null = null;

    void paymentProofService.objectUrl(previewSrc)
      .then((url) => {
        if (cancelled) { URL.revokeObjectURL(url); return; }
        created = url;
        setPreview({ id: activeId, url });
      })
      .catch(() => { /* preview is a convenience; View still works */ });

    return () => {
      cancelled = true;
      if (created) URL.revokeObjectURL(created);
    };
  }, [activeId, previewSrc]);

  const previewUrl = preview !== null && preview.id === activeId ? preview.url : null;

  const stateLabel = (s: PaymentProofState) =>
    s === 'verified' ? t(($) => $.orderDetail.proofStateVerified)
      : s === 'rejected' ? t(($) => $.orderDetail.proofStateRejected)
        : t(($) => $.orderDetail.proofStateUploaded);
  const stateCls = (s: PaymentProofState) =>
    s === 'verified' ? 'text-emerald-600 dark:text-emerald-400'
      : s === 'rejected' ? 'text-red-600 dark:text-red-400'
        : 'text-amber-600 dark:text-amber-400';

  // No read right, no section. Hooks above have all run, so this early return is safe, and it
  // keeps the panel from rendering an empty shell for a reader who may never see the evidence.
  if (!canRead) return null;

  return (
    <div className="flex flex-col gap-3">
      <div className="flex items-center justify-between">
        <span className="text-muted-foreground text-xs font-medium uppercase tracking-wide">
          {t(($) => $.orderDetail.proofTitle)}
        </span>
        {required && active == null ? (
          <Badge variant="secondary" className="text-[10px]">{t(($) => $.orderDetail.proofRequired)}</Badge>
        ) : null}
      </div>

      <input ref={fileRef} type="file" accept="image/*,.pdf" className="hidden" onChange={onPick} />

      {active == null ? (
        <div className="flex items-center justify-between gap-2">
          <span className="text-muted-foreground text-sm">{t(($) => $.orderDetail.proofNone)}</span>
          {canUpload && (
            <Button size="sm" variant="outline" onClick={() => fileRef.current?.click()} disabled={upload.isPending}>
              <Upload className="mr-1 size-3.5" />{t(($) => $.orderDetail.proofUploadBtn)}
            </Button>
          )}
        </div>
      ) : (
        <div className="flex flex-col gap-2 rounded-md border px-3 py-2.5">
          <div className="flex items-center justify-between gap-2">
            <span className={cn('text-sm font-semibold', stateCls(active.state))}>{stateLabel(active.state)}</span>
            <div className="flex items-center gap-1.5">
              <Button size="sm" variant="ghost" onClick={() => view(active)}>
                <ExternalLink className="mr-1 size-3.5" />{t(($) => $.orderDetail.proofView)}
              </Button>
              {active.state === 'uploaded' ? (
                <>
                  {canVerify && (
                    <Button size="sm" variant="outline" onClick={() => verify.mutate(active.id, { onError: fail })} disabled={verify.isPending}>
                      <CheckCircle2 className="mr-1 size-3.5" />{t(($) => $.orderDetail.proofVerify)}
                    </Button>
                  )}
                  {canReject && (
                    <Button size="sm" variant="outline" onClick={() => { setRejectingId(active.id); setReason(''); }}>
                      <XCircle className="mr-1 size-3.5" />{t(($) => $.orderDetail.proofReject)}
                    </Button>
                  )}
                </>
              ) : null}
              {canUpload && (
                <Button size="sm" variant="outline" onClick={() => fileRef.current?.click()} disabled={upload.isPending}>
                  {t(($) => $.orderDetail.proofReplace)}
                </Button>
              )}
            </div>
          </div>

          {/* The evidence itself — visible without leaving the screen. */}
          {previewUrl ? (
            <button
              type="button"
              onClick={() => view(active)}
              className="block w-full overflow-hidden rounded border bg-muted/30"
              title={active.original_filename ?? undefined}
            >
              <img
                src={previewUrl}
                alt={active.original_filename ?? 'payment proof'}
                className="max-h-56 w-full object-contain"
              />
            </button>
          ) : null}

          {/* Who submitted / reviewed it, and when — the audit trail the operator needs. */}
          <div className="text-muted-foreground flex flex-wrap gap-x-3 gap-y-0.5 text-[11px]">
            {active.original_filename ? <span className="font-mono">{active.original_filename}</span> : null}
            {active.uploaded_at ? <span>{t(($) => $.orderDetail.proofUploadedAt)}: {new Date(active.uploaded_at).toLocaleString()}</span> : null}
            {active.verified_at ? <span>{t(($) => $.orderDetail.proofVerifiedAt)}: {new Date(active.verified_at).toLocaleString()}</span> : null}
            {active.rejected_at ? <span>{t(($) => $.orderDetail.proofRejectedAt)}: {new Date(active.rejected_at).toLocaleString()}</span> : null}
          </div>

          {active.state === 'rejected' && active.rejection_reason ? (
            <p className="text-muted-foreground text-xs">
              {t(($) => $.orderDetail.proofReasonLabel)}: {active.rejection_reason}
            </p>
          ) : null}

          {rejectingId === active.id ? (
            <div className="flex items-center gap-2">
              <Input
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                placeholder={t(($) => $.orderDetail.proofRejectPrompt)}
                className="h-8"
              />
              <Button size="sm" onClick={doReject} disabled={!reason.trim() || reject.isPending}>
                {t(($) => $.orderDetail.proofReject)}
              </Button>
            </div>
          ) : null}
        </div>
      )}

      {proofs.length > 1 ? (
        <div className="flex flex-col gap-1.5">
          <span className="text-muted-foreground text-[11px] font-medium uppercase tracking-wide">
            {t(($) => $.orderDetail.proofHistoryTitle)}
          </span>
          {proofs.map((p, i) => (
            <div key={p.id} className="text-muted-foreground flex items-center gap-2 text-xs">
              <span className="font-mono">#{proofs.length - i}</span>
              <span className={cn('font-medium', stateCls(p.state))}>{stateLabel(p.state)}</span>
              {p.rejection_reason ? <span>· {p.rejection_reason}</span> : null}
              <button type="button" className="text-primary hover:underline" onClick={() => view(p)}>
                {t(($) => $.orderDetail.proofView)}
              </button>
            </div>
          ))}
        </div>
      ) : null}
    </div>
  );
}
