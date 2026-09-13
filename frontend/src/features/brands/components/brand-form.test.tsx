import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import '@testing-library/jest-dom';
import type { ReactNode } from 'react';
import { FormProvider, useForm } from 'react-hook-form';
import { describe, expect, it, vi } from 'vitest';

import { BrandFormFields, toSlug } from './brand-form';
import {
  toCreateFormValues,
  toUpdateFormValues,
  type BrandCreateFormValues,
  type BrandUpdateFormValues,
} from './brand-form-schema';
import type { Brand } from '@/features/brands/types/brand';

// Keep the field chrome out of the way — BrandFormFields only needs FormField to
// render its children, and the Company/Logo controls are irrelevant to slug logic.
vi.mock('@/components/crud', () => ({
  FormField: ({ children }: { children: ReactNode }) => <div>{children}</div>,
}));
vi.mock('@/features/branches/components/company-select', () => ({
  CompanySelect: () => null,
}));
vi.mock('@/components/ui/image-upload-field', () => ({
  ImageUploadField: () => null,
}));

function CreateHarness() {
  const form = useForm<BrandCreateFormValues>({ defaultValues: toCreateFormValues() });
  return (
    <FormProvider {...form}>
      <BrandFormFields mode="create" />
    </FormProvider>
  );
}

function EditHarness({ brand }: { brand: Brand }) {
  const form = useForm<BrandUpdateFormValues>({ defaultValues: toUpdateFormValues(brand) });
  return (
    <FormProvider {...form}>
      <BrandFormFields mode="edit" />
    </FormProvider>
  );
}

const nameInput = () => screen.getByPlaceholderText('e.g. Acme Food Brands') as HTMLInputElement;
const slugInput = () => screen.getByPlaceholderText('acme-food-brands') as HTMLInputElement;

function editableBrand(overrides: Partial<Brand> = {}): Brand {
  return {
    id: 'b1',
    company_id: 'c1',
    code: 'BRD-000001',
    name: 'Existing Brand',
    slug: 'existing-slug',
    logo: null,
    description: null,
    is_active: true,
    company: null,
    channels_count: 0,
    active_channels_count: 0,
    default_target_margin: null,
    default_markup: null,
    default_discount_pct: null,
    created_at: null,
    updated_at: null,
    ...overrides,
  } as unknown as Brand;
}

describe('toSlug', () => {
  it('generates the full slug from a Latin name, not the first character', () => {
    expect(toSlug('Aseel')).toBe('aseel');
    expect(toSlug('Aseel Foods')).toBe('aseel-foods');
  });

  it('produces a non-empty Latin slug for an Arabic name', () => {
    const slug = toSlug('أصيل');
    expect(slug.length).toBeGreaterThan(0);
    expect(slug).toMatch(/^[a-z0-9-]+$/);
  });

  it('returns empty for a name with no slug-able characters', () => {
    expect(toSlug('!!!')).toBe('');
  });
});

describe('BrandFormFields — slug auto-generation (create)', () => {
  it('generates the full slug "aseel" from name "Aseel" (regression: not "a")', async () => {
    const user = userEvent.setup();
    render(<CreateHarness />);

    await user.type(nameInput(), 'Aseel');

    expect(slugInput().value).toBe('aseel');
  });

  it('keeps tracking the full name as more characters are typed', async () => {
    const user = userEvent.setup();
    render(<CreateHarness />);

    await user.type(nameInput(), 'Aseel Foods');

    expect(slugInput().value).toBe('aseel-foods');
  });

  it('does not require the user to touch the slug field', async () => {
    const user = userEvent.setup();
    render(<CreateHarness />);

    await user.type(nameInput(), 'Aseel');

    // The slug field was populated purely from the name — the user never focused it.
    expect(slugInput().value).toBe('aseel');
  });

  it('does not overwrite a manually customised slug when the name changes later', async () => {
    const user = userEvent.setup();
    render(<CreateHarness />);

    await user.type(nameInput(), 'Aseel');
    await user.clear(slugInput());
    await user.type(slugInput(), 'aseel-eg');

    // Continue editing the name — the custom slug must survive.
    await user.type(nameInput(), ' Foods');

    expect(slugInput().value).toBe('aseel-eg');
  });
});

describe('BrandFormFields — slug safety (edit)', () => {
  it('preserves an existing slug even when the brand name changes', async () => {
    const user = userEvent.setup();
    render(<EditHarness brand={editableBrand({ name: 'Existing Brand', slug: 'existing-slug' })} />);

    expect(slugInput().value).toBe('existing-slug');

    await user.type(nameInput(), ' Renamed');

    // Edit mode never regenerates the slug from the name (it may be externally referenced).
    expect(slugInput().value).toBe('existing-slug');
  });
});
