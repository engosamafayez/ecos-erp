import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { useToast } from '@/components/ds/use-toast';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useWarehousesQuery } from '@/features/warehouses/hooks/use-warehouses';

import { useAssignOrderWarehouse } from '../hooks/use-distribution-workspace';
import type { OrderAwaitingGroup } from '../types';

/**
 * Mirrors of the server's own rules, restated so the operator is told before submitting
 * rather than after. The server remains the only authority: a payload that slips past
 * these is still rejected by `min:10` / `max:500` on the endpoint.
 */
const REASON_MIN = 10;
const REASON_MAX = 500;

/**
 * TASK-DISTRIBUTION-WAREHOUSE-ASSIGNMENT-RESOLUTION-001
 *
 * ASSIGN WAREHOUSE — a manual operator decision, made explicit.
 *
 * ┌─ NOTHING HERE CHOOSES THE WAREHOUSE ─────────────────────────────────────┐
 * │ The Select opens with NO pre-selection, and no candidate is ranked,        │
 * │ highlighted or defaulted. The Order's Zone, City, Governorate, Group,      │
 * │ Trip, Driver and Vehicle are all knowable at this point and NONE of them   │
 * │ is consulted: a suggested warehouse is an inferred warehouse the moment    │
 * │ the operator accepts it without reading it.                               │
 * │                                                                           │
 * │ The one path that DOES infer (`assign-warehouse`, which matches a policy)  │
 * │ exists and is deliberately not offered here.                              │
 * └───────────────────────────────────────────────────────────────────────────┘
 *
 * ELIGIBILITY IS THE SERVER'S. Options come from the warehouses endpoint with
 * `status=active`, whose company scope lives in the Warehouse `tenant` global scope and
 * cannot be widened by the caller. The write endpoint then re-checks the target
 * warehouse against the caller's company independently, so a tampered `warehouse_id`
 * fails there too — this list narrows what is offered, it does not authorise anything.
 *
 * REASON IS REQUIRED because the engine writes it into `warehouse_assignment_overrides`
 * alongside who, when, and the previous value. That is the existing audit trail; this
 * dialog adds no second one.
 */
export function AssignWarehouseDialog({
  order,
  activeWarehouseId,
  onClose,
}: {
  /** The exception row being resolved. Null closes the dialog. */
  order: OrderAwaitingGroup | null;
  /** The board's current warehouse filter, used only to warn about visibility. */
  activeWarehouseId: string | null;
  onClose: () => void;
}) {
  const { t } = useTranslation('logistics');
  const { toast } = useToast();

  const [warehouseId, setWarehouseId] = useState('');
  const [reason, setReason] = useState('');

  const assign = useAssignOrderWarehouse();

  // Active + company-scoped, both enforced server-side. Fetched only while open.
  const warehouses = useWarehousesQuery(
    { status: 'active', per_page: 100, sort_by: 'name', sort_dir: 'asc' },
    { enabled: order !== null },
  );

  if (!order) {
    return null;
  }

  const options = warehouses.data?.items ?? [];
  const trimmedReason = reason.trim();
  const reasonTooShort = trimmedReason.length > 0 && trimmedReason.length < REASON_MIN;
  const canSubmit = warehouseId !== '' && trimmedReason.length >= REASON_MIN && !assign.isPending;

  // The server's own message, not a generic one: 403 (permission), 404 (another
  // company's order or warehouse) and 422 (validation) each say something different,
  // and replacing them with one string is how an operator gets stuck.
  const serverMessage = (assign.error as { response?: { data?: { message?: string } } } | null)
    ?.response?.data?.message;

  function close() {
    setWarehouseId('');
    setReason('');
    assign.reset();
    onClose();
  }

  function submit() {
    // Bound before the callbacks: the guard above already proved `order` is present,
    // but a destructured prop re-widens to nullable inside a closure.
    const row = order;

    if (!canSubmit || !row) {
      return;
    }

    assign.mutate(
      { orderId: row.order_id, warehouseId, reason: trimmedReason },
      {
        onSuccess: () => {
          toast({
            title: t(($) => $.distributionWorkspace.assignWarehouse.success, {
              order: row.order_number,
            }),
          });
          close();
        },
      },
    );
  }

  const leavesThisView =
    activeWarehouseId !== null && warehouseId !== '' && warehouseId !== activeWarehouseId;

  return (
    <Dialog open onOpenChange={(next) => (next ? undefined : close())}>
      <DialogContent className="sm:max-w-md" data-testid="assign-warehouse-dialog">
        <DialogHeader>
          <DialogTitle>{t(($) => $.distributionWorkspace.assignWarehouse.title)}</DialogTitle>
          <DialogDescription>
            {t(($) => $.distributionWorkspace.assignWarehouse.description, {
              order: order.order_number,
            })}
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <div className="space-y-1.5">
            <Label htmlFor="assign-warehouse-select">
              {t(($) => $.distributionWorkspace.assignWarehouse.warehouseLabel)}
            </Label>
            <Select value={warehouseId} onValueChange={setWarehouseId}>
              <SelectTrigger id="assign-warehouse-select" data-testid="assign-warehouse-select">
                {/* No pre-selection: the placeholder is the initial state by design. */}
                <SelectValue
                  placeholder={t(
                    ($) => $.distributionWorkspace.assignWarehouse.warehousePlaceholder,
                  )}
                />
              </SelectTrigger>
              <SelectContent>
                {options.map((warehouse) => (
                  <SelectItem key={warehouse.id} value={warehouse.id}>
                    {warehouse.code ? `${warehouse.name} · ${warehouse.code}` : warehouse.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>

            {/* An empty list is a configuration answer, not a spinner that never ends. */}
            {!warehouses.isLoading && options.length === 0 ? (
              <p className="text-xs text-destructive" data-testid="assign-warehouse-none">
                {t(($) => $.distributionWorkspace.assignWarehouse.noWarehouses)}
              </p>
            ) : null}
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="assign-warehouse-reason">
              {t(($) => $.distributionWorkspace.assignWarehouse.reasonLabel)}
            </Label>
            <Textarea
              id="assign-warehouse-reason"
              value={reason}
              onChange={(event) => setReason(event.target.value)}
              maxLength={REASON_MAX}
              rows={3}
              placeholder={t(($) => $.distributionWorkspace.assignWarehouse.reasonPlaceholder)}
              data-testid="assign-warehouse-reason"
            />
            <p
              className={
                reasonTooShort ? 'text-xs text-destructive' : 'text-xs text-muted-foreground'
              }
            >
              {t(($) => $.distributionWorkspace.assignWarehouse.reasonHint, { min: REASON_MIN })}
            </p>
          </div>

          {/* Honest about what the operator will see next, rather than letting the row
              silently vanish from a filtered board. */}
          {leavesThisView ? (
            <p
              className="text-xs text-amber-700 dark:text-amber-400"
              data-testid="assign-warehouse-leaves-view"
            >
              {t(($) => $.distributionWorkspace.assignWarehouse.leavesView)}
            </p>
          ) : null}

          {/* This assignment does not group the Order — said plainly, so nobody reads a
              cleared warehouse blocker as "planned". */}
          <p className="text-xs text-muted-foreground">
            {t(($) => $.distributionWorkspace.assignWarehouse.noGroupNote)}
          </p>

          {assign.isError ? (
            <p className="text-sm text-destructive" data-testid="assign-warehouse-error">
              {serverMessage ?? t(($) => $.distributionWorkspace.assignWarehouse.failed)}
            </p>
          ) : null}
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={close} disabled={assign.isPending}>
            {t(($) => $.common.cancel)}
          </Button>
          <Button onClick={submit} disabled={!canSubmit} data-testid="assign-warehouse-submit">
            {assign.isPending
              ? t(($) => $.distributionWorkspace.assignWarehouse.submitting)
              : t(($) => $.distributionWorkspace.assignWarehouse.submit)}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
