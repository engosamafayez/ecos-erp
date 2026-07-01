import { useEffect, useState } from 'react';
import { Monitor, User } from 'lucide-react';

import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { useOpenSession, useCloseSession, useTerminals } from '@/features/pos/hooks/use-pos-queries';
import { usePosStore } from '@/features/pos/store/pos-store';
import { useAuthStore } from '@/features/auth/store/auth-store';

function detectDeviceType(): string {
  if (/Mobi|Android/i.test(navigator.userAgent)) return 'mobile';
  return 'browser';
}

type SessionDialogProps = {
  open: boolean;
  mode: 'open' | 'close';
  onOpenChange: (open: boolean) => void;
};

export function SessionDialog({ open, mode, onOpenChange }: SessionDialogProps) {
  const { sessionId } = usePosStore();
  const authUser = useAuthStore((s) => s.user);
  const openSession = useOpenSession();
  const closeSession = useCloseSession();
  const { data: terminals = [], isLoading: terminalsLoading } = useTerminals();

  const [terminalId, setTerminalId] = useState('');

  // Auto-select when exactly one terminal is available
  useEffect(() => {
    if (terminals.length === 1 && terminals[0]) {
      setTerminalId(terminals[0].id);
    }
  }, [terminals]);

  // Reset selection when dialog closes
  useEffect(() => {
    if (!open) setTerminalId('');
  }, [open]);

  async function handleOpen() {
    if (!terminalId) return;
    await openSession.mutateAsync({
      terminal_id:        terminalId,
      device_fingerprint: navigator.userAgent.slice(0, 64),
      device_type:        detectDeviceType(),
    });
    onOpenChange(false);
  }

  async function handleClose() {
    if (!sessionId) return;
    await closeSession.mutateAsync(sessionId);
    onOpenChange(false);
  }

  // ── Close mode ────────────────────────────────────────────────────────────

  if (mode === 'close') {
    return (
      <Dialog open={open} onOpenChange={onOpenChange}>
        <DialogContent className="max-w-sm">
          <DialogHeader>
            <DialogTitle>Close Session</DialogTitle>
          </DialogHeader>
          <p className="text-sm text-muted-foreground">
            This will close the current POS session. Ensure all shifts are approved first.
          </p>
          <DialogFooter>
            <Button variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
            <Button
              variant="destructive"
              disabled={closeSession.isPending}
              onClick={handleClose}
            >
              {closeSession.isPending ? 'Closing…' : 'Close Session'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    );
  }

  // ── Open mode ─────────────────────────────────────────────────────────────

  const noTerminals = !terminalsLoading && terminals.length === 0;
  const singleTerminal = terminals.length === 1 ? terminals[0] : null;
  const canOpen = !!terminalId && !openSession.isPending && !noTerminals;
  const deviceLabel = detectDeviceType() === 'mobile' ? 'Mobile' : 'Desktop';

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-sm">
        <DialogHeader>
          <div className="flex items-center gap-2">
            <Monitor className="size-5" />
            <DialogTitle>Open POS Session</DialogTitle>
          </div>
        </DialogHeader>

        <div className="space-y-4">
          {/* Cashier — read-only, resolved from authenticated user */}
          <div className="space-y-1.5">
            <Label>Cashier</Label>
            <div className="flex items-center gap-2 rounded-md border bg-muted/50 px-3 py-2 text-sm">
              <User className="size-4 shrink-0 text-muted-foreground" />
              <span className="font-medium">{authUser?.name ?? '—'}</span>
              <span className="ml-auto shrink-0 text-xs text-muted-foreground">Logged in</span>
            </div>
          </div>

          {/* Terminal — dropdown or single display */}
          <div className="space-y-1.5">
            <Label htmlFor="terminal-select">Terminal</Label>

            {terminalsLoading && (
              <div className="rounded-md border bg-muted/30 px-3 py-2 text-sm text-muted-foreground">
                Loading terminals…
              </div>
            )}

            {noTerminals && (
              <div className="rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">
                No POS terminal is assigned to your account.
              </div>
            )}

            {singleTerminal && (
              <div className="rounded-md border bg-muted/50 px-3 py-2 text-sm">
                <span className="font-medium">{singleTerminal.name}</span>
                <span className="ml-2 text-xs text-muted-foreground">{singleTerminal.code}</span>
              </div>
            )}

            {!terminalsLoading && terminals.length > 1 && (
              <Select value={terminalId} onValueChange={setTerminalId}>
                <SelectTrigger id="terminal-select">
                  <SelectValue placeholder="Select a terminal…" />
                </SelectTrigger>
                <SelectContent>
                  {terminals.map((t) => (
                    <SelectItem key={t.id} value={t.id}>
                      <span>{t.name}</span>
                      <span className="ml-2 text-xs text-muted-foreground">{t.code}</span>
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
          </div>

          {/* Device — auto-detected, read-only */}
          <div className="space-y-1.5">
            <Label>Device</Label>
            <div className="rounded-md border bg-muted/50 px-3 py-2 text-sm">
              <span className="font-medium">{deviceLabel}</span>
              <span className="ml-2 text-xs text-muted-foreground">Auto-detected</span>
            </div>
          </div>
        </div>

        <DialogFooter className="pt-2">
          <Button variant="outline" type="button" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button
            type="button"
            disabled={!canOpen}
            onClick={handleOpen}
          >
            {openSession.isPending ? 'Opening…' : 'Open Session'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
