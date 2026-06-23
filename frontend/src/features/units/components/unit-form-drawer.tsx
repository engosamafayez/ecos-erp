import { useEffect, useState } from 'react';
import axios from 'axios';
import { zodResolver } from '@hookform/resolvers/zod';
import { useForm } from 'react-hook-form';

import { EntityDrawer, EntityForm } from '@/components/crud';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { UnitFormFields } from '@/features/units/components/unit-form';
import {
  unitSchema,
  toFormValues,
  toPayload,
  type UnitFormValues,
} from '@/features/units/components/unit-form-schema';
import { useCreateUnit, useUpdateUnit } from '@/features/units/hooks/use-units';
import type { Unit } from '@/features/units/types/unit';

const FORM_ID = 'unit-form';

type UnitFormDrawerProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  unit?: Unit | null;
};

function extractMessage(error: unknown): string {
  return axios.isAxiosError(error) && typeof error.response?.data?.message === 'string'
    ? error.response.data.message
    : 'Something went wrong. Please try again.';
}

/**
 * Create / edit unit slide-over, built on the shared EntityDrawer + EntityForm.
 */
export function UnitFormDrawer({ open, onOpenChange, unit }: UnitFormDrawerProps) {
  const isEdit = Boolean(unit);
  const createUnit = useCreateUnit();
  const updateUnit = useUpdateUnit();
  const [serverError, setServerError] = useState<string | null>(null);

  const form = useForm<UnitFormValues>({
    resolver: zodResolver(unitSchema),
    defaultValues: toFormValues(unit),
  });

  useEffect(() => {
    if (open) {
      form.reset(toFormValues(unit));
    }
  }, [open, unit, form]);

  const isPending = createUnit.isPending || updateUnit.isPending;

  const handleOpenChange = (next: boolean) => {
    if (!next) {
      setServerError(null);
    }
    onOpenChange(next);
  };

  const handleSubmit = (values: UnitFormValues) => {
    setServerError(null);
    const payload = toPayload(values);
    const handlers = {
      onSuccess: () => handleOpenChange(false),
      onError: (error: unknown) => setServerError(extractMessage(error)),
    };

    if (isEdit && unit) {
      updateUnit.mutate({ id: unit.id, payload }, handlers);
    } else {
      createUnit.mutate(payload, handlers);
    }
  };

  return (
    <EntityDrawer
      open={open}
      onOpenChange={handleOpenChange}
      title={isEdit ? 'Edit Unit' : 'Create Unit'}
      description={isEdit ? 'Update the unit details below.' : 'Add a new unit of measure.'}
      footer={
        <>
          <Button type="button" variant="outline" onClick={() => handleOpenChange(false)}>
            Cancel
          </Button>
          <Button type="submit" form={FORM_ID} disabled={isPending}>
            {isPending ? 'Saving…' : isEdit ? 'Save changes' : 'Create unit'}
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
        <UnitFormFields />
      </EntityForm>
    </EntityDrawer>
  );
}
