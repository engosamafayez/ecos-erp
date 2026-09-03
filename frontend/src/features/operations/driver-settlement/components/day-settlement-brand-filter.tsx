import { Layers, X } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { useBrandsQuery } from '@/features/brands/hooks/use-brands';
import { useOrganizationContext } from '@/features/organization/context/organization-context';

/** Sentinel for the All-Brands option — Radix Select cannot hold an empty string value. */
const ALL = '__all__';

/**
 * The Day Settlement Brand Statistics control (§10/§11).
 *
 * Collapsed by default so it costs no permanent vertical space; expanding it reveals the canonical
 * Brand picker and the scope note that keeps the numbers honest. While collapsed with a Brand
 * active, a chip keeps the active narrowing visible — a hidden filter must never silently reshape
 * the board.
 *
 * The Brand LIST and Brand IDENTITY come from the canonical Organization → Brands authority
 * ({@see useBrandsQuery}); nothing brand-shaped is modelled locally and there is no free-text
 * matching. The SELECTION, however, is deliberately page-local: the global header Brand context
 * (`activeBrandId`) drives unrelated screens, and this drill-down must not reach out and change
 * them. Only the company scope is read from the organization context, so the list a user sees here
 * is the same list the rest of the application shows.
 */
export function DaySettlementBrandFilter({
  brandId,
  onBrandChange,
  open,
  onOpenChange,
}: {
  brandId: string | null;
  onBrandChange: (brandId: string | null) => void;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const { t } = useTranslation('logistics');
  const { activeCompanyId } = useOrganizationContext();

  // Only fetched once the operator actually opens the control, or while a Brand is already active
  // (so the chip can name it). No brand request is made for the default All-Brands board.
  const { data, isLoading } = useBrandsQuery(
    { per_page: 100, company_id: activeCompanyId ?? undefined },
    { enabled: open || brandId !== null },
  );
  const brands = data?.items ?? [];
  const activeBrand = brandId !== null ? (brands.find((b) => b.id === brandId) ?? null) : null;

  return (
    <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-2">
      <Button
        type="button"
        variant={open ? 'secondary' : 'outline'}
        size="sm"
        className="h-8 w-full justify-start gap-1.5 text-xs sm:w-auto"
        aria-expanded={open}
        onClick={() => onOpenChange(!open)}
      >
        <Layers className="h-3.5 w-3.5" aria-hidden />
        {t(($) => $.driverSettlement.brand.title)}
      </Button>

      {/* Collapsed + active → the narrowing stays visible and one click clears it. */}
      {!open && brandId !== null ? (
        <span className="inline-flex h-8 w-full items-center gap-1 rounded-md border border-primary/30 bg-primary/5 px-2 text-[11px] font-medium text-primary sm:w-auto">
          <span className="truncate">{activeBrand?.name ?? t(($) => $.driverSettlement.brand.selected)}</span>
          <button
            type="button"
            className="shrink-0 rounded p-0.5 hover:bg-primary/10"
            aria-label={t(($) => $.driverSettlement.brand.all)}
            onClick={() => onBrandChange(null)}
          >
            <X className="h-3 w-3" aria-hidden />
          </button>
        </span>
      ) : null}

      {open ? (
        <>
          <Select
            value={brandId ?? ALL}
            onValueChange={(v) => onBrandChange(v === ALL ? null : v)}
          >
            <SelectTrigger className="h-8 w-full text-xs sm:w-52" aria-label={t(($) => $.driverSettlement.brand.label)}>
              <SelectValue placeholder={t(($) => $.driverSettlement.brand.all)} />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={ALL} className="text-xs">
                {t(($) => $.driverSettlement.brand.all)}
              </SelectItem>
              {isLoading ? (
                <div className="px-2 py-1.5 text-xs text-muted-foreground">
                  {t(($) => $.driverSettlement.brand.loading)}
                </div>
              ) : null}
              {!isLoading && brands.length === 0 ? (
                <div className="px-2 py-1.5 text-xs text-muted-foreground">
                  {t(($) => $.driverSettlement.brand.empty)}
                </div>
              ) : null}
              {brands.map((b) => (
                <SelectItem key={b.id} value={b.id} className="text-xs">
                  {b.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>

          {/* What the selected Brand does and does NOT change — stated, never implied (§14/§15). */}
          <p className="max-w-prose text-[10px] leading-snug text-muted-foreground">
            {brandId === null
              ? t(($) => $.driverSettlement.brand.allNote)
              : t(($) => $.driverSettlement.brand.scopeNote)}
          </p>
        </>
      ) : null}
    </div>
  );
}
