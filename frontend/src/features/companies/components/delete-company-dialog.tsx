import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { useDeleteCompany } from '@/features/companies/hooks/use-companies';
import type { Company } from '@/features/companies/types/company';

type DeleteCompanyDialogProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  company: Company | null;
};

/**
 * Confirmation dialog for (soft) deleting a company.
 */
export function DeleteCompanyDialog({ open, onOpenChange, company }: DeleteCompanyDialogProps) {
  const deleteCompany = useDeleteCompany();

  const handleDelete = () => {
    if (!company) {
      return;
    }
    deleteCompany.mutate(company.id, { onSuccess: () => onOpenChange(false) });
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Delete company</DialogTitle>
          <DialogDescription>
            This will deactivate and remove{' '}
            <span className="text-foreground font-medium">{company?.name}</span> from the list. The
            record is soft-deleted and can be restored later.
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button
            type="button"
            variant="destructive"
            onClick={handleDelete}
            disabled={deleteCompany.isPending}
          >
            {deleteCompany.isPending ? 'Deleting…' : 'Delete'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
