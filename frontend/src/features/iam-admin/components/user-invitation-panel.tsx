import { useState } from 'react';
import axios from 'axios';
import { Check, Copy, Mail, X } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { ConfirmDialog, EmptyState, ErrorState, LoadingState } from '@/components/crud';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Can } from '@/features/authorization';
import {
  useInviteUser,
  useResendInvitation,
  useRevokeInvitation,
  useUserInvitations,
} from '@/features/iam-admin/hooks/use-users';
import type { Invitation, UserDetail } from '@/features/iam-admin/types/user';

const STATUS_STYLE: Record<Invitation['status'], string> = {
  pending: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
  accepted: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
  expired: 'bg-muted text-muted-foreground',
  revoked: 'bg-muted text-muted-foreground line-through',
};

/**
 * CORE-02 Task 1 — Invitation Closure. The invitation history for THIS user, plus the smallest
 * complete admin workflow: invite / resend / revoke. Gated entirely on `iam.users.invite`
 * (already seeded, already granted to company-admin) — one permission for the whole lifecycle
 * facet, matching how the rest of this workspace reuses one permission per related action set.
 */
export function UserInvitationPanel({ user }: { user: UserDetail }) {
  const { t } = useTranslation('iam-admin');
  const query = useUserInvitations(user.id);
  const invite = useInviteUser(user.id);
  const resend = useResendInvitation(user.id);
  const revoke = useRevokeInvitation(user.id);

  const [issuedToken, setIssuedToken] = useState<string | null>(null);
  const [pendingRevoke, setPendingRevoke] = useState<Invitation | null>(null);
  const [error, setError] = useState<string | null>(null);

  const invitations = query.data ?? [];
  const activePending = invitations.find((i) => i.status === 'pending' && !i.expired) ?? null;

  function handleError(err: unknown) {
    setError(
      axios.isAxiosError(err) && typeof err.response?.data?.message === 'string'
        ? err.response.data.message
        : t(($) => $.users.invitations.genericError),
    );
  }

  return (
    <Can permission="iam.users.invite" fallback={<InvitationHistoryReadOnly invitations={invitations} loading={query.isLoading} error={query.isError} />}>
      <div className="flex flex-col gap-4">
        <div className="flex items-center justify-between gap-2">
          <p className="text-muted-foreground text-sm">{t(($) => $.users.invitations.subtitle)}</p>
          {activePending ? (
            <Button
              type="button"
              variant="outline"
              size="sm"
              disabled={resend.isPending}
              onClick={() => {
                setError(null);
                resend.mutate(
                  {},
                  {
                    onSuccess: (result) => setIssuedToken(result.invitation_token),
                    onError: handleError,
                  },
                );
              }}
            >
              <Mail className="size-3.5" />
              {resend.isPending ? t(($) => $.users.invitations.sending) : t(($) => $.users.invitations.resend)}
            </Button>
          ) : (
            <Button
              type="button"
              size="sm"
              disabled={invite.isPending}
              onClick={() => {
                setError(null);
                invite.mutate(
                  {},
                  {
                    onSuccess: (result) => setIssuedToken(result.invitation_token),
                    onError: handleError,
                  },
                );
              }}
            >
              <Mail className="size-3.5" />
              {invite.isPending ? t(($) => $.users.invitations.sending) : t(($) => $.users.invitations.invite)}
            </Button>
          )}
        </div>

        {error ? <p className="text-destructive text-sm">{error}</p> : null}

        {query.isLoading ? (
          <LoadingState />
        ) : query.isError ? (
          <ErrorState description={query.error instanceof Error ? query.error.message : undefined} />
        ) : invitations.length === 0 ? (
          <EmptyState title={t(($) => $.users.invitations.empty)} />
        ) : (
          <ul className="flex flex-col gap-2">
            {invitations.map((invitation) => (
              <li key={invitation.id} className="flex items-center justify-between gap-2 rounded-md border px-3 py-2 text-sm">
                <div className="flex flex-col">
                  <span
                    className={`w-fit rounded px-1.5 py-0.5 text-xs font-medium ${STATUS_STYLE[invitation.expired && invitation.status === 'pending' ? 'expired' : invitation.status]}`}
                  >
                    {t(($) => $.users.invitations.status[invitation.expired && invitation.status === 'pending' ? 'expired' : invitation.status])}
                  </span>
                  <span className="text-muted-foreground mt-1 text-xs">
                    {invitation.expires_at
                      ? t(($) => $.users.invitations.expiresAt, { date: new Date(invitation.expires_at as string).toLocaleString() })
                      : null}
                  </span>
                </div>
                {invitation.status === 'pending' && !invitation.expired ? (
                  <Button type="button" variant="ghost" size="sm" onClick={() => setPendingRevoke(invitation)}>
                    <X className="size-3.5" />
                    {t(($) => $.users.invitations.revoke)}
                  </Button>
                ) : null}
              </li>
            ))}
          </ul>
        )}

        <InvitationLinkDialog token={issuedToken} onClose={() => setIssuedToken(null)} />

        {pendingRevoke ? (
          <ConfirmDialog
            open
            onOpenChange={(open) => !open && setPendingRevoke(null)}
            title={t(($) => $.users.invitations.revokeConfirmTitle)}
            description={t(($) => $.users.invitations.revokeConfirmDescription)}
            confirmLabel={t(($) => $.users.invitations.revoke)}
            variant="destructive"
            loading={revoke.isPending}
            onConfirm={() => {
              setError(null);
              revoke.mutate(pendingRevoke.id, {
                onSuccess: () => setPendingRevoke(null),
                onError: (err) => {
                  handleError(err);
                  setPendingRevoke(null);
                },
              });
            }}
          />
        ) : null}
      </div>
    </Can>
  );
}

function InvitationHistoryReadOnly({
  invitations,
  loading,
  error,
}: {
  invitations: Invitation[];
  loading: boolean;
  error: boolean;
}) {
  const { t } = useTranslation('iam-admin');
  if (loading) return <LoadingState />;
  if (error) return <ErrorState />;
  if (invitations.length === 0) return <EmptyState title={t(($) => $.users.invitations.empty)} />;
  return (
    <ul className="flex flex-col gap-2">
      {invitations.map((invitation) => (
        <li key={invitation.id} className="rounded-md border px-3 py-2 text-sm">
          <span className={`rounded px-1.5 py-0.5 text-xs font-medium ${STATUS_STYLE[invitation.status]}`}>
            {t(($) => $.users.invitations.status[invitation.status])}
          </span>
        </li>
      ))}
    </ul>
  );
}

/**
 * Shows the raw invitation link exactly once, immediately after invite()/resend() — mirrors
 * GeneratedPasswordDialog's exact pattern for the analogous server-generated-secret case. The
 * token cannot be retrieved again afterward; the backend never persists or re-derives it.
 */
function InvitationLinkDialog({ token, onClose }: { token: string | null; onClose: () => void }) {
  const { t } = useTranslation('iam-admin');
  const [copied, setCopied] = useState(false);

  const link = token ? `${window.location.origin}/accept-invitation?token=${encodeURIComponent(token)}` : '';

  async function handleCopy() {
    if (!link) return;
    try {
      await navigator.clipboard.writeText(link);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      // Clipboard access can be denied by the browser; the link stays visible and selectable.
    }
  }

  return (
    <Dialog open={token !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t(($) => $.users.invitations.linkTitle)}</DialogTitle>
          <DialogDescription>{t(($) => $.users.invitations.linkHint)}</DialogDescription>
        </DialogHeader>

        <div className="flex items-center gap-2 rounded-md border px-3 py-2">
          <code className="flex-1 truncate font-mono text-xs select-all">{link}</code>
          <Button type="button" variant="outline" size="icon" onClick={handleCopy} aria-label={t(($) => $.users.invitations.copy)}>
            {copied ? <Check className="size-4" /> : <Copy className="size-4" />}
          </Button>
        </div>

        <DialogFooter>
          <Button type="button" onClick={onClose}>
            {t(($) => $.users.invitations.linkDone)}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
