import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import axios from 'axios';

import { EntityDrawer } from '@/components/crud/entity-drawer';
import { Button } from '@/components/ui/button';
import { toast } from '@/components/ds/use-toast';
import { useUpdateSupplier } from '@/features/suppliers/hooks/use-suppliers';
import { SupplierRawMaterialsSelect } from '@/features/suppliers/components/supplier-raw-materials-select';
import { SupplierProductCategoriesSelect } from '@/features/suppliers/components/supplier-product-categories-select';
import type { Supplier } from '@/features/suppliers/types/supplier';

function extractMessage(error: unknown): string {
  return axios.isAxiosError(error) && typeof error.response?.data?.message === 'string'
    ? error.response.data.message
    : 'Something went wrong. Please try again.';
}

type Props = {
  supplier: Supplier | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
};

/**
 * TASK-ECOS-PROCUREMENT-SUPPLIERS-FINAL-USER-REVIEW-REMEDIATION-002 §5.
 *
 * A focused entry point for managing what a supplier can supply, reachable directly from
 * Supplier 360 → Products, instead of only through the full Edit Supplier form. Reuses the
 * EXACT same canonical mutation the Edit Supplier form itself uses
 * (`PUT /suppliers/{id}` → UpdateSupplierAction → SupplierCapabilitySyncService) — no new
 * backend endpoint, no second offerings engine, no duplicated catalogue data. That endpoint
 * takes the full supplier payload rather than a partial patch (`name` etc. are required), so
 * this drawer sends the supplier's OWN current scalar fields back unchanged alongside the two
 * updated capability arrays.
 */
export function ManageSupplierOfferingsDrawer({ supplier, open, onOpenChange }: Props) {
  const { t } = useTranslation('suppliers');
  const update = useUpdateSupplier();

  const [rawMaterialIds, setRawMaterialIds] = useState<string[]>([]);
  const [productCategoryIds, setProductCategoryIds] = useState<string[]>([]);
  const [seededFor, setSeededFor] = useState<string | null>(null);

  // Re-seed local selection whenever a DIFFERENT supplier is opened (adjusted during render,
  // matching the pattern the parent drawer already uses for its own tab-reset, so opening the
  // drawer for supplier B never shows supplier A's still-selected offerings for a frame).
  if (supplier && seededFor !== supplier.id) {
    setRawMaterialIds((supplier.raw_materials ?? []).map((m) => m.id));
    setProductCategoryIds((supplier.product_categories ?? []).map((c) => c.id));
    setSeededFor(supplier.id);
  }

  if (!supplier) return null;

  function handleSave() {
    if (!supplier) return;

    update.mutate(
      {
        id: supplier.id,
        payload: {
          name: supplier.name,
          // Full-replace write (like the two arrays below) — must send the Supplier's
          // OWN current, unchanged category set back, or this save would silently wipe
          // its categories the same way this exact call once risked wiping Supply
          // Capabilities if they were omitted.
          supplier_category_ids:
            supplier.categories?.map((c) => c.id)
            ?? (supplier.supplier_category_id ? [supplier.supplier_category_id] : []),
          contact_person: supplier.contact_person ?? undefined,
          email: supplier.email ?? undefined,
          phone: supplier.phone ?? undefined,
          mobile: supplier.mobile ?? undefined,
          country: supplier.country ?? undefined,
          state: supplier.state ?? undefined,
          city: supplier.city ?? undefined,
          district: supplier.district ?? undefined,
          address: supplier.address ?? undefined,
          google_maps_url: supplier.google_maps_url ?? undefined,
          notes: supplier.notes ?? undefined,
          is_active: supplier.is_active,
          raw_material_ids: rawMaterialIds,
          product_category_ids: productCategoryIds,
        },
      },
      {
        onSuccess: () => {
          toast.success(t($ => $.drawer360.products.offerings.saved));
          onOpenChange(false);
        },
        onError: (error) => toast.error(extractMessage(error)),
      },
    );
  }

  return (
    <EntityDrawer
      open={open}
      onOpenChange={onOpenChange}
      title={t($ => $.drawer360.products.offerings.manageTitle)}
      description={supplier.name}
      footer={
        <>
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={update.isPending}>
            {t($ => $.drawer360.products.offerings.cancel)}
          </Button>
          <Button onClick={handleSave} disabled={update.isPending}>
            {update.isPending ? t($ => $.drawer360.products.offerings.saving) : t($ => $.drawer360.products.offerings.save)}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-6">
        <p className="text-sm text-muted-foreground">{t($ => $.drawer360.products.offerings.description)}</p>
        <div>
          <label className="text-xs font-medium text-muted-foreground mb-2 block">{t($ => $.capabilities.rawMaterials.label)}</label>
          <SupplierRawMaterialsSelect
            value={rawMaterialIds}
            onChange={setRawMaterialIds}
            preloaded={supplier.raw_materials ?? []}
            disabled={update.isPending}
          />
        </div>
        <div>
          <label className="text-xs font-medium text-muted-foreground mb-2 block">{t($ => $.capabilities.productCategories.label)}</label>
          <SupplierProductCategoriesSelect
            value={productCategoryIds}
            onChange={setProductCategoryIds}
            preloaded={supplier.product_categories ?? []}
            disabled={update.isPending}
          />
        </div>
      </div>
    </EntityDrawer>
  );
}
