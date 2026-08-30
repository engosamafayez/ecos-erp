import { useRef } from 'react';
import { useFormContext, useWatch } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { Camera, X } from 'lucide-react';

import { Input } from '@/components/ui/input';
import { useIsMobile } from '@/hooks/use-is-mobile';
import type {
  GoodsReceiptFormValues,
  GrLineFormValues,
} from '@/features/goods-receipts/components/goods-receipt-form-schema';

export type PoLineInfo = {
  id: string;
  productName: string;
  productSku: string;
  unitPrice?: number;
  orderedQty?: number;
};

type LineError = {
  gross_received_quantity?: { message?: string };
  net_received_quantity?: { message?: string };
  notes?: { message?: string };
};

type Register = ReturnType<typeof useFormContext<GoodsReceiptFormValues>>['register'];
type SetValue = ReturnType<typeof useFormContext<GoodsReceiptFormValues>>['setValue'];

function fieldErr(err: LineError | undefined, k: keyof LineError) {
  const e = err?.[k];
  return typeof e?.message === 'string' ? e.message : undefined;
}

function fmt(n: number) {
  if (n === 0) return '0';
  return n % 1 === 0 ? String(n) : n.toFixed(4).replace(/\.?0+$/, '');
}

function varianceClass(variance: number) {
  if (variance < 0) return 'text-amber-600 dark:text-amber-400';
  if (variance > 0) return 'text-green-600 dark:text-green-400';
  return 'text-muted-foreground';
}

/** A line's derived display figures — computed the same way for table and card. */
type LineComputed = {
  line: GrLineFormValues;
  index: number;
  info: PoLineInfo | undefined;
  gross: number;
  net: number;
  ordered: number;
  variance: number;
  errs: LineError | undefined;
};

function computeLine(
  line: GrLineFormValues,
  index: number,
  poLineInfos: PoLineInfo[],
  lineErrors: LineError[] | undefined,
): LineComputed {
  const gross = Number(line.gross_received_quantity ?? 0);
  const net = Number(line.net_received_quantity ?? 0);
  const ordered = line.ordered_quantity;
  return {
    line,
    index,
    info: poLineInfos.find((p) => p.id === line.purchase_order_line_id),
    gross,
    net,
    ordered,
    variance: net - ordered,
    errs: lineErrors?.[index],
  };
}

type Props = {
  readOnly?: boolean;
  poLineInfos?: PoLineInfo[];
};

/**
 * Goods Receipt line editor with dual layout (TASK-ECOS-MOBILE-UX-CORE-CLOSURE-001).
 *
 * Desktop (`md+`): the existing 7-column table, unchanged.
 * Mobile (`< md`): a vertical list of line cards with full-width quantity inputs,
 * validation shown beneath the affected field, and the camera/notes controls in
 * reach — so receiving quantities can be entered on a phone without a
 * pan-and-type horizontal-scroll table.
 *
 * Both layouts bind to the SAME react-hook-form fields (`register`/`setValue`)
 * and the SAME validation — there is no mobile-only form state. A JS breakpoint
 * (`useIsMobile`) chooses one layout so each field registers exactly once (two
 * DOM inputs bound to one RHF field name would double-register).
 */
export function GoodsReceiptLinesEditor({ readOnly = false, poLineInfos = [] }: Props) {
  const { t } = useTranslation('goods-receipts');
  const isMobile = useIsMobile();
  const {
    register,
    control,
    setValue,
    formState: { errors },
  } = useFormContext<GoodsReceiptFormValues>();

  const lines: GrLineFormValues[] = useWatch({ control, name: 'lines' }) ?? [];
  const lineErrors = errors.lines as LineError[] | undefined;

  if (lines.length === 0) {
    return (
      <p className="text-muted-foreground text-sm">
        {t($ => $.lines.selectPoPrompt)}
      </p>
    );
  }

  if (isMobile) {
    return (
      <div className="space-y-3" role="list">
        {lines.map((line, index) => (
          <LineCard
            key={line.purchase_order_line_id}
            {...computeLine(line, index, poLineInfos, lineErrors)}
            readOnly={readOnly}
            register={register}
            setValue={setValue}
          />
        ))}
      </div>
    );
  }

  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead>
          <tr className="text-muted-foreground border-b text-start">
            <th className="pb-2 pr-3 font-medium">{t($ => $.lines.columns.product)}</th>
            <th className="w-28 pb-2 pr-3 text-end font-medium">{t($ => $.lines.columns.orderedQty)}</th>
            <th className="w-32 pb-2 pr-3 font-medium">{t($ => $.lines.columns.grossQty)}</th>
            <th className="w-32 pb-2 pr-3 font-medium">{t($ => $.lines.columns.netQty)}</th>
            <th className="w-24 pb-2 pr-3 text-end font-medium">{t($ => $.lines.columns.varianceQty)}</th>
            <th className="w-36 pb-2 pr-3 font-medium">{t($ => $.lines.columns.weightPhoto)}</th>
            <th className="w-40 pb-2 font-medium">{t($ => $.lines.columns.notes)}</th>
          </tr>
        </thead>
        <tbody className="divide-y">
          {lines.map((line, index) => (
            <LineRow
              key={line.purchase_order_line_id}
              {...computeLine(line, index, poLineInfos, lineErrors)}
              readOnly={readOnly}
              register={register}
              setValue={setValue}
            />
          ))}
        </tbody>
      </table>
    </div>
  );
}

/** Hidden fields carried by every line, in either layout. */
function HiddenLineFields({ index, register }: { index: number; register: Register }) {
  return (
    <>
      <input type="hidden" {...register(`lines.${index}.purchase_order_line_id`)} />
      <input type="hidden" {...register(`lines.${index}.product_id`)} />
      <input type="hidden" {...register(`lines.${index}.ordered_quantity`, { valueAsNumber: true })} />
      <input type="hidden" {...register(`lines.${index}.unit_price`, { valueAsNumber: true })} />
    </>
  );
}

/** Camera / weight-photo control — shared by the table row and the mobile card. */
function WeightPhotoControl({
  index,
  line,
  readOnly,
  setValue,
}: {
  index: number;
  line: GrLineFormValues;
  readOnly: boolean;
  setValue: SetValue;
}) {
  const { t } = useTranslation('goods-receipts');
  const photoInputRef = useRef<HTMLInputElement>(null);
  const hasPhoto = line.weight_photo instanceof File || Boolean(line.weight_photo_path);

  if (readOnly) {
    return line.weight_photo_path ? (
      <a
        href={line.weight_photo_path}
        target="_blank"
        rel="noopener noreferrer"
        className="text-primary inline-flex items-center gap-1 text-xs underline"
      >
        <Camera className="size-3" />
        {t($ => $.lines.viewPhoto)}
      </a>
    ) : (
      <span className="text-muted-foreground text-xs">—</span>
    );
  }

  return (
    <div className="flex items-center gap-1">
      <input
        ref={photoInputRef}
        type="file"
        accept=".jpg,.jpeg,.png"
        className="hidden"
        onChange={(e) => {
          const file = e.target.files?.[0] ?? null;
          setValue(`lines.${index}.weight_photo`, file, { shouldValidate: true });
        }}
      />
      <button
        type="button"
        onClick={() => photoInputRef.current?.click()}
        className={`inline-flex items-center gap-1 rounded px-2 py-1 text-xs ${
          hasPhoto
            ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
            : 'border-input border text-muted-foreground hover:bg-muted'
        }`}
      >
        <Camera className="size-3" />
        {hasPhoto ? t($ => $.lines.photoAdded) : t($ => $.lines.addPhoto)}
      </button>
      {hasPhoto && (
        <button
          type="button"
          onClick={() => {
            setValue(`lines.${index}.weight_photo`, null);
            setValue(`lines.${index}.weight_photo_path`, null);
            if (photoInputRef.current) photoInputRef.current.value = '';
          }}
          className="text-muted-foreground hover:text-destructive"
          aria-label={t($ => $.lines.removePhoto)}
        >
          <X className="size-3" />
        </button>
      )}
    </div>
  );
}

// ── Desktop table row ────────────────────────────────────────────────────────
function LineRow({
  index, line, info, gross, net, ordered, variance, errs, readOnly, register, setValue,
}: LineComputed & { readOnly: boolean; register: Register; setValue: SetValue }) {
  const { t } = useTranslation('goods-receipts');
  return (
    <tr>
      <td className="py-3 pr-3">
        <HiddenLineFields index={index} register={register} />
        <span className="font-medium">{info?.productName ?? '—'}</span>
        {info?.productSku && (
          <span className="text-muted-foreground ml-1.5 text-xs">{info.productSku}</span>
        )}
      </td>

      <td className="py-3 pr-3 text-end tabular-nums">{fmt(ordered)}</td>

      <td className="py-3 pr-3">
        {readOnly ? (
          <span className="tabular-nums">{fmt(gross)}</span>
        ) : (
          <>
            <Input
              type="number"
              min="0.0001"
              step="any"
              placeholder="0"
              {...register(`lines.${index}.gross_received_quantity`)}
            />
            {fieldErr(errs, 'gross_received_quantity') && (
              <p className="text-destructive mt-1 text-xs">
                {fieldErr(errs, 'gross_received_quantity')}
              </p>
            )}
          </>
        )}
      </td>

      <td className="py-3 pr-3">
        {readOnly ? (
          <span className="tabular-nums font-medium">
            {fmt(net)}{line.uom_symbol_snapshot ? <span className="text-muted-foreground ml-1 text-xs">{line.uom_symbol_snapshot}</span> : null}
          </span>
        ) : (
          <>
            <Input
              type="number"
              min="0.0001"
              max={gross || undefined}
              step="any"
              placeholder="0"
              {...register(`lines.${index}.net_received_quantity`)}
            />
            {fieldErr(errs, 'net_received_quantity') && (
              <p className="text-destructive mt-1 text-xs">
                {fieldErr(errs, 'net_received_quantity')}
              </p>
            )}
          </>
        )}
      </td>

      <td className={`py-3 pr-3 text-end tabular-nums ${varianceClass(variance)}`}>
        {net > 0 ? (variance >= 0 ? '+' : '') + fmt(variance) : '—'}
      </td>

      <td className="py-3 pr-3">
        <WeightPhotoControl index={index} line={line} readOnly={readOnly} setValue={setValue} />
      </td>

      <td className="py-3">
        {readOnly ? (
          <span className="text-muted-foreground text-xs">{line.notes || '—'}</span>
        ) : (
          <>
            <Input
              type="text"
              placeholder={t($ => $.lines.notesPlaceholder)}
              {...register(`lines.${index}.notes`)}
            />
            {fieldErr(errs, 'notes') && (
              <p className="text-destructive mt-1 text-xs">{fieldErr(errs, 'notes')}</p>
            )}
          </>
        )}
      </td>
    </tr>
  );
}

// ── Mobile line card ─────────────────────────────────────────────────────────
function LineCard({
  index, line, info, gross, net, ordered, variance, errs, readOnly, register, setValue,
}: LineComputed & { readOnly: boolean; register: Register; setValue: SetValue }) {
  const { t } = useTranslation('goods-receipts');

  return (
    <div role="listitem" className="space-y-3 rounded-lg border bg-card p-3.5">
      <HiddenLineFields index={index} register={register} />

      {/* Identity + ordered context */}
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="truncate text-sm font-medium">{info?.productName ?? '—'}</p>
          {info?.productSku && (
            <p className="font-mono text-xs text-muted-foreground">{info.productSku}</p>
          )}
        </div>
        <div className="shrink-0 text-end">
          <p className="text-[11px] uppercase tracking-wide text-muted-foreground">
            {t($ => $.lines.columns.orderedQty)}
          </p>
          <p className="text-sm tabular-nums">{fmt(ordered)}</p>
        </div>
      </div>

      {/* Gross + Net quantity entry */}
      <div className="grid grid-cols-2 gap-3">
        <label className="block space-y-1">
          <span className="text-[11px] uppercase tracking-wide text-muted-foreground">
            {t($ => $.lines.columns.grossQty)}
          </span>
          {readOnly ? (
            <p className="text-sm tabular-nums">{fmt(gross)}</p>
          ) : (
            <>
              <Input
                type="number"
                inputMode="decimal"
                min="0.0001"
                step="any"
                placeholder="0"
                {...register(`lines.${index}.gross_received_quantity`)}
              />
              {fieldErr(errs, 'gross_received_quantity') && (
                <p className="text-destructive text-xs">{fieldErr(errs, 'gross_received_quantity')}</p>
              )}
            </>
          )}
        </label>

        <label className="block space-y-1">
          <span className="text-[11px] uppercase tracking-wide text-muted-foreground">
            {t($ => $.lines.columns.netQty)}
          </span>
          {readOnly ? (
            <p className="text-sm font-medium tabular-nums">
              {fmt(net)}
              {line.uom_symbol_snapshot ? (
                <span className="ms-1 text-xs text-muted-foreground">{line.uom_symbol_snapshot}</span>
              ) : null}
            </p>
          ) : (
            <>
              <Input
                type="number"
                inputMode="decimal"
                min="0.0001"
                max={gross || undefined}
                step="any"
                placeholder="0"
                {...register(`lines.${index}.net_received_quantity`)}
              />
              {fieldErr(errs, 'net_received_quantity') && (
                <p className="text-destructive text-xs">{fieldErr(errs, 'net_received_quantity')}</p>
              )}
            </>
          )}
        </label>
      </div>

      {/* Variance + weight photo */}
      <div className="flex items-center justify-between gap-3">
        <span className="flex items-center gap-2 text-sm">
          <span className="text-[11px] uppercase tracking-wide text-muted-foreground">
            {t($ => $.lines.columns.varianceQty)}
          </span>
          <span className={`tabular-nums ${varianceClass(variance)}`}>
            {net > 0 ? (variance >= 0 ? '+' : '') + fmt(variance) : '—'}
          </span>
        </span>
        <WeightPhotoControl index={index} line={line} readOnly={readOnly} setValue={setValue} />
      </div>

      {/* Notes */}
      <label className="block space-y-1">
        <span className="text-[11px] uppercase tracking-wide text-muted-foreground">
          {t($ => $.lines.columns.notes)}
        </span>
        {readOnly ? (
          <p className="text-sm text-muted-foreground">{line.notes || '—'}</p>
        ) : (
          <>
            <Input
              type="text"
              placeholder={t($ => $.lines.notesPlaceholder)}
              {...register(`lines.${index}.notes`)}
            />
            {fieldErr(errs, 'notes') && (
              <p className="text-destructive text-xs">{fieldErr(errs, 'notes')}</p>
            )}
          </>
        )}
      </label>
    </div>
  );
}
