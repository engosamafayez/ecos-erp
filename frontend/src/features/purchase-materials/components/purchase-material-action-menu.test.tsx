/**
 * TASK-ECOS-PROCUREMENT-PURCHASE-REQUESTS-AND-HUB-FINAL-REMEDIATION-011 §6.
 *
 * The action menu must render EXACTLY the actions the backend's own available_actions list
 * says are valid right now — never more (a button that would 422), never fewer (a valid action
 * the operator can't reach without opening the drawer). select_supplier is the one token that
 * never becomes a menu item here (it needs a supplier picker, so it stays a drawer-only flow).
 */

import '@testing-library/jest-dom/vitest';
import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import enPm from '@/i18n/locales/en/purchase-materials.json';

const {
  submitMutate, approveMutate, rejectMutate, holdMutate, resumeMutate, cancelMutate,
} = vi.hoisted(() => ({
  submitMutate: vi.fn().mockResolvedValue(undefined),
  approveMutate: vi.fn().mockResolvedValue(undefined),
  rejectMutate: vi.fn().mockResolvedValue(undefined),
  holdMutate: vi.fn().mockResolvedValue(undefined),
  resumeMutate: vi.fn().mockResolvedValue(undefined),
  cancelMutate: vi.fn().mockResolvedValue(undefined),
}));

// PurchaseMaterialStatusBadge (rendered inside the menu trigger) uses the string-path form
// (t(`common.status.${status}`)); everything else in this component tree uses the selector
// form (t($ => $.foo.bar)). Support both so both call styles resolve against the real bundle.
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (selectorOrKey: ((b: unknown) => string) | string) =>
      typeof selectorOrKey === 'function'
        ? selectorOrKey(enPm)
        : selectorOrKey.split('.').reduce<unknown>((acc, key) => (acc as Record<string, unknown>)?.[key], enPm),
  }),
}));

vi.mock('@/components/ds/use-toast', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}));

vi.mock('../hooks/use-purchase-materials', () => ({
  useSubmitPurchaseMaterial: () => ({ mutateAsync: submitMutate, isPending: false }),
  useApprovePurchaseMaterial: () => ({ mutateAsync: approveMutate, isPending: false }),
  useRejectPurchaseMaterial: () => ({ mutateAsync: rejectMutate, isPending: false }),
  useHoldPurchaseMaterial: () => ({ mutateAsync: holdMutate, isPending: false }),
  useResumePurchaseMaterial: () => ({ mutateAsync: resumeMutate, isPending: false }),
  useCancelPurchaseMaterial: () => ({ mutateAsync: cancelMutate, isPending: false }),
}));

import { PurchaseMaterialActionMenu } from './purchase-material-action-menu';
import type { PurchaseMaterial, PurchaseMaterialAction } from '../types/purchase-material';

function material(status: string, actions: PurchaseMaterialAction[]): PurchaseMaterial {
  return {
    id: 'pm-1',
    status,
    status_label: status,
    available_actions: actions,
  } as PurchaseMaterial;
}

describe('PurchaseMaterialActionMenu', () => {
  it('renders only the status badge, with no menu trigger, when no actions are available', () => {
    render(<PurchaseMaterialActionMenu material={material('completed', [])} />);

    expect(screen.getByText(enPm.common.status.completed)).toBeInTheDocument();
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
  });

  it('lists exactly the tokens from available_actions, excluding select_supplier', async () => {
    const user = userEvent.setup();
    render(
      <PurchaseMaterialActionMenu
        material={material('approved', ['select_supplier', 'hold', 'cancel'])}
      />,
    );

    await user.click(screen.getByRole('button'));

    expect(screen.getByText(enPm.purchasesPage.actionMenu.actions.hold)).toBeInTheDocument();
    expect(screen.getByText(enPm.purchasesPage.actionMenu.actions.cancel)).toBeInTheDocument();
    expect(screen.queryByText(enPm.purchasesPage.actionMenu.actions.select_supplier)).not.toBeInTheDocument();
  });

  it('firing an action calls exactly that action\'s mutation with the request id', async () => {
    const user = userEvent.setup();
    render(<PurchaseMaterialActionMenu material={material('on_hold', ['resume', 'cancel'])} />);

    await user.click(screen.getByRole('button'));
    await user.click(screen.getByText(enPm.purchasesPage.actionMenu.actions.resume));

    expect(resumeMutate).toHaveBeenCalledWith('pm-1');
    expect(cancelMutate).not.toHaveBeenCalled();
  });

  it('reject fires with no reason from the table (a quick action, not the drawer\'s reasoned flow)', async () => {
    const user = userEvent.setup();
    render(<PurchaseMaterialActionMenu material={material('under_review', ['reject'])} />);

    await user.click(screen.getByRole('button'));
    await user.click(screen.getByText(enPm.purchasesPage.actionMenu.actions.reject));

    expect(rejectMutate).toHaveBeenCalledWith({ id: 'pm-1' });
  });
});
