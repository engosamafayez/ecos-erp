import { useEffect, useState } from 'react';
import axios from 'axios';
import { zodResolver } from '@hookform/resolvers/zod';
import { type Resolver, useForm } from 'react-hook-form';

import { EntityDrawer, EntityForm } from '@/components/crud';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { useCreateBrand, useUpdateBrand } from '@/features/brands/hooks/use-brands';
import type { Brand } from '@/features/brands/types/brand';
import { uploadOrgImage } from '@/lib/media-upload';
import { BrandFormFields } from './brand-form';
import {
  brandCreateSchema,
  brandUpdateSchema,
  toCreateFormValues,
  toUpdateFormValues,
  toCreatePayload,
  toUpdatePayload,
  type BrandCreateFormValues,
  type BrandUpdateFormValues,
} from './brand-form-schema';

const FORM_ID = 'brand-form';

type BrandFormDrawerProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  brand?: Brand | null;
};

function extractMessage(error: unknown): string {
  return axios.isAxiosError(error) && typeof error.response?.data?.message === 'string'
    ? error.response.data.message
    : 'Something went wrong. Please try again.';
}

export function BrandFormDrawer({ open, onOpenChange, brand }: BrandFormDrawerProps) {
  const isEdit = Boolean(brand);
  const createBrand = useCreateBrand();
  const updateBrand = useUpdateBrand();
  const [serverError, setServerError] = useState<string | null>(null);
  const [imageFile, setImageFile] = useState<File | null>(null);

  const createForm = useForm<BrandCreateFormValues>({
    resolver: zodResolver(brandCreateSchema) as unknown as Resolver<BrandCreateFormValues>,
    defaultValues: toCreateFormValues(),
  });

  const updateForm = useForm<BrandUpdateFormValues>({
    resolver: zodResolver(brandUpdateSchema) as unknown as Resolver<BrandUpdateFormValues>,
    defaultValues: brand ? toUpdateFormValues(brand) : { name: '', is_active: true },
  });

  const isPending = createBrand.isPending || updateBrand.isPending;

  useEffect(() => {
    if (open) {
      setImageFile(null);
      setServerError(null);
      if (isEdit && brand) {
        updateForm.reset(toUpdateFormValues(brand));
      } else {
        createForm.reset(toCreateFormValues());
      }
    }
  }, [open, brand, isEdit]);

  const handleOpenChange = (next: boolean) => {
    if (!next) setServerError(null);
    onOpenChange(next);
  };

  async function resolveLogoPath(existingLogo?: string): Promise<string | undefined> {
    if (!imageFile) return existingLogo ?? undefined;
    try {
      const uploaded = await uploadOrgImage(imageFile, 'brands');
      return uploaded.path;
    } catch {
      setServerError('Failed to upload image. Please try again.');
      return undefined;
    }
  }

  const handlers = {
    onSuccess: () => handleOpenChange(false),
    onError: (error: unknown) => setServerError(extractMessage(error)),
  };

  const header = serverError ? (
    <Alert variant="destructive" className="mb-4">
      <AlertTitle>Error</AlertTitle>
      <AlertDescription>{serverError}</AlertDescription>
    </Alert>
  ) : null;

  const footer = (
    <>
      <Button type="button" variant="outline" onClick={() => handleOpenChange(false)}>
        Cancel
      </Button>
      <Button type="submit" form={FORM_ID} disabled={isPending}>
        {isPending ? 'Saving…' : isEdit ? 'Save Changes' : 'Create Brand'}
      </Button>
    </>
  );

  return (
    <EntityDrawer
      open={open}
      onOpenChange={handleOpenChange}
      title={isEdit ? 'Edit Brand' : 'New Brand'}
      description={isEdit ? 'Update brand details.' : 'Create a new brand for your organization.'}
      footer={footer}
    >
      {header}
      {isEdit ? (
        <EntityForm
          form={updateForm}
          id={FORM_ID}
          onSubmit={async (values) => {
            setServerError(null);
            const logoPath = await resolveLogoPath(brand?.logo ?? undefined);
            if (serverError) return;
            const payload = toUpdatePayload(values);
            payload.logo = logoPath;
            if (brand) updateBrand.mutate({ id: brand.id, payload }, handlers);
          }}
        >
          <BrandFormFields
            mode="edit"
            existingLogoUrl={brand?.logo ?? null}
            onImageChange={setImageFile}
          />
        </EntityForm>
      ) : (
        <EntityForm
          form={createForm}
          id={FORM_ID}
          onSubmit={async (values) => {
            setServerError(null);
            const logoPath = await resolveLogoPath();
            if (serverError) return;
            const payload = toCreatePayload(values);
            payload.logo = logoPath;
            createBrand.mutate(payload, handlers);
          }}
        >
          <BrandFormFields
            mode="create"
            existingLogoUrl={null}
            onImageChange={setImageFile}
          />
        </EntityForm>
      )}
    </EntityDrawer>
  );
}
