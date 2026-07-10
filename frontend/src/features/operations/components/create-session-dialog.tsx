import { useState } from 'react';
import { Loader2 } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useToastStore } from '@/components/ds/use-toast';
import { useWarehouseOptions } from '@/features/products/hooks/use-warehouse-options';
import { useCreateSession } from '../hooks/use-preparation';

type Props = {
  open: boolean;
  onClose: () => void;
  onCreated?: () => void;
};

export function CreateSessionDialog({ open, onClose, onCreated }: Props) {
  const toast = useToastStore((s) => s.toast);
  const today = new Date().toISOString().split('T')[0];

  const [warehouseId,  setWarehouseId]  = useState('');
  const [planningDate, setPlanningDate] = useState(today);
  const [operatorId,   setOperatorId]   = useState('');
  const [supervisorId, setSupervisorId] = useState('');
  const [notes,        setNotes]        = useState('');

  const { data: warehouseOptions = [] } = useWarehouseOptions();
  const { mutate: createSession, isPending } = useCreateSession();

  function handleSubmit() {
    if (!warehouseId || !planningDate || !operatorId) {
      toast({ title: 'Missing required fields', description: 'Warehouse, date and operator are required.', variant: 'destructive' });
      return;
    }

    createSession(
      {
        warehouse_id:  warehouseId,
        planning_date: planningDate,
        operator_id:   operatorId,
        supervisor_id: supervisorId || undefined,
        notes:         notes || undefined,
      },
      {
        onSuccess: () => {
          toast({ title: 'Session created', description: 'Preparation session created successfully.' });
          handleClose();
          onCreated?.();
        },
        onError: () => {
          toast({ title: 'Error', description: 'Failed to create session.', variant: 'destructive' });
        },
      },
    );
  }

  function handleClose() {
    setWarehouseId('');
    setPlanningDate(today);
    setOperatorId('');
    setSupervisorId('');
    setNotes('');
    onClose();
  }

  return (
    <Dialog open={open} onOpenChange={(v) => !v && handleClose()}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>New Preparation Session</DialogTitle>
        </DialogHeader>

        <div className="space-y-4 py-2">
          {/* Warehouse */}
          <div className="space-y-1.5">
            <Label>Warehouse <span className="text-destructive">*</span></Label>
            <Select value={warehouseId} onValueChange={setWarehouseId}>
              <SelectTrigger>
                <SelectValue placeholder="Select warehouse…" />
              </SelectTrigger>
              <SelectContent>
                {warehouseOptions.map((w) => (
                  <SelectItem key={w.value} value={w.value}>{w.label}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          {/* Planning date */}
          <div className="space-y-1.5">
            <Label>Planning Date <span className="text-destructive">*</span></Label>
            <Input
              type="date"
              value={planningDate}
              onChange={(e) => setPlanningDate(e.target.value)}
            />
          </div>

          {/* Operator ID */}
          <div className="space-y-1.5">
            <Label>Operator ID <span className="text-destructive">*</span></Label>
            <Input
              placeholder="Operator user ID…"
              value={operatorId}
              onChange={(e) => setOperatorId(e.target.value)}
            />
          </div>

          {/* Supervisor ID (optional) */}
          <div className="space-y-1.5">
            <Label>Supervisor ID <span className="text-muted-foreground text-xs">(optional)</span></Label>
            <Input
              placeholder="Supervisor user ID…"
              value={supervisorId}
              onChange={(e) => setSupervisorId(e.target.value)}
            />
          </div>

          {/* Notes */}
          <div className="space-y-1.5">
            <Label>Notes <span className="text-muted-foreground text-xs">(optional)</span></Label>
            <Textarea
              placeholder="Any notes for this session…"
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              rows={2}
            />
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={handleClose} disabled={isPending}>
            Cancel
          </Button>
          <Button onClick={handleSubmit} disabled={isPending || !warehouseId || !planningDate || !operatorId}>
            {isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
            Create Session
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
