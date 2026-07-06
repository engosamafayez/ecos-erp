import { useState } from 'react';
import { Check, Loader2, Search, X } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useToastStore } from '@/components/ds/use-toast';
import { useWarehouseOptions } from '@/features/products/hooks/use-warehouse-options';
import { useOrdersQuery } from '@/features/orders/hooks/use-orders';
import { useCreateWave } from '../hooks/use-preparation';

type Props = {
  open: boolean;
  onClose: () => void;
  onCreated: () => void;
};

export function CreateWaveDialog({ open, onClose, onCreated }: Props) {
  const toast = useToastStore((s) => s.toast);
  const today = new Date().toISOString().split('T')[0];

  const [warehouseId,   setWarehouseId]   = useState('');
  const [planningDate,  setPlanningDate]  = useState(today);
  const [selectedIds,   setSelectedIds]   = useState<Set<string>>(new Set());
  const [orderSearch,   setOrderSearch]   = useState('');
  const [notes,         setNotes]         = useState('');

  const { data: warehouseOptions = [] } = useWarehouseOptions();

  const { data: ordersData, isLoading: ordersLoading } = useOrdersQuery({
    status:   'confirmed',
    per_page: 100,
    search:   orderSearch || undefined,
  });
  const orders = ordersData?.items ?? [];

  const { mutate: createWave, isPending } = useCreateWave();

  function toggleOrder(id: string) {
    setSelectedIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }

  function reset() {
    setWarehouseId('');
    setPlanningDate(today);
    setSelectedIds(new Set());
    setOrderSearch('');
    setNotes('');
  }

  function handleClose() {
    reset();
    onClose();
  }

  function handleSubmit() {
    if (!warehouseId) {
      toast({ type: 'error', title: 'Select a warehouse' });
      return;
    }
    if (selectedIds.size === 0) {
      toast({ type: 'error', title: 'Select at least one order' });
      return;
    }

    createWave(
      {
        warehouse_id:  warehouseId,
        planning_date: planningDate,
        order_ids:     Array.from(selectedIds),
        notes:         notes || undefined,
      },
      {
        onSuccess: () => {
          toast({ type: 'success', title: 'Wave created', description: 'New preparation wave has been created.' });
          reset();
          onCreated();
        },
        onError: (err: unknown) => {
          const msg = err instanceof Error ? err.message : 'Failed to create wave';
          toast({ type: 'error', title: 'Error', description: msg });
        },
      },
    );
  }

  return (
    <Dialog open={open} onOpenChange={(v) => { if (!v) handleClose(); }}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>New Preparation Wave</DialogTitle>
        </DialogHeader>

        <div className="space-y-4 py-2">
          {/* Warehouse */}
          <div className="space-y-1.5">
            <Label className="text-sm">Warehouse *</Label>
            <Select value={warehouseId} onValueChange={setWarehouseId}>
              <SelectTrigger className="h-9 text-sm">
                <SelectValue placeholder="Select warehouse…" />
              </SelectTrigger>
              <SelectContent>
                {warehouseOptions.map((w: { value: string; label: string }) => (
                  <SelectItem key={w.value} value={w.value}>{w.label}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          {/* Planning date */}
          <div className="space-y-1.5">
            <Label className="text-sm">Planning Date *</Label>
            <Input
              type="date"
              value={planningDate}
              min={today}
              onChange={(e) => setPlanningDate(e.target.value)}
              className="h-9 text-sm"
            />
          </div>

          {/* Order selection */}
          <div className="space-y-1.5">
            <div className="flex items-center justify-between">
              <Label className="text-sm">Orders * <span className="text-gray-400 font-normal">(confirmed only)</span></Label>
              {selectedIds.size > 0 && (
                <span className="text-xs text-blue-600 font-medium">{selectedIds.size} selected</span>
              )}
            </div>
            <div className="relative">
              <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-400" />
              <Input
                value={orderSearch}
                onChange={(e) => setOrderSearch(e.target.value)}
                placeholder="Search by order number…"
                className="pl-8 h-8 text-sm"
              />
            </div>
            <div className="border rounded-md overflow-y-auto max-h-48">
              {ordersLoading ? (
                <div className="flex items-center justify-center py-6">
                  <Loader2 className="w-4 h-4 animate-spin text-gray-400" />
                </div>
              ) : orders.length === 0 ? (
                <p className="text-sm text-gray-400 text-center py-6">No confirmed orders found.</p>
              ) : (
                orders.map((order) => {
                  const selected = selectedIds.has(order.id);
                  return (
                    <button
                      key={order.id}
                      type="button"
                      className={`w-full flex items-center gap-3 px-3 py-2.5 border-b last:border-0 text-left hover:bg-gray-50 transition-colors ${selected ? 'bg-blue-50' : ''}`}
                      onClick={() => toggleOrder(order.id)}
                    >
                      <div className={`w-4 h-4 rounded border flex-shrink-0 flex items-center justify-center ${selected ? 'bg-blue-600 border-blue-600' : 'border-gray-300'}`}>
                        {selected && <Check className="w-2.5 h-2.5 text-white" />}
                      </div>
                      <div className="flex-1 min-w-0">
                        <p className="text-sm font-medium text-gray-800 truncate">{order.order_number}</p>
                        <p className="text-xs text-gray-400 truncate">
                          {order.customer?.name ?? order.billing_first_name ?? '—'} · {order.total != null ? `${order.total}` : ''}
                        </p>
                      </div>
                    </button>
                  );
                })
              )}
            </div>
          </div>

          {/* Notes */}
          <div className="space-y-1.5">
            <Label className="text-sm">Notes <span className="text-gray-400 font-normal">(optional)</span></Label>
            <Textarea
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              placeholder="Add any notes for this wave…"
              className="text-sm resize-none h-16"
              maxLength={1000}
            />
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" size="sm" onClick={handleClose} disabled={isPending}>
            <X className="w-3.5 h-3.5 mr-1.5" />
            Cancel
          </Button>
          <Button size="sm" onClick={handleSubmit} disabled={isPending || !warehouseId || selectedIds.size === 0}>
            {isPending ? <Loader2 className="w-3.5 h-3.5 animate-spin mr-1.5" /> : null}
            Create Wave
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
