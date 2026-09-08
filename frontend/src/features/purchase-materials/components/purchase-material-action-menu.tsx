import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ChevronDown, Loader2 } from 'lucide-react';

import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { toast } from '@/components/ds/use-toast';

import {
  useApprovePurchaseMaterial,
  useCancelPurchaseMaterial,
  useHoldPurchaseMaterial,
  useRejectPurchaseMaterial,
  useResumePurchaseMaterial,
  useSubmitPurchaseMaterial,
} from '../hooks/use-purchase-materials';
import type { PurchaseMaterial, PurchaseMaterialAction } from '../types/purchase-material';
import { PurchaseMaterialStatusBadge } from './purchase-material-status-badge';

/**
 * TASK-...-011 §6: status must be actionable directly from the table, not only from the detail
 * drawer. Renders the current status badge plus a menu built ONLY from the backend's own
 * `available_actions` — the same list PurchaseMaterialStatus::availableActions() computes for
 * the drawer's action bar, so table and drawer can never offer different actions for the same
 * status. Reject fires with no reason from here (a quick table action); adding a reason still
 * goes through the drawer's Reject flow.
 */
export function PurchaseMaterialActionMenu({ material }: { material: PurchaseMaterial }) {
  const { t } = useTranslation('purchase-materials');
  const [open, setOpen] = useState(false);

  const submitMutation = useSubmitPurchaseMaterial();
  const approveMutation = useApprovePurchaseMaterial();
  const rejectMutation = useRejectPurchaseMaterial();
  const holdMutation = useHoldPurchaseMaterial();
  const resumeMutation = useResumePurchaseMaterial();
  const cancelMutation = useCancelPurchaseMaterial();

  const isBusy =
    submitMutation.isPending ||
    approveMutation.isPending ||
    rejectMutation.isPending ||
    holdMutation.isPending ||
    resumeMutation.isPending ||
    cancelMutation.isPending;

  const MUTATIONS: Partial<Record<PurchaseMaterialAction, () => Promise<unknown>>> = {
    submit: () => submitMutation.mutateAsync(material.id),
    approve: () => approveMutation.mutateAsync(material.id),
    reject: () => rejectMutation.mutateAsync({ id: material.id }),
    hold: () => holdMutation.mutateAsync(material.id),
    resume: () => resumeMutation.mutateAsync(material.id),
    cancel: () => cancelMutation.mutateAsync(material.id),
  };

  async function run(action: PurchaseMaterialAction) {
    const mutate = MUTATIONS[action];
    if (!mutate) return;
    try {
      await mutate();
      toast.success(t($ => $.purchasesPage.actionMenu.toastSuccess, { action: t($ => $.purchasesPage.actionMenu.actions[action]) }));
    } catch {
      toast.error(t($ => $.purchasesPage.actionMenu.toastFailed));
    }
  }

  // select_supplier has no table-row action — it needs a supplier picker, so it stays a
  // drawer-only flow (Supplier tab).
  const actionableTokens = material.available_actions.filter((a) => a !== 'select_supplier');

  if (actionableTokens.length === 0) {
    return <PurchaseMaterialStatusBadge status={material.status} />;
  }

  return (
    <DropdownMenu open={open} onOpenChange={setOpen}>
      <DropdownMenuTrigger asChild>
        <button
          type="button"
          onClick={(e) => e.stopPropagation()}
          disabled={isBusy}
          className="inline-flex items-center gap-1 rounded-full hover:opacity-80 transition-opacity disabled:opacity-50"
        >
          <PurchaseMaterialStatusBadge status={material.status} />
          {isBusy ? <Loader2 className="size-3 animate-spin text-muted-foreground" /> : <ChevronDown className="size-3 text-muted-foreground" />}
        </button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="start" onClick={(e) => e.stopPropagation()}>
        {actionableTokens.map((action) => (
          <DropdownMenuItem
            key={action}
            disabled={isBusy}
            onClick={() => void run(action)}
            className={action === 'cancel' || action === 'reject' ? 'text-destructive focus:text-destructive' : ''}
          >
            {t($ => $.purchasesPage.actionMenu.actions[action])}
          </DropdownMenuItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
