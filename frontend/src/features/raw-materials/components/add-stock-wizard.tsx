import { useState } from 'react';
import { Check } from 'lucide-react';

import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useCompany } from '@/features/organization/context/company-context';
import { useAddStock } from '@/features/raw-materials/hooks/use-raw-materials';
import type { RawMaterial } from '@/features/raw-materials/types';
import { useWarehousesQuery } from '@/features/warehouses/hooks/use-warehouses';
import { cn } from '@/lib/utils';

// ─── Step definitions ─────────────────────────────────────────────────────────

const STEP_LABELS = ['Warehouse', 'Quantity', 'Cost & Notes', 'Confirm'] as const;
type Step = 0 | 1 | 2 | 3;

// ─── Props ────────────────────────────────────────────────────────────────────

type Props = {
  material: RawMaterial;
  open:     boolean;
  onOpenChange: (open: boolean) => void;
  onSuccess?: () => void;
};

// ─── Component ────────────────────────────────────────────────────────────────

export function AddStockWizard({ material, open, onOpenChange, onSuccess }: Props) {
  const { currency } = useCompany();
  const [step,        setStep]        = useState<Step>(0);
  const [warehouseId, setWarehouseId] = useState('');
  const [quantity,    setQuantity]    = useState('');
  const [unitCost,    setUnitCost]    = useState('');
  const [notes,       setNotes]       = useState('');

  const addStock = useAddStock();
  const { data: warehousesData } = useWarehousesQuery({ per_page: 200, status: 'active' });
  const warehouses        = warehousesData?.items ?? [];
  const selectedWarehouse = warehouses.find((w) => w.id === warehouseId);

  function reset() {
    setStep(0);
    setWarehouseId('');
    setQuantity('');
    setUnitCost('');
    setNotes('');
    addStock.reset();
  }

  function handleClose() {
    if (addStock.isPending) return;
    reset();
    onOpenChange(false);
  }

  async function handleConfirm() {
    await addStock.mutateAsync({
      product_id:   material.id,
      warehouse_id: warehouseId,
      quantity:     parseFloat(quantity),
      unit_cost:    unitCost ? parseFloat(unitCost) : null,
      notes:        notes.trim() || null,
    });
    onSuccess?.();
    handleClose();
  }

  const canNext: boolean = (() => {
    if (step === 0) return !!warehouseId;
    if (step === 1) return !!quantity && parseFloat(quantity) > 0;
    return true;
  })();

  const unitName = material.unit?.name ?? 'units';
  const currentCost = material.manual_cost;

  return (
    <Dialog open={open} onOpenChange={handleClose}>
      <DialogContent className="sm:max-w-[460px]">
        <DialogHeader>
          <DialogTitle>Add Stock — {material.name}</DialogTitle>
        </DialogHeader>

        {/* ── Step indicator ── */}
        <div className="flex items-center">
          {STEP_LABELS.map((label, i) => (
            <div key={label} className="flex items-center flex-1 min-w-0">
              <div
                className={cn(
                  'size-6 rounded-full flex items-center justify-center text-xs font-semibold shrink-0 transition-colors',
                  i < step
                    ? 'bg-primary text-primary-foreground'
                    : i === step
                      ? 'bg-primary/15 text-primary border-2 border-primary'
                      : 'bg-muted text-muted-foreground',
                )}
              >
                {i < step ? <Check className="size-3.5" /> : i + 1}
              </div>
              {i < STEP_LABELS.length - 1 && (
                <div
                  className={cn(
                    'flex-1 h-px mx-1.5 transition-colors',
                    i < step ? 'bg-primary' : 'bg-border',
                  )}
                />
              )}
            </div>
          ))}
        </div>
        <p className="text-xs text-muted-foreground -mt-1">
          Step {step + 1} of {STEP_LABELS.length} — <span className="font-medium text-foreground">{STEP_LABELS[step]}</span>
        </p>

        {/* ── Step content ── */}
        <div className="min-h-[160px] py-2">

          {/* Step 0: Warehouse */}
          {step === 0 && (
            <div className="space-y-2">
              <Label>Destination Warehouse</Label>
              <Select value={warehouseId} onValueChange={setWarehouseId}>
                <SelectTrigger>
                  <SelectValue placeholder="Choose a warehouse…" />
                </SelectTrigger>
                <SelectContent>
                  {warehouses.length === 0 && (
                    <SelectItem value="_none" disabled>No active warehouses</SelectItem>
                  )}
                  {warehouses.map((w) => (
                    <SelectItem key={w.id} value={w.id}>
                      {w.name}
                      <span className="ml-1 text-muted-foreground text-xs">({w.code})</span>
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          )}

          {/* Step 1: Quantity */}
          {step === 1 && (
            <div className="space-y-2">
              <Label>
                Quantity to Add
                <span className="ml-1 text-muted-foreground font-normal">({unitName})</span>
              </Label>
              <Input
                type="number"
                min="0.001"
                step="0.001"
                value={quantity}
                onChange={(e) => setQuantity(e.target.value)}
                placeholder="0.000"
                autoFocus
              />
              <p className="text-xs text-muted-foreground">
                Warehouse: <span className="font-medium text-foreground">{selectedWarehouse?.name}</span>
              </p>
            </div>
          )}

          {/* Step 2: Cost & Notes */}
          {step === 2 && (
            <div className="space-y-4">
              <div className="space-y-2">
                <Label>
                  Unit Cost ({currency})
                  <span className="ml-1 text-muted-foreground font-normal">— optional</span>
                </Label>
                <Input
                  type="number"
                  min="0"
                  step="0.01"
                  value={unitCost}
                  onChange={(e) => setUnitCost(e.target.value)}
                  placeholder="0.00"
                  autoFocus
                />
                <p className="text-xs text-muted-foreground">
                  {currentCost != null ? (
                    <>Current cost: <span className="font-medium">{currentCost.toFixed(2)} {currency}</span></>
                  ) : (
                    'No cost recorded yet'
                  )}
                  {unitCost && parseFloat(unitCost) > 0 && (
                    <> → will update to <span className="font-medium text-foreground">{parseFloat(unitCost).toFixed(2)} {currency}</span></>
                  )}
                </p>
              </div>
              <div className="space-y-2">
                <Label>
                  Notes
                  <span className="ml-1 text-muted-foreground font-normal">— optional</span>
                </Label>
                <Textarea
                  value={notes}
                  onChange={(e) => setNotes(e.target.value)}
                  placeholder="e.g. Opening balance, manual adjustment…"
                  rows={2}
                />
              </div>
            </div>
          )}

          {/* Step 3: Review */}
          {step === 3 && (
            <div className="rounded-lg border bg-muted/30 p-4 space-y-2.5 text-sm">
              <ReviewRow label="Material"   value={material.name} />
              <ReviewRow label="Warehouse"  value={selectedWarehouse?.name ?? '—'} />
              <ReviewRow
                label="Quantity"
                value={`${parseFloat(quantity || '0').toFixed(3)} ${unitName}`}
              />
              {unitCost && parseFloat(unitCost) > 0 && (
                <ReviewRow label="Unit Cost" value={`${parseFloat(unitCost).toFixed(2)} ${currency}`} />
              )}
              {notes.trim() && (
                <ReviewRow label="Notes" value={notes.trim()} />
              )}
              {addStock.isError && (
                <p className="text-xs text-destructive pt-1">
                  Failed to add stock. Please try again.
                </p>
              )}
            </div>
          )}
        </div>

        {/* ── Footer ── */}
        <DialogFooter className="gap-2 sm:gap-2">
          {step > 0 && (
            <Button
              variant="outline"
              onClick={() => setStep((s) => (s - 1) as Step)}
              disabled={addStock.isPending}
            >
              Back
            </Button>
          )}
          <Button variant="outline" onClick={handleClose} disabled={addStock.isPending}>
            Cancel
          </Button>
          {step < STEP_LABELS.length - 1 ? (
            <Button onClick={() => setStep((s) => (s + 1) as Step)} disabled={!canNext}>
              Next
            </Button>
          ) : (
            <Button onClick={handleConfirm} disabled={addStock.isPending}>
              {addStock.isPending ? 'Adding…' : 'Confirm & Add Stock'}
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// ─── Helper ───────────────────────────────────────────────────────────────────

function ReviewRow({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex justify-between gap-4">
      <span className="text-muted-foreground shrink-0">{label}</span>
      <span className="font-medium text-end break-words">{value}</span>
    </div>
  );
}
