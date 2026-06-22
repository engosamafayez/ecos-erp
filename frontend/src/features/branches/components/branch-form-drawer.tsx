import { useEffect, useState } from 'react';
import axios from 'axios';
import { zodResolver } from '@hookform/resolvers/zod';
import { useForm } from 'react-hook-form';

import { EntityDrawer, EntityForm } from '@/components/crud';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { BranchFormFields } from '@/features/branches/components/branch-form';
import {
  branchSchema,
  toFormValues,
  toPayload,
  type BranchFormValues,
} from '@/features/branches/components/branch-form-schema';
import { useCreateBranch, useUpdateBranch } from '@/features/branches/hooks/use-branches';
import type { Branch } from '@/features/branches/types/branch';

const FORM_ID = 'branch-form';

type BranchFormDrawerProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** When provided the drawer edits; otherwise it creates. */
  branch?: Branch | null;
};

function extractMessage(error: unknown): string {
  return axios.isAxiosError(error) && typeof error.response?.data?.message === 'string'
    ? error.response.data.message
    : 'Something went wrong. Please try again.';
}

/**
 * Create / edit branch slide-over, built on the shared EntityDrawer + EntityForm.
 */
export function BranchFormDrawer({ open, onOpenChange, branch }: BranchFormDrawerProps) {
  const isEdit = Boolean(branch);
  const createBranch = useCreateBranch();
  const updateBranch = useUpdateBranch();
  const [serverError, setServerError] = useState<string | null>(null);

  const form = useForm<BranchFormValues>({
    resolver: zodResolver(branchSchema),
    defaultValues: toFormValues(branch),
  });

  useEffect(() => {
    if (open) {
      form.reset(toFormValues(branch));
    }
  }, [open, branch, form]);

  const isPending = createBranch.isPending || updateBranch.isPending;

  const handleOpenChange = (next: boolean) => {
    if (!next) {
      setServerError(null);
    }
    onOpenChange(next);
  };

  const handleSubmit = (values: BranchFormValues) => {
    setServerError(null);
    const payload = toPayload(values);
    const handlers = {
      onSuccess: () => handleOpenChange(false),
      onError: (error: unknown) => setServerError(extractMessage(error)),
    };

    if (isEdit && branch) {
      updateBranch.mutate({ id: branch.id, payload }, handlers);
    } else {
      createBranch.mutate(payload, handlers);
    }
  };

  return (
    <EntityDrawer
      open={open}
      onOpenChange={handleOpenChange}
      title={isEdit ? 'Edit Branch' : 'Create Branch'}
      description={isEdit ? 'Update the branch details below.' : 'Add a new branch to a company.'}
      footer={
        <>
          <Button type="button" variant="outline" onClick={() => handleOpenChange(false)}>
            Cancel
          </Button>
          <Button type="submit" form={FORM_ID} disabled={isPending}>
            {isPending ? 'Saving…' : isEdit ? 'Save changes' : 'Create branch'}
          </Button>
        </>
      }
    >
      {serverError ? (
        <Alert variant="destructive" className="mb-4">
          <AlertTitle>Unable to save</AlertTitle>
          <AlertDescription>{serverError}</AlertDescription>
        </Alert>
      ) : null}

      <EntityForm form={form} id={FORM_ID} onSubmit={handleSubmit}>
        <BranchFormFields />
      </EntityForm>
    </EntityDrawer>
  );
}
