import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';

type ConfirmDialogProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  description?: ReactNode;
  confirmLabel?: string;
  cancelLabel?: string;
  onConfirm: () => void;
  loading?: boolean;
  variant?: 'default' | 'destructive';
  /** Disables the confirm button without affecting Cancel — e.g. a mandatory reason field left blank. */
  confirmDisabled?: boolean;
};

/**
 * Reusable confirmation dialog (e.g. for destructive actions).
 */
export function ConfirmDialog({
  open,
  onOpenChange,
  title,
  description,
  confirmLabel,
  cancelLabel,
  onConfirm,
  loading = false,
  variant = 'default',
  confirmDisabled = false,
}: ConfirmDialogProps) {
  const { t } = useTranslation('common');

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          {description ? <DialogDescription>{description}</DialogDescription> : null}
        </DialogHeader>
        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={loading}
          >
            {cancelLabel ?? t($ => $.common.cancel)}
          </Button>
          <Button type="button" variant={variant} onClick={onConfirm} disabled={loading || confirmDisabled}>
            {loading ? t($ => $.actions.working) : (confirmLabel ?? t($ => $.common.confirm))}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
