import { BookOpen, Camera, CheckCircle2, Circle, Lock, Package, TrendingUp, Truck, XCircle } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import type { TFunction } from 'i18next';

import { Separator } from '@/components/ui/separator';
import { Skeleton } from '@/components/ui/skeleton';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { useOrderSnapshot } from '@/features/orders/hooks/use-order-snapshot';
import type { MarginStatus, OrderBusinessContextSnapshot } from '@/features/orders/types/order';

// ── Primitives ────────────────────────────────────────────────────────────────

function fmt(n: number | null | undefined): string {
  if (n == null) return '—';
  return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function fmtPct(n: number | null | undefined): string {
  if (n == null) return '—';
  return `${n.toFixed(2)}%`;
}

function marginHighlight(status: MarginStatus | null | undefined): 'green' | 'amber' | 'red' | undefined {
  if (!status) return undefined;
  if (status === 'within_target' || status === 'above_target') return 'green';
  if (status === 'below_target') return 'red';
  return undefined;
}

function SectionTitle({ children }: { children: React.ReactNode }) {
  return (
    <h3 className="mb-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
      {children}
    </h3>
  );
}

function Row({
  label,
  value,
  mono = false,
  highlight,
}: {
  label: string;
  value: React.ReactNode;
  mono?: boolean;
  highlight?: 'green' | 'amber' | 'red';
}) {
  return (
    <div className="flex items-center justify-between gap-4 py-1.5 text-sm">
      <span className="text-muted-foreground">{label}</span>
      <span
        className={cn(
          mono && 'font-mono tabular-nums',
          highlight === 'green' && 'text-emerald-600 dark:text-emerald-400',
          highlight === 'amber' && 'text-amber-600 dark:text-amber-400',
          highlight === 'red'   && 'text-destructive',
        )}
      >
        {value}
      </span>
    </div>
  );
}

// ── PART 9: Snapshot Timeline ─────────────────────────────────────────────────

type TimelineEvent = {
  label: string;
  time: string | null | undefined;
  done: boolean;
};

function SnapshotTimeline({ events }: { events: TimelineEvent[] }) {
  return (
    <ol className="relative ml-2 border-l border-muted space-y-0">
      {events.map((ev, i) => (
        <li key={i} className="ml-4 py-2">
          <span
            className={cn(
              'absolute -left-[9px] flex size-[18px] items-center justify-center rounded-full ring-2 ring-background',
              ev.done
                ? 'bg-emerald-500 text-white dark:bg-emerald-600'
                : 'bg-muted text-muted-foreground',
            )}
          >
            {ev.done ? (
              <CheckCircle2 className="size-2.5" />
            ) : (
              <Circle className="size-2.5" />
            )}
          </span>
          <p className={cn('text-xs font-medium', !ev.done && 'text-muted-foreground')}>
            {ev.label}
          </p>
          {ev.time && (
            <p className="text-[10px] text-muted-foreground">
              {new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(ev.time))}
            </p>
          )}
        </li>
      ))}
    </ol>
  );
}

// ── Business Context Section ──────────────────────────────────────────────────

function BusinessContextSection({ ctx, t }: { ctx: OrderBusinessContextSnapshot; t: TFunction<'orders', undefined> }) {
  return (
    <div className="flex flex-col gap-4">
      {/* Brand + Channel */}
      {(ctx.brand_context.name || ctx.channel_context.name) && (
        <div className="rounded-lg border p-3 space-y-0.5">
          {ctx.brand_context.name && (
            <Row label={t('snapshot.brand')} value={ctx.brand_context.name} />
          )}
          {ctx.channel_context.name && (
            <Row label={t('snapshot.channel')} value={ctx.channel_context.name} />
          )}
          {ctx.channel_context.type && (
            <Row label={t('snapshot.channelType')} value={ctx.channel_context.type} />
          )}
        </div>
      )}

      {/* Decision Provenance */}
      <div className="rounded-lg border p-3 space-y-0.5">
        <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground mb-2">
          {t('snapshot.decisionProvenance')}
        </p>
        {ctx.decision_provenance.price.source && (
          <Row label={t('snapshot.priceSource')} value={ctx.decision_provenance.price.source} />
        )}
        {ctx.decision_provenance.cost.source && (
          <Row label={t('snapshot.costSource')} value={ctx.decision_provenance.cost.source} />
        )}
        {ctx.decision_provenance.cost.recipe_version && (
          <Row label={t('snapshot.recipeVersion')} value={`v${ctx.decision_provenance.cost.recipe_version}`} />
        )}
        {ctx.decision_provenance.discount.source && (
          <Row
            label={t('snapshot.discountSource')}
            value={ctx.decision_provenance.discount.manual_override ? t('snapshot.manualOverride') : ctx.decision_provenance.discount.source}
            highlight={ctx.decision_provenance.discount.manual_override ? 'amber' : undefined}
          />
        )}
        {ctx.decision_provenance.shipping.zone && (
          <Row label={t('snapshot.shippingZone')} value={ctx.decision_provenance.shipping.zone} />
        )}
      </div>

      {/* Customer Context */}
      {ctx.customer_context.delivery_success_rate != null && (
        <div className="rounded-lg border p-3 space-y-0.5">
          <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground mb-2">
            {t('snapshot.customerContext')}
          </p>
          <Row
            label={t('snapshot.deliverySuccessRate')}
            value={`${ctx.customer_context.delivery_success_rate.toFixed(1)}%`}
            highlight={
              ctx.customer_context.delivery_success_rate >= 80
                ? 'green'
                : ctx.customer_context.delivery_success_rate >= 50
                ? 'amber'
                : 'red'
            }
          />
          {ctx.customer_context.tier && (
            <Row label={t('snapshot.customerTier')} value={ctx.customer_context.tier} />
          )}
          {ctx.customer_context.segment && (
            <Row label={t('snapshot.segment')} value={ctx.customer_context.segment} />
          )}
        </div>
      )}

      {/* Policy Versions */}
      {(ctx.policy_versions.pricing || ctx.policy_versions.shipping) && (
        <div className="rounded-lg border p-3 space-y-0.5">
          <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground mb-2">
            {t('snapshot.policyVersions')}
          </p>
          {ctx.policy_versions.pricing && (
            <Row label={t('snapshot.pricingPolicy')} value={`v${ctx.policy_versions.pricing}`} />
          )}
          {ctx.policy_versions.shipping && (
            <Row label={t('snapshot.shippingPolicy')} value={`v${ctx.policy_versions.shipping}`} />
          )}
        </div>
      )}

      {/* Marketing Context — only when populated */}
      {(ctx.marketing_context.campaign_name || ctx.marketing_context.utm_source) && (
        <div className="rounded-lg border p-3 space-y-0.5">
          <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground mb-2">
            {t('snapshot.marketing')}
          </p>
          {ctx.marketing_context.campaign_name && (
            <Row label={t('snapshot.campaign')} value={ctx.marketing_context.campaign_name} />
          )}
          {ctx.marketing_context.utm_source && (
            <Row label={t('snapshot.utmSource')} value={ctx.marketing_context.utm_source} />
          )}
          {ctx.marketing_context.utm_medium && (
            <Row label={t('snapshot.utmMedium')} value={ctx.marketing_context.utm_medium} />
          )}
        </div>
      )}
    </div>
  );
}

// ── Line rows ─────────────────────────────────────────────────────────────────

function LineSnapshotRow({
  line,
  t,
}: {
  line: NonNullable<ReturnType<typeof useOrderSnapshot>['snapshot']>['lines'][number];
  t: TFunction<'orders', undefined>;
}) {
  const hasCost = line.unit_cost != null;

  return (
    <div className="rounded-lg border bg-muted/20 p-3 text-sm">
      <div className="mb-2 flex items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="font-medium truncate">{line.product_name ?? '—'}</p>
          <p className="text-xs font-mono text-muted-foreground">{line.product_sku ?? ''}</p>
        </div>
        <div className="text-end shrink-0">
          <p className="font-semibold tabular-nums">{fmt(line.line_total)}</p>
          <p className="text-xs text-muted-foreground">
            {line.quantity} × {fmt(line.unit_price_at_sale)}
          </p>
        </div>
      </div>

      {/* Pricing section */}
      <div className="border-t pt-2 mt-2 space-y-0.5">
        <Row label={t('snapshot.priceAtSale')} value={fmt(line.unit_price_at_sale)} mono />
        {line.regular_price_at_sale != null && (
          <Row label={t('snapshot.regularPrice')} value={fmt(line.regular_price_at_sale)} mono />
        )}
        {line.sale_price_at_sale != null && (
          <Row label={t('snapshot.salePrice')} value={fmt(line.sale_price_at_sale)} mono />
        )}
      </div>

      {/* Cost section */}
      {hasCost && (
        <div className="border-t pt-2 mt-2 space-y-0.5">
          {line.raw_material_cost != null && (
            <Row label={t('snapshot.rawMaterials')} value={fmt(line.raw_material_cost)} mono />
          )}
          {line.packaging_cost != null && line.packaging_cost > 0 && (
            <Row label={t('snapshot.packaging')} value={fmt(line.packaging_cost)} mono />
          )}
          {line.manufacturing_cost != null && line.manufacturing_cost > 0 && (
            <Row label={t('snapshot.manufacturing')} value={fmt(line.manufacturing_cost)} mono />
          )}
          {line.other_cost != null && line.other_cost > 0 && (
            <Row label={t('snapshot.other')} value={fmt(line.other_cost)} mono />
          )}
          <Row label={t('snapshot.unitCost')} value={fmt(line.unit_cost)} mono />
          <Row label={t('snapshot.lineCost')} value={fmt(line.line_cost)} mono />
        </div>
      )}

      {/* Profitability */}
      {line.gross_profit != null && (
        <div className="border-t pt-2 mt-2 space-y-0.5">
          <Row
            label={t('snapshot.grossProfit')}
            value={fmt(line.gross_profit)}
            mono
            highlight={line.gross_profit >= 0 ? 'green' : 'red'}
          />
          <Row
            label={t('snapshot.margin')}
            value={fmtPct(line.margin_percent)}
            highlight={marginHighlight(line.margin_status)}
          />
          {line.target_margin_percent != null && (
            <Row label={t('snapshot.targetMargin')} value={fmtPct(line.target_margin_percent)} />
          )}
          {line.source_recipe_version && (
            <p className="text-[10px] text-muted-foreground mt-1">
              {t('snapshot.recipeVersion')} v{line.source_recipe_version}
              {line.bom_version_number != null ? ` · BOM #${line.bom_version_number}` : ''}
            </p>
          )}
          {line.price_review_id && (
            <p className="text-[10px] text-muted-foreground">
              {t('snapshot.priceReviewApproved')}{line.price_review_approved_at
                ? ` ${new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(line.price_review_approved_at))}`
                : ''}
            </p>
          )}
        </div>
      )}
    </div>
  );
}

// ── Main component ────────────────────────────────────────────────────────────

type OrderSnapshotTabProps = {
  orderId: string;
};

export function OrderSnapshotTab({ orderId }: OrderSnapshotTabProps) {
  const { t } = useTranslation('orders');
  const { snapshot, isLoading } = useOrderSnapshot(orderId);

  if (isLoading) {
    return (
      <div className="flex flex-col gap-3 p-4">
        {Array.from({ length: 5 }, (_, i) => (
          <Skeleton key={i} className="h-8 w-full rounded-md" />
        ))}
      </div>
    );
  }

  if (!snapshot) {
    return (
      <div className="flex flex-col items-center gap-3 py-16 text-center px-4">
        <Camera className="size-8 text-muted-foreground" />
        <p className="text-sm font-medium">{t('snapshot.snapshotNotAvailable')}</p>
        <p className="text-xs text-muted-foreground max-w-xs">
          {t('snapshot.snapshotDescription')}
        </p>
      </div>
    );
  }

  const hasCostData = snapshot.total_cogs != null && snapshot.total_cogs > 0;

  return (
    <div className="flex flex-col gap-6 p-4">

      {/* ── Version badge + Locked indicator ── */}
      <div className="flex items-center justify-between gap-3">
        <Badge variant="secondary" className="text-xs font-mono">
          {t('snapshot.version')} {snapshot.snapshot_version}
        </Badge>
        <div className="flex items-center gap-1.5 text-xs text-amber-700 dark:text-amber-400">
          <Lock className="size-3 shrink-0" />
          <span>{t('snapshot.locked')}</span>
          {snapshot.locked_at && (
            <span className="text-muted-foreground">
              · {new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(snapshot.locked_at))}
            </span>
          )}
        </div>
      </div>

      {/* ── SHA-256 integrity health indicator ── */}
      {snapshot.hash_verified === false ? (
        <div className="flex items-center gap-2 rounded-md border border-destructive/40 bg-destructive/5 px-3 py-2">
          <XCircle className="size-3.5 shrink-0 text-destructive" />
          <p className="text-xs text-destructive font-medium">
            {t('snapshot.integrityFailed')}
          </p>
        </div>
      ) : snapshot.hash_verified === true ? (
        <div className="flex items-center gap-2 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 dark:border-emerald-900/50 dark:bg-emerald-950/30">
          <CheckCircle2 className="size-3.5 shrink-0 text-emerald-600 dark:text-emerald-400" />
          <p className="text-xs text-emerald-700 dark:text-emerald-400">
            {t('snapshot.integrityVerified')}
          </p>
        </div>
      ) : (
        <div className="flex items-center gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 dark:border-amber-900/50 dark:bg-amber-950/30">
          <Lock className="size-3.5 shrink-0 text-amber-600 dark:text-amber-400" />
          <p className="text-xs text-amber-700 dark:text-amber-400">
            {t('snapshot.integrityImmutable')}
          </p>
        </div>
      )}

      {/* ── Snapshot Timeline ── */}
      <div>
        <SectionTitle>{t('snapshot.timelineTitle')}</SectionTitle>
        <SnapshotTimeline
          events={[
            {
              label: t('snapshot.timelineBusinessContext'),
              time: snapshot.business_context?.captured_at,
              done: snapshot.business_context != null,
            },
            {
              label: t('snapshot.timelineFinancialCreated'),
              time: snapshot.snapshotted_at,
              done: true,
            },
            {
              label: t('snapshot.timelineLocked'),
              time: snapshot.locked_at,
              done: snapshot.locked,
            },
            {
              label: t('snapshot.timelineAccountingPublished'),
              time: null,
              done: false,
            },
          ]}
        />
      </div>

      {/* ── Business Context ── */}
      {snapshot.business_context && (
        <div>
          <SectionTitle>
            <span className="inline-flex items-center gap-1.5">
              <BookOpen className="size-3" />
              {t('snapshot.businessContext')}
            </span>
          </SectionTitle>
          <BusinessContextSection ctx={snapshot.business_context} t={t} />
        </div>
      )}

      {/* ── Order financials ── */}
      <div>
        <SectionTitle>{t('snapshot.orderFinancials')}</SectionTitle>
        <div className="rounded-lg border p-3">
          <Row label={t('snapshot.subtotal')} value={fmt(snapshot.subtotal)} mono />
          {snapshot.discount_amount > 0 && (
            <Row
              label={snapshot.discount_type === 'percentage' ? t('snapshot.discountPct') : t('snapshot.discount')}
              value={`−${fmt(snapshot.discount_amount)}`}
              mono
              highlight="green"
            />
          )}
          {snapshot.shipping_cost > 0 && (
            <Row label={t('snapshot.shipping')} value={fmt(snapshot.shipping_cost)} mono />
          )}
          <Separator className="my-1.5" />
          <Row label={t('snapshot.grandTotal')} value={fmt(snapshot.grand_total)} mono />
          {snapshot.deposit_amount > 0 && (
            <>
              <Row label={t('snapshot.depositPaid')}      value={`−${fmt(snapshot.deposit_amount)}`} mono highlight="green" />
              <Row label={t('snapshot.remainingBalance')} value={fmt(snapshot.remaining_balance)}    mono />
            </>
          )}
        </div>
      </div>

      {/* ── Shipping snapshot ── */}
      {(snapshot.shipping_zone || snapshot.shipping_rule_name) && (
        <div>
          <SectionTitle>
            <span className="inline-flex items-center gap-1.5">
              <Truck className="size-3" />
              {t('snapshot.shippingSection')}
            </span>
          </SectionTitle>
          <div className="rounded-lg border p-3">
            {snapshot.shipping_zone && (
              <Row label={t('snapshot.zone')} value={snapshot.shipping_zone} />
            )}
            {snapshot.shipping_rule_name && (
              <Row label={t('snapshot.rule')} value={snapshot.shipping_rule_name} />
            )}
            <Row
              label={t('snapshot.override')}
              value={snapshot.shipping_override_applied ? t('snapshot.overrideYes') : t('snapshot.overrideNo')}
              highlight={snapshot.shipping_override_applied ? 'amber' : undefined}
            />
          </div>
        </div>
      )}

      {/* ── Margin summary ── */}
      {hasCostData && (
        <div>
          <SectionTitle>
            <span className="inline-flex items-center gap-1.5">
              <TrendingUp className="size-3" />
              {t('snapshot.marginSummary')}
            </span>
          </SectionTitle>
          <div className="rounded-lg border p-3">
            <Row label={t('snapshot.totalCogs')}     value={fmt(snapshot.total_cogs)} mono />
            {snapshot.total_raw_material_cost != null && snapshot.total_raw_material_cost > 0 && (
              <Row label={`  ${t('snapshot.rawMaterials')}`}  value={fmt(snapshot.total_raw_material_cost)} mono />
            )}
            {snapshot.total_packaging_cost != null && snapshot.total_packaging_cost > 0 && (
              <Row label={`  ${t('snapshot.packaging')}`}      value={fmt(snapshot.total_packaging_cost)} mono />
            )}
            {snapshot.total_manufacturing_cost != null && snapshot.total_manufacturing_cost > 0 && (
              <Row label={`  ${t('snapshot.manufacturing')}`}  value={fmt(snapshot.total_manufacturing_cost)} mono />
            )}
            {snapshot.total_other_cost != null && snapshot.total_other_cost > 0 && (
              <Row label={`  ${t('snapshot.other')}`}          value={fmt(snapshot.total_other_cost)} mono />
            )}
            <Separator className="my-1.5" />
            <Row
              label={t('snapshot.grossProfit')}
              value={fmt(snapshot.gross_profit)}
              mono
              highlight={(snapshot.gross_profit ?? 0) >= 0 ? 'green' : 'red'}
            />
            <Row
              label={t('snapshot.actualMargin')}
              value={fmtPct(snapshot.actual_margin_percent)}
              highlight={marginHighlight(snapshot.margin_status)}
            />
            {snapshot.target_margin_percent != null && (
              <Row label={t('snapshot.targetMargin')} value={fmtPct(snapshot.target_margin_percent)} />
            )}
            {snapshot.margin_difference != null && (
              <Row
                label={t('snapshot.difference')}
                value={`${snapshot.margin_difference >= 0 ? '+' : ''}${snapshot.margin_difference.toFixed(2)}pp`}
                highlight={snapshot.margin_difference >= 0 ? 'green' : 'red'}
              />
            )}
          </div>
        </div>
      )}

      {/* ── Line snapshots ── */}
      {snapshot.lines.length > 0 && (
        <div>
          <SectionTitle>
            <span className="inline-flex items-center gap-1.5">
              <Package className="size-3" />
              {t('snapshot.lineDetail', { count: snapshot.lines.length })}
            </span>
          </SectionTitle>
          <div className="flex flex-col gap-2">
            {snapshot.lines.map((line) => (
              <LineSnapshotRow key={line.id} line={line} t={t} />
            ))}
          </div>
        </div>
      )}

      {/* ── Snapshot metadata ── */}
      <div>
        <SectionTitle>{t('snapshot.snapshotMetadata')}</SectionTitle>
        <div className="rounded-lg border p-3 text-xs text-muted-foreground space-y-1">
          <div className="flex justify-between">
            <span>{t('snapshot.uuid')}</span>
            <span className="font-mono truncate max-w-[200px]">{snapshot.snapshot_uuid}</span>
          </div>
          <div className="flex justify-between">
            <span>{t('snapshot.currency')}</span>
            <span>{snapshot.currency}</span>
          </div>
          {snapshot.recipe_version && (
            <div className="flex justify-between">
              <span>{t('snapshot.recipeVersion')}</span>
              <span>{snapshot.recipe_version}</span>
            </div>
          )}
          <div className="flex justify-between">
            <span>{t('snapshot.pricingEngine')}</span>
            <span>v{snapshot.pricing_engine_version}</span>
          </div>
          <div className="flex justify-between">
            <span>{t('snapshot.costEngine')}</span>
            <span>v{snapshot.cost_engine_version}</span>
          </div>
          {snapshot.integrity_hash && (
            <div className="flex justify-between gap-2">
              <span className="shrink-0">{t('snapshot.sha256')}</span>
              <span className="font-mono truncate text-[9px]">{snapshot.integrity_hash}</span>
            </div>
          )}
          <div className="flex justify-between">
            <span>{t('snapshot.snapshottedAt')}</span>
            <span>
              {new Intl.DateTimeFormat(undefined, {
                dateStyle: 'medium',
                timeStyle: 'short',
              }).format(new Date(snapshot.snapshotted_at))}
            </span>
          </div>
        </div>
      </div>

    </div>
  );
}
