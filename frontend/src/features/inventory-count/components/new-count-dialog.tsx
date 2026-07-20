import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Loader2 } from 'lucide-react';

import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { CompanySelect } from '@/features/branches/components/company-select';
import { warehousesService } from '@/features/warehouses/services/warehouses-service';
import { toast } from '@/components/ds/use-toast';
import { useCreateCountSession } from '../hooks/use-inventory-count';

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
};

export function NewCountDialog({ open, onOpenChange }: Props) {
  const [companyId, setCompanyId] = useState('');
  const [warehouseId, setWarehouseId] = useState('');
  const [notes, setNotes] = useState('');
  const create = useCreateCountSession();

  const { data: warehousesData, isLoading: wLoading } = useQuery({
    queryKey: ['warehouses-for-count', companyId],
    queryFn: () => warehousesService.list({ company_id: companyId, status: 'active', per_page: 100 }),
    enabled: !!companyId,
    staleTime: 60_000,
  });

  const warehouses = warehousesData?.items ?? [];

  function handleClose() {
    setCompanyId('');
    setWarehouseId('');
    setNotes('');
    onOpenChange(false);
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!companyId || !warehouseId) return;
    try {
      await create.mutateAsync({ company_id: companyId, warehouse_id: warehouseId, notes: notes || undefined });
      toast.success('Count session created.');
      handleClose();
    } catch {
      toast.error('Failed to create count session.');
    }
  }

  return (
    <Dialog open={open} onOpenChange={handleClose}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>New Count Session</DialogTitle>
          <DialogDescription>
            Create a new physical inventory count session. All active products in the selected warehouse will be included.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={(e) => { void handleSubmit(e); }} className="flex flex-col gap-4 py-2">
          <div className="flex flex-col gap-1.5">
            <label className="text-sm font-medium">Company <span className="text-destructive">*</span></label>
            <CompanySelect value={companyId || null} onChange={(v) => { setCompanyId(v ?? ''); setWarehouseId(''); }} />
          </div>

          <div className="flex flex-col gap-1.5">
            <label className="text-sm font-medium">Warehouse <span className="text-destructive">*</span></label>
            {!companyId ? (
              <p className="text-xs text-muted-foreground italic">Select a company first.</p>
            ) : wLoading ? (
              <div className="flex items-center gap-2 text-xs text-muted-foreground">
                <Loader2 className="size-3.5 animate-spin" /> Loading warehouses…
              </div>
            ) : warehouses.length === 0 ? (
              <p className="text-xs text-muted-foreground italic">No active warehouses for this company.</p>
            ) : (
              <select
                value={warehouseId}
                onChange={(e) => setWarehouseId(e.target.value)}
                className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                required
              >
                <option value="">Select warehouse…</option>
                {warehouses.map((w) => (
                  <option key={w.id} value={w.id}>{w.name}</option>
                ))}
              </select>
            )}
          </div>

          <div className="flex flex-col gap-1.5">
            <label className="text-sm font-medium">Notes</label>
            <textarea
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              rows={2}
              placeholder="Optional notes for this session…"
              className="rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs resize-none focus:outline-none focus:ring-1 focus:ring-ring"
            />
          </div>

          <DialogFooter className="pt-2">
            <Button type="button" variant="outline" onClick={handleClose}>Cancel</Button>
            <Button type="submit" disabled={!companyId || !warehouseId || create.isPending}>
              {create.isPending ? <Loader2 className="size-3.5 animate-spin mr-1.5" /> : null}
              Create Session
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
