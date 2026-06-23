import { useEffect, useState } from 'react';
import axios from 'axios';
import { zodResolver } from '@hookform/resolvers/zod';
import { useForm } from 'react-hook-form';

import { EntityDrawer, EntityForm } from '@/components/crud';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { CategoryFormFields } from '@/features/categories/components/category-form';
import {
  categorySchema,
  toFormValues,
  toPayload,
  type CategoryFormValues,
} from '@/features/categories/components/category-form-schema';
import { useCreateCategory, useUpdateCategory } from '@/features/categories/hooks/use-categories';
import type { Category } from '@/features/categories/types/category';

const FORM_ID = 'category-form';

type CategoryFormDrawerProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  category?: Category | null;
};

function extractMessage(error: unknown): string {
  return axios.isAxiosError(error) && typeof error.response?.data?.message === 'string'
    ? error.response.data.message
    : 'Something went wrong. Please try again.';
}

/**
 * Create / edit category slide-over, built on the shared EntityDrawer + EntityForm.
 */
export function CategoryFormDrawer({ open, onOpenChange, category }: CategoryFormDrawerProps) {
  const isEdit = Boolean(category);
  const createCategory = useCreateCategory();
  const updateCategory = useUpdateCategory();
  const [serverError, setServerError] = useState<string | null>(null);

  const form = useForm<CategoryFormValues>({
    resolver: zodResolver(categorySchema),
    defaultValues: toFormValues(category),
  });

  useEffect(() => {
    if (open) {
      form.reset(toFormValues(category));
    }
  }, [open, category, form]);

  const isPending = createCategory.isPending || updateCategory.isPending;

  const handleOpenChange = (next: boolean) => {
    if (!next) {
      setServerError(null);
    }
    onOpenChange(next);
  };

  const handleSubmit = (values: CategoryFormValues) => {
    setServerError(null);
    const payload = toPayload(values);
    const handlers = {
      onSuccess: () => handleOpenChange(false),
      onError: (error: unknown) => setServerError(extractMessage(error)),
    };

    if (isEdit && category) {
      updateCategory.mutate({ id: category.id, payload }, handlers);
    } else {
      createCategory.mutate(payload, handlers);
    }
  };

  return (
    <EntityDrawer
      open={open}
      onOpenChange={handleOpenChange}
      title={isEdit ? 'Edit Category' : 'Create Category'}
      description={
        isEdit ? 'Update the category details below.' : 'Add a new category (max 3 levels deep).'
      }
      footer={
        <>
          <Button type="button" variant="outline" onClick={() => handleOpenChange(false)}>
            Cancel
          </Button>
          <Button type="submit" form={FORM_ID} disabled={isPending}>
            {isPending ? 'Saving…' : isEdit ? 'Save changes' : 'Create category'}
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
        <CategoryFormFields currentId={category?.id} />
      </EntityForm>
    </EntityDrawer>
  );
}
