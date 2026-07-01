import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Monitor } from 'lucide-react';

import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useOpenSession, useCloseSession } from '@/features/pos/hooks/use-pos-queries';
import { usePosStore } from '@/features/pos/store/pos-store';

const openSchema = z.object({
  terminal_id:        z.string().min(1),
  cashier_id:         z.string().min(1),
  device_fingerprint: z.string().min(1),
  ip_address:         z.string().min(1),
  device_type:        z.string().min(1),
});

type OpenForm = z.infer<typeof openSchema>;

type SessionDialogProps = {
  open: boolean;
  mode: 'open' | 'close';
  onOpenChange: (open: boolean) => void;
};

export function SessionDialog({ open, mode, onOpenChange }: SessionDialogProps) {
  const { terminalId, cashierId, sessionId } = usePosStore();
  const openSession = useOpenSession();
  const closeSession = useCloseSession();

  const form = useForm<OpenForm>({
    resolver: zodResolver(openSchema),
    defaultValues: {
      terminal_id:        terminalId,
      cashier_id:         cashierId,
      device_fingerprint: navigator.userAgent.slice(0, 64),
      ip_address:         '0.0.0.0',
      device_type:        'desktop',
    },
  });

  async function onSubmit(data: OpenForm) {
    await openSession.mutateAsync(data);
    onOpenChange(false);
  }

  async function handleClose() {
    if (!sessionId) return;
    await closeSession.mutateAsync(sessionId);
    onOpenChange(false);
  }

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
              {closeSession.isPending ? 'Closing...' : 'Close Session'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    );
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-sm">
        <DialogHeader>
          <div className="flex items-center gap-2">
            <Monitor className="size-5" />
            <DialogTitle>Open POS Session</DialogTitle>
          </div>
        </DialogHeader>

        <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-3">
          <div className="space-y-1.5">
            <Label htmlFor="terminal_id">Terminal ID</Label>
            <Input id="terminal_id" {...form.register('terminal_id')} />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="cashier_id">Cashier ID</Label>
            <Input id="cashier_id" {...form.register('cashier_id')} />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="device_type">Device Type</Label>
            <Input id="device_type" {...form.register('device_type')} />
          </div>
          <DialogFooter className="pt-2">
            <Button variant="outline" type="button" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button type="submit" disabled={openSession.isPending}>
              {openSession.isPending ? 'Opening...' : 'Open Session'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
